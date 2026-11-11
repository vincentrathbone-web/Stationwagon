// The Ads and the Jingles lists, behaving like the Music library's table (round 72): sorting by a click on a heading, columns to
// show and compact rows (both kept in this browser only), showing by state, and choosing several to turn on or off at once (the
// form posts the ids). The Music library's own code is library.js; this is the same for the two other lists. Without JavaScript
// the table still shows everything, in title order, and a bulk change still works (the ticked rows are posted with the form).
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
})();
