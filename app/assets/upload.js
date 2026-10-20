// Music library uploads: drop files on the box (or choose them). Each song is prepared in the
// browser first (prep.js: levelled, trimmed, re-encoded), then they go up one at a time with
// progress, and the page reloads to show them. Without JavaScript the form still works.
(() => {
  const form = document.getElementById("drop");
  if (!form || !window.FormData || !window.XMLHttpRequest) return;
  const input = document.getElementById("files");
  const list = document.getElementById("uplist");
  const csrf = form.querySelector('input[name="csrf"]').value;
  const maxBytes = Number(form.dataset.max || 0);
  form.querySelector('button[type="submit"]').hidden = true; // choosing files starts the upload

  const row = (name) => {
    const li = document.createElement("li");
    li.innerHTML = '<span class="n"></span><span class="s">Waiting</span><i class="bar"><i></i></i>';
    li.querySelector(".n").textContent = name;
    list.appendChild(li);
    return li;
  };

  const db = (v) => (v > 0 ? "+" : "") + v.toFixed(1).replace("-", "−") + " dB";
  const lufs = (v) => v.toFixed(1).replace("-", "−") + " LUFS";
  const describe = (p) => p.prepared
    ? "levelled " + lufs(p.info.lufs_before) + " → " + lufs(p.info.lufs) + " (" + db(p.info.gain_db) + ")" +
      (p.info.trimmed_start_s + p.info.trimmed_end_s >= 0.1 ? ", " + (p.info.trimmed_start_s + p.info.trimmed_end_s).toFixed(1) + " s of silence trimmed" : "")
    : "as it is: " + p.note;

  const send = (p, name, li) => new Promise((done) => {
    const data = new FormData();
    data.append("csrf", csrf);
    data.append("action", "upload");
    data.append("files[]", p.file, name);
    if (p.sourceSha1) data.append("source_sha1[]", p.sourceSha1);
    if (p.prepared) data.append("prep_lufs[]", String(p.info.lufs));
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
      done(ok);
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
          p = await window.SwPrep.prepare(files[i], (stage) => { status.textContent = stage; });
        } catch (ex) {
          p = { file: files[i], prepared: false, note: "prep failed: " + ex.message };
        }
      }
      if (p.prepared && maxBytes && p.file.size > maxBytes && files[i].size <= maxBytes) {
        p = { file: files[i], prepared: false, sourceSha1: p.sourceSha1, note: "the prepared song is bigger than this server accepts" };
      }
      ok += (await send(p, files[i].name, li)) ? 1 : 0;
    }
    if (ok) setTimeout(() => location.reload(), 900);
  };

  form.addEventListener("submit", (e) => { e.preventDefault(); upload(input.files); });
  input.addEventListener("change", () => upload(input.files));
  ["dragenter", "dragover"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.add("over"); }));
  ["dragleave", "drop"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.remove("over"); }));
  form.addEventListener("drop", (e) => upload(e.dataTransfer.files));
})();
