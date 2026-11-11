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
(() => {
  const form = document.getElementById("drop");
  if (!form || !window.FormData || !window.XMLHttpRequest) return;
  const input = document.getElementById("files");
  const list = document.getElementById("uplist");
  const csrf = form.querySelector('input[name="csrf"]').value;
  const maxBytes = Number(form.dataset.max || 0);
  form.querySelector('button[type="submit"]').hidden = true; // choosing files starts the upload
  // mastering, levelling and the loudness target are the station's (Sound & safety), sent with the page as data attributes
  const master = form.dataset.master === "1", level = form.dataset.level === "1";
  const fmt = (v) => Number(v).toFixed(1).replace("-", "−");
  // the import preset (Song prep): its settings ({} is the built-in Audition chain) and its name
  let preset = {};
  try { preset = JSON.parse(form.dataset.preset || "{}") || {}; } catch (_) { /* the built-in one */ }
  const presetName = form.dataset.presetName || "";

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
    let ok = 0;
    const ask = window.SwSongDetails && !window.swUploadNoWindow ? window.SwSongDetails.ask : null;
    for (let i = 0; i < files.length; i++) {
      const li = rows[i], status = li.querySelector(".s");
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
          if (choice.action === "stop") {
            for (let k = i + 1; k < files.length; k++) { rows[k].className = "bad"; rows[k].querySelector(".s").textContent = "Not added: cancelled"; }
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
    }
    if (ok) setTimeout(() => location.reload(), 900);
  };

  form.addEventListener("submit", (e) => { e.preventDefault(); upload(input.files); });
  input.addEventListener("change", () => upload(input.files));
  ["dragenter", "dragover"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.add("over"); }));
  ["dragleave", "drop"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.remove("over"); }));
  form.addEventListener("drop", (e) => upload(e.dataTransfer.files));
})();
