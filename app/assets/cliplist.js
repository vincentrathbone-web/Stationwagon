// The Ads and the Jingles lists, behaving like the Music library's table (round 72): sorting by a click on a heading, columns to
// show and compact rows (both kept in this browser only), showing by state, and choosing several to turn on or off at once (the
// form posts the ids). The Music library's own code is library.js; this is the same for the two other lists. Without JavaScript
// the table still shows everything, in title order, and a bulk change still works (the ticked rows are posted with the form).
// Round 82: Edit, Remove and the bulk change work in place, like the Music library's (library.js): they talk to the server in the
// background and change only that row, so the page never reloads or jumps and your place stays. Without JavaScript the same links
// and forms still work (the page reloads then).
(() => {
  const table = document.querySelector("table.lib.ads, table.lib.jingles");
  const bar = document.getElementById("bulkBar");
  if (!table || !table.tBodies.length || !bar) return;
  const body = table.tBodies[0];
  const $ = (id) => document.getElementById(id);
  const menu = $("colMenu"), noun = table.classList.contains("ads") ? "ad" : "jingle";
  const key = (k) => "sw." + menu.dataset.store + "." + k;
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (_) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (_) { /* a private window: the choice just isn't kept */ } },
  };
  const note = $("clipNote");
  const say = (text) => { note.textContent = text || ""; };
  const rows = () => [...body.querySelectorAll("tr.clip")];
  const shown = () => rows().filter((tr) => !tr.hidden);
  const selected = () => shown().filter((tr) => tr.querySelector(".sel").checked);

  // -- Choosing several
  const selAll = $("selAll");
  function syncBulk() {
    const vis = shown(), sel = selected();
    bar.hidden = sel.length === 0;
    $("bulkCount").textContent = sel.length + " selected";
    selAll.checked = vis.length > 0 && sel.length === vis.length;
    selAll.indeterminate = sel.length > 0 && sel.length < vis.length;
    const ids = $("bulkIds");
    ids.textContent = "";
    for (const tr of sel) {
      const i = document.createElement("input");
      i.type = "hidden"; i.name = "ids[]"; i.value = tr.querySelector(".sel").value;
      ids.append(i);
    }
  }
  body.addEventListener("change", (e) => { if (e.target.classList.contains("sel")) syncBulk(); });
  selAll.addEventListener("change", () => { shown().forEach((tr) => { tr.querySelector(".sel").checked = selAll.checked; }); syncBulk(); });
  $("bulkNone").addEventListener("click", () => { rows().forEach((tr) => { tr.querySelector(".sel").checked = false; }); syncBulk(); });

  // -- Showing by state (running or on / paused, off or ended)
  const filter = $("clipFilter");
  filter.hidden = false;
  function applyFilter() {
    const v = filter.value;
    let n = 0, total = 0;
    for (const tr of rows()) {
      const show = !v || tr.dataset.state === v;
      total++;
      if (show) n++;
      tr.hidden = !show;
      const ed = tr.nextElementSibling;
      if (ed && ed.classList.contains("editing")) ed.hidden = !show;
    }
    say(v ? "Showing " + n + " of " + total + " " + noun + "s." : "");
    syncBulk();
  }
  filter.addEventListener("change", applyFilter);

  // -- Sorting: a click on a heading; again for the other way. Empty values go last either way.
  const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
  let sortKey = "title", sortDir = 1;
  const valueOf = (tr, k, num) => {
    const c = tr.querySelector('[data-col="' + k + '"]');
    if (!c) return null;
    const v = c.dataset.sort !== undefined ? c.dataset.sort : c.textContent.trim();
    if (!num) return v;
    const x = parseFloat(v);
    return Number.isNaN(x) ? null : x;
  };
  function sortBy(k, num) {
    sortDir = k === sortKey ? -sortDir : 1;
    sortKey = k;
    const groups = rows().map((tr) => {
      const ed = tr.nextElementSibling;
      return { tr, ed: ed && ed.classList.contains("editing") ? ed : null, v: valueOf(tr, k, num), t: valueOf(tr, "title", false) };
    });
    groups.sort((a, b) => {
      const ea = a.v === null || a.v === "", eb = b.v === null || b.v === "";
      if (ea || eb) return ea === eb ? collator.compare(a.t, b.t) : (ea ? 1 : -1);
      const d = num ? a.v - b.v : collator.compare(a.v, b.v);
      return d ? d * sortDir : collator.compare(a.t, b.t);
    });
    for (const g of groups) { body.append(g.tr); if (g.ed) body.append(g.ed); }
    table.querySelectorAll("th[aria-sort]").forEach((th) => th.removeAttribute("aria-sort"));
    table.querySelector('th[data-col="' + k + '"]').setAttribute("aria-sort", sortDir === 1 ? "ascending" : "descending");
  }
  table.tHead.addEventListener("click", (e) => {
    const b = e.target.closest(".sortb");
    if (b) sortBy(b.dataset.key, b.dataset.type === "num");
  });

  // -- Columns to show, and compact rows (kept in this browser)
  const checks = [...menu.querySelectorAll("input[data-col]")], dense = $("denseToggle");
  menu.hidden = false;
  const hide = (list) => {
    table.dataset.hidden = list.join(" ");
    checks.forEach((c) => { c.checked = !list.includes(c.dataset.col); });
    table.querySelectorAll("[data-col]").forEach((c) => { c.hidden = list.includes(c.dataset.col); });
  };
  /** A row fresh from the server: the columns this browser hides stay hidden, and so does what the state filter hides. */
  const dress = (tr) => {
    const list = (table.dataset.hidden || "").split(" ").filter(Boolean);
    tr.querySelectorAll("[data-col]").forEach((c) => { c.hidden = list.includes(c.dataset.col); });
    tr.hidden = !!filter.value && tr.dataset.state !== filter.value;
  };
  let start = (table.dataset.hidden || "").split(" ").filter(Boolean);
  try {
    const list = JSON.parse(store.get(key("cols")));
    if (Array.isArray(list)) start = list.filter((k) => checks.some((c) => c.dataset.col === k));
  } catch (_) { /* nothing kept yet: the page's own choice */ }
  hide(start);
  checks.forEach((c) => c.addEventListener("change", () => {
    const hidden = checks.filter((x) => !x.checked).map((x) => x.dataset.col);
    hide(hidden);
    store.set(key("cols"), JSON.stringify(hidden));
  }));
  dense.checked = store.get(key("dense")) === "1";
  table.classList.toggle("dense", dense.checked);
  dense.addEventListener("change", () => { table.classList.toggle("dense", dense.checked); store.set(key("dense"), dense.checked ? "1" : "0"); });
  document.addEventListener("click", (e) => { if (menu.open && !menu.contains(e.target)) menu.open = false; });
  menu.addEventListener("keydown", (e) => { if (e.key === "Escape") { menu.open = false; menu.querySelector("summary").focus(); } });
  syncBulk();

  // -- Edit, Remove and the bulk change, in place (round 82)
  if (!window.fetch || !window.FormData || !window.DOMParser) return;
  const route = bar.getAttribute("action"), csrf = bar.querySelector('[name="csrf"]').value;
  const prefix = noun === "ad" ? "a" : "j";
  let open = null; // the editor row now open

  const send = async (data) => {
    data.set("csrf", csrf);
    let r, j;
    try {
      r = await fetch(route, { method: "POST", body: data, headers: { Accept: "application/json" }, credentials: "same-origin" });
      j = await r.json();
    } catch (_) { throw new Error("The connection dropped. Try again."); }
    if (!r.ok || j.ok === false) throw new Error(j.error || "Not saved (" + r.status + ").");
    return j;
  };
  const form = (fields) => { const d = new FormData(); for (const [k, v] of Object.entries(fields)) d.append(k, v); return d; };
  /** A page of the screen from the server, as a document (the rows as they are now, or one row with its editor). */
  const page = async (url) => {
    const r = await fetch(url, { credentials: "same-origin", headers: { Accept: "text/html" } });
    if (!r.ok) throw new Error("Couldn't read the list (" + r.status + ").");
    return new DOMParser().parseFromString(await r.text(), "text/html");
  };
  const listUrl = () => { const u = new URL(route, location.href); const q = new URLSearchParams(location.search).get("q"); if (q) u.searchParams.set("q", q); return u.toString(); };
  /** Puts the server's current version of these rows in place of the ones shown; the rest of the page is not touched. */
  async function refresh(ids) {
    const doc = await page(listUrl());
    for (const id of ids) {
      const old = $(prefix + id), fresh = doc.getElementById(prefix + id);
      if (!old || !fresh) continue;
      const row = document.importNode(fresh, true);
      old.replaceWith(row);
      dress(row);
      row.classList.remove("saved"); void row.offsetWidth; row.classList.add("saved");
    }
    syncBulk();
  }
  const closeEditor = () => { if (open) { open.remove(); open = null; } };
  const rowOf = (el) => el.closest("tr.clip");
  const idOf = (tr) => tr.id.slice(1);

  async function openEditor(tr, href) {
    closeEditor();
    say("");
    let doc;
    try { doc = await page(href); } catch (ex) { say(ex.message); return; }
    const src = doc.querySelector("tr.editing");
    if (!src) { say("That " + noun + " isn\u2019t here any more."); return; }
    const ed = document.importNode(src, true);
    tr.after(ed);
    open = ed;
    const f = ed.querySelector("form"), msg = document.createElement("p");
    msg.className = "msg"; msg.setAttribute("role", "status");
    f.prepend(msg);
    const say2 = (t, bad) => { msg.textContent = t || ""; msg.classList.toggle("bad", !!bad); };
    f.addEventListener("submit", async (e) => {
      e.preventDefault();
      say2("Saving\u2026");
      try {
        await send(new FormData(f));
        closeEditor();
        await refresh([idOf(tr)]);
        say("Saved.");
      } catch (ex) { say2(ex.message, true); }
    });
    const cancel = f.querySelector(".editbtns a.btn");
    if (cancel) cancel.addEventListener("click", (e) => { e.preventDefault(); closeEditor(); const a = tr.querySelector("td.acts a"); if (a) a.focus({ preventScroll: true }); });
    f.addEventListener("keydown", (e) => { if (e.key === "Escape") closeEditor(); });
    const first = f.querySelector("input[name=title]");
    if (first) first.focus({ preventScroll: true });
  }

  function askRemove(tr) {
    const cell = tr.querySelector("td.acts");
    const keep = [...cell.childNodes];
    cell.textContent = "";
    const q = document.createElement("span"); q.className = "warn2"; q.textContent = "Remove this " + noun + "?";
    const yes = document.createElement("button"); yes.type = "button"; yes.className = "btn sm danger"; yes.textContent = "Yes, remove";
    const no = document.createElement("button"); no.type = "button"; no.className = "btn sm"; no.textContent = "Keep";
    cell.append(q, yes, no);
    no.addEventListener("click", () => { cell.textContent = ""; cell.append(...keep); });
    yes.addEventListener("click", async () => {
      yes.disabled = true;
      try {
        await send(form({ action: "delete", id: idOf(tr) }));
        if (open && open.previousElementSibling === tr) closeEditor();
        tr.remove();
        if (!rows().length) { // the empty list's own message, as the server writes it
          const e = document.createElement("tr"), c = document.createElement("td");
          e.className = "empty"; c.colSpan = table.tHead.rows[0].cells.length; c.className = "muted2";
          c.textContent = noun === "ad" ? "No ads yet. Add some above, then say how often each one plays." : "No jingles yet. Add some above.";
          e.append(c); body.append(e);
        }
        syncBulk();
        say("Removed.");
      } catch (ex) { yes.disabled = false; say(ex.message); }
    });
    yes.focus({ preventScroll: true });
  }

  body.addEventListener("click", (e) => {
    const a = e.target.closest("td.acts a[href]");
    const tr = a && rowOf(a);
    if (!tr || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    if (/[?&]edit=\d+/.test(a.href)) { e.preventDefault(); if (open && open.previousElementSibling === tr) closeEditor(); else openEditor(tr, a.href); }
    else if (/[?&]confirm=\d+/.test(a.href)) { e.preventDefault(); askRemove(tr); }
  });

  // the bulk change (pause or resume, turn on or off): only the chosen rows change
  bar.addEventListener("submit", async (e) => {
    e.preventDefault();
    const ids = selected().map((tr) => idOf(tr));
    if (!ids.length) { say("Choose at least one " + noun + " first."); return; }
    const data = new FormData(bar, e.submitter);
    try {
      await send(data);
      await refresh(ids);
      rows().forEach((tr) => { tr.querySelector(".sel").checked = false; });
      syncBulk();
      say(ids.length + (ids.length === 1 ? " " + noun + " changed." : " " + noun + "s changed."));
    } catch (ex) { say(ex.message); }
  });
})();
