// Music library uploads: drop files on the box (or choose them) and they go up one at a time
// with progress, then the page reloads to show them. Without JavaScript the form still works.
(() => {
  const form = document.getElementById("drop");
  if (!form || !window.FormData || !window.XMLHttpRequest) return;
  const input = document.getElementById("files");
  const list = document.getElementById("uplist");
  const csrf = form.querySelector('input[name="csrf"]').value;
  form.querySelector('button[type="submit"]').hidden = true; // choosing files starts the upload

  const row = (name) => {
    const li = document.createElement("li");
    li.innerHTML = '<span class="n"></span><span class="s">Waiting</span><i class="bar"><i></i></i>';
    li.querySelector(".n").textContent = name;
    list.appendChild(li);
    return li;
  };

  const send = (file, li) => new Promise((done) => {
    const data = new FormData();
    data.append("csrf", csrf);
    data.append("action", "upload");
    data.append("files[]", file, file.name);
    const xhr = new XMLHttpRequest();
    xhr.open("POST", form.action);
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
      li.querySelector(".s").textContent = ok ? (res.duplicate ? "Already there" : "Added") : ((res && res.error) || (r && r.error) || "Failed (" + xhr.status + ")");
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
    for (let i = 0; i < files.length; i++) ok += (await send(files[i], rows[i])) ? 1 : 0;
    if (ok) setTimeout(() => location.reload(), 900);
  };

  form.addEventListener("submit", (e) => { e.preventDefault(); upload(input.files); });
  input.addEventListener("change", () => upload(input.files));
  ["dragenter", "dragover"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.add("over"); }));
  ["dragleave", "drop"].forEach((t) => form.addEventListener(t, (e) => { e.preventDefault(); form.classList.remove("over"); }));
  form.addEventListener("drop", (e) => upload(e.dataTransfer.files));
})();
