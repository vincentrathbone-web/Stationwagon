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
  const CLOCK_MS = 300000; // a fresh clock sample this often while it plays (the first ones at Listen)
  const SEEK_FROM = 1.2; // once on time, drift (s) beyond which it jumps back into step, as the player's decks do
  let np = null, offset = 0, audio = null, hls = null, settled = false, timer = 0, polledAt = 0, ping = null, restartedAt = 0;
  let clockSamples = [], clockAt = 0, seekedAt = 0;
  let moved = { pos: -1, at: 0 }; // where the sound last moved on, for the watchdog
  // what happened to the sound, for the tests (the last 40 events, seconds since the page loaded)
  const events = [], t0 = Date.now();
  const log = (what) => { events.push(`${((Date.now() - t0) / 1000).toFixed(1)} ${what}${document.hidden ? " (hidden)" : ""}`); if (events.length > 40) events.shift(); };

  const mmss = (s) => { s = Math.max(0, Math.round(s)); return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`; };
  const state = (text) => { $("emState").textContent = text; };
  const now = () => Date.now() / 1000 + offset; // the server's clock
  const delay = () => (np ? np.delay : 20);

  /**
   * The server's clock: now playing asked past Cloudflare's cache (a cached answer's time can be 2 s old),
   * halfway through the request, and of the last few the round trip with the shortest time wins.
   */
  async function sampleClock() {
    clockAt = Date.now();
    const sent = Date.now() / 1000;
    const r = await fetch(BASE + "api/nowplaying.php?clock=" + Math.random().toString(36).slice(2), { cache: "no-store" });
    if (!r.ok) throw new Error(String(r.status));
    const j = await r.json(), back = Date.now() / 1000;
    clockSamples = [...clockSamples, { rtt: back - sent, offset: j.server_now - (sent + back) / 2 }].slice(-5);
    offset = clockSamples.reduce((a, b) => (b.rtt < a.rtt ? b : a)).offset;
    return j;
  }

  async function poll() {
    clearTimeout(timer); polledAt = Date.now();
    try {
      if (!clockSamples.length || (audio && Date.now() - clockAt > CLOCK_MS)) np = await sampleClock();
      else {
        const r = await fetch(BASE + "api/nowplaying.php", { cache: "no-store" }); // the shared, cached answer
        if (!r.ok) throw new Error(String(r.status));
        np = await r.json();
      }
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
    songLook(on && !live ? on : null);
    tick();
  }

  /** The song's album art in place of the CD, and its copyright line under the title (round 45); a click opens the details. */
  let rightsOf = null, artShown = null;
  function songLook(song) {
    const art = song && song.art ? BASE + song.art : null;
    if (art !== artShown) {
      artShown = art;
      const img = $("emArtImg");
      img.hidden = !art;
      if (art) img.src = art; else img.removeAttribute("src");
      $("emArt").classList.toggle("has-art", !!art);
    }
    rightsOf = song && song.rights ? song : null;
    const b = $("emRights");
    b.hidden = !rightsOf;
    b.textContent = rightsOf ? rightsOf.rights.line : "";
    if (!rightsOf) $("emRDlg").hidden = true;
  }
  $("emRights").addEventListener("click", () => {
    if (!rightsOf || !window.SwRights) return;
    $("emRSong").textContent = rightsOf.title + (rightsOf.artist ? ` · ${rightsOf.artist}` : "");
    $("emRBody").replaceChildren(SwRights.details(rightsOf.rights));
    $("emRDlg").hidden = false;
    $("emRClose").focus();
  });
  $("emRClose").addEventListener("click", () => { $("emRDlg").hidden = true; $("emRights").focus(); });

  /** Stream time being heard, from the pieces' PROGRAM-DATE-TIME; null before it plays. */
  function playingDate() {
    if (hls && hls.playingDate) return hls.playingDate.getTime() / 1000;
    if (audio && audio.getStartDate) { const d = audio.getStartDate(); if (d && !isNaN(d.getTime())) return d.getTime() / 1000 + audio.currentTime; }
    return null;
  }

  /**
   * Hold the listener delay: silent and seeking until on time. After that it never plays faster or slower
   * (the browser's time-stretch is audible: a squeezed, low-quality sound); small drift is left alone, and
   * only a slip of over SEEK_FROM is put back, with the sound dipped for the jump.
   */
  function lock() {
    const pd = playingDate();
    if (!audio || audio.paused || pd === null) return;
    if (audio.playbackRate !== 1) audio.playbackRate = 1;
    const err = now() - pd - delay(); // positive: too far behind
    if (!settled) {
      if (Math.abs(err) < 0.25) { settled = true; audio.volume = 1; state("Listening"); }
      else audio.currentTime += err;
      return;
    }
    if (Math.abs(err) <= SEEK_FROM || Date.now() - seekedAt < 3000) return;
    seekedAt = Date.now();
    log(`seek ${err.toFixed(2)} s`);
    const el = audio;
    el.volume = 0;
    el.currentTime += err;
    el.addEventListener("seeked", () => setTimeout(() => { if (el === audio) el.volume = 1; }, 100), { once: true });
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

  // ---- The spectrum and the output strip, in a frame tall enough to show them (app.css) ------------
  // The sound itself never goes through Web Audio (the element plays it, as before: a phone that freezes its
  // sound engine behind the lock screen can't stop the music). The visuals read a copy (captureStream) for
  // the spectrum and the measured, left half of the strip; the right half is the stream's own coming pieces,
  // kept as hls.js loads them (about 20 s before they are heard) and placed by their PROGRAM-DATE-TIME.
  // Browsers without captureStream on media (Safari; Firefox's mozCaptureStream would silence the element)
  // show the art and the line but not these.
  const Ctx = window.AudioContext || window.webkitAudioContext;
  const vis = {
    ctx: null, bus: null, an: null, meter: null, src: null, el: null, pieces: [], plan: null, timer: 0, strip: null, spec: null,
    wanted: () => matchMedia("(min-height: 280px)").matches,
    able: () => !!Ctx && typeof HTMLMediaElement.prototype.captureStream === "function",
    /** In Listen's click (browsers start a sound engine only on a tap). */
    async open() {
      if (!this.wanted() || !this.able()) return;
      if (!this.ctx) {
        this.ctx = new Ctx();
        this.bus = this.ctx.createGain();
        const quiet = this.ctx.createGain(); quiet.gain.value = 0; quiet.connect(this.ctx.destination); // keeps the engine pulling
        this.an = SwSpectrum.analyser(this.ctx, this.bus); this.an.connect(quiet);
        this.meter = await SwOutWave.meter(this.ctx, [this.bus, this.bus], root.dataset.meter);
        this.spec = SwSpectrum.create({ canvas: $("emSpec"), analyser: () => this.an, on: () => !!audio && !audio.paused });
        const tok = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
        const colors = [], colours = () => colors.splice(0, 2, tok("--src-a"), tok("--src-out"));
        colours(); window.addEventListener("sw:skin", colours);
        this.strip = SwOutWave.strip($("emWave"), { colors, measured: () => (this.meter ? this.meter.cols : null), plan: () => this.plan, now: () => this.now(), span: () => 10 });
      }
      if (this.ctx.state === "suspended") await Promise.race([this.ctx.resume().catch(() => {}), new Promise((r) => setTimeout(r, 2000))]);
      clearInterval(this.timer); this.timer = setInterval(() => this.ahead(), 500);
      this.strip.start(); this.spec.run();
    },
    /** The context time being heard now. */
    now() {
      const c = this.ctx;
      if (!c) return 0;
      const ts = c.getOutputTimestamp ? c.getOutputTimestamp() : null;
      return ts && ts.contextTime > 0 ? ts.contextTime : c.currentTime;
    },
    /** A new element is playing: read its sound (once it has some: a stream without a track can't be read). */
    attach(el) {
      if (!this.ctx || this.el === el) return;
      const tracks = el.captureStream().getAudioTracks();
      if (!tracks.length) return;
      if (this.src) { try { this.src.disconnect(); } catch (_) { /* gone */ } }
      this.src = this.ctx.createMediaStreamSource(new MediaStream(tracks)); this.src.connect(this.bus);
      this.el = el;
    },
    /** hls.js loaded a piece (its copy). */
    keep(frag, payload) {
      if (!this.ctx || !frag.programDateTime) return;
      this.pieces.push({ at: frag.programDateTime / 1000, dur: frag.duration, payload, ch: null, rate: 0 });
      if (this.pieces.length > 12) this.pieces.shift();
    },
    /** The coming seconds, from the pieces placed at the time they will be heard. */
    async ahead() {
      const pd = playingDate();
      if (!this.ctx || !audio || audio.paused || pd === null) { this.plan = null; return; }
      const near = this.pieces.filter((p) => p.at + p.dur > pd - 1 && p.at < pd + 8);
      for (const p of near) {
        if (p.ch || p.decoding) continue;
        p.decoding = true;
        try { const b = await this.ctx.decodeAudioData(p.payload.slice(0)); p.ch = [b.getChannelData(0), b.numberOfChannels > 1 ? b.getChannelData(1) : b.getChannelData(0)]; p.rate = b.sampleRate; }
        catch (_) { p.ch = null; }
      }
      const cNow = this.now(), ready = near.filter((p) => p.ch && p.rate === this.ctx.sampleRate);
      this.plan = SwOutWave.render(ready.map((p) => ({ slot: 0, ch: p.ch, rate: p.rate, start: cNow + (p.at - pd), gain: () => 1 })), cNow, cNow + 6, 2);
    },
    close() {
      clearInterval(this.timer); this.timer = 0; this.plan = null; this.pieces = []; this.el = null;
      if (this.src) { try { this.src.disconnect(); } catch (_) { /* gone */ } this.src = null; }
      if (this.strip) this.strip.stop();
    },
  };

  async function listen() {
    $("emPlay").setAttribute("aria-pressed", "true"); $("emPlay").setAttribute("aria-label", "Stop");
    root.classList.add("playing");
    state("Connecting…");
    vis.open().catch(() => null); // in the tap: browsers start a sound engine only then
    // two more clock samples while the stream starts, so the shortest round trip has some to pick from
    (async () => { for (let i = 0; i < 2; i++) await sampleClock().catch(() => null); })();
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
      // hls.js's own loader, keeping a copy of each piece for the output strip's coming seconds (as the player does)
      const Base = Hls.DefaultConfig.loader;
      class Keep extends Base {
        load(context, config, callbacks) {
          super.load(context, config, { ...callbacks, onSuccess: (res, stats, ctx, net) => {
            if (ctx.frag && res.data instanceof ArrayBuffer && vis.ctx) vis.keep(ctx.frag, res.data.slice(0));
            callbacks.onSuccess(res, stats, ctx, net);
          } });
        }
      }
      const h = hls = new Hls({ liveSyncDurationCount: 3, liveMaxLatencyDurationCount: 6, fLoader: Keep });
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
    el.addEventListener("playing", () => { if (el === audio) { try { vis.attach(el); } catch (ex) { log(`no visuals: ${ex && ex.name}`); } } });
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
    vis.close();
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
    get offset() { return offset; },
    slip(s) { if (audio) audio.currentTime += s; }, // the sound slips s seconds
    poll: () => poll(),
    // the visuals: whether they run, the spectrum's state, how much of the strip is measured and planned
    get vis() {
      const m = vis.meter && vis.meter.cols, p = vis.plan, now = vis.now();
      return { on: !!vis.ctx, attached: !!vis.src, kind: vis.meter ? vis.meter.kind : null, spectrum: vis.spec ? vis.spec.state() : null,
        measured: m ? SwOutWave.range(m, now - 4, now) : null, planned: p ? SwOutWave.range(p, now + 0.5, now + 4) : null, pieces: vis.pieces.length };
    },
  };
  poll();
})();
