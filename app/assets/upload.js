// Music library uploads: drop files on the box (or choose them). Each song is prepared in the
// browser first (prep.js: mastered with the station's import preset and levelled as the two
// switches say, trimmed, re-encoded), then they go up one at a time with progress, each followed
// by the file it was made from (kept for the Song prep screen), and the page reloads to show them.
// The switches and the loudness target slider are the station's, saved as soon as they change; a new
// target offers to re-level the library (relevel.js, told by an "sw:target" event). Without JavaScript
// the form still works (MP3s only). Other formats (round 54: WAV, FLAC, M4A, AAC, Ogg, Opus, WMA) are
// converted in the browser (prep.js): the song goes up as an MP3, followed by the 320 kbps MP3 the
// station keeps as its original; one that can't be converted isn't sent.
(() => {
  const form = document.getElementById("drop");
  if (!form || !window.FormData || !window.XMLHttpRequest) return;
  const input = document.getElementById("files");
  const list = document.getElementById("uplist");
  const csrf = form.querySelector('input[name="csrf"]').value;
  const maxBytes = Number(form.dataset.max || 0);
  form.querySelector('button[type="submit"]').hidden = true; // choosing files starts the upload
  const master = document.getElementById("prepMaster"), level = document.getElementById("prepLevel");
  const saved = document.getElementById("prepSaved"), target = document.getElementById("prepTarget");
  const fmt = (v) => Number(v).toFixed(1).replace("-", "−");
  // the import preset (Song prep): its settings ({} is the built-in Audition chain) and its name
  let preset = {};
  try { preset = JSON.parse(form.dataset.preset || "{}") || {}; } catch (_) { /* the built-in one */ }
  const presetName = form.dataset.presetName || "";

  // the switches are the station's: save them as soon as they change (one save at a time, the
  // latest state last, so two quick flips can't land out of order)
  let timer = 0, queue = Promise.resolve();
  const savePrep = () => {
    saved.textContent = "Saving…";
    clearTimeout(timer);
    timer = setTimeout(() => {
      const data = new FormData();
      data.append("csrf", csrf);
      data.append("action", "prep");
      if (master.checked) data.append("master", "1");
      if (level.checked) data.append("level", "1");
      if (target) data.append("target", target.value);
      queue = queue.then(() => fetch(form.getAttribute("action"), { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" })
        .then((r) => r.json().then((j) => { if (!r.ok || !j.ok) throw new Error(j.error || r.status); return j; }))
        .then((j) => {
          saved.textContent = "Saved for every upload";
          form.dataset.target = String(j.target);
          document.dispatchEvent(new CustomEvent("sw:target", { detail: { target: j.target, level: j.level, songs: j.relevel || [] } }));
        })
        .catch(() => { saved.textContent = "Couldn’t save: try again"; }));
    }, 250);
  };
  if (master && level) [master, level].forEach((el) => el.addEventListener("change", savePrep));
  // the loudness target: snaps to the marked levels, shows its value as it moves, saved when let go
  const SNAP = [-16, -14, -11, -9];
  const showTarget = () => {
    document.getElementById("prepTargetOut").textContent = fmt(target.value);
    document.getElementById("prepTargetSw").textContent = fmt(target.value);
  };
  if (target) {
    // only a drag snaps: the arrow keys step half a dB at a time, past the marks too
    let dragging = false;
    target.addEventListener("pointerdown", () => { dragging = true; });
    ["pointerup", "pointercancel"].forEach((t) => target.addEventListener(t, () => { dragging = false; }));
    target.addEventListener("input", () => {
      const near = dragging && SNAP.find((v) => Math.abs(v - Number(target.value)) <= 0.5 && Number(target.value) !== v);
      if (near !== undefined && near !== false) target.value = String(near);
      showTarget();
    });
    target.addEventListener("change", savePrep);
    level.addEventListener("change", () => { document.getElementById("loud").toggleAttribute("data-off", !level.checked); });
  }

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

  const send = (p, name, li) => new Promise((done) => {
    const data = new FormData();
    data.append("csrf", csrf);
    data.append("action", "upload");
    data.append("files[]", p.file, name);
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
      li.querySelector(".s").textContent = ok ? (res.duplicate ? "Already there" : "Added, " + describe(p)) : ((res && res.error) || (r && r.error) || "Failed (" + xhr.status + ")");
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
    let ok = 0;
    for (let i = 0; i < files.length; i++) {
      const li = rows[i], status = li.querySelector(".s");
      let p = { file: files[i], prepared: false, note: "song prep didn’t load" };
      if (window.SwPrep) {
        try {
          // a sample-rate conversion the station makes is said before the work starts, and stays on the row
          const warn = li.querySelector(".w");
          p = await window.SwPrep.prepare(files[i], (stage) => { status.textContent = stage; },
            { master: !master || master.checked ? preset : false, level: !level || level.checked, target: Number(form.dataset.target),
              onNotice: (text) => { warn.textContent = text; warn.hidden = false; } });
        } catch (ex) {
          p = { file: files[i], prepared: false, note: "prep failed: " + ex.message };
        }
      }
      if (p.refused) {
        // not an MP3 and not converted: nothing to send (the station takes MP3s only)
        li.className = "bad";
        status.textContent = "Not added: " + p.note;
        continue;
      }
      if (p.prepared && maxBytes && p.file.size > maxBytes && files[i].size <= maxBytes && !p.original) {
        p = { file: files[i], prepared: false, sourceSha1: p.sourceSha1, note: "the prepared song is bigger than this server accepts" };
      }
      const res = await send(p, p.name || files[i].name, li);
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
    }
    if (ok) setTimeout(() => location.reload(), 900);
  };

  form.addEventListener("submit", (e) => { e.preventDefault(); upload(input.files); });
  input.addEventListener("change", () => upload(input.files));
  ["dragenter", "dragover"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.add("over"); }));
  ["dragleave", "drop"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.remove("over"); }));
  form.addEventListener("drop", (e) => upload(e.dataTransfer.files));
})();
