// Music library, in place (round 45): Edit opens its fields right under the song, saving, the album
// art and the copyright window talk to the server in the background and change only that row, so
// the page never reloads or jumps and your place stays. Remove asks in the row too. Without
// JavaScript the same forms and links still work (the page reloads then).
// The copyright window's "More information" is rich text of one kind: text, line breaks and links.
// Pasting and dropping insert plain text (line breaks kept, web addresses made links); the server
// cleans it again (Rights::cleanHtml), so nothing else can get in.
(() => {
  const wrap = document.getElementById("libWrap");
  if (!wrap || !window.fetch || !window.FormData) return;
  const body = document.getElementById("libBody");
  const action = wrap.dataset.action, csrf = wrap.dataset.csrf, base = wrap.dataset.base;
  const $ = (id) => document.getElementById(id);
  let open = null; // the editor row now open

  const send = async (fields, file) => {
    const data = new FormData();
    data.append("csrf", csrf);
    for (const [k, v] of Object.entries(fields)) data.append(k, v);
    if (file) data.append("art", file, file.name);
    let r, j;
    try {
      r = await fetch(action, { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" });
      j = await r.json();
    } catch (_) { throw new Error("The connection dropped. Try again."); }
    if (!r.ok || j.ok === false) throw new Error(j.error || "Not saved (" + r.status + ").");
    return j;
  };
  const track = (tr) => { try { return JSON.parse(tr.dataset.track); } catch (_) { return null; } };
  const hasRights = (r) => r && Object.values(r).some((v) => v !== "" && v != null);

  /** Show a changed song in its row: only what changed is touched. */
  function applyRow(tr, t, line) {
    tr.dataset.track = JSON.stringify(t);
    tr.querySelector(".tt").textContent = t.title;
    tr.querySelector(".alb").textContent = t.album || "";
    tr.querySelector(".c-artist").textContent = t.artist || "";
    const arts = tr.querySelector(".arts");
    arts.textContent = "";
    if (t.art) { const i = new Image(40, 40); i.alt = "Album art"; i.src = base + t.art + "?v=" + Date.now().toString(36); arts.appendChild(i); }
    const cb = tr.querySelector(".cb");
    cb.textContent = "";
    if (hasRights(t.rights)) {
      const p = document.createElement("button");
      p.type = "button"; p.className = "pill rights"; p.title = line || "Copyright details"; p.textContent = "© Copyright";
      cb.append(" ", p);
    }
    tr.classList.remove("saved"); void tr.offsetWidth; tr.classList.add("saved");
  }

  // ---- The row's editor ------------------------------------------------------------------------
  function closeEditor() {
    if (open) { open.remove(); open = null; }
  }

  function openEditor(tr) {
    closeEditor();
    const t = track(tr);
    if (!t) return;
    const ed = document.getElementById("libEditTpl").content.firstElementChild.cloneNode(true);
    tr.after(ed);
    open = ed;
    const f = ed.querySelector("form"), msg = f.querySelector('[data-role="msg"]');
    const say = (text, bad) => { msg.textContent = text || ""; msg.classList.toggle("bad", !!bad); };
    const fill = (x) => {
      f.title.value = x.title; f.artist.value = x.artist || ""; f.album.value = x.album || "";
      const pic = f.querySelector('[data-role="pic"]');
      pic.textContent = "";
      if (x.art) { const i = new Image(96, 96); i.alt = "Album art"; i.src = base + x.art + "?v=" + Date.now().toString(36); pic.appendChild(i); } else pic.textContent = "No album art";
      f.querySelector('[data-role="unpick"]').hidden = !x.art;
    };
    fill(t);
    const file = f.querySelector('[data-role="file"]');
    f.querySelector('[data-role="pick"]').addEventListener("click", () => file.click());
    file.addEventListener("change", async () => {
      if (!file.files[0]) return;
      say("Adding the album art…");
      try {
        const j = await send({ action: "art", id: t.id }, file.files[0]);
        Object.assign(t, j.track); fill(t); applyRow(tr, t, j.line); say("Album art saved.");
      } catch (ex) { say(ex.message, true); }
      file.value = "";
    });
    f.querySelector('[data-role="unpick"]').addEventListener("click", async () => {
      try {
        const j = await send({ action: "art_remove", id: t.id });
        Object.assign(t, j.track); fill(t); applyRow(tr, t, j.line); say("Album art removed.");
      } catch (ex) { say(ex.message, true); }
    });
    f.querySelector('[data-role="rights"]').addEventListener("click", () => openRights(tr, (x, line) => { Object.assign(t, x); }));
    f.querySelector('[data-role="close"]').addEventListener("click", closeEditor);
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!f.title.value.trim()) { say("A song needs a title.", true); f.title.focus({ preventScroll: true }); return; }
      say("Saving…");
      try {
        const j = await send({ action: "update", id: t.id, title: f.title.value, artist: f.artist.value, album: f.album.value });
        applyRow(tr, j.track, j.line);
        closeEditor();
      } catch (ex) { say(ex.message, true); }
    });
    f.addEventListener("keydown", (e) => { if (e.key === "Escape") closeEditor(); });
    f.title.focus({ preventScroll: true });
  }

  // ---- Remove, asked in the row ----------------------------------------------------------------
  function askRemove(tr) {
    const cell = tr.querySelector("td.acts");
    const keep = cell.innerHTML;
    cell.textContent = "";
    const q = document.createElement("span"); q.className = "warn2"; q.textContent = "Remove from the library?";
    const yes = document.createElement("button"); yes.type = "button"; yes.className = "btn sm danger"; yes.textContent = "Yes, remove";
    const no = document.createElement("button"); no.type = "button"; no.className = "btn sm"; no.textContent = "Keep";
    cell.append(q, yes, no);
    no.addEventListener("click", () => { cell.innerHTML = keep; });
    yes.addEventListener("click", async () => {
      yes.disabled = true;
      try {
        const j = await send({ action: "delete", id: track(tr).id });
        if (open && open.previousElementSibling === tr) closeEditor();
        tr.remove();
        if (j.summary) $("libCount").textContent = j.summary;
      } catch (ex) { q.textContent = ex.message; yes.remove(); }
    });
    no.focus({ preventScroll: true });
  }

  body.addEventListener("click", (e) => {
    const a = e.target.closest("a[data-act]");
    if (!a || e.defaultPrevented || e.button || e.ctrlKey || e.metaKey || e.shiftKey) return;
    const tr = a.closest("tr");
    e.preventDefault();
    if (a.dataset.act === "edit") openEditor(tr);
    else if (a.dataset.act === "remove") askRemove(tr);
  });

  // ---- The copyright window --------------------------------------------------------------------
  const dlg = $("rightsDlg"), form = $("rightsForm"), notes = $("rtNotes"), linkBar = $("rtLink"), url = $("rtUrl"), rmsg = $("rightsMsg");
  if (!dlg || typeof dlg.showModal !== "function") return;
  let current = null, after = null;
  const FIELDS = ["owner", "year", "licence", "publisher", "writers"];
  const say = (text, bad) => { rmsg.textContent = text || ""; rmsg.classList.toggle("bad", !!bad); };

  function openRights(tr, done) {
    current = tr; after = done;
    const t = track(tr), r = t.rights && !Array.isArray(t.rights) ? t.rights : {};
    $("rightsSong").textContent = t.title + (t.artist ? " · " + t.artist : "");
    for (const k of FIELDS) form.elements[k].value = r[k] || "";
    notes.innerHTML = r.notes || "";
    linkBar.hidden = true; say("");
    dlg.showModal();
    form.elements.owner.focus({ preventScroll: true });
  }
  body.addEventListener("click", (e) => {
    const b = e.target.closest(".pill.rights");
    if (b) openRights(b.closest("tr"), null);
  });
  $("rightsCancel").addEventListener("click", () => dlg.close());

  async function saveRights(clear) {
    const t = track(current);
    const fields = { action: "rights", id: t.id };
    for (const k of FIELDS) fields[k] = clear ? "" : form.elements[k].value;
    fields.notes = clear ? "" : notes.innerHTML;
    say("Saving…");
    try {
      const j = await send(fields);
      applyRow(current, j.track, j.line);
      if (after) after(j.track, j.line);
      dlg.close();
    } catch (ex) { say(ex.message, true); }
  }
  form.addEventListener("submit", (e) => { e.preventDefault(); saveRights(false); });
  $("rightsClear").addEventListener("click", () => { if (confirm("Clear all copyright details from this song?")) saveRights(true); });

  // ---- Text and links only ---------------------------------------------------------------------
  const URL_RE = /\bhttps?:\/\/[^\s<>"']+/gi;
  const range = () => {
    const s = window.getSelection();
    return s.rangeCount && notes.contains(s.getRangeAt(0).commonAncestorContainer) ? s.getRangeAt(0) : null;
  };
  const anchor = (href, text) => {
    const a = document.createElement("a");
    a.href = href; a.target = "_blank"; a.rel = "noopener noreferrer"; a.textContent = text;
    return a;
  };
  /** Plain text in at the caret: line breaks kept, web addresses made links. */
  function insertPlain(text) {
    let r = range();
    if (!r) { notes.focus({ preventScroll: true }); r = document.createRange(); r.selectNodeContents(notes); r.collapse(false); }
    r.deleteContents();
    const frag = document.createDocumentFragment();
    text.replace(/\r\n?/g, "\n").split("\n").forEach((line, i) => {
      if (i) frag.appendChild(document.createElement("br"));
      let at = 0;
      for (const m of line.matchAll(URL_RE)) {
        const clean = m[0].replace(/[.,;:!?)\]]+$/, "");
        if (m.index > at) frag.appendChild(document.createTextNode(line.slice(at, m.index)));
        frag.appendChild(anchor(clean, clean));
        at = m.index + clean.length;
      }
      if (at < line.length) frag.appendChild(document.createTextNode(line.slice(at)));
    });
    const last = frag.lastChild;
    const endsBreak = last && last.nodeName === "BR";
    r.insertNode(frag);
    if (endsBreak && !(last.nextSibling)) last.after(document.createElement("br")); // a line break at the end needs one more to show
    r.setStartAfter(endsBreak && last.nextSibling ? last : (last || r.startContainer));
    r.collapse(true);
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }
  notes.addEventListener("paste", (e) => { e.preventDefault(); insertPlain((e.clipboardData || window.clipboardData).getData("text/plain") || ""); });
  notes.addEventListener("drop", (e) => { e.preventDefault(); insertPlain((e.dataTransfer && e.dataTransfer.getData("text/plain")) || ""); });
  notes.addEventListener("keydown", (e) => {
    if (e.key === "Enter") { e.preventDefault(); insertPlain("\n"); }
    else if ((e.ctrlKey || e.metaKey) && /^[biu]$/i.test(e.key)) e.preventDefault(); // no bold, italic or underline
  });
  notes.addEventListener("beforeinput", (e) => { if (/^format/.test(e.inputType)) e.preventDefault(); });

  let kept = null;
  const showLink = () => { kept = range(); linkBar.hidden = false; url.value = ""; url.focus({ preventScroll: true }); };
  const hideLink = () => { linkBar.hidden = true; notes.focus({ preventScroll: true }); };
  const addressOf = (v) => {
    v = v.trim();
    if (/^(https?:\/\/|mailto:)\S+$/i.test(v)) return v;
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return "mailto:" + v;
    if (/^(www\.)?[^\s/.]+\.[^\s]+$/i.test(v)) return "https://" + v;
    return null;
  };
  function applyLink() {
    const href = addressOf(url.value);
    if (!href) { say("That doesn’t look like a web address.", true); url.focus({ preventScroll: true }); return; }
    say("");
    notes.focus({ preventScroll: true });
    const r = kept || (() => { const x = document.createRange(); x.selectNodeContents(notes); x.collapse(false); return x; })();
    const text = r.collapsed ? href.replace(/^mailto:/i, "") : r.toString();
    r.deleteContents();
    const a = anchor(href, text);
    r.insertNode(a);
    r.setStartAfter(a); r.collapse(true);
    const s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
    linkBar.hidden = true; kept = null;
  }
  function removeLink() {
    const r = range();
    const a = r && (r.startContainer.nodeType === 1 ? r.startContainer : r.startContainer.parentNode).closest("a");
    if (a) a.replaceWith(document.createTextNode(a.textContent));
  }
  dlg.querySelector('[data-cmd="link"]').addEventListener("mousedown", (e) => e.preventDefault()); // keep the selection
  dlg.querySelector('[data-cmd="link"]').addEventListener("click", showLink);
  dlg.querySelector('[data-cmd="unlink"]').addEventListener("mousedown", (e) => e.preventDefault());
  dlg.querySelector('[data-cmd="unlink"]').addEventListener("click", removeLink);
  $("rtApply").addEventListener("click", applyLink);
  $("rtCancel").addEventListener("click", hideLink);
  url.addEventListener("keydown", (e) => { if (e.key === "Enter") { e.preventDefault(); applyLink(); } else if (e.key === "Escape") { e.stopPropagation(); e.preventDefault(); hideLink(); } });
  // for the tests
  window.SwLibrary = { openEditor: (id) => { const tr = document.getElementById("t" + id); if (tr) openEditor(tr); }, insertPlain };
})();
