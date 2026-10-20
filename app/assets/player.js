// The listener player. Every listener hears the timeline DELAY seconds behind the server clock,
// so all devices hear the same moment and live pieces have time to arrive. Songs play on two
// decks with real crossfades (proven in P2); live shows come from the HLS stream on a third deck
// held at the same delay (P1's lock), faded against the song decks. Browsers without Web Audio,
// or ?mode=stream, get the stream alone at the same delay. Under the display, the output strip
// (outwave.js) shows what goes out: measured left of now, and to the right the coming output, which
// the player mixes from audio it reads about 20 s ahead. window.SwPlayer exposes state for tests.
(() => {
  "use strict";
  const root = document.getElementById("player");
  if (!root) return;
  const BASE = new URL(root.dataset.base, location.href).href; // the app's full address, ending in "/"
  const DELAY = Number(root.dataset.delay) || 20;      // seconds behind the server clock
  const $ = (id) => document.getElementById(id);
  const Ctx = window.AudioContext || window.webkitAudioContext;
  const STREAM_ONLY = !Ctx || new URLSearchParams(location.search).get("mode") === "stream";

  const fmt = (s) => {
    if (!isFinite(s)) return "–";
    const neg = s < 0; s = Math.abs(s);
    return (neg ? "-" : "") + Math.floor(s / 60) + ":" + String(Math.floor(s % 60)).padStart(2, "0");
  };
  const label = (e) => (e.type === "live" ? e.title : (e.artist ? e.artist + " – " : "") + e.title);
  const key = (e) => e.n + ":" + (e.type === "song" ? "s" + e.track_id : "l" + e.live_show_id) + ":" + e.start.toFixed(3);
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  // ---- Server clock: the round trip with the shortest time wins (from the POC) ---------------
  const clock = {
    offsetMs: 0, samples: [], last: null, lastOk: 0,
    local: () => performance.timeOrigin + performance.now(),
    now() { return (this.local() + this.offsetMs) / 1000; },
    async fetch() {
      const t0 = this.local();
      const res = await fetch(BASE + "api/timeline.php?t=" + Math.round(t0), { cache: "no-store" });
      const t1 = this.local();
      if (!res.ok) throw new Error("HTTP " + res.status);
      const json = await res.json();
      this.samples = [...this.samples, { rtt: t1 - t0, offset: json.server_now * 1000 - (t0 + t1) / 2 }].slice(-10);
      this.offsetMs = this.samples.reduce((a, b) => (b.rtt < a.rtt ? b : a)).offset;
      this.last = json;
      this.lastOk = t1;
      document.dispatchEvent(new CustomEvent("sw:timeline", { detail: json })); // notes.js watches notes_rev
      return json;
    },
  };

  // ---- Song deck: an <audio> through a gain node, so fades follow exact curves --------------
  // the station's fade shape (Sound & safety), from the timeline's settings
  const shape = () => (clock.last && clock.last.settings && clock.last.settings.fade_shape) || "equal_power";
  const curve = (from, to) => SwFade.curve(shape(), from, to);
  class Deck {
    constructor(ctx, out, name) {
      this.ctx = ctx; this.name = name;
      this.el = new Audio(); this.el.preload = "auto";
      this.gainNode = ctx.createGain(); this.gainNode.gain.value = 0;
      ctx.createMediaElementSource(this.el).connect(this.gainNode).connect(out);
      this.key = null; this.entry = null; this.busyUntil = 0; this.correcting = false; this.joinedAt = 0;
      // a stall after the first seconds is a dropout; waiting while a song loads is not
      this.el.addEventListener("waiting", () => { if (this.key && performance.now() - this.joinedAt > 3000) { player.log(`${name} waiting`); player.dropout(); } });
    }
    load(entry) {
      if (this.key === key(entry)) return;
      this.key = key(entry); this.entry = entry;
      this.el.src = new URL(entry.url, BASE).href;
      this.el.load();
    }
    unload() {
      this.el.pause(); this.key = null; this.entry = null; this.busyUntil = 0;
      this.gainNode.gain.cancelScheduledValues(this.ctx.currentTime); this.gainNode.gain.value = 0;
    }
    get playing() { return !this.el.paused; }
    get gain() { return this.gainNode.gain.value; }
    /** Seek to where() seconds (worked out once the file is ready) and play. */
    async playFrom(where) {
      if (this.el.readyState < 1) await new Promise((r) => this.el.addEventListener("loadedmetadata", r, { once: true }));
      this.el.currentTime = Math.max(0, where());
      await this.el.play();
    }
    ramp(to, seconds, at = this.ctx.currentTime) {
      const g = this.gainNode.gain, from = g.value;
      g.cancelScheduledValues(at);
      if (seconds <= 0.02) g.setValueAtTime(to, at); else g.setValueCurveAtTime(curve(from, to), at, seconds);
    }
  }

  // ---- Stream deck: the HLS stream, held DELAY seconds behind the server clock ---------------
  class StreamDeck {
    constructor(ctx, out) {
      this.ctx = ctx; this.el = new Audio(); this.el.preload = "none";
      this.hls = null; this.active = false; this.locked = false; this.settled = false; this.lag = null; this.vol = 0;
      this.native = false; this.gainNode = null;
      if (ctx) {
        this.gainNode = ctx.createGain(); this.gainNode.gain.value = 0;
        ctx.createMediaElementSource(this.el).connect(this.gainNode).connect(out);
      }
      this.el.addEventListener("waiting", () => { if (this.active && this.gain > 0) player.dropout(); });
    }
    get gain() { return this.gainNode ? this.gainNode.gain.value : this.vol; }
    async start() {
      if (this.active) return;
      this.active = true; this.locked = false; this.settled = false;
      const url = BASE + "api/stream.php";
      if (!window.Hls) await loadScript(BASE + "assets/vendor/hls.min.js").catch(() => null);
      if (!this.active) return;
      if (window.Hls && Hls.isSupported()) {
        // hls.js's own loader, keeping a copy of each piece (about 20 s before it is heard) for the
        // output strip's look-ahead; hls.js hands the original to its worker
        const Base = Hls.DefaultConfig.loader;
        class Keep extends Base {
          load(context, config, callbacks) {
            super.load(context, config, { ...callbacks, onSuccess: (res, stats, ctx, net) => {
              if (ctx.frag && res.data instanceof ArrayBuffer) ahead.live({ frag: ctx.frag, payload: res.data.slice(0) });
              callbacks.onSuccess(res, stats, ctx, net);
            } });
          }
        }
        this.hls = new Hls({ liveSyncDurationCount: 3, liveMaxLatencyDurationCount: 6, lowLatencyMode: false, fLoader: Keep });
        this.hls.on(Hls.Events.ERROR, (_, d) => {
          if (d.details === "bufferStalledError") player.dropout();
          if (d.fatal) (d.type === Hls.ErrorTypes.MEDIA_ERROR ? this.hls.recoverMediaError() : this.hls.startLoad());
        });
        this.hls.loadSource(url);
        this.hls.attachMedia(this.el);
      } else if (this.el.canPlayType("application/vnd.apple.mpegurl")) {
        this.native = true; this.el.src = url; // Safari: plays, but can't be faded through Web Audio
      } else {
        this.active = false; player.say("This browser can’t play the stream."); return;
      }
      this.el.play().catch((e) => player.playFailed(e));
    }
    stop() {
      this.active = false; this.locked = false; this.lag = null;
      if (this.hls) { this.hls.destroy(); this.hls = null; }
      this.el.pause(); this.el.removeAttribute("src"); this.el.load();
      this.setGain(0, 0);
    }
    /** Stream time being heard (seconds), from the pieces' PROGRAM-DATE-TIME. */
    playingDate() {
      if (this.hls && this.hls.playingDate) return this.hls.playingDate.getTime() / 1000;
      if (this.el.getStartDate) { const d = this.el.getStartDate(); if (d && !isNaN(d.getTime())) return d.getTime() / 1000 + this.el.currentTime; }
      return null;
    }
    /** Hold DELAY behind: seek until first on time, while silent or when far off; else play up to 3 % faster or slower. */
    lock() {
      const pd = this.playingDate();
      if (!this.active || pd === null || this.el.paused) { this.lag = null; return; }
      const err = clock.now() - pd - DELAY; // positive: too far behind
      this.lag = DELAY + err;
      if (Math.abs(err) < 0.25) this.settled = true;
      if (Math.abs(err) > 2 || ((!this.settled || this.gain < 0.01) && Math.abs(err) > 0.25)) { this.el.currentTime += err; this.el.playbackRate = 1; player.log(`stream seek ${err.toFixed(2)} s`); }
      else if (Math.abs(err) > 0.03) this.el.playbackRate = 1 + Math.max(-0.03, Math.min(0.03, err * 0.1));
      else this.el.playbackRate = 1;
      this.locked = Math.abs(err) < 0.5;
    }
    setGain(to, seconds) {
      if (this.gainNode && !this.native) {
        const g = this.gainNode.gain, at = this.ctx.currentTime;
        g.cancelScheduledValues(at);
        if (seconds <= 0.02) g.setValueAtTime(to, at); else g.setValueCurveAtTime(curve(g.value, to), at, seconds);
        return;
      }
      // element volume (read-only on iPhones, where this becomes a straight switch)
      clearInterval(this.fade);
      const from = this.vol, t0 = performance.now(), ms = Math.max(1, seconds * 1000);
      const apply = (v) => { this.vol = v; try { this.el.volume = Math.max(0, Math.min(1, v * player.volume)); } catch (_) { /* fixed */ } };
      if (seconds <= 0.02) { apply(to); return; }
      this.fade = setInterval(() => { const x = Math.min(1, (performance.now() - t0) / ms); apply(from + (to - from) * x); if (x >= 1) clearInterval(this.fade); }, 50);
    }
    ramp(to, seconds) { this.setGain(to, seconds); }
  }

  function loadScript(src) {
    return new Promise((ok, bad) => { const s = document.createElement("script"); s.src = src; s.onload = ok; s.onerror = bad; document.head.appendChild(s); });
  }

  // ---- The player ----------------------------------------------------------------------------
  const player = {
    on: false, ctx: null, mix: null, master: null, analyser: null, wave: null, decks: [], stream: null, started: new Set(), tries: new Map(),
    drops: 0, lastDrop: 0, engineAt: null, nudges: 0, nudging: false, volume: 0.8, timers: [], status: "Ready", lastKey: null, events: [],
    /** The last 40 things the player did, with the heard time (for tests and support). */
    log(what) { this.events = [...this.events, ((clock.last ? this.heard() : 0) % 1000).toFixed(1) + " " + what].slice(-40); },

    async start() {
      if (this.on) return;
      this.say("");
      this.on = true; this.started.clear(); this.tries.clear(); this.lastKey = null; this.engineAt = null; this.nudges = 0;
      this.setStatus("Connecting");
      if (!STREAM_ONLY) {
        this.ctx = new Ctx({ latencyHint: "playback" }); // bigger buffers: a radio needs no low latency
        // a locked phone never settles resume(): give it 2 s, then go on (play() then says it's blocked)
        if (this.ctx.state === "suspended") await Promise.race([this.ctx.resume().catch(() => {}), new Promise((r) => setTimeout(r, 2000))]);
        this.master = this.ctx.createGain(); this.master.gain.value = this.volume;
        this.analyser = this.ctx.createAnalyser(); this.analyser.fftSize = 256;
        this.master.connect(this.analyser); this.master.connect(this.ctx.destination);
        this.mix = this.ctx.createGain(); this.mix.connect(this.master); // the station's output, before the listener's volume
        // a phone can suspend (or "interrupt") the sound engine, e.g. with the screen locked: say so and ask it back
        this.ctx.onstatechange = () => {
          const st = this.ctx && this.ctx.state;
          this.log("sound engine " + st + (document.hidden ? " (page hidden)" : ""));
          if (this.on && st && st !== "running" && st !== "closed") this.ctx.resume().catch(() => {});
        };
        this.decks = [new Deck(this.ctx, this.mix, "A"), new Deck(this.ctx, this.mix, "B")];
      }
      this.stream = new StreamDeck(this.ctx, this.mix);
      if (this.ctx && window.SwOutWave) {
        const ctx = this.ctx;
        ahead.reset();
        SwOutWave.meter(ctx, [this.decks[0].gainNode, this.decks[1].gainNode, this.stream.gainNode, this.mix], root.dataset.meter)
          .then((m) => { if (this.ctx === ctx) this.wave = m; else m.stop(); }).catch((e) => this.log("output meter: " + e.message));
        if (strip) strip.start();
      }
      try {
        for (let i = 0; i < 3; i++) await clock.fetch();
      } catch (e) {
        this.say("Can’t reach the station: " + e.message);
      }
      if (!this.on) return;
      if (STREAM_ONLY) await this.stream.start(); // silent until it holds the delay (tick)
      this.timers = [
        setInterval(() => this.tick(), 100),
        setInterval(() => clock.fetch().then(() => this.online()).catch(() => this.offline()), 2000),
        setInterval(() => this.stream && this.stream.lock(), 500),
        setInterval(() => ahead.tick(), 500),
      ];
      this.setPlaying(true); this.setStatus("Playing");
      draw();
    },

    stop() {
      if (!this.on) return;
      this.on = false;
      this.timers.forEach(clearInterval); this.timers = [];
      this.decks.forEach((d) => d.unload()); this.decks = [];
      if (this.stream) { this.stream.stop(); this.stream = null; }
      if (this.wave) { this.wave.stop(); this.wave = null; }
      ahead.reset();
      if (strip) strip.stop();
      if (this.ctx) { this.ctx.close().catch(() => {}); this.ctx = null; }
      this.setStatus("Stopped"); this.setPlaying(false);
    },

    setVolume(v) {
      this.volume = v;
      if (this.master) this.master.gain.value = v;
      if (this.stream && (!this.stream.gainNode || this.stream.native)) this.stream.setGain(this.stream.vol, 0);
      try { localStorage.setItem("sw-volume", String(v)); } catch (_) { /* private window */ }
    },

    /**
     * Some phones (seen on a Xiaomi, about 16 s after the screen locks) freeze the sound engine's
     * clock while it still says "running": nothing is heard and no event fires. When its clock stops
     * for 0.3 s, nudge it (suspend, resume); if it is still stuck, build the player again. (A plain
     * <audio> is no way out there: Chrome paused one as soon as the screen locked.)
     */
    watchEngine() {
      const c = this.ctx, now = performance.now();
      if (!c || c.state !== "running" || this.nudging) { this.engineAt = null; return; }
      if (!this.engineAt || c.currentTime !== this.engineAt.time) {
        if (this.engineAt && this.nudges) { this.log("sound engine moving again"); this.nudges = 0; }
        this.engineAt = { time: c.currentTime, since: now };
        return;
      }
      if (now - this.engineAt.since < 300) return;
      this.engineAt = null;
      if (this.nudges < 2) {
        this.nudges++; this.nudging = true;
        this.log(`sound engine stuck at ${c.currentTime.toFixed(2)}${document.hidden ? " (page hidden)" : ""}: nudge ${this.nudges}`);
        c.suspend().then(() => c.resume()).catch(() => {}).finally(() => { this.nudging = false; });
      } else {
        this.log("sound engine still stuck: starting again"); this.dropout();
        this.stop(); this.start(); // keeps the event log and the dropout count
      }
    },

    dropout() { const t = performance.now(); if (t - this.lastDrop > 3000) { this.drops++; this.lastDrop = t; } },
    /** A deck couldn't start: the browser blocked sound (say so), or the file failed (a dropout). */
    playFailed(e) {
      this.log("play failed: " + (e && (e.name + " " + e.message)));
      if (e && e.name === "NotAllowedError") { this.setStatus("Blocked"); this.say("The browser blocked the sound. Tap Listen again."); this.stop(); this.setStatus("Blocked"); }
      else this.dropout();
    },
    online() { if (this.on && this.status !== "Playing") this.setStatus("Playing"); },
    offline() { if (this.on) { this.setStatus("Reconnecting"); this.dropout(); } },
    setStatus(s) { this.status = s; $("lpConn").textContent = s; },
    say(text) { $("lpNote").textContent = text; $("lpNote").hidden = !text; },
    setPlaying(on) {
      const b = $("lpPlay");
      b.setAttribute("aria-pressed", on ? "true" : "false");
      b.querySelector("span").textContent = on ? "Listening" : "Listen";
      $("lpStop").disabled = !on;
    },

    deckFor(e) { return this.decks.find((d) => d.key === key(e)) || null; },
    freeDeck(except, h) { return this.decks.find((d) => d !== except && d.busyUntil <= h && !d.correcting) || null; },
    heard() { return clock.now() - DELAY; },

    tick() {
      this.watchEngine();
      const tl = clock.last;
      if (!tl) return;
      const h = this.heard();
      const list = tl.entries;
      const i = list.findIndex((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open)));
      const cur = list[i] || null, next = i >= 0 ? list[i + 1] || null : list.find((e) => e.start > h) || null;
      show(cur, next, h, list);
      if (STREAM_ONLY) {
        if (this.stream.locked && this.stream.gain < 0.01 && !this.started.has("stream")) { this.started.add("stream"); this.stream.ramp(1, 0.3); }
        return;
      }
      if (!cur) return;

      // 1. What should be heard now is playing (joining mid-song or mid-show, or after a dropout)
      if (cur.type === "song") {
        let d = this.deckFor(cur);
        // join when nothing plays it yet; after a failed or stalled start, try again every 4 s
        const due = performance.now() - (this.tries.get(key(cur)) || -1e9) > 4000;
        if ((!d || !d.playing) && due && cur.end - h > 1) {
          this.started.add(key(cur)); this.tries.set(key(cur), performance.now());
          d = d || this.freeDeck(null, h) || this.decks[0];
          d.load(cur); d.joinedAt = performance.now(); this.log(`join ${cur.title} on ${d.name}`);
          d.busyUntil = cur.end + cur.fade_out;
          d.playFrom(() => this.heard() - cur.start + 0.05).then(() => d.ramp(1, 0.4)).catch((e) => this.playFailed(e));
          this.decks.forEach((o) => { if (o !== d && o.playing && o.busyUntil <= h) o.unload(); });
          if (this.stream.active && this.stream.gain > 0.01) { this.stream.ramp(0, 0.4); setTimeout(() => this.stream && this.stream.stop(), 600); }
        }
      } else {
        if (!this.stream.active) this.stream.start();
        if (!this.started.has(key(cur)) && this.stream.locked) {
          this.started.add(key(cur)); this.log("join live on the stream");
          this.stream.ramp(1, 0.5);
          this.decks.forEach((d) => { if (d.playing) { d.ramp(0, 0.5); setTimeout(() => d.unload(), 700); } });
        }
      }

      // Safety nets, whatever went wrong before: nothing plays past its own end (fade included),
      // and a live show doesn't keep an old song going while the stream gets ready
      this.decks.forEach((d) => {
        if (!d.playing || d.key === key(cur) || d.correcting) return;
        const e = list.find((x) => key(x) === d.key);
        if (!e || h > e.end + Math.max(0.5, e.fade_out) + 1) {
          this.log(`stopped ${d.name}: ${e ? "past its end" : "no longer planned"}`);
          d.ramp(0, 0.5); d.correcting = true; setTimeout(() => { d.correcting = false; if (!e || d.key === key(e)) d.unload(); }, 700);
        }
      });
      if (cur.type === "live" && h - cur.start > 3 && this.stream.gain < 0.5) {
        this.decks.forEach((d) => { if (d.playing && !d.correcting) { this.log(`stopped ${d.name}: live show, stream not ready`); d.ramp(0, 0.5); d.correcting = true; setTimeout(() => { d.correcting = false; d.unload(); }, 700); } });
      }

      // 2. Get the next entry ready, and hand over on its boundary
      if (!next) return;
      const ahead = next.start - h;
      if (next.type === "song" && ahead < 20) {
        const from = cur.type === "song" ? this.deckFor(cur) : null;
        let nd = this.deckFor(next) || this.freeDeck(from, h);
        if (nd) nd.load(next);
        if (nd && !this.started.has(key(next)) && ahead < 0.4) {
          this.started.add(key(next)); this.tries.set(key(next), performance.now() + ahead * 1000); this.log(`handover to ${next.title} on ${nd.name} in ${ahead.toFixed(2)} s, fade ${cur.type === "song" ? cur.fade_out : 0.5}`);
          const fade = cur.type === "song" ? Math.max(0.3, Math.min(cur.fade_out, (cur.full || cur.play) - cur.play + 0.001)) : 0.5;
          setTimeout(() => {
            if (!this.on) return;
            const late = Math.max(0, this.heard() - next.start);
            nd.joinedAt = performance.now();
            nd.gainNode.gain.value = 0;
            nd.playFrom(() => late).catch((e) => this.playFailed(e));
            nd.ramp(1, fade);
            nd.busyUntil = next.end + next.fade_out;
            if (from) { from.ramp(0, fade); from.busyUntil = next.start + fade + 0.2; setTimeout(() => { if (from.key === key(cur)) from.unload(); }, (fade + 0.3) * 1000); }
            if (cur.type === "live") { this.stream.ramp(0, fade); setTimeout(() => this.stream && this.stream.stop(), (fade + 0.3) * 1000); }
          }, Math.max(0, ahead * 1000));
        }
      } else if (next.type === "live" && ahead < 12) {
        if (!this.stream.active) this.stream.start(); // starts silent and locks to the delay
        if (!this.started.has(key(next)) && ahead < 0.4) {
          this.started.add(key(next));
          const from = cur.type === "song" ? this.deckFor(cur) : null;
          this.log(`handover to live in ${ahead.toFixed(2)} s from ${from ? from.name : "nothing"}, stream ${this.stream.locked ? "locked" : "not locked"}`);
          const fade = cur.type === "song" ? Math.max(0.3, Math.min(cur.fade_out, (cur.full || cur.play) - cur.play + 0.001)) : 0.5;
          setTimeout(() => {
            if (!this.on) return;
            this.stream.ramp(1, fade);
            if (from) { from.ramp(0, fade); from.busyUntil = next.start + fade + 0.2; setTimeout(() => { if (from.key === key(cur)) from.unload(); }, (fade + 0.3) * 1000); }
          }, Math.max(0, ahead * 1000));
        }
      }

      // 3. Keep the song deck on time (not during a handover): a short dip and a seek
      const d = cur.type === "song" ? this.deckFor(cur) : null;
      if (d && d.playing && !d.correcting && ahead > 1 && h - cur.start > 1.5) {
        const drift = d.el.currentTime - (h - cur.start);
        if (Math.abs(drift) > 0.3) {
          d.correcting = true; d.ramp(0, 0.15);
          setTimeout(() => { d.el.currentTime = this.heard() - cur.start + 0.05; d.ramp(1, 0.3); setTimeout(() => (d.correcting = false), 1500); }, 160);
        }
      }
    },

    state() {
      const h = clock.last ? this.heard() : null;
      const cur = clock.last && clock.last.entries.find((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open)));
      return {
        on: this.on, mode: STREAM_ONLY ? "stream" : "decks", status: this.status, drops: this.drops, delay: DELAY, heard: h,
        current: cur ? { key: key(cur), type: cur.type, title: cur.title, start: cur.start } : null,
        decks: this.decks.map((d) => ({ name: d.name, key: d.key, playing: d.playing, gain: +d.gain.toFixed(3), pos: d.el.currentTime, title: d.entry && d.entry.title })),
        events: this.events, hidden: document.hidden,
        engine: this.ctx ? { state: this.ctx.state, time: +this.ctx.currentTime.toFixed(2) } : null,
        stream: this.stream ? { active: this.stream.active, locked: this.stream.locked, gain: +this.stream.gain.toFixed(3), lag: this.stream.lag, native: this.stream.native } : null,
        wave: this.ctx ? {
          meter: this.wave ? this.wave.kind : null,
          ahead: ahead.plan ? +((ahead.plan.i0 + ahead.plan.n) * SwOutWave.STEP - this.ctx.currentTime).toFixed(1) : null,
          pieces: [...ahead.pieces.values()].filter(Boolean).length, live: [...ahead.pieces.keys()].filter((k) => k.startsWith("live#")).length,
          bytes: ahead.bytes,
        } : null,
      };
    },
  };

  // ---- Look-ahead: the coming output, mixed from audio read about 20 s ahead -------------------
  // For the output strip's right half the player reads what it will play next itself: the song
  // pieces the timeline lists (the byte ranges the stream serves) and the live pieces hls.js has
  // already loaded. It decodes them and mixes them through the same fades tick() will play.
  const AHEAD = 20;
  const fadeOf = (e) => (e.type === "song" ? Math.max(0.3, Math.min(e.fade_out, (e.full || e.play) - e.play + 0.001)) : 0.5);
  const ahead = {
    pieces: new Map(), loading: 0, plan: null, dirty: true, renderedAt: 0, bytes: 0,
    reset() { this.pieces.clear(); this.plan = null; this.dirty = true; },
    /** The gain entry i of the list will have at timeline time t: tick()'s handover curves. */
    gain(list, i, t) {
      const e = list[i], prev = list[i - 1], next = list[i + 1];
      if (t < e.start) return 0;
      let g = 1;
      if (prev && prev.end > e.start - 0.05) { const x = (t - e.start) / fadeOf(prev); if (x < 1) g = SwFade.in(shape(), x); }
      if (next) { if (t >= next.start) { const x = (t - next.start) / fadeOf(e); g *= x >= 1 ? 0 : SwFade.out(shape(), x); } }
      else if (!(e.type === "live" && e.open) && t > e.end) g = 0;
      return g;
    },
    /** Which strip colour each entry gets: its deck (A or B), the next song the other deck, live 2. */
    slots(list) {
      const out = new Map();
      let last = null;
      for (const e of list) {
        if (e.type === "live") { out.set(key(e), 2); continue; }
        const d = player.deckFor(e), s = d ? player.decks.indexOf(d) : last === null ? 0 : 1 - last;
        out.set(key(e), s); last = s;
      }
      return out;
    },
    /** Each song entry's pieces as [id, timeline start, seconds, byte offset, byte length]. */
    piecesOf(e) {
      let t = e.start;
      return (e.pieces || []).map(([off, len, sec], n) => { const p = [e.url + "#" + n, t, sec, off, len]; t += sec; return p; });
    },
    tick() {
      if (!player.on || !player.ctx || !clock.last || !window.SwOutWave) return;
      const h = player.heard();
      for (const e of clock.last.entries) {
        if (e.type !== "song" || !e.pieces || e.end + fadeOf(e) < h - 1 || e.start > h + AHEAD) continue;
        for (const [id, from, sec, off, len] of this.piecesOf(e)) {
          if (from + sec < h - 1 || from > h + AHEAD || this.pieces.has(id) || this.loading >= 2) continue;
          this.pieces.set(id, null); this.loading++;
          this.read(e.url, off, len).then((ch) => { this.pieces.set(id, { ch }); this.dirty = true; })
            .catch(() => this.pieces.delete(id)).finally(() => this.loading--);
        }
      }
      if (this.dirty || performance.now() - this.renderedAt > 2000) this.render();
    },
    /** One piece of a song file, decoded (a byte range on MP3 frame boundaries, as the stream serves). */
    async read(url, off, len) {
      const res = await fetch(new URL(url, BASE).href, { headers: { Range: `bytes=${off}-${off + len - 1}` } });
      if (!res.ok) throw new Error("HTTP " + res.status);
      let bytes = await res.arrayBuffer();
      this.bytes += bytes.byteLength;
      if (res.status === 200) bytes = bytes.slice(off, off + len); // the whole file came back
      const b = await player.ctx.decodeAudioData(bytes);
      return [b.getChannelData(0), b.numberOfChannels > 1 ? b.getChannelData(1) : b.getChannelData(0)];
    },
    /** A live piece hls.js has loaded (about 20 s before it is heard): decode a copy. */
    live(d) {
      const f = d.frag, ctx = player.ctx;
      if (!ctx || !f || !d.payload || !d.payload.byteLength || !f.programDateTime) return;
      const id = "live#" + f.programDateTime;
      if (this.pieces.has(id)) return;
      this.pieces.set(id, null);
      ctx.decodeAudioData(d.payload.slice(0)).then((b) => {
        this.pieces.set(id, { tl: f.programDateTime / 1000, ch: [b.getChannelData(0), b.numberOfChannels > 1 ? b.getChannelData(1) : b.getChannelData(0)] });
        this.dirty = true;
      }).catch(() => this.pieces.delete(id));
    },
    /** Mix the next AHEAD seconds from the pieces read so far, through the planned fades. */
    render() {
      const ctx = player.ctx;
      if (!ctx || !clock.last) return;
      const list = clock.last.entries, hNow = player.heard(), cNow = ctx.currentTime, rate = ctx.sampleRate;
      const toCtx = (tl) => cNow + (tl - hNow), toTl = (ct) => hNow + (ct - cNow);
      const slots = this.slots(list), src = [], keep = new Set();
      list.forEach((e, i) => {
        if (e.type !== "song" || !e.pieces) return;
        // a song already playing is placed by its deck's own position (it may run up to 0.3 s off)
        const d = player.deckFor(e), shift = d && d.playing ? (cNow - d.el.currentTime) - toCtx(e.start) : 0;
        for (const [id, from, sec] of this.piecesOf(e)) {
          if (from + sec < hNow - 1 || from > hNow + AHEAD + 1) continue;
          keep.add(id);
          const p = this.pieces.get(id);
          if (p) src.push({ slot: slots.get(key(e)), ch: p.ch, rate, start: toCtx(from) + shift, gain: (ct) => this.gain(list, i, toTl(ct)) });
        }
      });
      const lives = list.map((e, i) => (e.type === "live" ? i : -1)).filter((i) => i >= 0);
      for (const [id, p] of this.pieces) {
        if (!id.startsWith("live#") || !p) continue;
        if (p.tl + 10 < hNow - 1) continue;
        keep.add(id);
        if (lives.length) src.push({ slot: 2, ch: p.ch, rate, start: toCtx(p.tl), gain: (ct) => { const t = toTl(ct); let g = 0; for (const i of lives) g = Math.max(g, this.gain(list, i, t)); return g; } });
      }
      for (const [id, p] of [...this.pieces]) if (p && !keep.has(id)) this.pieces.delete(id);
      this.plan = SwOutWave.render(src, cNow, cNow + AHEAD, 4);
      this.dirty = false; this.renderedAt = performance.now();
    },
  };

  // ---- The output strip ---------------------------------------------------------------------------
  const outNow = () => {
    const c = player.ctx;
    if (!c) return 0;
    const ts = c.getOutputTimestamp ? c.getOutputTimestamp() : null;
    return ts && ts.contextTime > 0 ? ts.contextTime : c.currentTime;
  };
  const waveCv = $("lpWave"), tok = (n) => getComputedStyle(root).getPropertyValue(n).trim();
  const COLORS = [tok("--src-a"), tok("--src-b"), tok("--src-live"), tok("--src-out")];
  const strip = waveCv && window.SwOutWave && !STREAM_ONLY ? SwOutWave.strip(waveCv, {
    colors: COLORS,
    measured: () => (player.wave ? player.wave.cols : null),
    plan: () => ahead.plan,
    now: outNow,
    span: () => 10,
    // where each entry starts (a handover), in its colour
    marks: () => {
      const c = player.ctx, tl = clock.last;
      if (!c || !tl) return [];
      const hNow = player.heard(), cNow = c.currentTime, slots = ahead.slots(tl.entries);
      return tl.entries.filter((e) => Math.abs(e.start - hNow) < 8).map((e) => ({ t: cNow + (e.start - hNow), color: COLORS[slots.get(key(e))] }));
    },
  }) : null;
  if (waveCv && STREAM_ONLY) waveCv.hidden = true;
  if (strip) strip.draw();

  // ---- Screen ----------------------------------------------------------------------------------
  let shownKey = null;
  function show(cur, next, h, list) {
    $("lpBehind").textContent = DELAY + " s behind live";
    $("lpDrops").textContent = player.drops;
    if (!cur) {
      $("lpTitle").textContent = list.length ? "Starting…" : "Nothing on air yet";
      return;
    }
    const live = cur.type === "live";
    const el = h - cur.start;
    $("lpTime").textContent = fmt(el);
    $("lpElapsed").textContent = fmt(el);
    $("lpRemain").textContent = live ? (cur.open ? "live" : "-" + fmt(cur.end - h)) : "-" + fmt(cur.end - h);
    $("lpFill").style.width = live && cur.open ? "100%" : Math.max(0, Math.min(100, el / Math.max(1, cur.play) * 100)).toFixed(1) + "%";
    root.classList.toggle("live", live);
    $("lpMode").textContent = live ? "LIVE · " + cur.title : "AutoDJ";
    $("lpNext").textContent = next ? (next.type === "live" ? "Live show" : label(next)) + " in " + fmt(next.start - h) : "";
    if (key(cur) !== shownKey) {
      shownKey = key(cur);
      $("lpTitle").textContent = label(cur);
      if ("mediaSession" in navigator && window.MediaMetadata) {
        navigator.mediaSession.metadata = new MediaMetadata({ title: live ? "LIVE · " + cur.title : cur.title, artist: live ? root.dataset.station : (cur.artist || root.dataset.station), album: root.dataset.station });
      }
    }
    const around = list.filter((e) => e.end > h - 600).slice(0, 8);
    const at = around.indexOf(cur);
    $("lpList").innerHTML = around.slice(Math.max(0, at - 2), Math.max(0, at - 2) + 6).map((e) => {
      const cls = e === cur ? "now" : e.end <= h ? "past" : "";
      return `<li class="${cls}${e.type === "live" ? " live" : ""}"><span class="t"></span><span class="d">${e.type === "live" && e.open ? "live" : fmt(e.play)}</span></li>`;
    }).join("");
    [...$("lpList").querySelectorAll(".t")].forEach((t, j) => { t.textContent = label(around[Math.max(0, at - 2) + j]); });
  }

  // a simple level display while playing through Web Audio
  function draw() {
    const c = $("lpSpec");
    if (!player.on || !c) return;
    const g = c.getContext("2d"), w = (c.width = c.clientWidth * (window.devicePixelRatio || 1)), hh = (c.height = 46 * (window.devicePixelRatio || 1));
    g.clearRect(0, 0, w, hh);
    if (player.analyser) {
      const bins = new Uint8Array(player.analyser.frequencyBinCount);
      player.analyser.getByteFrequencyData(bins);
      const n = 32, bw = w / n;
      g.fillStyle = getComputedStyle(root).getPropertyValue("--amber").trim() || "#1ed760";
      for (let b = 0; b < n; b++) { const v = bins[Math.floor(b * bins.length / n / 1.4)] / 255; g.fillRect(b * bw + 1, hh - v * hh, bw - 2, v * hh); }
    }
    requestAnimationFrame(draw);
  }

  // ---- Controls ---------------------------------------------------------------------------------
  try { const v = parseFloat(localStorage.getItem("sw-volume")); if (v >= 0 && v <= 1) player.volume = v; } catch (_) { /* private window */ }
  $("lpVol").value = Math.round(player.volume * 100); $("lpVolOut").textContent = $("lpVol").value;
  $("lpVol").addEventListener("input", (e) => { $("lpVolOut").textContent = e.target.value; player.setVolume(e.target.value / 100); });
  $("lpPlay").addEventListener("click", () => player.start());
  document.addEventListener("visibilitychange", () => { if (player.on) player.log("page " + (document.hidden ? "hidden" : "shown")); });
  $("lpStop").addEventListener("click", () => player.stop());
  if ("mediaSession" in navigator) {
    try { navigator.mediaSession.setActionHandler("play", () => player.start()); navigator.mediaSession.setActionHandler("pause", () => player.stop()); navigator.mediaSession.setActionHandler("stop", () => player.stop()); } catch (_) { /* unsupported */ }
  }
  // show what's on before anyone presses Listen
  clock.fetch().then(() => { const tl = clock.last, h = clock.now() - DELAY; const i = tl.entries.findIndex((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open))); show(tl.entries[i] || null, tl.entries[i + 1] || null, h, tl.entries); }).catch(() => {});
  setInterval(() => { if (!player.on && clock.last) { const tl = clock.last, h = clock.now() - DELAY; const i = tl.entries.findIndex((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open))); show(tl.entries[i] || null, tl.entries[i + 1] || null, h, tl.entries); } }, 1000);
  setInterval(() => { if (!player.on) clock.fetch().catch(() => {}); }, 10000);
  window.SwPlayer = {
    start: () => player.start(), stop: () => player.stop(), state: () => player.state(), serverNow: () => (clock.last ? clock.now() : null),
    // the output strip, for tests: context time now, and the highest peaks (A, B, live, output)
    // measured or planned between two context times
    wave: {
      now: () => (player.ctx ? player.ctx.currentTime : null),
      measured: (t0, t1) => (player.wave ? SwOutWave.range(player.wave.cols, t0, t1) : null),
      planned: (t0, t1) => (ahead.plan ? SwOutWave.range(ahead.plan, t0, t1) : null),
    },
  };
})();
