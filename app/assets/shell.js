// The frame page (views/shell.php, round 61): the screen in one frame, the bar in another under it. The
// screen's frame moves between screens; this page never reloads, so the bar plays on with no gap.
// - The address bar and the title follow the screen (Back and Forward move the screen's frame, which loads
//   and is followed again); reloading the page opens the same screen.
// - A link or a redirect to a page that isn't a screen (the station page, the podcast page, Privacy,
//   signing in or out) opens in the whole window instead.
// - Each new screen is told to the bar, which mutes itself in the studio and Song prep.
// - In the Windows or Android app (an older one, before its own bar area) this page isn't wanted: it asks
//   for the screens without it from then on (the sw_bare cookie) and opens the screen itself.
(() => {
  const BASE = new URL(document.body.dataset.base, location.href);
  const screen = document.getElementById("swScreen"), bar = document.getElementById("swBar");

  if (window.__TAURI__ || window.__TAURI_INTERNALS__ || (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform())) {
    document.cookie = `sw_bare=1; path=${BASE.pathname}; max-age=31536000; samesite=lax`;
    location.replace(location.href);
    return;
  }

  /** The route of an address on this station (no leading slash), or null for another site. */
  const routeOf = (href) => {
    const u = new URL(href, location.href);
    if (u.origin !== BASE.origin || !u.pathname.startsWith(BASE.pathname)) return null;
    return u.pathname.slice(BASE.pathname.length).replace(/\/+$/, "");
  };
  /** A screen that lives in the frame (the same list as App::shellRoute). */
  const isScreen = (r) => r !== null && (r === "studio" || r === "account" || r === "manage" || (r.startsWith("manage/") && r !== "manage/skip" && r !== "manage/songprep/original"));

  let shown = null;
  screen.addEventListener("load", () => {
    let loc, doc;
    try { loc = screen.contentWindow.location.href; doc = screen.contentDocument; } catch (_) { return; } // another site: leave it
    if (!loc || loc === "about:blank") return;
    const r = routeOf(loc);
    if (!isScreen(r)) { location.replace(loc); return; } // not a screen (signed out, the station page…): the whole window
    if (loc !== location.href) history.replaceState(null, "", loc);
    document.title = doc.title || document.title;
    // links out of the screens open in the whole window, not in the frame
    doc.addEventListener("click", (e) => {
      const a = e.target.closest && e.target.closest("a[href]");
      if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      if (a.target && a.target !== "_self") return;
      const to = routeOf(a.href);
      if (to !== null && isScreen(to)) return;
      if (to === null && !a.href.startsWith("http")) return; // mailto:, javascript: …
      e.preventDefault();
      location.href = a.href;
    });
    if (r !== shown) {
      shown = r;
      try { bar.contentWindow.SwBar ? bar.contentWindow.SwBar.screen(r) : bar.contentWindow.postMessage({ sw: "screen", route: r }, location.origin); } catch (_) { /* the bar is still loading: it gets the next */ }
    }
  });
  // the bar's volume slider pops up out of it: its frame grows upwards over the screen while it's up
  window.addEventListener("message", (e) => { if (e.origin === location.origin && e.source === bar.contentWindow && e.data && e.data.sw === "bar-tall") bar.classList.toggle("tall", !!e.data.tall); });
  bar.addEventListener("load", () => { if (shown) try { bar.contentWindow.SwBar && bar.contentWindow.SwBar.screen(shown); } catch (_) { /* gone */ } });
  // for the tests
  window.swShell = { get route() { return shown; }, screen, bar };
})();
