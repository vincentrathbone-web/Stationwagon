// The listener player. Every listener hears the timeline DELAY seconds behind the server clock,
// so all devices hear the same moment and live pieces have time to arrive. Songs play on two
// decks with real crossfades (proven in P2); live shows come from the HLS stream on a third deck
// held at the same delay (P1's lock), faded against the song decks. Browsers without Web Audio,
// or ?mode=stream, get the stream alone at the same delay. Under the display, the output strip
// (outwave.js) shows what goes out: measured left of now, and to the right the coming output, which
// the player mixes from audio it reads about 20 s ahead. window.SwPlayer exposes state for tests.
// The embed (views/embed.php) runs this same engine (round 60: it played the stream before, with no
// crossfades); embed.js shows what's on there and hears from it through "sw:player" events.
(() => {
  "use strict";
  const root = document.getElementById("player") || document.getElementById("embed");
  if (!root) return;
  const EMBED = root.id === "embed";
  const BASE = new URL(root.dataset.base, location.href).href; // the app's full address, ending in "/"
  const SEEK_FROM = 1.2; // song deck drift (s) beyond which the deck seeks back into step
  // Android's Chrome skips bits of a song (5 jumps in 40 s on a Xiaomi) when a media element plays
  // through a "playback" (big buffer) AudioContext, and "interactive" gives underruns; "balanced" plays clean.
  const HANDHELD = (navigator.maxTouchPoints > 0 && matchMedia("(pointer: coarse)").matches) || /Android|iPhone|iPad/.test(navigator.userAgent);
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
    offsetMs: 0, samples: [], last: null, lastOk: 0, fails: 0,
    local: () => performance.timeOrigin + performance.now(),
    now() { return (this.local() + this.offsetMs) / 1000; },
    async fetch() {
      const t0 = this.local();
      const res = await fetch(BASE + "api/timeline.php?t=" + Math.round(t0), { cache: "no-store" }).catch((e) => { this.fails++; throw e; });
      const t1 = this.local();
      if (!res.ok) { this.fails++; throw new Error("HTTP " + res.status); }
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
      this.gainNode = ctx.createGain(); this.gainNode.gain.value = 0;
      this.gainNode.connect(out);
      this.key = null; this.entry = null; this.busyUntil = 0; this.correcting = false; this.joinedAt = 0; this.seekedAt = 0;
      this.moved = { pos: -1, at: 0 };
      this.fresh();
    }
    /** A new element under the deck's gain (also when the old one's sound stopped moving: round 60). */
    fresh() {
      if (this.el) { this.el.pause(); this.el.removeAttribute("src"); this.el.load(); try { this.src.disconnect(); } catch (_) { /* gone */ } }
      const el = this.el = new Audio(); el.preload = "auto";
      this.src = this.ctx.createMediaElementSource(el); this.src.connect(this.gainNode);
      // a stall after the first seconds is a dropout; waiting while a song loads is not
      el.addEventListener("waiting", () => { if (el === this.el && this.key && performance.now() - this.joinedAt > 3000 && performance.now() - this.seekedAt > 2000) { player.log(`${this.name} waiting`); player.waits++; player.dropout("waiting for data", `deck ${this.name}, ${this.bufferedAhead().toFixed(1)} s buffered`); } });
      this.moved = { pos: -1, at: performance.now() };
    }
    /** Playing but its position hasn't moved for 8 s (a sound output stopped under it). */
    stuck() {
      // (a timing correction moves it, so it doesn't hide a stuck one: those would repeat every 2 s)
      if (!this.playing) { this.moved = { pos: -1, at: performance.now() }; return false; }
      const t = this.el.currentTime;
      if (t !== this.moved.pos) { this.moved = { pos: t, at: performance.now() }; return false; }
      return performance.now() - this.moved.at > 8000;
    }
    /** Seconds of this song the browser holds ahead of where it plays. */
    bufferedAhead() {
      const b = this.el.buffered, t = this.el.currentTime;
      for (let i = 0; i < b.length; i++) if (b.start(i) <= t + 0.1 && t <= b.end(i)) return b.end(i) - t;
      return 0;
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
    /** Seek to where() seconds (worked out once the file is ready) and play. catchUp: where() is
     * where the song should be at each moment (a join), so a late start is made up before it fades in. */
    async playFrom(where, catchUp = false) {
      if (this.el.readyState < 1) await new Promise((r) => this.el.addEventListener("loadedmetadata", r, { once: true }));
      this.seekedAt = performance.now();
      this.el.currentTime = Math.max(0, where());
      await this.el.play();
      // starting takes a moment (the song came out 0.25 to 0.4 s late on a PC), so while it is still
      // silent (it fades in after this) hop forward by what it is late, at most twice
      for (let i = 0; catchUp && i < 2; i++) {
        await new Promise((r) => setTimeout(r, 250));
        const late = where() - this.el.currentTime;
        if (Math.abs(late) < 0.08 || this.el.paused) break;
        player.log(`${this.name} started ${late.toFixed(2)} s late: catching up`);
        this.el.currentTime += late;
        await new Promise((r) => { this.el.addEventListener("seeked", r, { once: true }); setTimeout(r, 2000); });
      }
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
      // a wait just after its own seek (holding the delay at the join) isn't a dropout, as on the song decks
      this.el.addEventListener("waiting", () => { if (this.active && this.gain > 0 && performance.now() - (this.seekedAt || 0) > 2000) { player.waits++; player.dropout("waiting for data", "the stream"); } });
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
          if (d.details === "bufferStalledError") player.dropout("stream buffer ran empty");
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
    /**
     * Hold DELAY behind: seek until first on time, and while silent. Never faster or slower (as the song
     * decks: the time-stretch is audible); once heard, only a slip of over SEEK_FROM is seeked back.
     */
    lock() {
      const pd = this.playingDate();
      if (!this.active || pd === null || this.el.paused) { this.lag = null; return; }
      if (this.el.playbackRate !== 1) this.el.playbackRate = 1;
      const err = clock.now() - pd - DELAY; // positive: too far behind
      this.lag = DELAY + err;
      if (Math.abs(err) < 0.25) this.settled = true;
      const silent = !this.settled || this.gain < 0.01;
      if ((silent && Math.abs(err) > 0.25) || (Math.abs(err) > SEEK_FROM && performance.now() - (this.seekedAt || 0) > 3000)) {
        this.seekedAt = performance.now();
        this.el.currentTime += err;
        player.log(`stream seek ${err.toFixed(2)} s`);
      }
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
    // for the Diagnostics panel (diag.js): what each dropout was, and what the page costs
    dropLog: [], corrections: 0, stalls: 0, waits: 0, drift: null, cost: { strip: 0, spectrum: 0, mix: 0 },
    drops: 0, lastDrop: 0, listeners: null, ping: null, engineAt: null, nudges: 0, nudging: false, volume: 0.8, timers: [], status: "Ready", lastKey: null, events: [],
    /** The last 40 things the player did, with the heard time (for tests and support). */
    log(what) { this.events = [...this.events, ((clock.last ? this.heard() : 0) % 1000).toFixed(1) + " " + what].slice(-40); },

    async start() {
      if (this.on) return;
      this.say("");
      this.on = true; this.started.clear(); this.tries.clear(); this.lastKey = null; this.engineAt = null; this.nudges = 0;
      this.setStatus("Connecting");
      if (!STREAM_ONLY) {
        this.ctx = new Ctx({ latencyHint: HANDHELD ? "balanced" : "playback" }); // bigger buffers on a PC; a phone skips with them
        // a locked phone never settles resume(): give it 2 s, then go on (play() then says it's blocked)
        if (this.ctx.state === "suspended") await Promise.race([this.ctx.resume().catch(() => {}), new Promise((r) => setTimeout(r, 2000))]);
        this.master = this.ctx.createGain(); this.master.gain.value = this.volume;
        this.master.connect(this.ctx.destination);
        this.mix = this.ctx.createGain(); this.mix.connect(this.master); // the station's output, before the listener's volume
        // the spectrum reads the station's output, so the listener's volume doesn't shrink it
        this.analyser = SwSpectrum.analyser(this.ctx, this.mix);
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
      // counted as listening while it plays: a ping a minute (assets/listeners.js)
      if (window.SwListeners) this.ping = SwListeners.start(BASE, EMBED ? "embed" : "player", (n) => this.setListeners(n));
      if (this.analyser) spectrum.run(); // stream only: no Web Audio, so nothing to measure
    },

    stop() {
      if (!this.on) return;
      this.on = false;
      if (this.ping) { this.ping.stop(); this.ping = null; }
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
        if (!this.nudges) this.stalls++;
        this.nudges++; this.nudging = true;
        this.log(`sound engine stuck at ${c.currentTime.toFixed(2)}${document.hidden ? " (page hidden)" : ""}: nudge ${this.nudges}`);
        c.suspend().then(() => c.resume()).catch(() => {}).finally(() => { this.nudging = false; });
      } else {
        this.log("sound engine still stuck: starting again"); this.dropout("sound engine stalled", "started again");
        this.stop(); this.start(); // keeps the event log and the dropout count
      }
    },

    /** Something interrupted the sound: count it (once in 3 s) and keep what it was. */
    dropout(cause = "unknown", detail = "") {
      const t = performance.now();
      this.dropLog = [...this.dropLog, { at: Date.now(), cause, detail }].slice(-20);
      if (t - this.lastDrop > 3000) { this.drops++; this.lastDrop = t; }
    },
    /** A deck couldn't start: the browser blocked sound (say so), or the file failed (a dropout). */
    playFailed(e) {
      this.log("play failed: " + (e && (e.name + " " + e.message)));
      if (e && e.name === "NotAllowedError") { this.setStatus("Blocked"); this.say("The browser blocked the sound. Tap Listen again."); this.stop(); this.setStatus("Blocked"); }
      else this.dropout("a song couldn’t start", e ? e.name : "");
    },
    online() { if (this.on && this.status !== "Playing") this.setStatus("Playing"); },
    offline() { if (this.on) { this.setStatus("Reconnecting"); this.dropout("no answer from the station"); } },
    // the embed shows these itself (embed.js): it hears of each change through an event
    tell() { root.dispatchEvent(new CustomEvent("sw:player", { detail: { status: this.status, note: this.note, on: this.on } })); },
    setStatus(s) { this.status = s; if (EMBED) this.tell(); else $("lpConn").textContent = s; },
    setListeners(n) { this.listeners = n; if (!EMBED) $("lpListeners").textContent = String(n); },
    say(text) { this.note = text; if (EMBED) { this.tell(); return; } $("lpNote").textContent = text; $("lpNote").hidden = !text; },
    setPlaying(on) {
      if (EMBED) { this.tell(); return; }
      const b = $("lpPlay");
      b.setAttribute("aria-pressed", on ? "true" : "false");
      b.querySelector("span").textContent = on ? "Listening" : "Listen";
      $("lpStop").disabled = !on;
    },

    deckFor(e) { return this.decks.find((d) => d.key === key(e)) || null; },
    freeDeck(except, h) { return this.decks.find((d) => d !== except && d.busyUntil <= h && !d.correcting) || null; },
    heard() { return clock.now() - DELAY; },
    /** No sound-gap checks for the next seconds (a deck starting or seeking). */
    settle(seconds) { if (this.ctx) ahead.quietUntil = Math.max(ahead.quietUntil, this.ctx.currentTime + seconds); },

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
          d.load(cur); d.joinedAt = performance.now(); this.log(`join ${cur.title} on ${d.name}`); this.settle(3);
          d.busyUntil = cur.end + cur.fade_out;
          d.playFrom(() => this.heard() - cur.start + 0.05, true).then(() => d.ramp(1, 0.4)).catch((e) => this.playFailed(e));
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

      // A deck whose sound stopped moving (its output stopped under it): a new element, which joins again below
      this.decks.forEach((d) => {
        if (!d.stuck()) return;
        this.log(`${d.name} stopped moving: starting it again`); this.dropout("the sound stopped moving", `deck ${d.name}`);
        const k = d.key; d.fresh(); d.key = null; d.entry = null; d.busyUntil = 0; d.gainNode.gain.cancelScheduledValues(this.ctx.currentTime); d.gainNode.gain.value = 0;
        if (k) this.tries.delete(k);
      });

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
            nd.joinedAt = performance.now(); this.settle(fade + 1.5);
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

      // 3. Keep the song deck on time (not during a handover). A phone's song position can read a
      // few tenths of a second ahead of what is heard, so small drift is left alone (changing the
      // speed to ease it away made the sound worse); only a real slip of over 1.2 s gets a dip and a seek.
      const d = cur.type === "song" ? this.deckFor(cur) : null;
      if (d && d.playing && !d.correcting && ahead > 1 && h - cur.start > 1.5) {
        const drift = d.el.currentTime - (h - cur.start);
        this.drift = drift;
        if (Math.abs(drift) > SEEK_FROM) {
          this.corrections++; this.log(`timing correction on ${d.name}: drift ${drift.toFixed(2)} s`);
          this.dropout("timing correction", `drift ${drift > 0 ? "+" : ""}${drift.toFixed(2)} s`);
          this.settle(2); d.correcting = true; d.seekedAt = performance.now(); d.ramp(0, 0.15);
          setTimeout(() => { d.el.currentTime = this.heard() - cur.start + 0.05; d.ramp(1, 0.3); setTimeout(() => (d.correcting = false), 1500); }, 160);
        }
      }
    },

    state() {
      const h = clock.last ? this.heard() : null;
      const cur = clock.last && clock.last.entries.find((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open)));
      return {
        on: this.on, mode: STREAM_ONLY ? "stream" : "decks", status: this.status, drops: this.drops, delay: DELAY, heard: h,
        listeners: this.listeners, ping: this.ping ? this.ping.sid : null,
        current: cur ? { key: key(cur), type: cur.type, title: cur.title, start: cur.start } : null,
        decks: this.decks.map((d) => ({ name: d.name, key: d.key, playing: d.playing, gain: +d.gain.toFixed(3), pos: d.el.currentTime, title: d.entry && d.entry.title, start: d.entry ? d.entry.start : null })),
        events: this.events, hidden: document.hidden,
        diag: {
          dropLog: this.dropLog, corrections: this.corrections, stalls: this.stalls, waits: this.waits, gaps: ahead.gaps,
          drift: this.drift, cost: { ...this.cost }, fails: clock.fails, rtt: clock.samples.length ? Math.round(clock.samples[clock.samples.length - 1].rtt) : null,
          buffered: this.decks.map((d) => (d.playing ? +d.bufferedAhead().toFixed(1) : null)),
          sound: this.ctx ? { rate: this.ctx.sampleRate, out: this.ctx.outputLatency || null, base: this.ctx.baseLatency || null,
            stats: (() => { const st = this.ctx.playbackStats || this.ctx.playoutStats; if (!st) return null; const o = {}; for (const k in st) if (typeof st[k] === "number") o[k] = st[k]; return o; })() } : null,
          readBytes: ahead.bytes,
        },
        engine: this.ctx ? { state: this.ctx.state, time: +this.ctx.currentTime.toFixed(2) } : null,
        stream: this.stream ? { active: this.stream.active, locked: this.stream.locked, gain: +this.stream.gain.toFixed(3), lag: this.stream.lag, native: this.stream.native, rate: this.stream.el.playbackRate } : null,
        spectrum: spectrum.state(),
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
    pieces: new Map(), loading: 0, plan: null, dirty: true, renderedAt: 0, bytes: 0, checked: 0, gaps: 0, quietUntil: 0,
    reset() { this.pieces.clear(); this.plan = null; this.dirty = true; this.checked = 0; this.quietUntil = 0; }, // a new sound engine counts from 0
    /**
     * A sound gap: the measured output went silent (below -60 dB) for 80 ms or more while the mix
     * planned for that moment says music should be heard (above -30 dB). This catches dropouts
     * that no browser event reports, such as a starved song deck. Not checked just after a deck
     * starts or corrects its timing, when the plan and the sound may be a moment apart.
     */
    checkGaps() {
      const p = this.plan, m = player.wave && player.wave.cols, c = player.ctx;
      if (!p || !m || !c || player.wave.kind !== "sample-exact") return;
      const STEP = SwOutWave.STEP, end = Math.min(Math.floor((outNow() - 0.15) / STEP), p.i0 + p.n);
      let i = Math.max(this.checked, p.i0), run = 0;
      for (; i < end; i++) {
        const want = p.get(i, p.k - 1), got = m.get(i, m.k - 1);
        if (want === null || got === null || i * STEP < this.quietUntil) { run = 0; continue; }
        if (want > 0.03 && got < 0.001) {
          if (++run === 8) { this.gaps++; player.log("sound gap: the output went silent"); player.dropout("sound gap", "the output went silent while music should play"); }
        } else run = 0;
      }
      this.checked = i;
    },
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
        // read a song's pieces once a deck has the whole file: the browser's cache then serves them.
        // Read while the deck's own download still runs, they would come over the network again.
        if (!this.downloaded(e)) continue;
        for (const [id, from, sec, off, len] of this.piecesOf(e)) {
          if (from + sec < h - 1 || from > h + AHEAD || this.pieces.has(id) || this.loading >= 2) continue;
          this.pieces.set(id, null); this.loading++;
          this.read(e.url, off, len).then((ch) => { this.pieces.set(id, { ch }); this.dirty = true; })
            .catch(() => this.pieces.delete(id)).finally(() => this.loading--);
        }
      }
      this.checkGaps(); // against the plan the strip shows, before a new one replaces it
      if (this.dirty || performance.now() - this.renderedAt > 2000) {
        const t0 = performance.now();
        this.render();
        player.cost.mix += performance.now() - t0;
      }
    },
    /** A deck holds this song and isn't downloading it now (done, or paused by the browser on a long song). */
    downloaded(e) {
      const d = player.decks.find((x) => x.key === key(e)), el = d && d.el;
      return !!el && el.readyState >= 3 && el.networkState !== HTMLMediaElement.NETWORK_LOADING;
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
  const waveCv = $(EMBED ? "emWave" : "lpWave"), tok = (n) => getComputedStyle(root).getPropertyValue(n).trim();
  const COLORS = [], colours = () => COLORS.splice(0, 4, tok("--src-a"), tok("--src-b"), tok("--src-live"), tok("--src-out"));
  colours();
  window.addEventListener("sw:skin", colours); // the viewer changed the look
  const strip = waveCv && window.SwOutWave && !STREAM_ONLY ? SwOutWave.strip(waveCv, {
    colors: COLORS,
    measured: () => (player.wave ? player.wave.cols : null),
    plan: () => ahead.plan,
    now: outNow,
    span: () => 10,
    cost: (ms) => { player.cost.strip += ms; },
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
  /** The copyright line under the song's title (round 45); a click opens the full details. */
  let rightsOf = null;
  function showRights(song) {
    rightsOf = song && song.rights ? song : null;
    const b = $("lpRights");
    b.hidden = !rightsOf;
    b.textContent = rightsOf ? rightsOf.rights.line : "";
    b.title = rightsOf ? "Copyright details" : "";
  }
  if (!EMBED) {
    $("lpRights").addEventListener("click", () => {
      const dlg = $("lpRDlg");
      if (!rightsOf || !dlg || typeof dlg.showModal !== "function" || !window.SwRights) return;
      $("lpRSong").textContent = label(rightsOf);
      $("lpRBody").replaceChildren(SwRights.details(rightsOf.rights));
      dlg.showModal();
    });
    $("lpRClose").addEventListener("click", () => $("lpRDlg").close());
  }
  /** The phone's lock screen and notification: what is heard. */
  function session(cur) {
    if (!("mediaSession" in navigator) || !window.MediaMetadata) return;
    const live = cur.type === "live";
    navigator.mediaSession.metadata = new MediaMetadata({ title: live ? "LIVE · " + cur.title : cur.title, artist: live ? root.dataset.station : (cur.artist || root.dataset.station), album: (!live && cur.album) || root.dataset.station,
      artwork: !live && cur.art ? [{ src: new URL(cur.art, BASE).href }] : [] });
  }
  function show(cur, next, h, list) {
    if (EMBED) { if (cur && player.on && key(cur) !== shownKey) { shownKey = key(cur); session(cur); } return; } // embed.js shows the rest
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
      showRights(live ? null : cur);
      session(cur);
    }
    const around = list.filter((e) => e.end > h - 600).slice(0, 8);
    const at = around.indexOf(cur);
    $("lpList").innerHTML = around.slice(Math.max(0, at - 2), Math.max(0, at - 2) + 6).map((e) => {
      const cls = e === cur ? "now" : e.end <= h ? "past" : "";
      return `<li class="${cls}${e.type === "live" ? " live" : ""}"><span class="t"></span><span class="d">${e.type === "live" && e.open ? "live" : fmt(e.play)}</span></li>`;
    }).join("");
    [...$("lpList").querySelectorAll(".t")].forEach((t, j) => { t.textContent = label(around[Math.max(0, at - 2) + j]); });
  }

  // ---- Spectrum in the display (spectrum.js), read from the station's output before the listener's volume
  // (in the embed, only while the frame is tall enough to show it: app.css hides the canvas otherwise)
  const spectrum = SwSpectrum.create({ canvas: $(EMBED ? "emSpec" : "lpSpec"), analyser: () => player.analyser, on: () => player.on, cost: (ms) => { player.cost.spectrum += ms; }, root });

  // ---- Controls ---------------------------------------------------------------------------------
  document.addEventListener("visibilitychange", () => { if (player.on) player.log("page " + (document.hidden ? "hidden" : "shown")); });
  if ("mediaSession" in navigator) {
    try { navigator.mediaSession.setActionHandler("play", () => player.start()); navigator.mediaSession.setActionHandler("pause", () => player.stop()); navigator.mediaSession.setActionHandler("stop", () => player.stop()); } catch (_) { /* unsupported */ }
  }
  if (EMBED) player.volume = 1; // no volume control in the embed: the website's visitor has their own
  else {
    try { const v = parseFloat(localStorage.getItem("sw-volume")); if (v >= 0 && v <= 1) player.volume = v; } catch (_) { /* private window */ }
    $("lpVol").value = Math.round(player.volume * 100); $("lpVolOut").textContent = $("lpVol").value;
    $("lpVol").addEventListener("input", (e) => { $("lpVolOut").textContent = e.target.value; player.setVolume(e.target.value / 100); });
    $("lpPlay").addEventListener("click", () => player.start());
    // "Listening now" on the health line, from every timeline fetch (and each ping's answer)
    document.addEventListener("sw:timeline", (e) => { if (typeof e.detail.listeners === "number") player.setListeners(e.detail.listeners); });
    $("lpStop").addEventListener("click", () => player.stop());
    // show what's on before anyone presses Listen (the embed shows it from now playing)
    clock.fetch().then(() => { const tl = clock.last, h = clock.now() - DELAY; const i = tl.entries.findIndex((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open))); show(tl.entries[i] || null, tl.entries[i + 1] || null, h, tl.entries); }).catch(() => {});
    setInterval(() => { if (!player.on && clock.last) { const tl = clock.last, h = clock.now() - DELAY; const i = tl.entries.findIndex((e) => e.start <= h && (h < e.end || (e.type === "live" && e.open))); show(tl.entries[i] || null, tl.entries[i + 1] || null, h, tl.entries); } }, 1000);
    setInterval(() => { if (!player.on) clock.fetch().catch(() => {}); }, 10000);
  }
  /** The element heard most now (a song deck, else the stream), and what it lags the server's clock by. */
  const heardBest = () => {
    const d = player.decks.filter((x) => x.playing && x.entry).sort((a, b) => b.gain - a.gain)[0];
    if (d && d.gain > 0.01) return { el: d.el, gain: d.gain, lag: clock.now() - (d.entry.start + d.el.currentTime) };
    const s = player.stream;
    if (s && s.active && !s.el.paused) return { el: s.el, gain: s.gain, lag: s.lag };
    return d ? { el: d.el, gain: d.gain, lag: null } : null;
  };
  window.SwPlayer = {
    start: () => player.start(), stop: () => player.stop(), state: () => player.state(), serverNow: () => (clock.last ? clock.now() : null),
    heard: () => heardBest(), // for the embed and its tests: { el, gain, lag }
    timeline: () => clock.last, // the Diagnostics panel reads the current song's pieces
    // for tests of the Diagnostics panel: the song on air slips s seconds; the output goes silent for ms
    slip: (s) => { const d = player.decks.find((x) => x.playing && x.gain > 0.5); if (d) d.el.currentTime += s; return !!d; },
    hush: (ms) => { if (!player.mix) return false; player.mix.gain.value = 0; setTimeout(() => { if (player.mix) player.mix.gain.value = 1; }, ms); return true; },
    // the output strip, for tests: context time now, and the highest peaks (A, B, live, output)
    // measured or planned between two context times
    wave: {
      now: () => (player.ctx ? player.ctx.currentTime : null),
      measured: (t0, t1) => (player.wave ? SwOutWave.range(player.wave.cols, t0, t1) : null),
      planned: (t0, t1) => (ahead.plan ? SwOutWave.range(ahead.plan, t0, t1) : null),
    },
  };
})();
