// The small player other websites put in an iframe (views/embed.php): what's on from
// api/nowplaying.php, and Listen plays the station's HLS stream, held at the listener delay like
// the station's own player (so the title shown is what's heard). No crossfading decks here: the
// stream carries the station's mix as it is.
(() => {
  const root = document.getElementById("embed");
  const BASE = root.dataset.base;
  const $ = (id) => document.getElementById(id);
  const POLL_MS = 15000;
  let np = null, offset = 0, audio = null, hls = null, settled = false, timer = 0, polledAt = 0;

  const mmss = (s) => { s = Math.max(0, Math.round(s)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`; };
  const state = (text) => { $("emState").textContent = text; };
  const now = () => Date.now() / 1000 + offset; // the server's clock
  const delay = () => (np ? np.delay : 20);

  async function poll() {
    clearTimeout(timer); polledAt = Date.now();
    try {
      const sent = Date.now() / 1000;
      const r = await fetch(BASE + "api/nowplaying.php", { cache: "no-store" });
      if (!r.ok) throw new Error(String(r.status));
      np = await r.json();
      offset = np.server_now - (sent + Date.now() / 1000) / 2; // halfway through the request
      show();
    } catch (_) {
      if (!np) $("emTitle").textContent = "The station can’t be reached just now";
    }
    timer = setTimeout(poll, POLL_MS);
  }

  function show() {
    const on = np && np.on_air;
    const live = !!(on && on.type === "live");
    root.classList.toggle("live", live);
    $("emMode").textContent = live ? "Live" : "AutoDJ";
    $("emTitle").textContent = !on ? "Off air" : live ? on.title + (on.dj ? ` · ${on.dj}` : "")
      : on.title + (on.artist ? ` · ${on.artist}` : "");
    const next = np && np.next && np.next[0];
    $("emNext").textContent = next && !live ? `Next: ${next.title}` : "";
    tick();
  }

  /** Stream time being heard, from the pieces' PROGRAM-DATE-TIME; null before it plays. */
  function playingDate() {
    if (hls && hls.playingDate) return hls.playingDate.getTime() / 1000;
    if (audio && audio.getStartDate) { const d = audio.getStartDate(); if (d && !isNaN(d.getTime())) return d.getTime() / 1000 + audio.currentTime; }
    return null;
  }

  /** Hold the listener delay: silent and seeking until on time, then up to 3 % faster or slower. */
  function lock() {
    const pd = playingDate();
    if (!audio || audio.paused || pd === null) return;
    const err = now() - pd - delay(); // positive: too far behind
    if (Math.abs(err) < 0.25 && !settled) { settled = true; audio.volume = 1; state("Listening"); }
    if (Math.abs(err) > 2 || (!settled && Math.abs(err) > 0.25)) { audio.currentTime += err; audio.playbackRate = 1; }
    else if (Math.abs(err) > 0.03) audio.playbackRate = 1 + Math.max(-0.03, Math.min(0.03, err * 0.1));
    else audio.playbackRate = 1;
  }

  // the bar and time move between polls on the server's clock, at the listener delay
  function tick() {
    lock();
    const on = np && np.on_air;
    if (!on) return;
    const heard = (settled && playingDate()) || now() - delay();
    if (on.type === "song" && heard >= on.ends && Date.now() - polledAt > 3000) { poll(); return; } // the next song has started
    const elapsed = heard - on.started;
    $("emFill").style.width = on.type === "song" && on.duration > 0 ? `${Math.min(100, (elapsed / on.duration) * 100)}%` : "100%";
    if (settled && audio && !audio.paused) state(on.type === "song" ? `${mmss(elapsed)} / ${mmss(on.duration)}` : `Live for ${mmss(elapsed)}`);
  }
  setInterval(tick, 500);

  async function loadHls() {
    if (window.Hls) return;
    await new Promise((ok, fail) => {
      const s = document.createElement("script");
      s.src = BASE + "assets/vendor/hls.min.js"; s.onload = ok; s.onerror = fail;
      document.head.appendChild(s);
    });
  }

  async function listen() {
    const url = BASE + "api/stream.php";
    // silent until it holds the delay: volume 0, not muted (browsers pause muted media in a background tab)
    audio = new Audio(); audio.volume = 0; settled = false;
    $("emPlay").setAttribute("aria-pressed", "true"); $("emPlay").setAttribute("aria-label", "Stop");
    root.classList.add("playing");
    state("Connecting…");
    try {
      // hls.js first, as the station's player does: some browsers (Edge) say they play HLS
      // themselves but then can't; Safari on an iPhone has no hls.js support and plays it itself
      await loadHls().catch(() => null);
      if (window.Hls && Hls.isSupported()) {
        hls = new Hls({ liveSyncDurationCount: 3, liveMaxLatencyDurationCount: 6 });
        hls.on(Hls.Events.ERROR, (_, d) => { if (d.fatal) (d.type === Hls.ErrorTypes.MEDIA_ERROR ? hls.recoverMediaError() : hls.startLoad()); });
        hls.loadSource(url);
        hls.attachMedia(audio);
      } else if (audio.canPlayType("application/vnd.apple.mpegurl")) {
        audio.src = url;
      } else {
        throw new Error("This browser can’t play the stream.");
      }
      audio.addEventListener("waiting", () => { if (settled) state("Buffering…"); });
      await audio.play();
      tick();
    } catch (ex) {
      stop();
      state(ex && ex.message && ex.name !== "NotAllowedError" ? ex.message : "Press play to listen");
    }
  }

  function stop() {
    if (hls) { hls.destroy(); hls = null; }
    if (audio) { audio.pause(); audio.removeAttribute("src"); audio.load(); audio = null; }
    settled = false;
    $("emPlay").setAttribute("aria-pressed", "false"); $("emPlay").setAttribute("aria-label", "Listen");
    root.classList.remove("playing");
    state("Press play to listen");
  }

  $("emPlay").addEventListener("click", () => (audio ? stop() : listen()));
  // for the tests: whether it plays, is heard, and how far behind the server's clock
  window.swEmbed = {
    get playing() { return !!audio && !audio.paused; },
    get heard() { return settled && !!audio && audio.volume > 0; },
    get lag() { const pd = playingDate(); return pd === null ? null : now() - pd; },
    get np() { return np; },
  };
  poll();
})();
