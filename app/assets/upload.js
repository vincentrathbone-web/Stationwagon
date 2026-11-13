// Music library uploads: drop files on the box (or choose them). Each song is prepared in the
// browser first (prep.js: mastered with the station's import preset and levelled as the two
// switches say, trimmed, re-encoded), then they go up one at a time with progress, each followed
// by the file it was made from (kept for the Song prep screen), and the page reloads to show them.
// Mastering, levelling and the loudness target are the station's, set on Sound & safety (round 64; they
// were switches and a slider on this page): the page hands them over as data attributes. Without JavaScript
// the form still works (MP3s only). Other formats (round 54: WAV, FLAC, M4A, AAC, Ogg, Opus, WMA) are
// converted in the browser (prep.js): the song goes up as an MP3, followed by the 320 kbps MP3 the
// station keeps as its original; one that can't be converted isn't sent.
// Round 56 (goal 10): each song opens the song details window first (library.js, SwSongDetails): its
// album art, title, artist and album, the recording, the composition and the licence, filled from the
// file's own tags. It is prepared in the background meanwhile, and goes up only after Add song; several
// picked at once get a window each, in turn. (window.swUploadNoWindow, for tests about other things,
// uploads straight away as before.)
// Round 83: the tick box "Do bulk upload now and enter details later" skips the window for the songs picked
// then: they go up one after another with their files' own details, prepared as usual.
// Round 84: the songs are prepared in this page, so closing or refreshing it stops the rest (a closed page can't keep
// working; moving the preparing to the server is a later phase). So while songs are still going: the browser asks
// before leaving, a line says how many are done and to keep the page open, and the names not finished yet are kept in
// this browser; after a refresh the page lists them so they can be picked again. On the Music library each song's
// file is first checked against the library (by its hash), so songs already added are skipped at once.
// Round 85 (v1.13): where the station's Node is set up (Sound & safety), files go up as they are, in pieces that resume,
// and the server prepares them (PrepQueue, cli/prep.php): only sending needs the page. "Being prepared" lists them on
// every device. Elsewhere (no Node, or a page that can't fingerprint files) songs are prepared here as before.
(() => {
  const form = document.getElementById("drop");
  if (!form || !window.FormData || !window.XMLHttpRequest) return;
  const input = document.getElementById("files");
  const list = document.getElementById("uplist");
  const csrf = form.querySelector('input[name="csrf"]').value;
  const maxBytes = Number(form.dataset.max || 0);
  form.querySelector('button[type="submit"]').hidden = true; // choosing files starts the upload
  const bulk = document.getElementById("bulkUp"); // the Music library's "enter details later" (round 83)
  if (bulk) document.getElementById("bulkUpWrap").hidden = false;
  // mastering, levelling and the loudness target are the station's (Sound & safety), sent with the page as data attributes
  const master = form.dataset.master === "1", level = form.dataset.level === "1";
  const fmt = (v) => Number(v).toFixed(1).replace("-", "−");
  // the import preset (Song prep): its settings ({} is the built-in Audition chain) and its name
  let preset = {};
  try { preset = JSON.parse(form.dataset.preset || "{}") || {}; } catch (_) { /* the built-in one */ }
  const presetName = form.dataset.presetName || "";

  // ---- What's still to do, kept in this browser (round 84) ----
  const leftKey = "sw-upload-left:" + form.getAttribute("action");
  const store = {
    get() { try { const v = JSON.parse(localStorage.getItem(leftKey) || "[]"); return Array.isArray(v) ? v : []; } catch (_) { return []; } },
    set(v) { try { if (v.length) localStorage.setItem(leftKey, JSON.stringify(v)); else localStorage.removeItem(leftKey); } catch (_) { /* private window */ } },
  };
  const keyOf = (f) => f.name + "|" + f.size;
  const finished = (f) => store.set(store.get().filter((x) => x.k !== keyOf(f)));
  const progress = document.createElement("p");
  progress.className = "upprogress"; progress.id = "upProgress"; progress.setAttribute("aria-live", "polite"); progress.hidden = true;
  list.before(progress);
  // left over from last time: the page was closed or refreshed before these were done
  const showLeft = () => {
    const left = store.get(), old = document.getElementById("upLeft");
    if (old) old.remove();
    if (!left.length) return;
    const box = document.createElement("div");
    box.className = "alert bad upleft"; box.id = "upLeft"; box.setAttribute("role", "status");
    const p = document.createElement("p");
    p.textContent = left.length + (left.length === 1 ? " song" : " songs") + " from your last upload didn’t finish: the page was closed or refreshed before "
      + (left.length === 1 ? "it was" : "they were") + " done. Pick " + (left.length === 1 ? "it" : "them") + " again (songs already added are skipped):";
    const ul = document.createElement("ul");
    left.slice(0, 50).forEach((x) => { const li = document.createElement("li"); li.textContent = x.n; ul.appendChild(li); });
    if (left.length > 50) { const li = document.createElement("li"); li.textContent = "and " + (left.length - 50) + " more"; ul.appendChild(li); }
    const clear = document.createElement("button");
    clear.type = "button"; clear.className = "btn sm"; clear.textContent = "Forget this list";
    clear.addEventListener("click", () => { store.set([]); box.remove(); });
    box.append(p, ul, clear);
    form.after(box);
  };
  showLeft();
  let busy = false;
  window.addEventListener("beforeunload", (e) => { if (busy) { e.preventDefault(); e.returnValue = ""; } });
  // the file's hash (as prep.js makes it), to ask whether the library has it already
  const hashOf = async (f) => {
    try {
      if (!window.crypto || !crypto.subtle) return null;
      return [...new Uint8Array(await crypto.subtle.digest("SHA-1", await f.arrayBuffer()))].map((b) => b.toString(16).padStart(2, "0")).join("");
    } catch (_) { return null; }
  };
  const have = async (sum) => {
    if (!bulk || !sum) return false; // the Music library only
    const data = new FormData();
    data.append("csrf", csrf); data.append("action", "have"); data.append("sha1[]", sum);
    try {
      const j = await fetch(form.getAttribute("action"), { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" }).then((x) => x.json());
      return !!(j && j.have && j.have.includes(sum));
    } catch (_) { return false; }
  };

  const row = (name) => {
    const li = document.createElement("li");
    li.innerHTML = '<span class="n"></span><span class="s">Waiting</span><span class="w" hidden></span><i class="bar"><i></i></i>';
    li.querySelector(".n").textContent = name;
    list.appendChild(li);
    return li;
  };

  const db = (v) => (v > 0 ? "+" : "") + v.toFixed(1).replace("-", "−") + " dB";
  const lufs = (v) => v.toFixed(1).replace("-", "−") + " LUFS";
  const khz = (r) => String(r / 1000).replace(/.0+$/, "") + " kHz";
  const from = (p) => p.info && p.info.converted
    ? "converted from " + p.info.converted.kind + " (" + p.info.converted.from.codec + ", " + khz(p.info.converted.from.rate) + "; a " + p.info.converted.original_kbps + " kbps MP3 kept as its original), "
    : "";
  const describe = (p) => from(p) + (p.kept
    ? "kept as uploaded (nothing to change: no mastering, no levelling, " + khz(p.info.quality.rate) + "), measured at " + lufs(p.info.lufs)
    : p.prepared
    ? (p.info.mastered ? "mastered, " : "") +
      (p.info.levelled ? "levelled " + lufs(p.info.lufs_before) + " → " + lufs(p.info.lufs) + " (" + db(p.info.gain_db) + ")" : "kept at " + lufs(p.info.lufs)) +
      (p.info.trimmed_start_s + p.info.trimmed_end_s >= 0.1 ? ", " + (p.info.trimmed_start_s + p.info.trimmed_end_s).toFixed(1) + " s of silence trimmed" : "") +
      (p.info.quality ? ", " + p.info.quality.kbps + " kbps at " + khz(p.info.quality.rate) : "")
    : "as it is: " + p.note);

  // ---- Round 85 (v1.13): prepared on the server ----
  // When the station's Node is set up (Sound & safety), each file goes up as it is, in pieces that can resume, and the
  // server prepares it (cli/prep.php): the page may then be closed. Only the transfer needs the page. The list below the
  // drop box ("Being prepared") shows every upload not added yet, from any device, and refreshes while one is going.
  let server = form.dataset.server === "1";
  const qPanel = document.getElementById("prepQueue");
  const mine = new Set(); // this page's uploads, by job id
  const ask1 = (fields, files) => {
    const d = new FormData();
    d.append("csrf", csrf);
    Object.keys(fields).forEach((k) => d.append(k, fields[k]));
    Object.keys(files || {}).forEach((k) => d.append(k, files[k][0], files[k][1]));
    return fetch(form.getAttribute("action"), { method: "POST", body: d, headers: { Accept: "application/json" }, credentials: "same-origin" }).then((x) => x.json());
  };
  const pause = (ms) => new Promise((r) => setTimeout(r, ms));
  const b64 = async (blob) => {
    const b = new Uint8Array(await blob.arrayBuffer());
    let s = "";
    for (let i = 0; i < b.length; i += 8192) s += String.fromCharCode.apply(null, b.subarray(i, i + 8192));
    return btoa(s);
  };
  // one piece, with progress; null when the connection failed
  const piece = (id, offset, blob, onProgress) => new Promise(async (done) => {
    const d = new FormData();
    d.append("csrf", csrf); d.append("action", "q_chunk"); d.append("id", String(id)); d.append("offset", String(offset));
    // a piece under 16 KB goes as text: some hosts' firewalls refuse a small binary upload (as the studio's last piece)
    if (blob.size < 16384) d.append("b64", await b64(blob)); else d.append("data", blob, "piece");
    const xhr = new XMLHttpRequest();
    xhr.open("POST", form.getAttribute("action"));
    xhr.setRequestHeader("Accept", "application/json");
    xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total * blob.size); };
    xhr.onload = () => { try { done(JSON.parse(xhr.responseText)); } catch (_) { done({ ok: false, received: -1, error: "The server answered " + xhr.status + "." }); } };
    xhr.onerror = () => done(null);
    xhr.send(d);
  });
  /** Send a file from where the server has it to its end, then say it's whole. { ok } or { ok: false, error }. */
  async function transfer(job, file, onBar, stop) {
    let offset = job.received || 0, tries = 0, finishedAt = -1;
    const size = Math.max(65536, Math.min(job.chunk || 8388608, maxBytes ? maxBytes - 65536 : Infinity));
    while (!stop.now) {
      if (offset >= file.size) {
        const f = await ask1({ action: "q_finish", id: job.id }).catch(() => null);
        if (f && f.ok) return { ok: true };
        if (f && typeof f.received === "number" && f.received !== finishedAt) { finishedAt = offset = f.received; continue; }
        if (!f && tries++ < 5) { await pause(2000 * tries); continue; }
        return { ok: false, error: (f && f.error) || "Connection lost" };
      }
      const at = offset;
      const r = await piece(job.id, at, file.slice(at, at + size), (n) => onBar((at + n) / file.size));
      if (r && r.ok) { offset = r.received; tries = 0; onBar(offset / file.size); continue; }
      if (r && r.error === "out of step" && r.received >= 0) { offset = r.received; continue; }
      if (r && r.error) return { ok: false, error: r.error };
      if (tries++ < 5) { await pause(2000 * tries); continue; } // the connection dropped: try again a few times
      return { ok: false, error: "Connection lost" };
    }
    return { ok: false, error: "stopped" };
  }
  // a non-MP3's tags in the shape the details window takes (an MP3's come from its ID3 tag as they are)
  const windowTags = (t) => ({ title: t.title, artist: t.artist, album: t.album, year: t.date, track: t.track, writers: t.composer, lyricists: t.lyricist,
    publisher: t.publisher, copyright: t.copyright, isrc: t.isrc || t.ISRC, label: t.label, iswc: t.iswc });

  /**
   * One picked file through the server. "browser" when it must be prepared here instead (the server can't, or this page
   * can't fingerprint files), "stop" when the window's Cancel all was chosen, else "done".
   */
  async function viaServer(f, li, n, total, ask) {
    const status = li.querySelector(".s"), bar = li.querySelector(".bar i");
    status.textContent = "Checking";
    const sum = await hashOf(f);
    if (!sum) return "browser";
    const b = await ask1({ action: "q_begin", name: f.name, bytes: String(f.size), sha1: sum, hold: ask ? "1" : "0" }).catch(() => null);
    if (b && b.browser) { server = false; return "browser"; }
    if (!b || !b.ok) { li.className = "bad"; status.textContent = b ? "Not added: " + b.error : "Connection lost"; return "done"; }
    if (b.state === "have") { li.className = "ok"; status.textContent = "Already there"; bar.style.width = "100%"; return "done"; }
    if (b.state !== "uploading") { li.className = "ok"; status.textContent = "Already being prepared on the server (see the list below)"; bar.style.width = "100%"; mine.add(b.id); return "done"; }
    mine.add(b.id);
    poll();
    let win = null;
    const stop = { now: false };
    if (ask) {
      const kind = window.SwPrepCore ? window.SwPrepCore.sniff(new Uint8Array(await f.slice(0, 65536).arrayBuffer()), f.name) : "mp3";
      let first = null;
      if (kind === "mp3") { try { first = window.SwPrepCore.readTags(new Uint8Array(await f.slice(0, 4 * 1024 * 1024).arrayBuffer())); } catch (_) { /* no tag */ } }
      const pic = first && first.picture ? new Blob([first.picture.data], { type: first.picture.mime }) : null;
      win = ask(f.name, n, total, first && first.tags, pic);
      if (kind !== "mp3" && window.SwPrep && window.SwPrep.tags) {
        window.SwPrep.tags(f).then((t) => { if (!stop.now) win.tags(windowTags(t.tags || {}), t.picture ? new Blob([t.picture.data], { type: t.picture.mime }) : null); });
      }
      status.textContent = "Waiting for its details";
      win.status("Uploading (prepared on the server afterwards)");
    }
    const say = (text) => { if (!stop.now) { status.textContent = text; if (win) win.status(text + " (prepared on the server afterwards)"); } };
    const going = transfer(b, f, (p) => { bar.style.width = (p * 100).toFixed(1) + "%"; say("Uploading " + Math.floor(p * 100) + " %"); }, stop);
    if (win) {
      const choice = await win.done;
      if (choice.action !== "add") {
        stop.now = true;
        await ask1({ action: "q_cancel", id: b.id }).catch(() => null);
        li.className = "bad"; status.textContent = "Not added: skipped";
        return choice.action === "stop" ? "stop" : "done";
      }
      li.querySelector(".n").textContent = choice.title + (choice.artist ? " · " + choice.artist : "");
      const d = await ask1({ action: "q_details", id: b.id, details: JSON.stringify({ title: choice.title, artist: choice.artist, album: choice.album, rights: choice.rights, art: choice.art === "new" ? "keep" : choice.art }) },
        choice.art === "new" && choice.artFile ? { art: [choice.artFile, choice.artFile.name] } : null).catch(() => null);
      if (!d || !d.ok) { stop.now = true; await ask1({ action: "q_cancel", id: b.id }).catch(() => null); li.className = "bad"; status.textContent = "Not added: " + ((d && d.error) || "connection lost"); return "done"; }
    }
    const t = await going;
    if (!t.ok) { li.className = "bad"; status.textContent = "Not sent: " + t.error + ". Pick it again to carry on where it stopped."; return "done"; }
    li.className = "ok"; bar.style.width = "100%";
    status.textContent = "Uploaded: being prepared on the server (see the list below)";
    poll();
    return "done";
  }

  // ---- The list of uploads being prepared (any device) ----
  const STATES = { uploading: "Uploading", queued: "Waiting to be prepared", working: "Preparing", prepared: "Prepared", done: "Added", failed: "Not added", cancelled: "Cancelled" };
  let pollTimer = null, reloadWhenDone = false;
  const jobLine = (j) => {
    const pct = j.progress !== null && j.progress !== undefined ? " " + Math.floor(j.progress * 100) + " %" : "";
    if (j.state === "uploading") return j.stale ? "Upload stopped at" + pct + ": pick the file again (on the device it came from) to finish it" : "Uploading" + pct;
    if (j.state === "queued") return j.waiting_for_node ? "Waiting: song prep on the server isn’t set up (Sound & safety)" : "Waiting to be prepared";
    if (j.state === "working") return (j.stage || "Preparing") + pct;
    if (j.state === "prepared") return j.hold ? "Prepared: waiting for its details (the details window was closed)" : "Prepared: being added";
    if (j.state === "done") return (j.result && j.result.duplicate ? "Already there" : "Added, " + describe(j.result || { prepared: false, note: "" })) + (j.result && j.result.added_note ? " · " + j.result.added_note : "");
    if (j.state === "failed") return j.error || "Not added";
    return STATES[j.state] || j.state;
  };
  const jobButtons = (j) => {
    const b = [];
    if (["uploading", "queued", "working", "prepared"].includes(j.state)) b.push(["q_cancel", "Cancel"]);
    if (j.state === "prepared" && j.hold) b.unshift(["q_release", "Add with the file’s details"]);
    if (j.state === "failed") b.push(["q_retry", "Try again"]);
    if (["failed", "cancelled", "done"].includes(j.state)) b.push(["q_remove", j.state === "done" ? "Hide" : "Remove"]);
    return b;
  };
  function render(jobs) {
    if (!qPanel) return;
    const recent = Date.now() - 15 * 60 * 1000;
    const shown = jobs.filter((j) => j.state !== "done" || mine.has(j.id) || Date.parse(j.updated.replace(" ", "T") + "Z") > recent);
    qPanel.hidden = !shown.length;
    const ol = qPanel.querySelector("ol");
    ol.innerHTML = "";
    shown.forEach((j) => {
      const li = document.createElement("li");
      li.className = j.state === "done" ? "ok" : j.state === "failed" || j.state === "cancelled" ? "bad" : "";
      li.dataset.job = String(j.id); li.dataset.state = j.state;
      li.innerHTML = '<span class="n"></span><span class="s"></span><span class="who muted2"></span><span class="qb"></span><i class="bar"><i></i></i>';
      li.querySelector(".n").textContent = (j.result && j.result.title) || j.name;
      li.querySelector(".s").textContent = jobLine(j);
      li.querySelector(".who").textContent = (j.who ? "From " + j.who : "") + (j.result && j.result.notice ? (j.who ? " · " : "") + j.result.notice : "");
      const p = j.state === "done" ? 1 : j.progress !== null && j.progress !== undefined ? j.progress : 0;
      li.querySelector(".bar i").style.width = (p * 100).toFixed(1) + "%";
      jobButtons(j).forEach(([action, label]) => {
        const btn = document.createElement("button");
        btn.type = "button"; btn.className = "btn sm"; btn.textContent = label; btn.dataset.action = action;
        btn.addEventListener("click", async () => {
          btn.disabled = true;
          const r = await ask1({ action, id: j.id }).catch(() => null);
          if (r && !r.ok && r.error) alert(r.error);
          poll();
        });
        li.querySelector(".qb").appendChild(btn);
      });
      ol.appendChild(li);
    });
    const going = jobs.filter((j) => ["uploading", "queued", "working", "prepared"].includes(j.state) && !(j.state === "uploading" && j.stale));
    const h = qPanel.querySelector("h3");
    h.textContent = going.length ? "Being prepared on the server: " + going.length + (going.length === 1 ? " upload" : " uploads") : "Uploads prepared on the server";
    // this page's own uploads all finished: show the new songs (as after an upload in the browser)
    const myJobs = jobs.filter((j) => mine.has(j.id));
    if (reloadWhenDone && !busy && myJobs.length && myJobs.every((j) => ["done", "failed", "cancelled"].includes(j.state) || (j.state === "prepared" && j.hold))
        && myJobs.some((j) => j.state === "done")) {
      reloadWhenDone = false;
      setTimeout(() => location.reload(), 1200);
    }
    return going.length > 0;
  }
  async function poll() {
    if (!qPanel) return;
    clearTimeout(pollTimer);
    const j = await ask1({ action: "q_list" }).catch(() => null);
    const active = j && j.ok ? render(j.jobs) : true;
    pollTimer = setTimeout(poll, active || busy ? 2500 : 30000);
  }
  if (qPanel) poll();

  const send = (p, name, li, choice) => new Promise((done) => {
    const data = new FormData();
    data.append("csrf", csrf);
    data.append("action", "upload");
    data.append("files[]", p.file, name);
    if (choice) {
      // the window's details (ManagePage::detailsPosted); a picture chosen there goes with them
      data.append("details[]", JSON.stringify({ title: choice.title, artist: choice.artist, album: choice.album, rights: choice.rights, art: choice.art }));
      if (choice.artFile) data.append("art_0", choice.artFile, choice.artFile.name);
    }
    if (p.sourceSha1) data.append("source_sha1[]", p.sourceSha1);
    if (p.prepared && p.info.levelled) data.append("prep_lufs[]", String(p.info.lufs));
    // the song's normalization, kept with it (round 41)
    if (p.prepared) {
      data.append("prep_source_lufs[]", String(p.info.lufs_before));
      data.append("prep_gain[]", String(p.info.gain_db));
      data.append("prep_target[]", p.info.target === null ? "" : String(p.info.target));
      data.append("prep_settings[]", p.info.mastered ? JSON.stringify(preset) : "");
    }
    if (p.prepared && p.info.mastered) { data.append("prep_mastered[]", "1"); data.append("prep_preset[]", presetName); }
    if (p.prepared && p.info.outro) data.append("prep_outro[]", JSON.stringify(p.info.outro)); // for silence detection
    const xhr = new XMLHttpRequest();
    xhr.open("POST", form.getAttribute("action")); // not form.action: the form has a field named "action"
    xhr.setRequestHeader("Accept", "application/json");
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        li.querySelector(".bar i").style.width = (e.loaded / e.total * 100).toFixed(1) + "%";
        li.querySelector(".s").textContent = e.loaded < e.total ? "Uploading" : "Checking";
      }
    };
    xhr.onload = () => {
      let r = null;
      try { r = JSON.parse(xhr.responseText); } catch (_) { /* not JSON */ }
      const res = r && r.results ? r.results[0] : null;
      const ok = res && res.ok;
      li.className = ok ? "ok" : "bad";
      li.querySelector(".s").textContent = ok ? (res.duplicate ? "Already there" + (choice ? " (its details weren’t changed: edit them in the list)" : "") : "Added, " + describe(p) + (res.note ? " · " + res.note : ""))
        : ((res && res.error) || (r && r.error) || "Failed (" + xhr.status + ")");
      done(ok ? res : null);
    };
    xhr.onerror = () => { li.className = "bad"; li.querySelector(".s").textContent = "Connection lost"; done(false); };
    xhr.send(data);
  });

  const upload = async (files) => {
    files = [...files].filter((f) => f.size > 0);
    if (!files.length) return;
    list.hidden = false;
    list.innerHTML = "";
    const rows = files.map((f) => row(f.name));
    let ok = 0, done = 0;
    // round 84: keep the names still to do in this browser, warn before leaving, and say how far it has got
    const mine = new Set(files.map(keyOf));
    store.set([...store.get().filter((x) => !mine.has(x.k)), ...files.map((f) => ({ k: keyOf(f), n: f.name }))]);
    const old = document.getElementById("upLeft");
    if (old) old.remove();
    busy = true;
    let sent = 0; // round 85: how many went to the server to be prepared there
    const tell = () => {
      progress.hidden = false;
      progress.className = "upprogress" + (done < files.length ? "" : " done");
      progress.textContent = done < files.length
        ? done + " of " + files.length + (server
          ? " sent. Keep this page open until all are sent: the server prepares them, so after that you can close it."
          : " done. Keep this page open until all are finished: closing or refreshing it stops the rest.")
        : sent ? "All " + files.length + " sent. They’re being prepared on the server: you can close this page." : "All " + files.length + " done.";
    };
    tell();
    const next = (i) => { finished(files[i]); done++; tell(); };
    // "Do bulk upload now and enter details later" (round 83) skips the window: the songs keep their files' own details
    const later = bulk && bulk.checked;
    const ask = window.SwSongDetails && !window.swUploadNoWindow && !later ? window.SwSongDetails.ask : null;
    for (let i = 0; i < files.length; i++) {
      const li = rows[i], status = li.querySelector(".s");
      // round 85: sent to the server to be prepared there, when the station's Node is set up
      if (server) {
        const how = await viaServer(files[i], li, i + 1, files.length, ask);
        if (how === "stop") {
          next(i);
          for (let k = i + 1; k < files.length; k++) { rows[k].className = "bad"; rows[k].querySelector(".s").textContent = "Not added: cancelled"; next(k); }
          break;
        }
        if (how === "done") { sent++; next(i); continue; }
        status.textContent = "Waiting"; // "browser": prepared in this page after all
      }
      // already in the library (picked again after the page was closed): skipped at once
      if (bulk) {
        status.textContent = "Checking";
        if (await have(await hashOf(files[i]))) {
          li.className = "ok"; status.textContent = "Already there"; li.querySelector(".bar i").style.width = "100%";
          next(i);
          continue;
        }
        status.textContent = "Waiting";
      }
      // the window first, filled from an MP3's tag at once (another format's tags come once it's decoded)
      let win = null;
      if (ask) {
        let first = null;
        try { if (window.SwPrepCore) first = window.SwPrepCore.readTags(new Uint8Array(await files[i].slice(0, 4 * 1024 * 1024).arrayBuffer())); } catch (_) { /* no tag */ }
        const pic = first && first.picture ? new Blob([first.picture.data], { type: first.picture.mime }) : null;
        win = ask(files[i].name, i + 1, files.length, first && first.tags, pic);
        status.textContent = "Waiting for its details";
      }
      let gone = false; // skipped or cancelled: its preparation may still finish, but says nothing more
      const say = (text) => { if (gone) return; status.textContent = text; if (win) win.status("Preparing in the background: " + text.toLowerCase()); };
      const prepping = (async () => {
        let p = { file: files[i], prepared: false, note: "song prep didn’t load" };
        if (window.SwPrep) {
          try {
            // a sample-rate conversion the station makes is said before the work starts, and stays on the row
            const warn = li.querySelector(".w");
            p = await window.SwPrep.prepare(files[i], say,
              { master: master ? preset : false, level, target: Number(form.dataset.target),
                onNotice: (text) => { warn.textContent = text; warn.hidden = false; } });
          } catch (ex) {
            p = { file: files[i], prepared: false, note: "prep failed: " + ex.message };
          }
        }
        return p;
      })();
      let choice = null;
      if (win) {
        prepping.then((p) => {
          if (gone) return;
          if (p.tags) {
            const t = p.tags;
            win.tags({ title: t.title, artist: t.artist, album: t.album, year: t.date, track: t.track, writers: t.composer, lyricists: t.lyricist,
              publisher: t.publisher, copyright: t.copyright, isrc: t.isrc || t.ISRC, label: t.label, iswc: t.iswc },
              p.picture ? new Blob([p.picture.data], { type: p.picture.mime }) : null);
          }
          win.status(p.refused ? "This file can’t be added: " + p.note : "Prepared: ready to add");
        });
        choice = await win.done;
        if (choice.action !== "add") {
          gone = true;
          li.className = "bad";
          status.textContent = "Not added: skipped";
          next(i);
          if (choice.action === "stop") {
            for (let k = i + 1; k < files.length; k++) { rows[k].className = "bad"; rows[k].querySelector(".s").textContent = "Not added: cancelled"; next(k); }
            break;
          }
          continue;
        }
        li.querySelector(".n").textContent = choice.title + (choice.artist ? " · " + choice.artist : "");
        status.textContent = "Preparing";
      }
      let p = await prepping;
      if (p.refused) {
        // not an MP3 and not converted: nothing to send (the station takes MP3s only)
        li.className = "bad";
        status.textContent = "Not added: " + p.note;
        next(i);
        continue;
      }
      if (p.prepared && maxBytes && p.file.size > maxBytes && files[i].size <= maxBytes && !p.original) {
        p = { file: files[i], prepared: false, sourceSha1: p.sourceSha1, note: "the prepared song is bigger than this server accepts" };
      }
      const res = await send(p, p.name || files[i].name, li, choice);
      if (res) ok++;
      // keep the file the song was made from too, so it can be prepared again (Song prep); also for
      // a song already in the library that hasn't got its original yet (uploaded before round 40)
      if (res && res.original === false) {
        const status = li.querySelector(".s"), said = status.textContent;
        status.textContent = said + " · keeping the original";
        const data = new FormData();
        data.append("csrf", csrf); data.append("action", "original"); data.append("id", String(res.id));
        data.append("original", p.original || files[i], p.original ? p.name : files[i].name);
        const kept = await fetch(form.getAttribute("action"), { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" })
          .then((x) => x.json()).catch(() => ({ ok: false, error: "connection lost" }));
        status.textContent = said + (kept.ok ? " · original kept" : " · original not kept: " + (kept.error || "failed"));
      }
      next(i);
    }
    busy = false;
    tell();
    if (sent) { reloadWhenDone = true; poll(); } // the songs prepared on the server show when they're added
    else if (ok) setTimeout(() => location.reload(), 900);
  };

  form.addEventListener("submit", (e) => { e.preventDefault(); upload(input.files); });
  input.addEventListener("change", () => upload(input.files));
  ["dragenter", "dragover"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.add("over"); }));
  ["dragleave", "drop"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.remove("over"); }));
  form.addEventListener("drop", (e) => upload(e.dataTransfer.files));
})();
