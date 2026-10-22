// The small player other websites put in an iframe (views/embed.php): what's on from
// api/nowplaying.php, and Listen plays the station's HLS stream, held at the listener delay like
// the station's own player (so the title shown is what's heard). No crossfading decks here: the
// stream carries the station's mix as it is. If something outside pauses it (a phone's power manager
// does after a minute of what it takes for silence behind the lock screen) or the stream fails for good,
// it starts again by itself, still counted as the same listener.
(() => {
  const root = document.getElementById("embed");
  const BASE = root.dataset.base;
  const $ = (id) => document.getElementById(id);
  const POLL_MS = 15000;
  let np = null, offset = 0, audio = null, hls = null, settled = false, timer = 0, polledAt = 0, ping = null, restartedAt = 0;
  let moved = { pos: -1, at: 0 }; // where the sound last moved on, for the watchdog
  // what happened to the sound, for the tests (the last 40 events, seconds since the page loaded)
  const events = [], t0 = Date.now();
  const log = (what) => { events.push(`${((Date.now() - t0) / 1000).toFixed(1)} ${what}${document.hidden ? " (hidden)" : ""}`); if (events.length > 40) events.shift(); };

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
    if (Math.abs(err) > 2 || (!settled && Math.abs(err) > 0.25)) { if (settled) log(`seek ${err.toFixed(2)} s`); audio.currentTime += err; audio.playbackRate = 1; }
    else if (Math.abs(err) > 0.03) audio.playbackRate = 1 + Math.max(-0.03, Math.min(0.03, err * 0.1));
    else audio.playbackRate = 1;
  }

  // the bar and time move between polls on the server's clock, at the listener delay
  function tick() {
    watch();
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

  document.addEventListener("visibilitychange", () => log(document.hidden ? "page hidden" : "page shown"));

  async function listen() {
    $("emPlay").setAttribute("aria-pressed", "true"); $("emPlay").setAttribute("aria-label", "Stop");
    root.classList.add("playing");
    state("Connecting…");
    try {
      await start();
      if (window.SwListeners) ping = SwListeners.start(BASE, "embed"); // counted as listening while it plays
      tick();
    } catch (ex) {
      stop();
      state(ex && ex.message && ex.name !== "NotAllowedError" ? ex.message : "Press play to listen");
    }
  }

  /** The sound itself: a new audio element on the stream, silent until it holds the delay. */
  async function start() {
    const url = BASE + "api/stream.php";
    // silent until it holds the delay: volume 0, not muted (browsers pause muted media in a background tab)
    const el = audio = new Audio(); el.volume = 0; settled = false;
    // hls.js first, as the station's player does: some browsers (Edge) say they play HLS
    // themselves but then can't; Safari on an iPhone has no hls.js support and plays it itself
    await loadHls().catch(() => null);
    if (window.Hls && Hls.isSupported()) {
      const h = hls = new Hls({ liveSyncDurationCount: 3, liveMaxLatencyDurationCount: 6 });
      h.on(Hls.Events.ERROR, (_, d) => {
        log(`stream ${d.fatal ? "fatal " : ""}${d.details}`);
        if (!d.fatal || h !== hls) return;
        // a stall that hls.js gives up on (the sound output paused under it) needs a fresh start, not a repair
        if (d.type === Hls.ErrorTypes.MEDIA_ERROR && d.details !== Hls.ErrorDetails.BUFFER_STALLED_ERROR) h.recoverMediaError();
        else restart(d.details === Hls.ErrorDetails.BUFFER_STALLED_ERROR ? "the sound stalled" : "the stream stopped");
      });
      h.loadSource(url);
      h.attachMedia(el);
    } else if (el.canPlayType("application/vnd.apple.mpegurl")) {
      el.src = url;
    } else {
      throw new Error("This browser can’t play the stream.");
    }
    el.addEventListener("waiting", () => { log("waiting"); if (settled && el === audio) state("Buffering…"); });
    for (const e of ["play", "playing", "stalled", "suspend", "ended", "error"]) el.addEventListener(e, () => log(e === "error" && el.error ? `error ${el.error.code}` : e));
    // paused, but not by Stop (which drops the element first): something outside did it
    el.addEventListener("pause", () => { log("pause"); if (el === audio) setTimeout(() => { if (el === audio && el.paused) restart("paused from outside"); }, 1000); });
    await el.play();
  }

  /** Playing, but the sound hasn't moved on for 8 s (or it was paused under us): start it again. */
  function watch() {
    if (!audio || !settled) { moved = { pos: -1, at: Date.now() }; return; }
    if (audio.currentTime !== moved.pos && !audio.paused) { moved = { pos: audio.currentTime, at: Date.now() }; return; }
    if (Date.now() - moved.at > 8000) restart(audio.paused ? "paused from outside" : "the sound stopped moving");
  }

  /** Start the sound again on a new element (the listener stays counted); at most every 5 s. */
  async function restart(why) {
    if (!audio || Date.now() - restartedAt < 5000) return;
    restartedAt = Date.now();
    log(`starting again: ${why}`);
    drop();
    state("Connecting…");
    try { await start(); tick(); } catch (ex) { log(`couldn't start again: ${ex && ex.name}`); stop(); }
  }

  /** Let go of the stream and the element (Stop, or before starting again). */
  function drop() {
    const el = audio, h = hls;
    audio = null; hls = null; settled = false;
    if (h) h.destroy();
    if (el) { el.pause(); el.removeAttribute("src"); el.load(); }
  }

  function stop() {
    if (ping) { ping.stop(); ping = null; }
    drop();
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
    get ping() { return ping ? ping.sid : null; },
    get events() { return events.slice(); },
    pauseFromOutside() { if (audio) audio.pause(); }, // as a phone's power manager does
    get media() { return audio; },
  };
  poll();
})();
