// The look (skin) picker (src/Skins.php). The choice is kept on this device (localStorage "sw.skin"),
// so every screen of the station follows it, and tabs already open switch at once. Canvases read
// their colours with SwSkin.tok() and redraw on the "sw:skin" event.
(() => {
  const KEY = "sw.skin", root = document.documentElement;
  const picks = () => [...document.querySelectorAll("[data-skin-pick]")];
  const first = picks()[0];
  const SKINS = first ? JSON.parse(first.dataset.skins) : { green: "Green" };
  const IDS = Object.keys(SKINS), DEFAULT = IDS[0];
  let cache = new Map();

  const current = () => root.getAttribute("data-skin") || DEFAULT;
  /** A token's value in the current skin (cached until the skin changes). */
  function tok(name) {
    if (!cache.has(name)) cache.set(name, getComputedStyle(root).getPropertyValue(name).trim());
    return cache.get(name);
  }

  function show() {
    const id = current();
    for (const el of picks()) {
      if (el.tagName === "SELECT") el.value = id;
      else { el.textContent = `Look: ${SKINS[id]}`; el.setAttribute("aria-label", `Look: ${SKINS[id]}. Change the look on this device`); }
    }
  }

  function apply(id, save) {
    if (!IDS.includes(id)) id = DEFAULT;
    if (id === DEFAULT) root.removeAttribute("data-skin"); else root.setAttribute("data-skin", id);
    if (save) { try { localStorage.setItem(KEY, id); } catch (_) { /* a private window: this page only */ } }
    cache = new Map();
    show();
    window.dispatchEvent(new CustomEvent("sw:skin", { detail: id }));
  }

  for (const el of picks()) {
    if (el.tagName === "SELECT") el.addEventListener("change", () => apply(el.value, true));
    else el.addEventListener("click", () => apply(IDS[(IDS.indexOf(current()) + 1) % IDS.length], true));
  }
  // another tab (or screen) of this station changed the look
  window.addEventListener("storage", (e) => { if (e.key === KEY) apply(e.newValue || DEFAULT, false); });
  show();

  window.SwSkin = { current, tok, set: (id) => apply(id, true), skins: SKINS };
})();
