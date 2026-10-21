// The episode editor (manage/podcast/<id>): the episode's takes laid end to end on a timeline,
// two time-synced tracks (Music and Mic, from the studio's own recording) or one (as aired), each
// split into clips at every silence. Zoom with − / + or Ctrl + wheel; click a clip and press Delete
// to cut it (Undo brings it back). Mix down flattens the tracks in this browser: each track is
// decoded a chunk at a time, the cut clips are muted on their own track, empty stretches over 2 s
// shrink to 1 s, and the result is encoded to MP3 (studio-encoder.js) and uploaded in parts.
// A take with `skip` is the rest of a show as aired after the studio's recording stopped early: its
// clips and waveform are shown and read from `skip` seconds in.
// window.SwEditor is for the browser test.
(() => {
  "use strict";
  const root = document.getElementById("editor");
  if (!root) return;
  const P = window.SwPodcast;
  const $ = (id) => document.getElementById(id);
  const BASE = new URL(root.dataset.base, location.href).href, CSRF = root.dataset.csrf, ID = Number(root.dataset.id), KBPS = Number(root.dataset.kbps) || 128;
  const RATE = 44100, RULER = 24, PART_BYTES = 1 << 20;
  const NAMES = { music: "Music", mic: "Mic", aired: "As aired" };

  const fmt = (s) => { s = Math.max(0, Math.round(s || 0)); return s >= 3600 ? `${Math.floor(s / 3600)}:${String(Math.floor(s % 3600 / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`; };
  async function api(action, { body, query = "", raw = false } = {}) {
    const opts = body === undefined ? { cache: "no-store" } : { method: "POST", cache: "no-store", body: raw ? body : JSON.stringify(body), headers: { "X-CSRF": CSRF, "Content-Type": raw ? "audio/mpeg" : "application/json" } };
    const res = await fetch(BASE + "podcast/api/" + action + query, opts);
    const json = await res.json().catch(() => ({}));
    json.httpStatus = res.status;
    return json;
  }
  const chunkBytes = async (take, track, n) => {
    for (let i = 0; ; i++) {
      const res = await fetch(`${BASE}podcast/api/chunk?id=${ID}&take=${take}&track=${track}&n=${n}`, { cache: "no-store" });
      if (res.ok) return new Uint8Array(await res.arrayBuffer());
      if (i >= 2 || res.status < 500) throw new Error(`piece ${n} of the ${NAMES[track]} track is missing (HTTP ${res.status})`);
    }
  };
  const decoder = () => { const c = new OfflineAudioContext(2, RATE, RATE); return (u8) => c.decodeAudioData(u8.slice().buffer); };

  // ---- State -------------------------------------------------------------------------------
  let ep = null, total = 0, rows = [], pps = 1, selected = null, busy = false;
  let deleted = new Set(); const undo = [];
  let mixedFor = null; // the cuts the current mixdown was made with (sorted, joined)
  const cv = $("edCv"), scroll = $("edScroll"), space = $("edSpace");
  const key = () => [...deleted].sort().join(",");
  const msg = (t) => { $("edMsg").textContent = t; };

  // clips of a take on a track, with their ids, in episode seconds
  function clipsOf(take, track) {
    const a = take.analysis && take.analysis.tracks[track];
    return a ? a.clips.map((c, i) => ({ id: `${take.id}:${track}:${i}`, take, track, from: take.offset + c[0], to: take.offset + c[1] })).filter((c) => c.to > c.from) : [];
  }
  /** A take's analysis as the editor uses it: from `skip` seconds in (clips before it emptied, keeping their indexes). */
  function viewOf(take, full) {
    take.full = full;
    if (!full || !take.skip) { take.analysis = full; return; }
    const s = take.skip, tracks = {};
    for (const [k, a] of Object.entries(full.tracks)) {
      const peaks = P.fromBase64(a.peaks).subarray(Math.floor(s * full.rate));
      tracks[k] = { peaks: a.peaks, _peaks: peaks, clips: a.clips.map(([x, y]) => (y <= s ? [0, 0] : [Math.max(0, x - s), y - s])) };
    }
    take.analysis = { rate: full.rate, tracks };
  }
  function layout() {
    const device = ep.takes.some((t) => t.kind === "device");
    rows = device ? ["music", "mic"] : ["aired"];
  }
  // which rows a take's track is drawn on: an as-aired take in a two-track episode covers both
  const rowsFor = (track) => (track === "aired" && rows.length === 2 ? [0, 1] : [rows.indexOf(track)]);

  // ---- Drawing --------------------------------------------------------------------------------
  function fit() { pps = Math.max(0.02, (scroll.clientWidth - 2) / Math.max(1, total)); size(); }
  function zoom(f, atX = scroll.clientWidth / 2) {
    const t = (scroll.scrollLeft + atX) / pps;
    pps = Math.min(400, Math.max((scroll.clientWidth - 2) / Math.max(1, total), pps * f));
    size();
    scroll.scrollLeft = t * pps - atX;
    draw();
  }
  function size() { space.style.width = Math.ceil(total * pps) + "px"; draw(); }
  function draw() {
    if (!ep) return;
    const w = cv.clientWidth, h = cv.clientHeight, dpr = devicePixelRatio || 1;
    if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) { cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); }
    const g = cv.getContext("2d");
    g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h);
    const x0 = scroll.scrollLeft, t0 = x0 / pps, t1 = (x0 + w) / pps, rh = (h - RULER) / rows.length;
    const X = (t) => t * pps - x0;
    // ruler
    g.fillStyle = "#181818"; g.fillRect(0, 0, w, RULER);
    const step = [1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600].find((s) => s * pps >= 70) || 7200;
    g.font = "11px Figtree, system-ui, sans-serif"; g.textBaseline = "middle";
    for (let s = Math.floor(t0 / step) * step; s <= t1; s += step) {
      const x = Math.round(X(s)) + 0.5;
      g.fillStyle = "#3a3a3a"; g.fillRect(x, 0, 1, h);
      g.fillStyle = "#9a9a9a"; g.fillText(fmt(s), x + 4, RULER / 2);
    }
    // takes: their edges, and the row labels
    for (const take of ep.takes) {
      const x = X(take.offset);
      if (take.offset > 0 && x > -2 && x < w + 2) { g.fillStyle = "#7c7c7c"; g.fillRect(Math.round(x), RULER, 2, h - RULER); }
    }
    rows.forEach((r, i) => { g.fillStyle = i % 2 ? "#141414" : "#171717"; g.globalCompositeOperation = "destination-over"; g.fillRect(0, RULER + i * rh, w, rh); g.globalCompositeOperation = "source-over"; });
    // clips with their waveform
    for (const take of ep.takes) {
      if (take.offset > t1 || take.offset + take.duration < t0) continue;
      for (const track of Object.keys(take.tracks)) {
        const a = take.analysis && take.analysis.tracks[track];
        if (!a) continue;
        const peaks = a._peaks || (a._peaks = P.fromBase64(a.peaks)), rate = take.analysis.rate;
        for (const c of clipsOf(take, track)) {
          if (c.to < t0 || c.from > t1) continue;
          const gone = deleted.has(c.id), sel = selected === c.id;
          for (const ri of rowsFor(track)) {
            const y = RULER + ri * rh + 4, ch = rh - 8, xa = X(c.from), xb = X(c.to), cw = Math.max(2, xb - xa);
            g.fillStyle = gone ? "rgba(255,255,255,.03)" : track === "mic" ? "#5a2a33" : "#1f5133";
            roundRect(g, xa, y, cw, ch, 4); g.fill();
            if (!gone) {
              g.fillStyle = track === "mic" ? "#ff9aa6" : "#1ed760";
              const pa = Math.max(0, Math.floor((c.from - take.offset) * rate)), pb = Math.min(peaks.length, Math.ceil((c.to - take.offset) * rate));
              const stepPx = Math.max(1, pps / rate);
              for (let p = pa; p < pb; p++) {
                const x = X(take.offset + p / rate); if (x < xa - 1 || x > w) continue; if (x < -stepPx) continue;
                const v = peaks[p] / 255, bh = Math.max(1, v * (ch / 2 - 3));
                g.fillRect(x, y + ch / 2 - bh, Math.max(1, stepPx - (stepPx > 3 ? 1 : 0)), bh * 2);
              }
            } else {
              g.strokeStyle = "rgba(255,255,255,.18)"; g.setLineDash([4, 4]); g.lineWidth = 1; roundRect(g, xa + 0.5, y + 0.5, cw - 1, ch - 1, 4); g.stroke(); g.setLineDash([]);
            }
            if (sel) { g.strokeStyle = "#ffffff"; g.lineWidth = 2; roundRect(g, xa + 1, y + 1, cw - 2, ch - 2, 4); g.stroke(); }
          }
        }
      }
    }
    rows.forEach((r, i) => {
      g.fillStyle = "rgba(16,16,16,.8)"; g.fillRect(6, RULER + i * rh + 6, 70, 18);
      g.fillStyle = "#b3b3b3"; g.font = "600 11px Figtree, system-ui, sans-serif"; g.fillText(NAMES[r], 12, RULER + i * rh + 15);
    });
  }
  function roundRect(g, x, y, w, h, r) { r = Math.min(r, w / 2, h / 2); g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r); g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath(); }

  // ---- Selecting and cutting --------------------------------------------------------------------
  function clipAt(px, py) {
    const rh = (cv.clientHeight - RULER) / rows.length, ri = Math.floor((py - RULER) / rh);
    if (py < RULER || ri < 0 || ri >= rows.length) return null;
    const t = (scroll.scrollLeft + px) / pps, slack = 3 / pps;
    for (const take of ep.takes) {
      if (t < take.offset - slack || t > take.offset + take.duration + slack) continue;
      for (const track of Object.keys(take.tracks)) {
        if (!rowsFor(track).includes(ri)) continue;
        const c = clipsOf(take, track).find((c) => t >= c.from - slack && t <= c.to + slack);
        if (c) return c.id;
      }
    }
    return null;
  }
  function select(id) { selected = id; controls(); draw(); }
  function toggle(id = selected) {
    if (!id || busy) return;
    const was = deleted.has(id);
    was ? deleted.delete(id) : deleted.add(id);
    undo.push([id, was]);
    saveEdits(); controls(); draw();
  }
  function undoLast() {
    const u = undo.pop(); if (!u || busy) return;
    const [id, was] = u; was ? deleted.add(id) : deleted.delete(id);
    selected = id; saveEdits(); controls(); draw();
  }
  let saveTimer = null, saving = null;
  function saveEdits() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => { saving = api("edits", { body: { id: ID, deleted: [...deleted] } }).then((r) => { if (r.error) msg("Your cuts weren’t saved: " + r.error); saving = null; }); }, 400);
  }
  const flushEdits = async () => { if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; await api("edits", { body: { id: ID, deleted: [...deleted] } }); } if (saving) await saving; };

  function controls() {
    const len = P.planLength(ep ? ep.takes : [], deleted);
    $("edInfo").textContent = ep ? `${deleted.size} clip${deleted.size === 1 ? "" : "s"} cut · episode ${fmt(len)} of ${fmt(total)}` : "";
    $("edDelete").disabled = !selected || busy;
    $("edDelete").textContent = selected && deleted.has(selected) ? "Restore clip" : "Delete clip";
    $("edUndo").disabled = !undo.length || busy;
    const ready = ep && ep.takes.length && ep.takes.every((t) => t.analysis);
    $("edMix").disabled = !ready || busy || ep.raw_deleted || len <= 0;
    const stale = ep && ep.audio && mixedFor !== null && mixedFor !== key();
    const mixed = ep && ep.audio && !stale;
    $("edPublish").disabled = !mixed || busy;
    $("edPublish").textContent = ep && (ep.state === "published" || ep.state === "hidden") ? "Save and update" : "Publish";
    $("edMixNote").textContent = ep && ep.raw_deleted ? "The raw tracks were deleted 30 days after publishing, so this episode can’t be mixed again."
      : stale ? "You changed the cuts since the last mixdown. Mix down again to publish them."
      : ep && ep.audio ? `Mixed down: ${fmt(ep.audio.seconds)}, ${(ep.audio.bytes / 1048576).toFixed(1)} MB. Listen below before you publish.`
      : `Mixdown flattens the tracks into one MP3, without the clips you cut. ${estimate(len)}`;
    const hide = $("edHide");
    hide.hidden = !ep || !(ep.state === "published" || ep.state === "hidden");
    hide.textContent = ep && ep.state === "hidden" ? "Show again" : "Hide";
    $("edPubInfo").textContent = !ep ? "" : ep.state === "published" ? "Published: on the podcast page and in the feed." : ep.state === "hidden" ? "Hidden: not on the podcast page or in the feed." : "Draft: only staff can see it.";
  }
  /** Mixing runs at about 10–20× real time in this browser (more on a fast PC, less on a phone). */
  function estimate(seconds) {
    const lo = seconds / 20 / 60, hi = seconds / 10 / 60;
    return hi < 1 ? "It takes under a minute; keep this page open." : `It takes about ${Math.max(1, Math.round(lo))}–${Math.max(2, Math.round(hi))} minutes; keep this page open.`;
  }
  function showAudio() {
    const a = $("edAudio");
    if (!ep.audio) { a.hidden = true; return; }
    a.hidden = false; a.src = `${BASE}podcast/api/audio?id=${ID}&v=${encodeURIComponent(ep.audio.mixed_at || "")}`;
  }

  // ---- An as-aired take with no clips yet: find them here, once -----------------------------------
  // (from `skip` on only, when it just fills in after the studio's recording; what's before stays empty)
  async function analyse(take) {
    const chunks = take.tracks.aired, an = new P.Analyzer(RATE), dec = decoder(), skip = take.skip || 0;
    const reader = new P.ChunkReader(chunks, (n) => chunkBytes(take.id, "aired", n), dec, 2, P.CODEC_DELAY + Math.round(skip * RATE));
    const block = RATE * 30;
    for (let pos = 0; pos < reader.length; pos += block) {
      msg(`Finding the clips in the show as it aired… ${Math.round(pos / reader.length * 100)}%`);
      const [l, r] = await reader.read(pos, Math.min(block, reader.length - pos));
      const loud = new Float32Array(l.length); for (let i = 0; i < l.length; i++) loud[i] = Math.max(Math.abs(l[i]), Math.abs(r[i]));
      an.push(loud);
    }
    const res = an.finish(), lead = Math.floor(skip * P.PEAK_RATE), peaks = new Uint8Array(lead + res.peaks.length);
    peaks.set(res.peaks, lead);
    const full = { rate: P.PEAK_RATE, tracks: { aired: { peaks: P.toBase64(peaks), clips: res.clips.map(([a, b]) => [Math.round((a + skip) * 1000) / 1000, Math.round((b + skip) * 1000) / 1000]) } } };
    const s = await api("analysis", { body: { id: ID, take: take.id, analysis: full } });
    if (s.error) throw new Error(s.error);
    viewOf(take, full);
  }

  // ---- Mixdown ----------------------------------------------------------------------------------
  async function mixdown() {
    if (busy) return;
    busy = true; controls();
    const bar = $("edProgBar"); $("edProg").hidden = false; bar.style.width = "0%";
    const info = (t) => { $("edMixInfo").textContent = t; };
    const enc = new Worker(root.dataset.encoder);
    // listen at once: the worker says "ready" as soon as it loads, and a message nobody hears is lost
    const ready = new Promise((ok, fail) => { enc.onmessage = (e) => (e.data.ready ? ok() : null); enc.onerror = () => fail(new Error("the encoder didn’t load")); });
    try {
      await flushEdits();
      await ready;
      const plans = ep.takes.map((t) => ({ take: t, ...P.planTake(t, deleted) })), t0 = performance.now();
      const want = plans.reduce((n, p) => n + p.segments.reduce((m, s) => m + Math.round((s[1] - s[0]) * RATE), 0), 0);
      let made = 0, part = 0, pending = [], pendingBytes = 0, uploads = Promise.resolve(), flushed = null;
      const sendPart = () => {
        if (!pendingBytes) return;
        const body = new Uint8Array(pendingBytes); let o = 0; for (const b of pending) { body.set(b, o); o += b.length; }
        pending = []; pendingBytes = 0;
        const n = part++;
        uploads = uploads.then(async () => {
          for (let i = 0; ; i++) {
            const r = await api("mix-chunk", { body, raw: true, query: `?id=${ID}&n=${n}` });
            if (r.httpStatus === 200) return;
            if (i >= 3) throw new Error(r.error || "HTTP " + r.httpStatus);
            await new Promise((ok) => setTimeout(ok, 1000 * (i + 1)));
          }
        });
      };
      enc.onmessage = (e) => {
        const m = e.data;
        if (m.error) return;
        for (const f of m.frames || []) { pending.push(f.bytes); pendingBytes += f.bytes.length; }
        if (pendingBytes >= PART_BYTES) sendPart();
        if (m.final) { sendPart(); if (flushed) flushed(); }
      };
      enc.postMessage({ init: { gen: 1, rate: RATE, kbps: KBPS, channels: 2 } });
      const BLOCK = RATE * 10, dec = decoder();
      for (const { take, segments, muted } of plans) {
        const tracks = Object.keys(take.tracks);
        const skip = P.CODEC_DELAY + Math.round((take.skip || 0) * RATE);
        const readers = Object.fromEntries(tracks.map((tr) => [tr, new P.ChunkReader(take.tracks[tr], (n) => chunkBytes(take.id, tr, n), dec, tr === "mic" ? 1 : 2, skip)]));
        for (const [a, b] of segments) {
          const from = Math.round(a * RATE), to = Math.round(b * RATE);
          for (let pos = from; pos < to; pos += BLOCK) {
            const n = Math.min(BLOCK, to - pos), L = new Float32Array(n), R = new Float32Array(n);
            for (const tr of tracks) {
              const ch = await readers[tr].read(pos, n), m = muted[tr];
              const near = m.filter(([x, y]) => y + 0.02 >= pos / RATE && x - 0.02 <= (pos + n) / RATE);
              for (let i = 0; i < n; i++) {
                const gain = near.length ? P.maskAt(near, (pos + i) / RATE) : 1;
                if (gain === 0) continue;
                L[i] += ch[0][i] * gain; R[i] += (ch[1] || ch[0])[i] * gain;
              }
            }
            for (let i = 0; i < n; i++) { L[i] = Math.max(-1, Math.min(1, L[i])); R[i] = Math.max(-1, Math.min(1, R[i])); }
            enc.postMessage({ pcm: { gen: 1, l: L, r: R } }, [L.buffer, R.buffer]);
            made += n;
            bar.style.width = (made / want * 90).toFixed(1) + "%";
            const took = (performance.now() - t0) / 1000, left = made > 0 && took > 3 ? took * (want - made) / made : null;
            info(`Mixing ${fmt(made / RATE)} of ${fmt(want / RATE)}…` + (left === null ? "" : left < 60 ? " under a minute left" : ` about ${Math.round(left / 60)} min left`));
          }
        }
      }
      await new Promise((ok) => { flushed = ok; enc.postMessage({ flush: { gen: 1 } }); });
      info("Uploading the mixdown…");
      await uploads;
      const r = await api("mix-done", { body: { id: ID, parts: part, seconds: made / RATE, deleted: [...deleted] } });
      if (r.error) throw new Error(r.error);
      bar.style.width = "100%";
      await reload();
      info(`Done: ${fmt(made / RATE)}.`);
    } catch (e) {
      info("The mixdown stopped: " + e.message + " Try again.");
    } finally {
      enc.terminate(); busy = false; controls(); setTimeout(() => { $("edProg").hidden = true; }, 1500);
    }
  }

  async function publish(go) {
    if (busy) return;
    const title = $("edName").value.trim();
    if (!title) { $("edPubInfo").textContent = "Give the episode a title first."; $("edName").focus(); return; }
    busy = true; controls();
    const r = await api("publish", { body: { id: ID, title, description: $("edDesc").value, publish: go } });
    busy = false;
    if (r.error) { controls(); $("edPubInfo").textContent = r.error; return; }
    $("edTitle").textContent = title;
    await reload();
    $("edPubInfo").textContent = go ? "Published: on the podcast page and in the feed." : "Saved.";
  }
  async function setHidden() {
    if (busy) return;
    const r = await api("hide", { body: { id: ID, hidden: ep.state !== "hidden" } });
    if (r.error) { $("edPubInfo").textContent = r.error; return; }
    await reload();
  }

  // ---- Loading ----------------------------------------------------------------------------------
  async function reload() {
    const r = await api("episode", { query: `?id=${ID}` });
    if (r.error) throw new Error(r.error);
    ep = r;
    ep.takes.forEach((t) => viewOf(t, t.analysis));
    total = ep.takes.reduce((n, t) => n + t.duration, 0);
    mixedFor = ep.mixed_deleted ? [...ep.mixed_deleted].sort().join(",") : (ep.audio ? key() : null);
    layout(); controls(); showAudio(); draw();
  }
  async function start() {
    try {
      await reload();
      deleted = new Set(ep.deleted);
      mixedFor = ep.mixed_deleted ? [...ep.mixed_deleted].sort().join(",") : (ep.audio ? key() : null);
      if (!ep.takes.length) { msg("This episode has no recording."); controls(); return; }
      for (const t of ep.takes) if (!t.analysis && t.kind === "aired") await analyse(t);
      const tail = ep.takes.filter((t) => t.skip > 0).reduce((n, t) => n + t.duration, 0);
      fit();
      const clips = ep.takes.reduce((n, t) => n + Object.keys(t.tracks).reduce((m, tr) => m + clipsOf(t, tr).length, 0), 0);
      msg(`${ep.takes.length} part${ep.takes.length === 1 ? "" : "s"}, ${fmt(total)}, ${clips} clips. ` + (ep.takes.some((t) => t.kind === "device") ? "Recorded in the studio: music and mic on their own tracks." : "As it aired: music and voice on one track.")
        + (tail ? ` The studio’s recording stopped early (the page reloaded), so the last ${Math.round(tail)} s of that part are as aired.` : ""));
      controls();
    } catch (e) { msg("The episode couldn’t load: " + e.message); }
  }

  // ---- Wiring -----------------------------------------------------------------------------------
  $("edZoomIn").onclick = () => zoom(2);
  $("edZoomOut").onclick = () => zoom(0.5);
  $("edFit").onclick = () => { fit(); scroll.scrollLeft = 0; draw(); };
  $("edDelete").onclick = () => toggle();
  $("edUndo").onclick = undoLast;
  $("edMix").onclick = mixdown;
  $("edPublish").onclick = () => publish(true);
  $("edSave").onclick = () => publish(false);
  $("edHide").onclick = setHidden;
  scroll.addEventListener("scroll", draw, { passive: true });
  scroll.addEventListener("wheel", (e) => { if (!e.ctrlKey || !ep) return; e.preventDefault(); const r = scroll.getBoundingClientRect(); zoom(e.deltaY < 0 ? 1.25 : 0.8, e.clientX - r.left); }, { passive: false });
  cv.addEventListener("click", (e) => { if (!ep) return; const r = cv.getBoundingClientRect(); select(clipAt(e.clientX - r.left, e.clientY - r.top)); scroll.focus({ preventScroll: true }); });
  document.addEventListener("keydown", (e) => {
    if (e.target.closest("input, textarea, select")) return;
    if ((e.key === "Delete" || e.key === "Backspace") && selected) { e.preventDefault(); toggle(); }
    else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "z") { e.preventDefault(); undoLast(); }
    else if (e.key === "Escape") select(null);
  });
  window.addEventListener("resize", () => { if (ep) { const keep = pps * total <= scroll.clientWidth + 4; if (keep) fit(); else draw(); } });
  window.addEventListener("beforeunload", (e) => { if (busy) { e.preventDefault(); e.returnValue = ""; } }); // a mixdown under way
  window.addEventListener("pagehide", () => { // cuts made in the last moment
    if (!saveTimer) return;
    clearTimeout(saveTimer); saveTimer = null;
    fetch(BASE + "podcast/api/edits", { method: "POST", keepalive: true, body: JSON.stringify({ id: ID, deleted: [...deleted] }), headers: { "X-CSRF": CSRF, "Content-Type": "application/json" } });
  });

  window.SwEditor = {
    state: () => ({ loaded: !!(ep && ep.takes.every((t) => t.analysis)), state: ep && ep.state, takes: ep ? ep.takes.map((t) => ({ id: t.id, kind: t.kind, duration: t.duration, offset: t.offset })) : [],
      clips: ep ? ep.takes.flatMap((t) => Object.keys(t.tracks).flatMap((tr) => clipsOf(t, tr).map((c) => ({ id: c.id, from: c.from, to: c.to })))) : [],
      deleted: [...deleted], selected, pps, total, busy, length: ep ? P.planLength(ep.takes, deleted) : 0, audio: ep && ep.audio,
      mixInfo: $("edMixInfo").textContent, stale: !!(ep && ep.audio && mixedFor !== key()) }),
    // where a clip is drawn now, in page pixels (for clicking it), or null when it's off screen
    clipBox(id) {
      const c = ep && ep.takes.flatMap((t) => Object.keys(t.tracks).flatMap((tr) => clipsOf(t, tr))).find((c) => c.id === id);
      if (!c) return null;
      const r = cv.getBoundingClientRect(), rh = (cv.clientHeight - RULER) / rows.length, ri = rowsFor(c.track)[0];
      const xa = Math.max(0, c.from * pps - scroll.scrollLeft), xb = Math.min(cv.clientWidth, c.to * pps - scroll.scrollLeft);
      if (xb <= xa) return null;
      return { x: r.left + (xa + xb) / 2, y: r.top + RULER + ri * rh + rh / 2 };
    },
    scrollTo(t) { scroll.scrollLeft = t * pps - 20; draw(); },
    mixdown, flushEdits,
  };
  start();
})();
