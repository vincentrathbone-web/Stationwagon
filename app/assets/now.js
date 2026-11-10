// The small Now playing card other websites frame (views/now.php, round 61): what listeners hear now,
// from api/nowplaying.php (the shared answer, cached for 2 s at Cloudflare), with its album art and a
// progress line that moves between asks on the server's clock. No sound: the card links to the player.
(() => {
  const root = document.getElementById("now");
  if (!root) return;
  const BASE = root.dataset.base;
  const $ = (id) => document.getElementById(id);
  const POLL_MS = 15000;
  let np = null, offset = 0, polledAt = 0, timer = 0, artShown;

  async function poll() {
    clearTimeout(timer); polledAt = Date.now();
    try {
      const sent = Date.now() / 1000;
      const r = await fetch(BASE + "api/nowplaying.php", { cache: "no-store" });
      if (!r.ok) throw new Error(String(r.status));
      np = await r.json();
      offset = np.server_now - (sent + Date.now() / 1000) / 2; // a cached answer may be 2 s old: plenty for a progress line
      show();
    } catch (_) {
      if (!np) $("nwTitle").textContent = "The station can’t be reached just now";
    }
    timer = setTimeout(poll, POLL_MS);
  }

  function show() {
    const air = np && np.on_air, live = !!(air && air.type === "live");
    root.classList.toggle("live", live);
    $("nwMode").textContent = live ? "Live" : "Now playing";
    $("nwTitle").textContent = !air ? "Off air" : air.title;
    $("nwArtist").textContent = !air ? "" : live ? (air.dj || "") : (air.artist || "");
    const art = air && !live && air.art ? BASE + air.art : null;
    if (art !== artShown) {
      artShown = art;
      $("nwArtImg").hidden = !art;
      if (art) $("nwArtImg").src = art; else $("nwArtImg").removeAttribute("src");
      $("nwArt").classList.toggle("has-art", !!art);
    }
    $("nwLink").setAttribute("aria-label", !air ? "Listen" : `Listen: ${air.title}${!live && air.artist ? ", " + air.artist : ""}`);
    tick();
  }

  function tick() {
    const air = np && np.on_air;
    if (!air) { $("nwFill").style.width = "0"; return; }
    const heard = Date.now() / 1000 + offset - (np.delay || 20);
    if (air.type === "song" && heard >= air.ends && Date.now() - polledAt > 3000) { poll(); return; } // the next song has started
    $("nwFill").style.width = air.type === "song" && air.duration > 0 ? `${Math.max(0, Math.min(100, ((heard - air.started) / air.duration) * 100))}%` : "100%";
  }
  setInterval(tick, 1000);
  // for the tests
  window.swNow = { get np() { return np; }, poll: () => poll() };
  poll();
})();
