// "From the presenter" beside the player: the live show's notes, or the last show's (up to a week
// old). Fetched from api/notes.php on load and whenever the timeline's notes_rev changes (the
// player's clock fetch sends a "sw:timeline" event). A note shows once listeners, DELAY seconds
// behind, reach the moment it was posted, so it never runs ahead of what they hear. Notes that
// arrive during a show get a "New" tag and a short toast. Everything is built as text, not HTML.
//   window.SwNotes.state() -> { rev, show, shown: [ids], held: [ids] }   (for tests)
(() => {
  const root = document.getElementById("player"), box = document.getElementById("lpNotes");
  if (!root || !box) return;
  const $ = (id) => document.getElementById(id);
  const BASE = new URL(root.dataset.base, location.href).href, DELAY = Number(root.dataset.delay) || 0;
  const KIND = { verse: "Verse", refs: "References", video: "Video", note: "Note" };
  const ICON = {
    verse: "M1 3.5C3 2.6 5.5 2.6 7.5 3.6V13c-2-1-4.5-1-6.5-.1zM8.5 3.6c2-1 4.5-1 6.5-.1v9.4c-2-.9-4.5-.9-6.5.1z",
    refs: "M2 3h2v2H2zM6 3.25h8v1.5H6zM2 7h2v2H2zM6 7.25h8v1.5H6zM2 11h2v2H2zM6 11.25h8v1.5H6z",
    video: "M5 3l8 5-8 5z",
    note: "M2 2h12v9H7l-3.5 3v-3H2z",
  };
  let data = null, rev = null, loading = false, shownKey = "", seen = null, fresh = new Set();

  const heard = () => {
    const now = window.SwPlayer && SwPlayer.serverNow ? SwPlayer.serverNow() : null;
    return (now !== null ? now : Date.now() / 1000) - DELAY;
  };
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
  const link = (href, text, cls) => { const a = el("a", cls, text); a.href = href; a.target = "_blank"; a.rel = "noopener"; return a; };

  async function load() {
    if (loading) return;
    loading = true;
    try {
      const r = await fetch(BASE + "api/notes.php", { cache: "no-store" });
      if (r.ok) { data = await r.json(); rev = data.rev; render(); }
    } catch (_) { /* offline: try again on the next change */ } finally { loading = false; }
  }

  function render() {
    if (!data) return;
    const h = heard();
    // a note edited in the studio comes as a new note; its old version shows until listeners reach the edit
    const reached = new Set(data.items.filter((n) => n.posted <= h).map((n) => n.id));
    const items = data.show ? data.items.filter((n) => n.posted <= h && !(n.replaced_by && reached.has(n.replaced_by))) : [];
    const key = rev + "|" + items.map((n) => n.id).join(",");
    if (key === shownKey) return;
    shownKey = key;
    // notes already visible when the page first shows the panel are not "new"; any that turn up
    // later (posted since, or held back until listeners reached them) are, during a live show
    if (seen === null) seen = new Set(items.map((n) => n.id));
    box.hidden = items.length === 0;
    if (!items.length) return;
    const live = data.show.live;
    const arrived = items.filter((n) => !seen.has(n.id));
    arrived.forEach((n) => { seen.add(n.id); if (live) fresh.add(n.id); });
    if (!live) fresh.clear();
    $("lpNotesTag").textContent = data.show.label;
    $("lpNotesTag").className = "tag" + (live ? " live" : "");
    $("lpNotesBy").textContent = data.show.by;
    $("lpNotesList").replaceChildren(...items.map(note));
    if (live && arrived.length) toast(arrived.length === 1 ? "New note from " + (data.show.dj || "the presenter") : arrived.length + " new notes from " + (data.show.dj || "the presenter"));
  }

  function note(n) {
    const li = el("li", "lp-pnote" + (fresh.has(n.id) ? " fresh" : ""));
    li.dataset.id = n.id;
    const ico = el("span", "ico");
    ico.setAttribute("aria-hidden", "true");
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg"), path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    svg.setAttribute("viewBox", "0 0 16 16"); path.setAttribute("d", ICON[n.kind] || ICON.note); svg.append(path); ico.append(svg);
    const nb = el("div", "nb"), kind = el("div", "kind", (KIND[n.kind] || "Note") + " · ");
    kind.append(el("time", "", n.at));
    if (fresh.has(n.id)) kind.append(el("span", "newtag", "New"));
    nb.append(kind);
    if (n.kind === "verse") {
      const ref = el("div", "ref", n.ref);
      ref.append(el("em", "", n.version));
      const quote = el("blockquote", "", n.text), acts = el("div", "acts"), copy = el("button", "btn sm", "Copy verse");
      copy.type = "button";
      copy.addEventListener("click", () => copyText(`${n.ref} (${n.version}) ${n.text}`, quote));
      acts.append(link(n.read, "Read " + n.passage.replace(/-/g, "–") + " ↗"), copy);
      nb.append(ref, quote, acts);
    } else if (n.kind === "refs") {
      if (n.text) nb.append(el("p", "txt", n.text));
      const refs = el("div", "refs");
      n.refs.forEach((r) => refs.append(link(r.url, r.label)));
      nb.append(refs);
    } else if (n.kind === "video") {
      const acts = el("div", "acts");
      acts.append(link(n.url, "Open video ↗"));
      nb.append(el("p", "txt", n.text), acts);
    } else {
      nb.append(el("p", "txt", n.text));
    }
    li.append(ico, nb);
    return li;
  }

  function copyText(text, from) {
    const select = () => {
      const r = document.createRange(); r.selectNodeContents(from);
      const s = getSelection(); s.removeAllRanges(); s.addRange(r);
      toast("Selected. Press Ctrl+C to copy.");
    };
    try { navigator.clipboard.writeText(text).then(() => toast("Verse copied"), select); } catch (_) { select(); }
  }

  function toast(text) {
    const t = $("lpToast");
    if (!t) return;
    t.textContent = text; t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => (t.hidden = true), 2600);
  }

  document.addEventListener("sw:timeline", (e) => { const r = e.detail && e.detail.notes_rev; if (r && r !== rev) load(); });
  setInterval(render, 1000); // notes held back for the delay appear on time
  load();
  window.SwNotes = {
    state: () => ({
      rev, show: data && data.show,
      shown: [...$("lpNotesList").children].map((li) => Number(li.dataset.id)),
      held: data && data.show ? data.items.filter((n) => n.posted > heard()).map((n) => n.id) : [],
      fresh: [...fresh], hidden: box.hidden,
    }),
  };
})();
