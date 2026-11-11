// Music library, in place (round 45): Edit opens its fields right under the song, saving, the album
// art and the copyright window talk to the server in the background and change only that row, so
// the page never reloads or jumps and your place stays. Remove asks in the row too. Without
// JavaScript the same forms and links still work (the page reloads then).
// The song details window's "More information" is rich text of one kind: text, line breaks and links.
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
    for (const [k, v] of Object.entries(fields)) {
      if (Array.isArray(v)) v.forEach((x) => data.append(k + "[]", x)); else data.append(k, v);
    }
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
      p.type = "button"; p.className = "pill rights sym"; p.title = line || "Copyright details"; p.setAttribute("aria-label", "Copyright: " + (line || "details")); p.textContent = "©";
      cb.append(" ", p);
    }
    const stars = tr.querySelector(".stars");
    if (stars && "rating" in t) { paintStars(stars, t.rating); tr.querySelector(".c-rating").dataset.sort = String(t.rating || 3); }
    tr.classList.remove("saved"); void tr.offsetWidth; tr.classList.add("saved");
  }

  /** Show a rating (0 or null = none) in a star group: filled stars, the checked one, and the one Tab lands on. */
  function paintStars(group, value) {
    const v = value || 0;
    group.querySelectorAll(".star").forEach((b, i) => {
      const n = i + 1;
      b.classList.toggle("on", n <= v);
      b.setAttribute("aria-checked", n === v ? "true" : "false");
      b.tabIndex = n === v || (!v && n === 1) ? 0 : -1;
    });
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

  // ---- Stars, sorting, columns, selection (round 62) -----------------------------------------------
  // Ratings are saved in the background like every other change here; sorting, the rating filter, the
  // column choice and compact rows work on the rows already on the page (the choices are kept in this
  // browser only). Without JavaScript the table still shows everything, in title order.
  const table = $("libTable"), note = $("libNote");
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (_) { /* a private window: the choice just isn't kept */ } },
  };
  const say2 = (text, bad) => { note.textContent = text || ""; note.classList.toggle("bad", !!bad); };
  const rows = () => [...body.querySelectorAll("tr[id^='t']")];
  const plural = (n) => n + " star" + (n === 1 ? "" : "s");

  /** Rate one song (0 clears), right away on screen, then on the server; put it back if that fails. */
  async function setRating(tr, next) {
    const t = track(tr);
    if (!t) return;
    const was = t.rating || 0, group = tr.querySelector(".stars");
    if (next === was) return;
    paintStars(group, next);
    try {
      await send({ action: "rate", id: t.id, rating: next || "" });
      t.rating = next || null;
      tr.dataset.track = JSON.stringify(t);
      tr.querySelector(".c-rating").dataset.sort = String(next || 3);
      applyFilter();
      say2(next ? "“" + t.title + "” rated " + plural(next) + "." : "“" + t.title + "” has no rating now.");
    } catch (ex) { paintStars(group, was); say2(ex.message, true); }
  }
  body.addEventListener("click", (e) => {
    const s = e.target.closest(".star");
    if (!s) return;
    const tr = s.closest("tr"), n = +s.dataset.stars;
    setRating(tr, n === ((track(tr) || {}).rating || 0) ? 0 : n); // the star it already has takes it off
  });
  body.addEventListener("keydown", (e) => {
    const s = e.target.closest(".star");
    if (!s || e.ctrlKey || e.metaKey || e.altKey) return;
    const group = s.parentNode, n = +s.dataset.stars;
    if (/^[0-5]$/.test(e.key)) { e.preventDefault(); setRating(s.closest("tr"), +e.key); return; } // 1 to 5, and 0 to clear
    const to = { ArrowRight: n + 1, ArrowUp: n + 1, ArrowLeft: n - 1, ArrowDown: n - 1, Home: 1, End: 5 }[e.key];
    if (!to) return;
    e.preventDefault();
    const target = group.children[Math.min(5, Math.max(1, to)) - 1];
    group.querySelectorAll(".star").forEach((b) => { b.tabIndex = b === target ? 0 : -1; });
    target.focus();
  });

  if (!table) return;

  // -- Choosing songs and rating several at once
  const bar = $("bulkBar"), selAll = $("selAll"), bulkStars = $("bulkStars");
  const shown = () => rows().filter((tr) => !tr.hidden);
  const selected = () => shown().filter((tr) => tr.querySelector(".sel").checked);
  function syncBulk() {
    const vis = shown(), n = selected().length;
    bar.hidden = n === 0;
    $("bulkCount").textContent = n + " selected";
    selAll.checked = vis.length > 0 && n === vis.length;
    selAll.indeterminate = n > 0 && n < vis.length;
  }
  body.addEventListener("change", (e) => { if (e.target.classList.contains("sel")) syncBulk(); });
  new MutationObserver(syncBulk).observe(body, { childList: true });
  selAll.addEventListener("change", () => { shown().forEach((tr) => { tr.querySelector(".sel").checked = selAll.checked; }); syncBulk(); });
  $("bulkNone").addEventListener("click", () => { rows().forEach((tr) => { tr.querySelector(".sel").checked = false; }); syncBulk(); });
  async function bulkRate(n) {
    const trs = selected();
    if (!trs.length) return;
    say2("Saving…");
    try {
      await send({ action: "rate", ids: trs.map((tr) => track(tr).id), rating: n || "" });
      for (const tr of trs) {
        const t = track(tr);
        t.rating = n || null;
        tr.dataset.track = JSON.stringify(t);
        paintStars(tr.querySelector(".stars"), n);
        tr.querySelector(".c-rating").dataset.sort = String(n || 3);
      }
      applyFilter();
      say2(trs.length + (trs.length === 1 ? " song" : " songs") + (n ? " rated " + plural(n) + "." : " have no rating now."));
    } catch (ex) { say2(ex.message, true); }
  }
  bulkStars.addEventListener("click", (e) => { const s = e.target.closest(".star"); if (s) bulkRate(+s.dataset.stars); });
  $("bulkClear").addEventListener("click", () => bulkRate(0));

  // -- Showing songs by rating
  const rf = $("ratingFilter");
  rf.hidden = false;
  function applyFilter() {
    const v = rf.value;
    let n = 0, total = 0;
    for (const tr of rows()) {
      const r = (track(tr) || {}).rating || 0, show = !v || (v === "none" ? r === 0 : r === +v);
      total++;
      if (show) n++;
      tr.hidden = !show;
      const ed = tr.nextElementSibling;
      if (ed && ed.classList.contains("editing")) ed.hidden = !show;
    }
    if (v) say2("Showing " + n + " of " + total + " songs.");
    syncBulk();
  }
  rf.addEventListener("change", () => { applyFilter(); if (!rf.value) say2(""); });

  // -- Sorting: a click on a heading; again for the other way. Empty values go last either way.
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
  let sortKey = "title", sortDir = 1;
  const valueOf = (tr, key, num) => {
    const c = tr.querySelector('[data-col="' + key + '"]');
    if (!c) return null;
    const v = c.dataset.sort !== undefined ? c.dataset.sort : c.textContent.trim();
    if (!num) return v;
    const x = parseFloat(v);
    return Number.isNaN(x) ? null : x;
  };
  function sortBy(key, num) {
    closeEditor();
    sortDir = key === sortKey ? -sortDir : 1;
    sortKey = key;
    const groups = rows().map((tr) => {
      const ed = tr.nextElementSibling;
      return { tr, ed: ed && ed.classList.contains("editing") ? ed : null, v: valueOf(tr, key, num), t: valueOf(tr, "title", false) };
    });
    groups.sort((a, b) => {
      const ea = a.v === null || a.v === "", eb = b.v === null || b.v === "";
      if (ea || eb) return ea === eb ? collator.compare(a.t, b.t) : (ea ? 1 : -1);
      const d = num ? a.v - b.v : collator.compare(a.v, b.v);
      return d ? d * sortDir : collator.compare(a.t, b.t);
    });
    for (const g of groups) { body.append(g.tr); if (g.ed) body.append(g.ed); }
    table.querySelectorAll("th[aria-sort]").forEach((th) => th.removeAttribute("aria-sort"));
    table.querySelector('th[data-col="' + key + '"]').setAttribute("aria-sort", sortDir === 1 ? "ascending" : "descending");
  }
  table.tHead.addEventListener("click", (e) => {
    const b = e.target.closest(".sortb");
    if (b) sortBy(b.dataset.key, b.dataset.type === "num");
  });

  // -- Columns to show, and compact rows (kept in this browser)
  const menu = $("colMenu"), checks = [...menu.querySelectorAll("input[data-col]")], dense = $("denseToggle");
  menu.hidden = false;
  try {
    const list = JSON.parse(store.get("sw.library.cols"));
    if (Array.isArray(list)) {
      const known = list.filter((k) => checks.some((c) => c.dataset.col === k));
      table.dataset.hidden = known.join(" ");
      checks.forEach((c) => { c.checked = !known.includes(c.dataset.col); });
    }
  } catch (_) { /* nothing kept yet: the page's own choice */ }
  checks.forEach((c) => c.addEventListener("change", () => {
    const hidden = checks.filter((x) => !x.checked).map((x) => x.dataset.col);
    table.dataset.hidden = hidden.join(" ");
    store.set("sw.library.cols", JSON.stringify(hidden));
  }));
  dense.checked = store.get("sw.library.dense") === "1";
  table.classList.toggle("dense", dense.checked);
  dense.addEventListener("change", () => { table.classList.toggle("dense", dense.checked); store.set("sw.library.dense", dense.checked ? "1" : "0"); });
  document.addEventListener("click", (e) => { if (menu.open && !menu.contains(e.target)) menu.open = false; });
  menu.addEventListener("keydown", (e) => { if (e.key === "Escape") { menu.open = false; menu.querySelector("summary").focus(); } });

  // ---- The song details window (round 45; round 56: the upload window too) -----------------------
  // For a song in the library it is the copyright and licence window (Save / Clear all / Cancel). Before
  // an upload (upload.js, through SwSongDetails.ask) it also has the album art, title, artist and album,
  // and Add song / Skip this song / Cancel the rest. A missing songwriter, licence type or publisher is
  // pointed out (radio returns ask for them) but never stops a save (the user's choice).
  const dlg = $("rightsDlg"), form = $("rightsForm"), notes = $("rtNotes"), linkBar = $("rtLink"), url = $("rtUrl"), rmsg = $("rightsMsg");
  if (!dlg || typeof dlg.showModal !== "function") return;
  let current = null, after = null, asking = null;
  const FIELDS = ["owner", "year", "licence", "publisher", "writers", "track", "label", "isrc", "pline", "lyricists", "iswc",
    "licence_ref", "licence_url", "licence_until", "terms"];
  const say = (text, bad) => { rmsg.textContent = text || ""; rmsg.classList.toggle("bad", !!bad); };
  const WANTED = [["writers", "songwriter"], ["licence", "licence type"], ["publisher", "publisher"]];
  function warn() {
    const missing = WANTED.filter(([k]) => !form.elements[k].value.trim()).map(([, w]) => w);
    const w = $("sdWarn");
    w.hidden = !missing.length;
    w.textContent = missing.length ? "No " + (missing.length > 1 ? missing.slice(0, -1).join(", ") + " or " + missing[missing.length - 1] : missing[0])
      + " yet: radio returns ask for " + (missing.length > 1 ? "them" : "it") + ". You can still " + (asking ? "add the song" : "save") + " and fill " + (missing.length > 1 ? "them" : "it") + " in later." : "";
  }
  form.addEventListener("input", (e) => { if (e.target.name) { e.target.dataset.touched = "1"; delete e.target.dataset.fromName; } if (WANTED.some(([k]) => k === e.target.name)) warn(); });
  const mode = (upload) => {
    $("sdTop").hidden = !upload; $("sdBtns").hidden = !upload; $("rightsBtns").hidden = upload;
    $("lkAlbumWrap").hidden = upload; // a song in the library: the album is here (an upload has it in its top part)
    if (window.SwLookup) window.SwLookup.reset();
    $("rightsTitle").textContent = upload ? "Before it’s added" : "Copyright and licence";
  };
  const fill = (r) => {
    for (const k of FIELDS) { form.elements[k].value = r[k] || ""; delete form.elements[k].dataset.touched; }
    notes.innerHTML = r.notes || "";
    linkBar.hidden = true; say(""); warn();
  };

  // Look up details (assets/lookup.js): what the song is, and how to read and write the window's fields
  const lkField = (name) => (name === "album" ? (asking ? form.elements.album : $("lkAlbumIn")) : form.elements[name]);
  if (window.SwLookup) window.SwLookup.init({
    action, csrf,
    query: () => {
      if (asking) return { title: form.elements.title.value, artist: form.elements.artist.value, isrc: form.elements.isrc.value, seconds: null };
      const t = track(current) || {};
      return { title: t.title, artist: t.artist, isrc: form.elements.isrc.value, seconds: t.duration || null };
    },
    get: (name) => (lkField(name) ? lkField(name).value : ""),
    set: (name, value) => { const f = lkField(name); if (f) { f.value = value; f.dataset.touched = "1"; } },
    addNote: (text) => { if (notes.textContent.trim()) insertPlain("\n"); insertPlain(text); },
    done: () => warn(),
    hasArt: () => (asking ? (art.how === "new" || (art.how === "keep" && !!fileArt)) : !!(track(current) || {}).art),
    useCover: async (file) => {
      if (asking) { art = Object.assign(art, { how: "new", file }); showArt(file); return; }
      const t = track(current), j = await send({ action: "art", id: t.id }, file);
      Object.assign(t, j.track);
      applyRow(current, j.track, j.line);
    },
  });

  function openRights(tr, done) {
    if (asking) return;
    current = tr; after = done;
    const t = track(tr), r = t.rights && !Array.isArray(t.rights) ? t.rights : {};
    mode(false);
    $("rightsSong").textContent = t.title + (t.artist ? " · " + t.artist : "");
    fill(r);
    $("lkAlbumIn").value = t.album || "";
    dlg.showModal();
    form.elements.owner.focus({ preventScroll: true });
  }
  body.addEventListener("click", (e) => {
    const b = e.target.closest(".pill.rights");
    if (b) openRights(b.closest("tr"), null);
  });
  $("rightsCancel").addEventListener("click", () => dlg.close());

  const collect = (clear) => {
    const r = {};
    for (const k of FIELDS) r[k] = clear ? "" : form.elements[k].value;
    r.notes = clear ? "" : notes.innerHTML;
    return r;
  };
  async function saveRights(clear) {
    const t = track(current);
    const fields = Object.assign({ action: "rights", id: t.id }, collect(clear));
    say("Saving…");
    try {
      let j = await send(fields);
      // the album (filled in by Look up details, or typed here) is saved with the song's other details
      const album = $("lkAlbumIn").value.trim();
      if (!clear && album !== (t.album || "")) j = await send({ action: "update", id: t.id, title: t.title, artist: t.artist, album });
      applyRow(current, j.track, j.line);
      if (after) after(j.track, j.line);
      dlg.close();
    } catch (ex) { say(ex.message, true); }
  }
  form.addEventListener("submit", (e) => { e.preventDefault(); if (asking) finish("add"); else saveRights(false); });
  $("rightsClear").addEventListener("click", () => { if (confirm("Clear all copyright details from this song?")) saveRights(true); });

  // ---- Before an upload ----------------------------------------------------------------------------
  // The file's own tags fill the window first (an MP3's ID3 tag at once; another format's tags once the
  // browser has decoded it); a field you've typed in is never overwritten. The art is the file's own
  // ("keep"), one you chose ("new") or none ("remove").
  let art = { how: "keep", file: null, url: null };
  const showArt = (blob) => {
    const pic = $("sdPic");
    if (art.url) { URL.revokeObjectURL(art.url); art.url = null; }
    pic.textContent = "";
    if (blob) { art.url = URL.createObjectURL(blob); const i = new Image(); i.alt = "Album art"; i.src = art.url; pic.appendChild(i); }
    else pic.textContent = "No art";
    $("sdUnpick").hidden = !blob;
  };
  let fileArt = null; // the picture in the file's own tag
  $("sdPick").addEventListener("click", () => $("sdFile").click());
  $("sdFile").addEventListener("change", () => {
    const f = $("sdFile").files[0];
    $("sdFile").value = "";
    if (!f) return;
    if (!/^image\/(jpeg|png)$/.test(f.type) || f.size > 3 * 1024 * 1024) { say("The album art must be a JPEG or PNG picture of up to 3 MB.", true); return; }
    say("");
    art = Object.assign(art, { how: "new", file: f });
    showArt(f);
  });
  $("sdUnpick").addEventListener("click", () => { art = Object.assign(art, { how: "remove", file: null }); showArt(null); });

  /** Fill empty, untouched fields from a file's tags ({title, artist, album, year, track, writers, lyricists, publisher, copyright, isrc, label}). */
  function fromTags(tags, picture) {
    // a title made from the file's name gives way to the file's own (it can arrive later, once decoded)
    const set = (k, v) => {
      const el = form.elements[k];
      if (v && el && !el.dataset.touched && (!el.value.trim() || el.dataset.fromName)) { el.value = String(v).trim(); delete el.dataset.fromName; }
    };
    if (tags) {
      set("title", tags.title); set("artist", tags.artist); set("album", tags.album);
      const c = String(tags.copyright || "").trim();
      set("year", tags.year || (c.match(/\d{4}/) || [])[0]);
      set("owner", c.replace(/^\s*(©|\(c\)|copyright)\s*/i, "").replace(/^(©|\(c\))?\s*\d{4}\s*/i, "").trim());
      for (const k of ["track", "writers", "lyricists", "publisher", "isrc", "label"]) set(k, tags[k]);
    }
    if (picture && art.how === "keep" && !fileArt) { fileArt = picture; showArt(picture); }
    warn();
  }

  let settle = null;
  function finish(action) {
    if (!asking) return;
    if (action === "add") {
      const title = form.elements.title.value.trim();
      if (!title) { say("A song needs a title.", true); form.elements.title.focus(); return; }
    }
    const out = { action, title: form.elements.title.value.trim(), artist: form.elements.artist.value.trim(), album: form.elements.album.value.trim(),
      rights: collect(false), art: art.how, artFile: art.how === "new" ? art.file : null };
    asking = null;
    dlg.close();
    showArt(null);
    settle(out);
  }
  $("sdSkip").addEventListener("click", () => finish("skip"));
  $("sdStop").addEventListener("click", () => finish("stop"));
  dlg.addEventListener("cancel", (e) => { if (asking) { e.preventDefault(); finish("skip"); } }); // Esc skips this song

  /**
   * Ask about one song before it's uploaded. Returns { status(text), tags(tags, picture), done }:
   * `done` gives { action: "add" | "skip" | "stop", title, artist, album, rights, art: "keep" | "new" | "remove", artFile }.
   */
  function ask(name, n, of, tags, picture) {
    asking = name; fileArt = null; art = { how: "keep", file: null, url: null };
    mode(true);
    $("rightsSong").textContent = name + (of > 1 ? " · song " + n + " of " + of : "");
    $("sdStop").hidden = of <= 1 || n >= of;
    for (const k of ["title", "artist", "album"]) { form.elements[k].value = ""; delete form.elements[k].dataset.touched; delete form.elements[k].dataset.fromName; }
    fill({});
    showArt(null);
    fromTags(tags, picture);
    if (!form.elements.title.value) { form.elements.title.value = name.replace(/\.[^.]+$/, "").replace(/[_]+/g, " ").trim(); form.elements.title.dataset.fromName = "1"; }
    const done = new Promise((ok) => { settle = ok; });
    dlg.showModal();
    form.elements.title.focus({ preventScroll: true });
    return { status: (text) => { $("sdPrep").textContent = text; }, tags: (t, p) => { if (asking === name) fromTags(t, p); }, done };
  }
  window.SwSongDetails = { ask };

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
