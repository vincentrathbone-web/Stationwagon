// The Song prep screen (views/manage/songprep.php, round 40): try mastering settings on any song of
// the library and hear the whole song, original against processed (A/B), both levelled the way
// import levels them. The song is processed in pieces by songprep-worker.js with the same chain
// import uses (master-core.js), the piece being heard first, then the rest; every change starts
// again from where it plays. Two views of the waves (the whole song, and the seconds around the
// playhead, enlarged), loudness readouts, a gain-reduction meter per band and the limiters, a
// five-band EQ drawn as a curve to drag, and the compressor in a simple and a full view. Presets
// are saved on the server; one is used for new uploads. "Apply to this song" prepares the song
// again from its original with these settings (prep.js) and puts it in place.
(() => {
  "use strict";
  const root = document.getElementById("songprep");
  if (!root || !window.SwMaster || !window.SwPrepCore || !window.Worker) return;
  const D = JSON.parse(document.getElementById("songprepData").textContent);
  const M = window.SwMaster, $ = (id) => document.getElementById(id);
  const RATE = 44100, PIECE_S = 10, METER_S = 0.01, BLOCK_S = 0.1, FINE = 220, COARSE = 4410;
  const TARGET = typeof D.target === "number" ? D.target : window.SwPrepCore.P.TARGET_LUFS, CEIL = window.SwPrepCore.P.PEAK_CEILING_DB, MAXG = window.SwPrepCore.P.MAX_GAIN_DB;
  const KEYS = ["cross", "threshold", "ratio", "knee", "gain", "attackMs", "releaseMs", "mbThresh", "mbMargin", "shelfDb", "exciteDb", "sideDb", "maxGain", "ceiling", "eqOn", "compOn", "finishOn", "eq", "simple"];
  const BANDS = ["Low", "Low mid", "High mid", "High"];
  const HANDHELD = (navigator.maxTouchPoints > 0 && matchMedia("(pointer: coarse)").matches) || /Android|iPhone|iPad/.test(navigator.userAgent);
  const db = (v) => (v === null || !isFinite(v) ? "–" : (v > 0 ? "+" : "") + v.toFixed(1).replace("-", "−"));
  const tok = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();

  // ---- state ----------------------------------------------------------------------------------
  let presets = D.presets.slice(), defaultId = D.defaultId, presetId = D.defaultId && presets.some((p) => p.id === D.defaultId) ? D.defaultId : 0;
  let settings = M.preset({}), simple = null, dirty = false; // loadPreset fills these at the start
  let song = null; // { track, n, L, R, PL, PR, pieces, gens, fineO, fineP, coarseO, coarseP, msO, msP, meter, peaks }
  let gen = 0, inFlight = null, renderTimer = 0;
  let ctx = null, gO = null, gP = null, hear = "P", play = null, nodes = [], waiting = false;
  let cursor = 0; // the song position (s) when not playing
  let worker = null, restarts = 0;

  const say = (t, bad = false) => { const m = $("spMsg"); m.textContent = t; m.className = "spmsg" + (bad ? " bad" : ""); };

  // ---- presets --------------------------------------------------------------------------------
  function loadPreset(id) {
    presetId = id;
    const p = presets.find((x) => x.id === id);
    settings = M.preset(p ? p.settings : {});
    simple = Object.assign({ amount: 0.5, punch: 0, loudness: settings.maxGain, ceiling: settings.ceiling }, (p && p.settings.simple) || {});
    dirty = false;
    fillControls();
    showPresets();
    changed(false);
  }
  /** What is saved: only the settings a preset may hold (PrepPresets::KEYS). */
  function saved() {
    const o = {};
    for (const k of KEYS) if (k === "simple") o.simple = { ...simple }; else o[k] = JSON.parse(JSON.stringify(settings[k]));
    return o;
  }
  function showPresets() {
    const sel = $("spPreset");
    sel.innerHTML = "";
    const add = (id, name) => { const o = document.createElement("option"); o.value = String(id); o.textContent = name + (id === defaultId ? "  ★ new uploads" : ""); sel.appendChild(o); };
    add(0, "Audition chain (built in)");
    for (const p of presets) add(p.id, p.name);
    sel.value = String(presetId);
    const cur = presets.find((x) => x.id === presetId);
    $("spName").value = cur ? cur.name : "";
    $("spSave").disabled = !cur || !dirty;
    $("spDelete").disabled = !cur;
    $("spDefault").disabled = presetId === defaultId || dirty;
    $("spDefault").textContent = presetId === defaultId ? "★ Used for new uploads" : "Use for new uploads";
    $("spDirty").hidden = !dirty;
  }
  async function post(fields, file) {
    const data = new FormData();
    data.append("csrf", D.csrf);
    for (const [k, v] of Object.entries(fields)) data.append(k, v);
    if (file) data.append("file", file, "song.mp3");
    const r = await fetch(D.postUrl, { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" });
    const j = await r.json().catch(() => ({ ok: false, error: "The server answered " + r.status }));
    if (!r.ok && j.ok === undefined) j.ok = false;
    return j;
  }
  async function savePreset(asNew) {
    const name = $("spName").value.trim();
    const j = await post({ action: "save", id: asNew ? "0" : String(presetId), name, settings: JSON.stringify(saved()) });
    if (!j.ok) { say(j.error || "Couldn’t save.", true); return; }
    const p = { id: j.id, name, settings: saved() };
    presets = presets.filter((x) => x.id !== j.id).concat(p).sort((a, b) => a.name.localeCompare(b.name));
    presetId = j.id; dirty = false; showPresets();
    say(`Saved “${name}”.`);
  }
  $("spSave").addEventListener("click", () => savePreset(false));
  $("spSaveAs").addEventListener("click", () => savePreset(true));
  $("spDelete").addEventListener("click", async () => {
    const cur = presets.find((x) => x.id === presetId);
    if (!cur || !confirm(`Remove the preset “${cur.name}”?`)) return;
    const j = await post({ action: "delete", id: String(cur.id) });
    if (!j.ok) { say(j.error || "Couldn’t remove it.", true); return; }
    presets = presets.filter((x) => x.id !== cur.id);
    if (defaultId === cur.id) defaultId = 0;
    say(`Removed “${cur.name}”.`);
    loadPreset(0);
  });
  $("spDefault").addEventListener("click", async () => {
    const j = await post({ action: "default", id: String(presetId) });
    if (!j.ok) { say(j.error || "Couldn’t set it.", true); return; }
    defaultId = j.id; showPresets();
    say(`New uploads are mastered with “${presetId ? presets.find((x) => x.id === presetId).name : "Audition chain"}”.`);
  });
  $("spPreset").addEventListener("change", (e) => {
    if (dirty && !confirm("Leave the changes you haven’t saved?")) { e.target.value = String(presetId); return; }
    loadPreset(Number(e.target.value));
  });
  window.addEventListener("beforeunload", (e) => { if (dirty) { e.preventDefault(); e.returnValue = ""; } });

  // ---- the controls ---------------------------------------------------------------------------
  // [id, label, get, set, min, max, step, unit]
  const S = (k) => [() => settings[k], (v) => { settings[k] = v; }];
  const A = (k, i) => [() => settings[k][i], (v) => { settings[k][i] = v; }];
  const advanced = [
    ["Crossovers", [0, 1, 2].map((i) => [`cross${i}`, ["Low / low mid", "Low mid / high mid", "High mid / high"][i], ...A("cross", i), ...M.RANGES.cross[i], 1, "Hz"])],
    ...BANDS.map((b, i) => [b + " band", [
      [`thr${i}`, "Threshold", ...A("threshold", i), ...M.RANGES.threshold, 0.5, "dB"],
      [`rat${i}`, "Ratio", ...A("ratio", i), ...M.RANGES.ratio, 0.1, ":1"],
      [`gain${i}`, "Gain", ...A("gain", i), ...M.RANGES.gain, 0.5, "dB"],
    ]]),
    ["Timing", [["knee", "Knee", ...S("knee"), ...M.RANGES.knee, 0.5, "dB"], ["att", "Attack", ...S("attackMs"), ...M.RANGES.attackMs, 0.1, "ms"], ["rel", "Release", ...S("releaseMs"), ...M.RANGES.releaseMs, 1, "ms"]]],
    ["Multiband limiter", [["mbt", "Drive (threshold)", ...S("mbThresh"), ...M.RANGES.mbThresh, 0.1, "dB"], ["mbm", "Ceiling (margin)", ...S("mbMargin"), ...M.RANGES.mbMargin, 0.1, "dB"]]],
    ["Finish", [["shelf", "Brightness (above 5 kHz)", ...S("shelfDb"), ...M.RANGES.shelfDb, 0.1, "dB"], ["excite", "Exciter (overtones)", ...S("exciteDb"), ...M.RANGES.exciteDb, 1, "dB"],
      ["side", "Width (sides above 1.8 kHz)", ...S("sideDb"), ...M.RANGES.sideDb, 0.1, "dB"], ["maxg", "Loudness (maximizer)", ...S("maxGain"), ...M.RANGES.maxGain, 0.1, "dB"],
      ["ceil", "Ceiling", ...S("ceiling"), ...M.RANGES.ceiling, 0.01, "dBFS"]]],
  ];
  const simpleRows = [
    ["amount", "Amount", "How hard the compressor works: the middle is the Audition chain, left lets the music breathe, right squeezes it", 0, 1, 0.01, (v) => Math.round(v * 100) + " %"],
    ["punch", "Punch", "Lets the starts of notes through before the compressor catches them", 0, 1, 0.01, (v) => Math.round(v * 100) + " %"],
    ["loudness", "Loudness", "The maximizer's gain before the final limiter", ...M.RANGES.maxGain, 0.1, (v) => "+" + v.toFixed(1) + " dB"],
    ["ceiling", "Ceiling", "No peak goes above this", ...M.RANGES.ceiling, 0.01, (v) => v.toFixed(2).replace("-", "−") + " dBFS"],
  ];
  const fmt = (v, step, unit) => (step >= 1 ? Math.round(v) : step >= 0.1 ? v.toFixed(1) : v.toFixed(2)).toString().replace("-", "−") + (unit.startsWith(":") ? unit : " " + unit);

  function buildControls() {
    const sv = $("spSimple");
    for (const [k, label, help, min, max, step, show] of simpleRows) {
      const row = document.createElement("label");
      row.className = "sprow";
      row.innerHTML = `<span class="spl"><b>${label}</b><small>${help}</small></span><input type="range" min="${min}" max="${max}" step="${step}" id="sps-${k}"><output id="spso-${k}"></output>`;
      sv.appendChild(row);
      row.querySelector("input").addEventListener("input", (e) => {
        simple[k] = Number(e.target.value);
        Object.assign(settings, M.simple(simple));
        $("spso-" + k).textContent = show(simple[k]);
        fillAdvanced();
        changed();
      });
    }
    const av = $("spAdvanced");
    for (const [title, rows] of advanced) {
      const g = document.createElement("fieldset");
      g.className = "spgroup";
      g.innerHTML = `<legend>${title}</legend>`;
      for (const [id, label, get, set, min, max, step, unit] of rows) {
        const row = document.createElement("label");
        row.className = "sprow";
        row.innerHTML = `<span class="spl">${label}</span><input type="range" min="${min}" max="${max}" step="${step}" id="spa-${id}"><output id="spao-${id}"></output>`;
        g.appendChild(row);
        row.querySelector("input").addEventListener("input", (e) => {
          set(Number(e.target.value));
          $("spao-" + id).textContent = fmt(get(), step, unit);
          changed();
        });
      }
      av.appendChild(g);
    }
    for (const [id, key] of [["spEqOn", "eqOn"], ["spCompOn", "compOn"], ["spFinishOn", "finishOn"]]) {
      $(id).addEventListener("change", (e) => { settings[key] = e.target.checked; changed(); });
    }
    $("spHpOn").addEventListener("change", (e) => { settings.eq.hp.on = e.target.checked; drawEq(); changed(); });
  }
  function fillAdvanced() {
    for (const [, rows] of advanced) for (const [id, , get, , , , step, unit] of rows) {
      $("spa-" + id).value = String(get());
      $("spao-" + id).textContent = fmt(get(), step, unit);
    }
  }
  function fillControls() {
    for (const [k, , , , , , show] of simpleRows) { $("sps-" + k).value = String(simple[k]); $("spso-" + k).textContent = show(simple[k]); }
    fillAdvanced();
    $("spEqOn").checked = settings.eqOn; $("spCompOn").checked = settings.compOn; $("spFinishOn").checked = settings.finishOn;
    $("spHpOn").checked = settings.eq.hp.on;
    fillEqFields();
    drawEq();
  }

  // ---- the EQ curve ---------------------------------------------------------------------------
  const eqc = $("spEq"), FMIN = 20, FMAX = 20000, DBR = 15;
  const eqBands = () => {
    const e = settings.eq;
    return [
      { key: "hp", label: "HP", get hz() { return e.hp.hz; }, set hz(v) { e.hp.hz = v; }, db: 0, fixedDb: true, range: M.RANGES.hpHz },
      { key: "low", label: "L", get hz() { return e.low.hz; }, set hz(v) { e.low.hz = v; }, get db() { return e.low.db; }, set db(v) { e.low.db = v; } },
      ...[0, 1, 2].map((i) => ({ key: "b" + i, label: String(i + 1), get hz() { return e.bells[i].hz; }, set hz(v) { e.bells[i].hz = v; }, get db() { return e.bells[i].db; }, set db(v) { e.bells[i].db = v; }, get q() { return e.bells[i].q; }, set q(v) { e.bells[i].q = v; } })),
      { key: "high", label: "H", get hz() { return e.high.hz; }, set hz(v) { e.high.hz = v; }, get db() { return e.high.db; }, set db(v) { e.high.db = v; } },
    ];
  };
  const xOf = (hz, w) => Math.log(hz / FMIN) / Math.log(FMAX / FMIN) * w;
  const hzOf = (x, w) => FMIN * Math.pow(FMAX / FMIN, Math.min(1, Math.max(0, x / w)));
  const yOf = (d, h) => h / 2 - d / DBR * (h / 2 - 8);
  const dbOf = (y, h) => (h / 2 - y) / (h / 2 - 8) * DBR;
  function response(hz) {
    const e = settings.eq, ks = [];
    if (e.hp.on) ks.push(M.biquad("hp", e.hp.hz, RATE));
    if (e.low.db) ks.push(M.biquad("low", e.low.hz, RATE, Math.SQRT1_2, e.low.db));
    for (const b of e.bells) if (b.db) ks.push(M.biquad("bell", b.hz, RATE, b.q, b.db));
    if (e.high.db) ks.push(M.biquad("shelf", e.high.hz, RATE, Math.SQRT1_2, e.high.db));
    const w = 2 * Math.PI * hz / RATE, c1 = Math.cos(w), s1 = Math.sin(w), c2 = Math.cos(2 * w), s2 = Math.sin(2 * w);
    let g = 0;
    for (const [b0, b1, b2, a1, a2] of ks) {
      const nr = b0 + b1 * c1 + b2 * c2, ni = -(b1 * s1 + b2 * s2), dr = 1 + a1 * c1 + a2 * c2, di = -(a1 * s1 + a2 * s2);
      g += 10 * Math.log10((nr * nr + ni * ni) / (dr * dr + di * di));
    }
    return g;
  }
  function drawEq() {
    const dpr = devicePixelRatio || 1, w = eqc.clientWidth, h = eqc.clientHeight;
    if (!w) return;
    eqc.width = Math.round(w * dpr); eqc.height = Math.round(h * dpr);
    const g = eqc.getContext("2d");
    g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h);
    g.strokeStyle = tok("--wave-grid"); g.fillStyle = tok("--faint"); g.lineWidth = 1; g.font = "11px " + tok("--f-mono");
    for (const f of [50, 100, 200, 500, 1000, 2000, 5000, 10000]) { const x = Math.round(xOf(f, w)) + 0.5; g.beginPath(); g.moveTo(x, 0); g.lineTo(x, h); g.stroke(); g.fillText(f >= 1000 ? f / 1000 + "k" : String(f), x + 3, h - 4); }
    for (const d of [-12, -6, 0, 6, 12]) { const y = Math.round(yOf(d, h)) + 0.5; g.beginPath(); g.moveTo(0, y); g.lineTo(w, y); g.stroke(); g.fillText((d > 0 ? "+" : "") + d, 3, y - 3); }
    g.strokeStyle = settings.eqOn ? tok("--amber") : tok("--faint"); g.lineWidth = 2; g.beginPath();
    for (let x = 0; x <= w; x += 2) { const y = yOf(Math.max(-DBR, Math.min(DBR, response(hzOf(x, w)))), h); x ? g.lineTo(x, y) : g.moveTo(x, y); }
    g.stroke();
    for (const b of eqBands()) {
      if (b.key === "hp" && !settings.eq.hp.on) continue;
      const x = xOf(b.hz, w), y = yOf(b.db, h);
      g.fillStyle = b.key === drag?.key ? tok("--amber") : tok("--raised"); g.strokeStyle = tok("--ink"); g.lineWidth = 1.5;
      g.beginPath(); g.arc(x, y, 9, 0, 2 * Math.PI); g.fill(); g.stroke();
      g.fillStyle = tok("--ink"); g.font = "bold 10px " + tok("--f-ui"); g.textAlign = "center"; g.fillText(b.label, x, y + 3.5); g.textAlign = "start";
    }
  }
  let drag = null;
  const at = (e) => { const r = eqc.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top, r.width, r.height]; };
  eqc.addEventListener("pointerdown", (e) => {
    const [x, y, w, h] = at(e);
    let best = null, bd = 18;
    for (const b of eqBands()) { if (b.key === "hp" && !settings.eq.hp.on) continue; const d = Math.hypot(xOf(b.hz, w) - x, yOf(b.db, h) - y); if (d < bd) { bd = d; best = b; } }
    if (!best) return;
    drag = best; eqc.setPointerCapture(e.pointerId); drawEq();
  });
  eqc.addEventListener("pointermove", (e) => {
    if (!drag) return;
    const [x, y, w, h] = at(e), r = drag.range || M.RANGES.hz;
    drag.hz = Math.round(Math.min(r[1], Math.max(r[0], hzOf(x, w))));
    if (!drag.fixedDb) drag.db = Math.round(Math.min(M.RANGES.db[1], Math.max(M.RANGES.db[0], dbOf(y, h))) * 2) / 2;
    fillEqFields(); drawEq(); changed();
  });
  const endDrag = () => { if (drag) { drag = null; drawEq(); } };
  eqc.addEventListener("pointerup", endDrag); eqc.addEventListener("pointercancel", endDrag);
  eqc.addEventListener("wheel", (e) => {
    const [x, y, w, h] = at(e);
    const b = eqBands().find((k) => k.q !== undefined && Math.hypot(xOf(k.hz, w) - x, yOf(k.db, h) - y) < 18);
    if (!b) return;
    e.preventDefault();
    b.q = Math.round(Math.min(M.RANGES.q[1], Math.max(M.RANGES.q[0], b.q * (e.deltaY < 0 ? 1.1 : 1 / 1.1))) * 100) / 100;
    fillEqFields(); drawEq(); changed();
  }, { passive: false });
  // the same bands as numbers, under the curve
  function buildEqFields() {
    const box = $("spEqFields");
    for (const b of eqBands()) {
      const row = document.createElement("div");
      row.className = "speqf";
      row.innerHTML = `<b>${{ hp: "High-pass", low: "Low shelf", high: "High shelf" }[b.key] || "Bell " + b.label}</b>` +
        `<label>Hz <input type="number" data-k="hz" min="${(b.range || M.RANGES.hz)[0]}" max="${(b.range || M.RANGES.hz)[1]}" step="1"></label>` +
        (b.fixedDb ? "" : `<label>dB <input type="number" data-k="db" min="${M.RANGES.db[0]}" max="${M.RANGES.db[1]}" step="0.5"></label>`) +
        (b.q !== undefined ? `<label>Q <input type="number" data-k="q" min="${M.RANGES.q[0]}" max="${M.RANGES.q[1]}" step="0.05"></label>` : "");
      row.dataset.key = b.key;
      box.appendChild(row);
      row.addEventListener("change", (e) => {
        const band = eqBands().find((k) => k.key === b.key), k = e.target.dataset.k, r = k === "hz" ? (band.range || M.RANGES.hz) : M.RANGES[k];
        const v = Math.min(r[1], Math.max(r[0], Number(e.target.value) || 0));
        band[k] = v; e.target.value = String(v);
        drawEq(); changed();
      });
    }
  }
  function fillEqFields() {
    for (const b of eqBands()) {
      const row = document.querySelector(`.speqf[data-key="${b.key}"]`);
      if (!row) continue;
      for (const inp of row.querySelectorAll("input")) inp.value = String(b[inp.dataset.k]);
    }
  }

  // ---- a change: render again from where it plays ---------------------------------------------
  function changed(mark = true) {
    if (mark) { dirty = true; showPresets(); }
    gen++;
    clearTimeout(renderTimer);
    renderTimer = setTimeout(pump, mark ? 200 : 0); // sliders send many changes: start after a short pause
    if (song) overviewDirty = true;
  }

  // ---- the song -------------------------------------------------------------------------------
  function trackOptions() {
    const sel = $("spSong");
    for (const t of D.tracks) {
      const o = document.createElement("option");
      o.value = String(t.id);
      o.textContent = (t.artist ? t.artist + " – " : "") + t.title + (t.original ? "" : "  (stored copy only)");
      sel.appendChild(o);
    }
    sel.addEventListener("change", () => loadSong(Number(sel.value)));
  }
  async function loadSong(id) {
    const t = D.tracks.find((x) => x.id === id);
    if (!t) return;
    stop();
    $("spSong").value = String(id);
    song = null; overviewDirty = true;
    $("spApply").disabled = true;
    $("spSource").textContent = t.original ? "Previewing from the original upload." : "This song’s original wasn’t kept (it was uploaded before round 40), so this previews the stored copy, which was already prepared. Upload it again to keep its original.";
    $("spSource").className = t.original ? "muted2" : "spnote warn";
    say("Loading the song…");
    try {
      const url = t.original ? D.origUrl + "?id=" + t.id : D.mediaBase + t.file;
      const buf = await (await fetch(url, { credentials: "same-origin" })).arrayBuffer();
      const a = await new OfflineAudioContext(2, 1, RATE).decodeAudioData(buf);
      const L = new Float32Array(a.getChannelData(0)), R = new Float32Array(a.numberOfChannels > 1 ? a.getChannelData(1) : a.getChannelData(0));
      const n = L.length, pieces = Math.ceil(n / (PIECE_S * RATE)), mk = (k) => new Float32Array(Math.ceil(n / k));
      song = {
        track: t, n, L, R, PL: new Float32Array(n), PR: new Float32Array(n), pieces, gens: new Int32Array(pieces).fill(-1),
        fineO: peaks(L, R, 0, n, FINE, mk(FINE)), fineP: mk(FINE), coarseO: peaks(L, R, 0, n, COARSE, mk(COARSE)), coarseP: mk(COARSE),
        msO: null, peakO: 0, msP: new Float64Array(Math.floor(n / (BLOCK_S * RATE))).fill(NaN), peakP: new Float32Array(pieces),
        meter: { bands: [0, 1, 2, 3].map(() => mk(METER_S * RATE)), mb: mk(METER_S * RATE), lim: mk(METER_S * RATE) },
      };
      cursor = 0;
      feed();
      $("spApply").disabled = !t.original;
      $("spApply").title = t.original ? "" : "The station needs this song’s original for that";
      $("spLen").textContent = clock(n / RATE);
      history.replaceState(null, "", "?song=" + t.id);
      say("");
      changed(false);
    } catch (ex) {
      say("Couldn’t load that song: " + ex.message, true);
    }
  }
  /** The loudest sample (both channels) in each k samples of [a, b), into out. */
  function peaks(L, R, a, b, k, out) {
    for (let j = Math.floor(a / k); j * k < b; j++) {
      let p = 0;
      for (let i = j * k, e = Math.min(b, (j + 1) * k); i < e; i++) { const v = Math.abs(L[i]) > Math.abs(R[i]) ? Math.abs(L[i]) : Math.abs(R[i]); if (v > p) p = v; }
      out[j] = p;
    }
    return out;
  }

  // ---- rendering, piece by piece --------------------------------------------------------------
  const here = () => (play ? play.pos + (ctx.currentTime - play.at) : cursor);
  function pump() {
    if (!song || inFlight) return;
    const first = Math.min(song.pieces - 1, Math.floor(Math.max(0, here()) / PIECE_S));
    for (let k = 0; k < song.pieces; k++) {
      const p = (first + k) % song.pieces;
      if (song.gens[p] !== gen) {
        const from = p * PIECE_S * RATE, to = Math.min(song.n, (p + 1) * PIECE_S * RATE);
        inFlight = { gen, piece: p };
        worker.postMessage({ render: true, gen, piece: p, from, to, preset: settings });
        progress();
        return;
      }
    }
    progress();
  }
  /** The worker gets the song and measures it (again after a restart). */
  function feed() {
    inFlight = null;
    worker.postMessage({ load: true, channels: [song.L.slice(), song.R.slice()], rate: RATE });
    worker.postMessage({ measure: true });
  }
  // a worker that fails to start or dies (a script that didn't load, out of memory) is started
  // again, a few times, or the pieces would wait for it forever
  function startWorker() {
    worker = new Worker(D.worker);
    worker.onmessage = got;
    worker.onerror = (e) => {
      e.preventDefault && e.preventDefault();
      worker.terminate();
      if (++restarts > 3) { inFlight = null; say("Couldn’t process the song: " + (e.message || "the processing stopped") + ". Reload the page to try again.", true); return; }
      startWorker();
      if (song) { feed(); changed(false); }
    };
  }
  function got(e) {
    const m = e.data;
    if (m.error) { say("Couldn’t process the song: " + m.error, true); inFlight = null; return; }
    if (m.measured && song) { song.msO = m.ms; song.peakO = m.peak; readouts(); return; }
    if (!m.rendered || !song) return;
    // a reply for the song before (sent before a song change) doesn't free the queue
    if (inFlight && inFlight.gen === m.gen && inFlight.piece === m.piece) inFlight = null;
    if (m.gen === gen) {
      const s = song, len = m.L.length;
      s.PL.set(m.L, m.from); s.PR.set(m.R, m.from);
      peaks(s.PL, s.PR, m.from, m.from + len, FINE, s.fineP); peaks(s.PL, s.PR, m.from, m.from + len, COARSE, s.coarseP);
      const st = Math.round(METER_S * RATE), k0 = Math.round(m.from / st);
      m.meter.bands.forEach((b, i) => s.meter.bands[i].set(b.subarray(0, s.meter.bands[i].length - k0), k0));
      s.meter.mb.set(m.meter.mb.subarray(0, s.meter.mb.length - k0), k0); s.meter.lim.set(m.meter.lim.subarray(0, s.meter.lim.length - k0), k0);
      const b0 = Math.round(m.from / (BLOCK_S * RATE));
      for (let i = 0; i < m.ms.length && b0 + i < s.msP.length; i++) s.msP[b0 + i] = m.ms[i];
      s.peakP[m.piece] = m.peak;
      s.gens[m.piece] = m.gen;
      overviewDirty = true;
      readouts();
      if (waiting) resume();
    }
    pump();
  }
  startWorker();
  function progress() {
    if (!song) return;
    const done = song.gens.filter((g) => g === gen).length;
    $("spRender").textContent = done === song.pieces ? "Processed" : `Processing ${Math.round(done / song.pieces * 100)} %`;
  }

  // ---- loudness ---------------------------------------------------------------------------------
  const lufs = (p) => -0.691 + 10 * Math.log10(p);
  /** Gated integrated loudness from 100 ms blocks (400 ms windows, 75 % overlap), as BS.1770. */
  function integrated(ms) {
    if (!ms) return null;
    const w = [];
    for (let i = 0; i + 4 <= ms.length; i++) { const v = (ms[i] + ms[i + 1] + ms[i + 2] + ms[i + 3]) / 4; if (isFinite(v) && v > 0) w.push(v); }
    const abs = w.filter((p) => lufs(p) > -70);
    if (!abs.length) return null;
    const rel = lufs(abs.reduce((a, b) => a + b, 0) / abs.length) - 10, kept = abs.filter((p) => lufs(p) > rel);
    return lufs(kept.reduce((a, b) => a + b, 0) / kept.length);
  }
  /** Loudness range (EBU Tech 3342): the 10th to 95th percentile of the 3 s loudness, gated. */
  function range(ms) {
    if (!ms) return null;
    const st = [];
    for (let i = 0; i + 30 <= ms.length; i += 1) { let s = 0, ok = true; for (let j = i; j < i + 30; j++) { if (!isFinite(ms[j])) { ok = false; break; } s += ms[j]; } if (ok && s > 0) st.push(lufs(s / 30)); }
    const abs = st.filter((v) => v > -70);
    if (abs.length < 10) return null;
    const rel = lufs(abs.reduce((a, v) => a + Math.pow(10, (v + 0.691) / 10), 0) / abs.length) - 20;
    const kept = abs.filter((v) => v > rel).sort((a, b) => a - b);
    return kept[Math.floor(kept.length * 0.95) - 1] - kept[Math.floor(kept.length * 0.1)];
  }
  const shortTerm = (ms, t) => { if (!ms) return null; const e = Math.min(ms.length, Math.floor(t / BLOCK_S)), b = Math.max(0, e - 30); let s = 0; for (let i = b; i < e; i++) { if (!isFinite(ms[i])) return null; s += ms[i]; } return e > b && s > 0 ? lufs(s / (e - b)) : null; };
  /** The gain import's levelling gives a song of this loudness and peak (prep-core.js). */
  const levelGain = (l, peak) => (l === null ? 0 : D.level ? Math.min(TARGET - l, CEIL - 20 * Math.log10(peak || 1e-9), MAXG) : 0);
  let gainO = 0, gainP = 0;
  function readouts() {
    if (!song) return;
    const lo = integrated(song.msO), lp = integrated(song.msP), peakP = Math.max(...song.peakP);
    gainO = levelGain(lo, song.peakO); gainP = levelGain(lp, peakP);
    const all = song.gens.every((g) => g === gen), est = all ? "" : " (so far)";
    const set = (id, v) => { $(id).textContent = v; };
    set("spLufsO", db(lo)); set("spLufsP", db(lp) + est);
    set("spLraO", song.msO ? db(range(song.msO)).replace("+", "") : "–"); set("spLraP", all ? db(range(song.msP)).replace("+", "") : "–");
    set("spPeakO", db(20 * Math.log10(song.peakO || 1e-9))); set("spPeakP", db(20 * Math.log10(peakP || 1e-9)) + est);
    set("spGainO", D.level ? db(gainO) : "off"); set("spGainP", D.level ? db(gainP) + est : "off");
    if (play) levels();
  }

  // ---- playback: half-second buffers of both versions, scheduled ahead ------------------------
  const CHUNK_S = 0.5, AHEAD_S = 1.2;
  function levels(at = ctx.currentTime) {
    const lin = (d) => Math.pow(10, d / 20), vol = Number($("spVol").value);
    gO.gain.setTargetAtTime(hear === "O" ? lin(gainO) * vol : 0, at, 0.004);
    gP.gain.setTargetAtTime(hear === "P" ? lin(gainP) * vol : 0, at, 0.004);
  }
  function ensureCtx() {
    if (ctx) return;
    ctx = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: HANDHELD ? "balanced" : "playback" });
    gO = ctx.createGain(); gP = ctx.createGain(); gO.gain.value = 0; gP.gain.value = 0;
    gO.connect(ctx.destination); gP.connect(ctx.destination);
  }
  const ready = (from, to) => { for (let p = Math.floor(from / PIECE_S); p <= Math.floor(Math.min(to, song.n / RATE - 1e-6) / PIECE_S); p++) if (song.gens[p] !== gen) return false; return true; };
  function start(pos) {
    ensureCtx();
    ctx.resume();
    stopNodes();
    play = { pos, at: ctx.currentTime + 0.08, next: pos };
    waiting = false;
    levels();
    pump();
    schedule();
    $("spPlay").textContent = "Pause";
  }
  function schedule() {
    if (!play) return;
    const end = song.n / RATE;
    while (play.next < here() + AHEAD_S && play.next < end) {
      const a = play.next, b = Math.min(end, a + CHUNK_S);
      if (!ready(a, b)) {
        // not processed yet: stop where the scheduled sound ends and go on when it's ready
        if (play.next - here() < 0.15) { const pos = play.next; stopNodes(); cursor = pos; play = null; waiting = true; $("spRender").textContent = "Processing…"; }
        return;
      }
      const s0 = Math.round(a * RATE), s1 = Math.round(b * RATE), t = play.at + (a - play.pos);
      for (const [g, L, R] of [[gO, song.L, song.R], [gP, song.PL, song.PR]]) {
        const buf = ctx.createBuffer(2, s1 - s0, RATE);
        buf.copyToChannel(L.subarray(s0, s1), 0); buf.copyToChannel(R.subarray(s0, s1), 1);
        const src = ctx.createBufferSource(); src.buffer = buf; src.connect(g); src.start(Math.max(ctx.currentTime, t));
        nodes.push(src);
        src.onended = () => { nodes = nodes.filter((x) => x !== src); };
      }
      play.next = b;
    }
    if (play.next >= end && here() >= end) { cursor = 0; stop(); }
  }
  function resume() { if (waiting && ready(cursor, cursor + CHUNK_S)) start(cursor); }
  function stopNodes() { for (const n of nodes) { try { n.stop(); } catch (_) { /* ended */ } } nodes = []; }
  function stop() {
    if (play) cursor = Math.min(here(), song ? song.n / RATE : 0);
    stopNodes(); play = null; waiting = false;
    $("spPlay").textContent = "Play";
  }
  $("spPlay").addEventListener("click", () => { if (!song) return; if (play || waiting) { waiting = false; stop(); } else start(cursor); });
  const setHear = (w) => {
    hear = w;
    $("spHearO").setAttribute("aria-pressed", String(w === "O")); $("spHearP").setAttribute("aria-pressed", String(w === "P"));
    if (ctx) levels();
  };
  $("spHearO").addEventListener("click", () => setHear("O"));
  $("spHearP").addEventListener("click", () => setHear("P"));
  $("spVol").addEventListener("input", () => { if (ctx) levels(); });
  document.addEventListener("keydown", (e) => {
    if (e.target.matches && e.target.matches("input, select, textarea")) return;
    if (e.code === "Space") { e.preventDefault(); $("spPlay").click(); }
    if (e.key === "a" || e.key === "A") setHear(hear === "O" ? "P" : "O");
  });
  function seek(pos) {
    pos = Math.max(0, Math.min(song.n / RATE - 0.05, pos));
    if (play || waiting) start(pos); else { cursor = pos; pump(); }
  }
  setInterval(() => { schedule(); if (!inFlight) pump(); }, 100);

  // ---- drawing --------------------------------------------------------------------------------
  const ov = $("spOverview"), strip = $("spStrip"), meters = $("spMeters");
  let overviewDirty = true;
  const clock = (s) => Math.floor(s / 60) + ":" + String(Math.floor(s % 60)).padStart(2, "0");
  function size(c) {
    const dpr = devicePixelRatio || 1, w = c.clientWidth, h = c.clientHeight;
    if (c.width !== Math.round(w * dpr) || c.height !== Math.round(h * dpr)) { c.width = Math.round(w * dpr); c.height = Math.round(h * dpr); }
    const g = c.getContext("2d"); g.setTransform(dpr, 0, 0, dpr, 0, 0); return [g, w, h];
  }
  /** Mirror-image wave of peak values, x from 0 to w over [i0, i1) of arr. */
  function wave(g, arr, i0, i1, w, h, fill, gainDb = 0) {
    const k = Math.pow(10, gainDb / 20), mid = h / 2, per = (i1 - i0) / w;
    g.fillStyle = fill;
    g.beginPath();
    for (let x = 0; x < w; x++) {
      let p = 0;
      for (let i = Math.floor(i0 + x * per), e = Math.max(i + 1, Math.floor(i0 + (x + 1) * per)); i < e; i++) if (i >= 0 && i < arr.length && arr[i] > p) p = arr[i];
      const v = Math.min(1, p * k) * (mid - 2);
      g.rect(x, mid - v, 1, v * 2 || 0.5);
    }
    g.fill();
  }
  function drawOverview() {
    const [g, w, h] = size(ov);
    g.clearRect(0, 0, w, h);
    if (!song) return;
    const n = song.coarseO.length;
    wave(g, song.coarseO, 0, n, w, h, tok("--faint"), hear === "O" || !play ? 0 : 0);
    // processed on top, only where it is up to date; the rest hatched
    g.save();
    for (let p = 0; p < song.pieces; p++) {
      const x0 = Math.floor(p * PIECE_S * RATE / COARSE / n * w), x1 = Math.ceil(Math.min(song.n, (p + 1) * PIECE_S * RATE) / COARSE / n * w);
      if (song.gens[p] === gen) { g.save(); g.beginPath(); g.rect(x0, 0, x1 - x0, h); g.clip(); wave(g, song.coarseP, 0, n, w, h, tok("--amber"), gainP - gainO); g.restore(); }
      else { g.fillStyle = tok("--hover-tint"); g.fillRect(x0, 0, x1 - x0, h); }
    }
    g.restore();
  }
  function frame() {
    requestAnimationFrame(frame);
    if (document.hidden) return;
    if (overviewDirty) { drawOverview(); overviewDirty = false; }
    const t = song ? here() : 0;
    // playhead on the overview (a thin line drawn over the cached waves: redraw the waves first)
    if (song) {
      drawOverview();
      const [g, w, h] = size(ov), x = Math.round(t / (song.n / RATE) * w) + 0.5;
      g.strokeStyle = tok("--ink"); g.lineWidth = 1; g.beginPath(); g.moveTo(x, 0); g.lineTo(x, h); g.stroke();
      $("spAt").textContent = clock(t);
    }
    drawStrip(t);
    drawMeters(t);
  }
  function drawStrip(t) {
    const [g, w, h] = size(strip), span = Number($("spZoom").value);
    g.clearRect(0, 0, w, h);
    g.strokeStyle = tok("--wave-grid"); g.lineWidth = 1;
    for (const d of [-6, -12, -24]) { const v = Math.pow(10, d / 20) * (h / 2 - 2); for (const y of [h / 2 - v, h / 2 + v]) { g.beginPath(); g.moveTo(0, Math.round(y) + 0.5); g.lineTo(w, Math.round(y) + 0.5); g.stroke(); } }
    if (!song) return;
    const per = RATE / FINE, i0 = (t - span / 2) * per, i1 = (t + span / 2) * per;
    // both at the level import gives them, so what you see is what you hear
    wave(g, song.fineO, i0, i1, w, h, tok("--faint"), gainO);
    g.globalAlpha = 0.85;
    wave(g, song.fineP, i0, i1, w, h, tok("--amber"), gainP);
    g.globalAlpha = 1;
    g.strokeStyle = tok("--ink"); g.beginPath(); g.moveTo(Math.round(w / 2) + 0.5, 0); g.lineTo(Math.round(w / 2) + 0.5, h); g.stroke();
    g.fillStyle = tok("--faint"); g.font = "11px " + tok("--f-mono");
    for (const d of [-6, -12, -24]) g.fillText(d + " dB", 4, h / 2 - Math.pow(10, d / 20) * (h / 2 - 2) - 3);
    const so = shortTerm(song.msO, t), sp = shortTerm(song.msP, t);
    $("spNowO").textContent = so === null ? "–" : db(so + gainO);
    $("spNowP").textContent = sp === null ? "–" : db(sp + gainP);
  }
  function drawMeters(t) {
    const [g, w, h] = size(meters);
    g.clearRect(0, 0, w, h);
    const names = [...BANDS, "MB limiter", "Limiter"], cols = names.length, cw = w / cols, top = 6, bot = h - 34, MAXDB = 18;
    g.font = "11px " + tok("--f-ui"); g.textAlign = "center";
    const k = Math.floor(t / METER_S), look = 8; // the most in the last 80 ms
    for (let c = 0; c < cols; c++) {
      const arr = !song ? null : c < 4 ? song.meter.bands[c] : c === 4 ? song.meter.mb : song.meter.lim;
      let v = 0;
      if (arr && song.gens[Math.min(song.pieces - 1, Math.floor(t / PIECE_S))] === gen) for (let i = Math.max(0, k - look); i <= k && i < arr.length; i++) v = Math.max(v, arr[i]);
      const x = c * cw + cw * 0.3, bw = cw * 0.4, full = bot - top;
      g.fillStyle = tok("--well"); g.fillRect(x, top, bw, full);
      const hh = Math.min(1, v / MAXDB) * full;
      g.fillStyle = v > 9 ? tok("--onair") : v > 4 ? tok("--warn") : tok("--amber");
      g.fillRect(x, top, bw, hh); // reduction grows downwards from the top
      g.fillStyle = tok("--ink"); g.fillText(v >= 0.05 ? "−" + v.toFixed(1) : "0", c * cw + cw / 2, bot + 14);
      g.fillStyle = tok("--faint"); g.fillText(names[c], c * cw + cw / 2, bot + 28);
    }
    g.textAlign = "start";
  }
  const seekFrom = (c, e, map) => { if (!song) return; const r = c.getBoundingClientRect(); seek(map((e.clientX - r.left) / r.width)); };
  ov.addEventListener("click", (e) => seekFrom(ov, e, (f) => f * song.n / RATE));
  strip.addEventListener("click", (e) => seekFrom(strip, e, (f) => here() + (f - 0.5) * Number($("spZoom").value)));
  window.addEventListener("resize", () => { overviewDirty = true; drawEq(); });
  window.addEventListener("sw:skin", () => { overviewDirty = true; drawEq(); });

  // ---- apply to this song ---------------------------------------------------------------------
  $("spApply").addEventListener("click", async () => {
    if (!song || !song.track.original || !window.SwPrep) return;
    const cur = presets.find((x) => x.id === presetId), name = (cur ? cur.name : "Audition chain") + (dirty ? " (changed)" : "");
    if (!confirm(`Prepare “${song.track.title}” again from its original with “${name}” and put it in place of the station’s copy?`)) return;
    const btn = $("spApply");
    btn.disabled = true;
    try {
      say("Preparing the whole song…");
      const blob = await (await fetch(D.origUrl + "?id=" + song.track.id, { credentials: "same-origin" })).blob();
      const p = await window.SwPrep.prepare(blob, (stage) => say("Preparing: " + stage), { master: saved(), level: D.level, target: TARGET });
      if (!p.prepared) throw new Error(p.note || "song prep didn’t run");
      say("Uploading…");
      const j = await post({ action: "apply", id: String(song.track.id), prep_lufs: p.info.levelled ? String(p.info.lufs) : "", prep_outro: JSON.stringify(p.info.outro || []),
        prep_mastered: "1", prep_preset: name, prep_source_lufs: String(p.info.lufs_before), prep_gain: String(p.info.gain_db),
        prep_target: p.info.target === null ? "" : String(p.info.target), prep_settings: JSON.stringify(saved()) }, p.file);
      if (!j.ok) throw new Error(j.error || "the server refused it");
      say(`“${song.track.title}” is now prepared with “${name}”. Its next plays use the new copy.`);
    } catch (ex) {
      say("Not applied: " + ex.message, true);
    } finally {
      btn.disabled = false;
    }
  });

  // ---- start ----------------------------------------------------------------------------------
  buildControls();
  buildEqFields();
  trackOptions();
  loadPreset(presetId);
  requestAnimationFrame(frame);
  const first = D.tracks.find((t) => t.id === D.song) || D.tracks.find((t) => t.original) || D.tracks[0];
  if (first) loadSong(first.id); else say("The library has no songs yet. Add some on the Music library page.");
  // for tests
  window.SwSongPrep = {
    state: () => ({ song: song && { id: song.track.id, n: song.n, pieces: song.pieces, done: song.gens.filter((g) => g === gen).length, original: song.track.original },
      gen, playing: !!play, waiting, hear, at: song ? here() : 0, presetId, defaultId, dirty, presets: presets.map((p) => ({ id: p.id, name: p.name })),
      settings: saved(), gainO, gainP, lufsO: song && integrated(song.msO), lufsP: song && integrated(song.msP),
      reduction: song ? { bands: song.meter.bands.map((a) => Math.max(...a)), lim: Math.max(...song.meter.lim) } : null }),
    processed: (from, to) => song && [song.PL.slice(from, to), song.PR.slice(from, to)],
    original: (from, to) => song && [song.L.slice(from, to), song.R.slice(from, to)],
    seek,
  };
})();
