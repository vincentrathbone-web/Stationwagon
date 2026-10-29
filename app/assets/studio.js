// The live studio (design/skins/green/studio.html), built on the proof of concept's P4 studio.
// Two decks and the mic are mixed in Web Audio; what listeners get (the program bus) is encoded
// to MP3 with lamejs (in a worker, studio-encoder.js) and sent as ~6 s pieces, in order, from the moment the planner hands over.
// Going live: the planner cuts the AutoDJ at the next piece boundary; Deck A picks up that song
// exactly where the AutoDJ is, so listeners hear no change until the DJ fades it down.
// The DJ hears the music bus (plus any deck on Cue); the mic is never played back to them.
// Above the decks, the output strip (outwave.js) shows the program bus going out: measured left of
// now, and to the right the coming seconds, mixed ahead from the decks' songs through the crossfader.
// In the Windows app (desktop/), BUTT or Mixxx can be the sound instead: the app's bridge hands over
// their MP3 frames, which go out as the same pieces; BUTT connecting goes live by itself, stopping ends the show.
// window.SwStudio.state() is for the browser test.
(() => {
  "use strict";
  const root = document.getElementById("studio");
  if (!root) return;
  const $ = (id) => document.getElementById(id);
  const BASE = new URL(root.dataset.base, location.href).href;
  const CSRF = root.dataset.csrf, DELAY = Number(root.dataset.delay) || 20, TZ = root.dataset.tz || "UTC";
  const RATE = 44100, DEAD_AIR_S = 10;
  const kbps = () => (S && S.settings.live_kbps) || 192; // Sound & safety: sound quality of live shows
  const shape = () => (S && S.settings.fade_shape) || "equal_power"; // Sound & safety: fade shape
  const Ctx = window.AudioContext || window.webkitAudioContext;
  // a phone or tablet: its notification sounds can reach an open mic (the page can't silence them;
  // the mix itself isn't touched, see HANDOVER), so the studio reminds the DJ when it switches on
  const handheld = (navigator.maxTouchPoints > 0 && matchMedia("(pointer: coarse)").matches) || /Android|iPhone|iPad/.test(navigator.userAgent);
  const QUIET = "Turn on Do Not Disturb (Focus on an iPhone or iPad) and wear headphones, so notification sounds can’t reach your mic.";

  const fmt = (s) => { s = Math.max(0, Math.round(s || 0)); return s >= 3600 ? `${Math.floor(s / 3600)}:${String(Math.floor(s % 3600 / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}` : `${Math.floor(s / 60)}:${String(s % 60).padStart(2, "0")}`; };
  const hms = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23" });
  const hm = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
  const at = (t, f = hm) => f.format(new Date(t * 1000));
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
  const events = [];
  const log = (m) => { events.push({ t: Date.now(), m }); if (events.length > 300) events.shift(); };

  // ---- Server clock (shortest round trip wins, as the player) ---------------------------------
  const clock = {
    offsetMs: 0, samples: [], timeline: null,
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
      this.timeline = json;
      return json;
    },
  };

  // ---- Talking to the server ----------------------------------------------------------------
  async function api(action, { body, query = "", raw = false } = {}) {
    const opts = body === undefined ? { cache: "no-store" } : {
      method: "POST", cache: "no-store", body: raw ? body : JSON.stringify(body),
      headers: { "X-CSRF": CSRF, "Content-Type": raw ? "audio/mpeg" : "application/json" },
    };
    const res = await fetch(BASE + "studio/api/" + action + query, opts);
    const json = await res.json().catch(() => ({}));
    json.httpStatus = res.status;
    if (res.status === 401) banner("You’re signed out. Sign in again in another tab, then carry on here.", "end");
    return json;
  }

  // ---- State -------------------------------------------------------------------------------
  let S = null;                  // the last studio state from the server
  let ctx = null, on = false;    // audio switched on
  let musicBus, programBus, splitL, anL, anR, tap, chain = null, ducker = null, micOnAir;
  let talk = false, xfValue = -100, fading = null;
  let live = null;               // { public_id, start, sending, title }
  // the MP3 encoder runs in a worker (studio-encoder.js); encGen tells the current show's answers from an old one's
  let encoder = null, encGen = 0, encReady = false, encFlushed = null, pieceFrames = [], pieceSamples = 0, seq = 0, armedAt = null;
  const queue = []; let sending = false, sentCount = 0, lastSentAt = null, sendErrors = 0, lastError = null;
  let wake = null, order = { scope: null, items: [], editable: false }, nowItem = -1;
  let wave = null, wavePlan = null, fadePlan = null; const waveMarks = [];
  // the podcast recorder (studio-recorder.js): music and mic as two tracks on this device, uploaded after End show
  let rec = null, recUp = null, recWaiting = 0;
  // the Windows app (desktop/): its bridge takes BUTT or Mixxx; null in a browser
  const APP = window.__TAURI__ && window.__TAURI__.core ? window.__TAURI__ : null;
  // the Android app (mobile/): Do Not Disturb and a screen-off show while on air, Back asks first
  const DROID = window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.StationApp && window.Capacitor.getPlatform && window.Capacitor.getPlatform() === "android" ? window.Capacitor.Plugins.StationApp : null;
  const bridge = { connected: false, status: null, rate: RATE, endTimer: null, frames: 0, dropped: 0, busy: false, quick: false, quickFrom: 0 };

  // ---- Decks: a decoded song played from an AudioBuffer, with a waveform ----------------------
  class Deck {
    constructor(name) {
      this.name = name; this.buf = null; this.item = null; this.src = null; this.startedAt = 0; this.offset = 0;
      this.peaks = null; this.loading = false; this.token = 0; this.cue = false; this.orderIndex = -1;
    }
    wire() {
      this.vol = ctx.createGain(); this.xf = ctx.createGain(); this.duck = ctx.createGain(); this.cueGain = ctx.createGain();
      this.vol.gain.value = Number($("v" + this.name).value) / 100; this.cueGain.gain.value = 0;
      this.vol.connect(this.xf).connect(this.duck).connect(musicBus); // duck: the ducker, per deck, so the output strip sees each deck's share
      this.vol.connect(this.cueGain).connect(ctx.destination); // Cue: headphones only, before the crossfader
    }
    get playing() { return !!this.src; }
    get duration() { return this.buf ? this.buf.duration : (this.item ? this.item.seconds : 0); }
    position() { return this.src ? this.offset + (ctx.currentTime - this.startedAt) : this.offset; }
    async load(item, source, orderIndex = -1) {
      const token = ++this.token;
      this.stop(); this.item = item; this.buf = null; this.peaks = null; this.offset = 0; this.loading = true; this.orderIndex = orderIndex;
      this.show(source); state(this.name, "Loading…");
      try {
        const buf = await decode(item.url);
        if (token !== this.token) return false;
        this.buf = buf; this.peaks = peaks(buf, 160);
        state(this.name, "Ready");
        return true;
      } catch (e) {
        if (token === this.token) { state(this.name, "Couldn’t load"); log(`${this.name} load failed: ${e.message}`); }
        return false;
      } finally { if (token === this.token) { this.loading = false; controls(); } }
    }
    show(source) {
      $("t" + this.name).textContent = this.item ? this.item.title : "Nothing loaded";
      $("a" + this.name).textContent = this.item ? (this.item.artist || (this.item.kind === "id" ? "Station ID" : "")) : "";
      $("s" + this.name).textContent = source || "";
    }
    /** Play from `from` seconds, starting at context time `when` (now if not given). */
    play(from = this.offset, when = ctx.currentTime + 0.02) {
      if (!this.buf) return;
      this.stop(true);
      if (from >= this.buf.duration - 0.05) from = 0;
      const src = ctx.createBufferSource();
      src.buffer = this.buf; src.connect(this.vol);
      src.onended = () => { if (this.src === src) { this.src = null; this.offset = this.buf ? this.buf.duration : 0; state(this.name, "Ready"); controls(); } };
      src.start(when, Math.max(0, from));
      this.src = src; this.startedAt = when; this.offset = Math.max(0, from);
      state(this.name, "Playing"); planAhead();
      if (this.orderIndex >= 0) { nowItem = this.orderIndex; renderOrder(); }
      controls();
    }
    pause() { if (this.src) { this.offset = this.position(); this.stop(true); state(this.name, "Ready"); controls(); } }
    stop(keep = false) {
      if (this.src) { const s = this.src; this.src = null; try { s.onended = null; s.stop(); } catch (_) {} }
      if (!keep) { this.offset = 0; if (this.buf) state(this.name, "Ready"); }
      planAhead();
    }
    seek(sec) { const was = this.playing; this.stop(true); this.offset = Math.max(0, Math.min(sec, this.duration)); if (was) this.play(this.offset); }
  }
  const decks = { A: new Deck("A"), B: new Deck("B") };

  // Decoded songs by address. Only the AutoDJ song on air is decoded ahead (so Go live picks it up
  // at once); a decoded song takes about 10 MB a minute, so nothing else is kept.
  const decoded = new Map();
  function decode(url) {
    if (!decoded.has(url)) {
      decoded.set(url, fetch(new URL(url, BASE).href).then((res) => { if (!res.ok) throw new Error("HTTP " + res.status); return res.arrayBuffer(); })
        .then((b) => ctx.decodeAudioData(b)).catch((e) => { decoded.delete(url); throw e; }));
    }
    return decoded.get(url);
  }
  function preloadOnAir() {
    const tl = clock.timeline;
    if (!on || live || !tl) return;
    const t = clock.now() + 3, e = (tl.entries || []).find((x) => x.type === "song" && x.start <= t && t < x.end);
    if (!e) return;
    for (const url of decoded.keys()) if (url !== e.url && !Object.values(decks).some((d) => d.item && d.item.url === url)) decoded.delete(url);
    decode(e.url).catch(() => {});
  }
  const state = (k, text) => ($("st" + k).textContent = text);

  function peaks(buf, n) {
    const ch = buf.getChannelData(0), step = Math.floor(ch.length / n) || 1, out = new Float32Array(n);
    let max = 0;
    for (let i = 0; i < n; i++) {
      let p = 0; const end = Math.min(ch.length, (i + 1) * step);
      for (let j = i * step; j < end; j += 16) p = Math.max(p, Math.abs(ch[j]));
      out[i] = p; max = Math.max(max, p);
    }
    return out.map((v) => (max ? v / max : 0));
  }

  function drawWave(k) {
    const d = decks[k], cv = $("w" + k), g = cv.getContext("2d"), w = cv.clientWidth, h = cv.clientHeight, dpr = devicePixelRatio || 1;
    if (!w) return;
    if (cv.width !== Math.round(w * dpr)) { cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); }
    g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h);
    const tk = (n, f) => (window.SwSkin ? SwSkin.tok(n) : "") || f; // the skin's tokens (skin.js)
    g.fillStyle = tk("--lcd", "#1f1f1f"); g.fillRect(0, 0, w, h);
    if (!d.peaks) return;
    const played = tk("--amber", "#1ed760"), ahead = tk("--amber-dim", "#1f5133");
    const n = d.peaks.length, bw = w / n, ph = d.duration ? d.position() / d.duration : 0;
    for (let i = 0; i < n; i++) {
      const a = Math.max(1, d.peaks[i] * (h / 2 - 4)), x = i * bw;
      g.fillStyle = i / n < ph ? played : ahead; g.fillRect(x, h / 2 - a, Math.max(1, bw - 1), a * 2);
    }
    const xfs = S ? S.settings.crossfade_seconds : 6;
    const fx = Math.max(0, (1 - xfs / d.duration)) * w;
    g.fillStyle = tk("--cue-zone", "rgba(124,196,255,.14)"); g.fillRect(fx, 0, w - fx, h);
    g.fillStyle = tk("--ink", "#ffffff"); g.fillRect(Math.min(w - 2, ph * w - 1), 0, 2, h);
  }

  // ---- Switching on: sound, mic, mixer, encoder tap -----------------------------------------
  const TAP_WORKLET = `
class SwTap extends AudioWorkletProcessor {
  constructor(){ super(); this.size = 4096; this.l = new Float32Array(this.size); this.r = new Float32Array(this.size); this.n = 0; this.start = 0; }
  process(inputs){
    const inp = inputs[0]; if (!inp || !inp.length) return true;
    const L = inp[0], R = inp[1] || inp[0];
    if (this.n === 0) this.start = currentFrame;
    for (let i = 0; i < L.length; i++) { this.l[this.n] = L[i]; this.r[this.n] = R[i]; this.n++; }
    if (this.n >= this.size) { this.port.postMessage({ frame: this.start, l: this.l.slice(0, this.n), r: this.r.slice(0, this.n) }); this.n = 0; }
    return true;
  }
}
registerProcessor("sw-tap", SwTap);`;

  let powering = false;
  async function powerOn() {
    if (on || powering) return;
    if (!Ctx || !window.AudioWorkletNode) { banner("This browser can’t run the studio. Use a recent Chrome or Edge.", "end"); return; }
    if (!encoder) {
      encoder = new Worker(root.dataset.encoder);
      encoder.onmessage = (e) => {
        const m = e.data;
        if (m.ready) { encReady = true; return; }
        if (m.error) { log("encoder: " + m.error); return; }
        if (m.gen !== encGen || !live) return; // an old show's tail
        addFrames(m.frames, m.final);
        if (m.final && encFlushed) { encFlushed(); encFlushed = null; }
      };
      encoder.onerror = (e) => { log("encoder failed: " + (e.message || "can't load")); };
      for (let i = 0; i < 50 && !encReady; i++) await sleep(100);
    }
    if (!encReady) { encoder = null; banner("The MP3 encoder didn’t load. Reload the page.", "end"); return; }
    powering = true;
    banner("Switching on: allow the microphone when the browser asks…");
    try {
      // on a phone or tablet the smallest buffers (3 ms) crackle as soon as the phone is busy (an app
      // updating in the background gave 20+ gaps in 30 s); "balanced" (about 22 ms) gave 0–1 there
      ctx = new Ctx({ sampleRate: RATE, latencyHint: handheld ? "balanced" : "interactive" });
      await ctx.resume();
      await ctx.audioWorklet.addModule(URL.createObjectURL(new Blob([TAP_WORKLET], { type: "text/javascript" })));
      musicBus = ctx.createGain(); programBus = ctx.createGain();
      musicBus.connect(programBus); musicBus.connect(ctx.destination);
      decks.A.wire(); decks.B.wire(); setXf(-100);
      micOnAir = ctx.createGain(); micOnAir.gain.value = 0; micOnAir.connect(programBus);
      splitL = ctx.createChannelSplitter(2); anL = ctx.createAnalyser(); anR = ctx.createAnalyser(); anL.fftSize = anR.fftSize = 1024;
      programBus.connect(splitL); splitL.connect(anL, 0); splitL.connect(anR, 1);
      tap = new AudioWorkletNode(ctx, "sw-tap", { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [2], channelCount: 2, channelCountMode: "explicit" });
      const silent = ctx.createGain(); silent.gain.value = 0;
      programBus.connect(tap).connect(silent).connect(ctx.destination);
      tap.port.onmessage = (e) => onPcm(e.data);
      if (window.SwRecorder) {
        try { rec = await SwRecorder.create({ ctx, music: musicBus, mic: micOnAir, encoder: root.dataset.encoder, log }); }
        catch (e) { rec = null; log("recorder: " + e.message); }
      }
      if (window.SwOutWave) {
        SwOutWave.meter(ctx, [decks.A.duck, decks.B.duck, micOnAir, programBus], root.dataset.meter)
          .then((m) => { wave = m; log("output meter " + m.kind); }).catch((e) => log("output meter: " + e.message));
      }
      try { await useMic(savedMic().device); } catch (e) { if (e.name === "NotAllowedError") throw e; await useMic(""); } // that mic is gone: use the default
      await listDevices();
      on = true; powering = false;
      log(`switched on at ${ctx.sampleRate} Hz`);
      ctx.onstatechange = () => { log("sound engine " + ctx.state); if (ctx.state !== "running") ctx.resume().catch(() => {}); };
      if (DROID) await droidReady();
      else banner("Studio ready. Load songs, check your mic, then press Go live." + (handheld ? " " + QUIET : ""));
      renderOrder(); await refresh();
    } catch (e) {
      log("switch on failed: " + e.message);
      powering = false;
      banner(e.name === "NotAllowedError" ? "The browser blocked the microphone. Allow it in the address bar, then try again." : "The studio couldn’t switch on: " + e.message, "end");
    }
  }

  async function useMic(deviceId) {
    const old = chain;
    const next = await SwMic.createMicChain(ctx, await SwMic.getMic(deviceId || undefined));
    if (old) { old.output.disconnect(); old.stop(); }
    if (ducker) ducker.stop();
    chain = next;
    applyMicUi();
    chain.output.connect(micOnAir);
    // the ducker turns both decks' duck stages down together
    const both = (f) => [decks.A.duck.gain, decks.B.duck.gain].forEach(f);
    ducker = SwMic.createDucker(ctx, { gain: { cancelScheduledValues: (t) => both((g) => g.cancelScheduledValues(t)), setTargetAtTime: (v, t, c) => both((g) => g.setTargetAtTime(v, t, c)) } }, chain, () => talk);
  }

  async function listDevices() {
    const all = await navigator.mediaDevices.enumerateDevices();
    const ins = all.filter((d) => d.kind === "audioinput"), outs = all.filter((d) => d.kind === "audiooutput");
    const sel = $("dev");
    sel.replaceChildren(...ins.map((d, i) => { const o = el("option", "", d.label || `Microphone ${i + 1}`); o.value = d.deviceId; return o; }));
    const used = savedMic().device;
    if (used && ins.some((d) => d.deviceId === used)) sel.value = used;
    sel.disabled = ins.length === 0;
    const out = outs.find((d) => d.deviceId === "default") || outs[0];
    if (out && out.label) $("stOut").textContent = out.label.replace(/^Default - /, "");
    listOutputs(outs);
  }

  // ---- Headphones: where the DJ hears the studio (everything they hear: the music going out and any
  // deck on Cue). The whole sound engine moves to that device; what goes on air doesn't change.
  const OUT_KEY = "sw.studio.output";
  const canPickOutput = () => !!(ctx && typeof ctx.setSinkId === "function");
  function savedOutput() { try { return localStorage.getItem(OUT_KEY) || ""; } catch (_) { return ""; } }
  function listOutputs(outs) {
    // "default" and "communications" are the system's own picks, shown as the first choice
    const real = outs.filter((d) => d.deviceId && d.deviceId !== "default" && d.deviceId !== "communications");
    const sel = $("stOutDev"), def = outs.find((d) => d.deviceId === "default");
    const first = el("option", "", "System default" + (def && def.label ? ` (${def.label.replace(/^Default - /, "")})` : "")); first.value = "";
    sel.replaceChildren(first, ...real.map((d, i) => { const o = el("option", "", d.label || `Sound output ${i + 1}`); o.value = d.deviceId; return o; }));
    const show = canPickOutput() && real.length > 1;
    sel.hidden = !show; $("stOut").hidden = show;
    if (!canPickOutput()) return;
    const want = savedOutput(), have = real.some((d) => d.deviceId === want) ? want : "";
    sel.value = have;
    if ((ctx.sinkId || "") !== have) setOutput(have, false); // the saved one, or back to the default if it was unplugged
  }
  async function setOutput(id, remember = true) {
    if (!canPickOutput()) return;
    const sel = $("stOutDev");
    try {
      await ctx.setSinkId(id);
      if (remember) { try { localStorage.setItem(OUT_KEY, id); } catch (_) { /* not remembered */ } }
      log("headphones: " + (id ? sel.selectedOptions[0]?.textContent || id : "system default"));
    } catch (e) {
      sel.value = ctx.sinkId || "";
      banner("Couldn’t switch your headphones to that output: " + (e.message || e.name) + ". The studio stays on the one before.", "end");
    }
  }

  // ---- Mixer --------------------------------------------------------------------------------
  function setXf(v) {
    stopFade(); // a running fade stops where it is
    xfValue = v; $("xf").value = v;
    if (!ctx) return;
    const p = (v + 100) / 200, t = ctx.currentTime;
    decks.A.xf.gain.setTargetAtTime(SwFade.out(shape(), p), t, 0.01);
    decks.B.xf.gain.setTargetAtTime(SwFade.in(shape(), p), t, 0.01);
    setTimeout(planAhead, 60); // once the crossfader has settled
  }
  /**
   * Fade across to the other deck over `sec` seconds; it starts if loaded, the old one stops. The
   * sound engine runs the fade itself (a hidden or busy page can't stall it); a timer only moves the
   * slider and stops the old deck afterwards.
   */
  function autoFade(sec) {
    const toB = xfValue <= 0, target = toB ? decks.B : decks.A, other = toB ? decks.A : decks.B;
    if (target.buf && !target.playing) target.play();
    stopFade();
    const from = xfValue, to = toB ? 100 : -100, t = ctx.currentTime, n = 64, a = new Float32Array(n), b = new Float32Array(n);
    for (let i = 0; i < n; i++) { const p = (from + (to - from) * i / (n - 1) + 100) / 200; a[i] = SwFade.out(shape(), p); b[i] = SwFade.in(shape(), p); }
    decks.A.xf.gain.setValueCurveAtTime(a, t, sec);
    decks.B.xf.gain.setValueCurveAtTime(b, t, sec);
    fadePlan = { t, sec, a, b }; mark(t, target.name); mark(t + sec, target.name); planAhead();
    const t0 = performance.now();
    fading = setInterval(() => {
      const p = Math.min(1, (performance.now() - t0) / (sec * 1000));
      xfValue = from + (to - from) * p; $("xf").value = xfValue;
      if (p >= 1) { clearInterval(fading); fading = null; other.pause(); controls(); }
    }, 50);
    log(`fade to ${target.name} over ${sec} s`);
  }
  /** Stop a running fade where it is (the DJ grabbed the crossfader). */
  function stopFade() {
    if (fading) { clearInterval(fading); fading = null; }
    fadePlan = null;
    if (!ctx) return;
    const t = ctx.currentTime;
    for (const d of [decks.A, decks.B]) {
      const g = d.xf.gain;
      if (g.cancelAndHoldAtTime) g.cancelAndHoldAtTime(t); else { const v = g.value; g.cancelScheduledValues(t); g.setValueAtTime(v, t); }
    }
  }
  function setTalk(v) {
    talk = v;
    $("talk").setAttribute("aria-pressed", String(talk));
    $("talk").textContent = talk ? "Mic live · tap to close" : "Mic on air";
    if (micOnAir) { micOnAir.gain.setTargetAtTime(talk ? 1 : 0, ctx.currentTime, 0.02); mark(ctx.currentTime, "mic"); }
    log("mic " + (talk ? "on air" : "off"));
    // opening the mic starts the talk item that comes next in the running order
    if (talk && order.items[nowItem + 1] && order.items[nowItem + 1].kind === "talk") markNow(nowItem + 1);
  }
  function markNow(i) { nowItem = i; renderOrder(); log(`running order: item ${i + 1} now`); }

  // ---- Output strip: what goes out, measured and (to the right of now) mixed ahead -------------
  const WAVE_AHEAD = 12;
  const tok = (n) => getComputedStyle(root).getPropertyValue(n).trim();
  const WAVE_COLORS = [], waveCols = {};
  const waveColours = () => {
    WAVE_COLORS.splice(0, 4, tok("--src-a"), tok("--src-b"), tok("--src-live"), tok("--src-out"));
    Object.assign(waveCols, { A: WAVE_COLORS[0], B: WAVE_COLORS[1], mic: WAVE_COLORS[2] });
  };
  waveColours();
  window.addEventListener("sw:skin", waveColours); // the viewer changed the look
  function mark(t, what) { waveMarks.push({ t, color: waveCols[what] }); if (waveMarks.length > 40) waveMarks.shift(); }
  /** Deck k's crossfader gain at context time t: a running fade's curve, else where it is now. */
  function xfAt(k, t) {
    const f = fadePlan;
    if (f && t >= f.t) {
      const arr = k === "A" ? f.a : f.b, x = (t - f.t) / f.sec;
      if (x >= 1) return arr[arr.length - 1];
      const p = x * (arr.length - 1), i = Math.floor(p);
      return arr[i] + (arr[Math.min(arr.length - 1, i + 1)] - arr[i]) * (p - i);
    }
    return decks[k].xf.gain.value;
  }
  /** The coming seconds: each playing deck's song through its volume, the crossfader and the ducking now. */
  function planAhead() {
    if (!on || !ctx || !window.SwOutWave) return;
    const now = ctx.currentTime, src = [];
    ["A", "B"].forEach((k, slot) => {
      const d = decks[k], b = d.buf;
      if (!d.playing || !b) return;
      src.push({ slot, rate: b.sampleRate, start: d.startedAt - d.offset, ch: [b.getChannelData(0), b.numberOfChannels > 1 ? b.getChannelData(1) : b.getChannelData(0)],
        gain: (t) => d.vol.gain.value * xfAt(k, t) * d.duck.gain.value });
    });
    wavePlan = SwOutWave.render(src, now, now + WAVE_AHEAD, 4);
  }
  const outStrip = window.SwOutWave ? SwOutWave.strip($("stOutCv"), {
    colors: WAVE_COLORS,
    measured: () => (wave ? wave.cols : null),
    plan: () => wavePlan,
    now: () => { if (!ctx) return 0; const ts = ctx.getOutputTimestamp ? ctx.getOutputTimestamp() : null; return ts && ts.contextTime > 0 ? ts.contextTime : ctx.currentTime; },
    span: () => 20,
    marks: () => waveMarks,
  }) : null;
  // shown or hidden, and its height, are remembered on this computer
  const savedWave = () => { try { return JSON.parse(localStorage.getItem("sw.studio.wave") || "{}"); } catch (_) { return {}; } };
  const saveWave = (patch) => { try { localStorage.setItem("sw.studio.wave", JSON.stringify({ ...savedWave(), ...patch })); } catch (_) {} };
  function showWave(open) {
    $("stOutWin").classList.toggle("closed", !open);
    $("stOutBox").hidden = !open;
    $("stOutTg").setAttribute("aria-expanded", String(open));
    $("stOutTg").title = open ? "Hide what goes out" : "Show what goes out";
    if (open && outStrip) outStrip.draw();
  }
  function waveHeight(px) {
    const h = Math.round(Math.max(30, Math.min(240, px)));
    $("stOutCv").style.height = h + "px";
    $("stOutGrip").setAttribute("aria-valuenow", String(h));
    if (outStrip) outStrip.draw();
    return h;
  }
  function wireWave() {
    const s = savedWave();
    waveHeight(s.height || 50); showWave(s.open !== false);
    $("stOutTg").onclick = () => { const open = $("stOutBox").hidden; showWave(open); saveWave({ open }); };
    const grip = $("stOutGrip");
    grip.onpointerdown = (e) => {
      e.preventDefault(); grip.setPointerCapture(e.pointerId);
      const y0 = e.clientY, h0 = $("stOutCv").getBoundingClientRect().height;
      grip.onpointermove = (m) => waveHeight(h0 + m.clientY - y0);
      grip.onpointerup = grip.onpointercancel = () => { grip.onpointermove = grip.onpointerup = grip.onpointercancel = null; saveWave({ height: waveHeight($("stOutCv").getBoundingClientRect().height) }); };
    };
    grip.onkeydown = (e) => {
      const step = e.key === "ArrowDown" ? 10 : e.key === "ArrowUp" ? -10 : 0;
      if (!step) return;
      e.preventDefault(); saveWave({ height: waveHeight($("stOutCv").getBoundingClientRect().height + step) });
    };
    if (outStrip) outStrip.start();
  }

  // ---- Microphone strip ---------------------------------------------------------------------
  // [id, name, enabled key, [label, min, max, unit, setting, scale]...]
  const MODS = [
    ["gain", "Input", "input", [["Gain", 0, 24, " dB", "gain", 1]]],
    ["gate", "Gate", "gate", [["Threshold", -70, -20, " dB", "gateThreshold", 1], ["Release", 50, 800, " ms", "gateRelease", 1000]]],
    ["comp", "Compressor", "comp", [["Threshold", -40, 0, " dB", "compThreshold", 1], ["Ratio", 1, 10, ":1", "compRatio", 1]]],
    ["eq", "EQ", "eq", [["Low cut", 40, 200, " Hz", "lowcut", 1], ["Presence", 0, 8, " dB", "presence", 1]]],
    ["lim", "Limiter", "limiter", [["Ceiling", -6, 0, " dBFS", "ceiling", 1]]],
    ["duck", "Ducking", "duck", [["Depth", -24, 0, " dB", "duckDepth", 1], ["Release", 200, 2000, " ms", "duckRelease", 1000]]],
  ];
  function savedMic() { try { return JSON.parse(localStorage.getItem("sw.studio.mic") || "{}"); } catch (_) { return {}; } }
  function saveMic(patch) { try { localStorage.setItem("sw.studio.mic", JSON.stringify({ ...savedMic(), ...patch })); } catch (_) {} }
  function buildChainUi() {
    const box = $("chain");
    box.replaceChildren(...MODS.map(([id, name, , ctrls]) => {
      const m = el("div", "st-mod"); m.id = "m-" + id;
      const head = el("header"); head.append(el("h3", "", name));
      const sw = el("input", "st-sw"); sw.type = "checkbox"; sw.checked = true; sw.id = "sw-" + id; sw.setAttribute("aria-label", name + " on");
      head.append(sw); m.append(head);
      ctrls.forEach(([label, min, max, unit], i) => {
        const c = el("div", "st-ctrl"), lbl = el("span", "lbl"), l = el("label", "", label), o = el("output");
        l.htmlFor = id + i; o.id = `o-${id}${i}`; lbl.append(l, o);
        const r = el("input"); r.type = "range"; r.min = min; r.max = max; r.step = max - min > 50 ? 10 : (max - min > 10 ? 1 : 0.5); r.id = id + i;
        c.append(lbl, r); m.append(c);
      });
      if (id === "gate") { const lamp = el("div", "st-lamp"); lamp.id = "gateLamp"; lamp.append(el("i"), el("span", "", "Closed")); m.append(lamp); }
      if (id === "comp") { const c = el("div", "st-ctrl"), lbl = el("span", "lbl", "Gain reduction"), o = el("output"); o.id = "grv"; lbl.append(o); const g = el("div", "st-grm"); g.append(el("i")); g.firstChild.id = "gr"; c.append(lbl, g); m.append(c); }
      return m;
    }));
    MODS.forEach(([id, , key, ctrls]) => {
      $("sw-" + id).onchange = (e) => { $("m-" + id).classList.toggle("off", !e.target.checked); if (chain) chain.setEnabled(key, e.target.checked); saveMic({ off: MODS.filter(([i]) => !$("sw-" + i).checked).map(([i]) => i) }); };
      ctrls.forEach(([, , , unit, setting, scale], i) => {
        $(id + i).oninput = (e) => { const v = Number(e.target.value); $(`o-${id}${i}`).textContent = v + unit; if (chain) chain.set(setting, v / scale); saveMic({ custom: { ...(savedMic().custom || {}), [setting]: v / scale } }); };
      });
    });
  }
  /** Show the chain's settings on the sliders (after a preset or a new mic). */
  function applyMicUi() {
    const saved = savedMic();
    if (chain) {
      chain.usePreset($("preset").value);
      if (S) chain.set("duckDepth", S.settings.duck_db); // the station's "music under the voice"
      Object.entries(saved.custom || {}).forEach(([k, v]) => chain.set(k, v));
    }
    const s = chain ? chain.settings : { ...SwMic.PRESETS[$("preset").value], ...(S ? { duckDepth: S.settings.duck_db } : {}), ...(saved.custom || {}) };
    MODS.forEach(([id, , key, ctrls]) => {
      const off = (saved.off || []).includes(id);
      $("sw-" + id).checked = !off; $("m-" + id).classList.toggle("off", off);
      if (chain) chain.setEnabled(key, !off);
      ctrls.forEach(([, , , unit, setting, scale], i) => { const v = Math.round(s[setting] * scale * 10) / 10; $(id + i).value = v; $(`o-${id}${i}`).textContent = v + unit; });
    });
  }

  // ---- Go live, sending, end show -----------------------------------------------------------
  /** Go live, or (how = "rejoin") carry on the DJ's own show after a reload or a drop. */
  async function goLive(how = "golive", via = bridge.connected ? "bridge" : "studio") {
    if (live || (via === "studio" ? !on : !bridge.connected)) return;
    $("stGo").disabled = true; $("stGo").classList.add("joining"); $("stGoLabel").textContent = how === "rejoin" ? "Rejoining…" : "Joining…";
    const r = await api(how, { body: {} });
    if (r.error) { banner(r.error, "end"); controls(); await refresh(); return; }
    live = { public_id: r.live.public_id, start: r.live.start, title: r.live.title, sending: false, via };
    if (via === "studio") encoder.postMessage({ init: { gen: ++encGen, rate: ctx.sampleRate, kbps: kbps() } });
    pieceFrames = []; pieceSamples = 0; seq = 0; armedAt = live.start;
    if (rec && via === "studio") rec.arm(live.public_id); // BUTT's show: the podcast uses what went on air
    sentCount = 0; sendErrors = 0; lastSentAt = null; lastError = null;
    if (r.continued) {
      log(`continuing ${r.continued} as ${live.public_id}`);
      banner(`You’re back: “${live.title}” carries on at ${at(live.start, hms)}, with its notes. The AutoDJ filled the gap.`);
    }
    log(`live ${live.public_id} starts at ${at(live.start, hms)} (in ${(live.start - clock.now()).toFixed(1)} s)`);
    controls();
    const h = r.handover;
    if (via === "bridge") {
      banner(`Joining from ${bridgeName()}. Its sound goes out from ${at(live.start, hms)}.`);
    } else if (h && h.type === "song") {
      setXf(-100);
      const ok = await decks.A.load({ title: h.title, artist: h.artist, url: h.url, seconds: h.full }, "From the AutoDJ · picked up when you went live");
      if (ok && live) {
        // play it exactly where the timeline is: server time at context time t is clock.now() + (t - ctx.currentTime)
        const when = ctx.currentTime + 0.1;
        decks.A.play(clock.now() + 0.1 - h.start, when);
        decks.A.handover = h;
        log(`deck A picks up "${h.title}" at ${fmt(clock.now() - h.start)}`);
      }
      if (!ok && live) banner("Deck A couldn’t load the AutoDJ song. Start a deck or open the mic before you go on air.", "end");
      else if (live) banner(`Joining in sync. Deck A picks up “${h.title}” exactly where the AutoDJ is, so listeners hear no change. You take over at ${at(live.start, hms)}.`);
    } else {
      banner(`Joining. You take over at ${at(live.start, hms)}.`);
    }
    try { wake = await navigator.wakeLock.request("screen"); } catch (_) { wake = null; }
    if (DROID && live) droidOnAir(true);
    await refresh();
    controls();
  }

  function onPcm({ frame, l, r }) {
    if (!live || live.via !== "studio" || !encoder || armedAt === null) return;
    const chunkStart = clock.now() + (frame / ctx.sampleRate - ctx.currentTime);
    if (chunkStart + l.length / ctx.sampleRate <= armedAt) return; // before our slot
    let from = 0;
    if (!live.sending) {
      from = Math.max(0, Math.round((armedAt - chunkStart) * ctx.sampleRate));
      live.sending = true; live.firstAt = chunkStart + from / ctx.sampleRate;
      if (rec) rec.startAt(frame + from); // the recording starts on the same sample as the broadcast
      log(`on air: first sample at ${at(live.firstAt, hms)}`);
      banner("You’re on air. Fade Deck A down under your voice whenever you’re ready.");
      setTimeout(() => { if (live && live.sending && $("stBannerText").textContent.startsWith("You’re on air")) banner(""); }, 6000);
      controls();
    }
    const L = l.slice(from), R = r.slice(from);
    encoder.postMessage({ pcm: { gen: encGen, l: L, r: R } }, [L.buffer, R.buffer]);
  }

  /** Whole MP3 frames in; a piece goes out each time one holds a piece length (`seconds`, if given). */
  function addFrames(frames, final, rate = ctx.sampleRate, seconds = S ? S.settings.segment_seconds : 6) {
    const cut = () => {
      const body = new Uint8Array(pieceFrames.reduce((n, b) => n + b.length, 0));
      let o = 0; for (const b of pieceFrames) { body.set(b, o); o += b.length; }
      queue.push({ live: live.public_id, seq: seq++, dur: pieceSamples / rate, body });
      pieceFrames = []; pieceSamples = 0;
      pump();
    };
    for (const f of frames) {
      pieceFrames.push(f.bytes); pieceSamples += f.samples;
      if (pieceSamples >= seconds * rate) cut();
    }
    if (final && pieceFrames.length) cut();
  }

  async function pump() {
    if (sending) return;
    sending = true;
    while (queue.length) {
      const p = queue[0];
      try {
        const r = await api("piece", { body: p.body, raw: true, query: `?live=${encodeURIComponent(p.live)}&seq=${p.seq}&dur=${p.dur.toFixed(4)}` });
        if (r.httpStatus === 410) { queue.length = 0; serverEnded(r.error); break; }
        if (r.httpStatus === 409 && typeof r.expected === "number" && r.expected < p.seq) throw new Error(r.error);
        if (r.httpStatus !== 200) throw new Error(r.error || "HTTP " + r.httpStatus);
        queue.shift(); sentCount++; lastSentAt = performance.now(); lastError = null;
      } catch (e) {
        sendErrors++; lastError = e.message;
        log(`piece ${p.seq} not sent (${e.message}); trying again`);
        await sleep(1000);
      }
    }
    sending = false;
  }
  const waitForQueue = (ms) => new Promise((resolve) => { const t0 = performance.now(); const t = setInterval(() => { if ((!queue.length && !sending) || performance.now() - t0 > ms) { clearInterval(t); resolve(); } }, 100); });

  function askEnd() {
    banner("End the show? The AutoDJ takes over with a crossfade into the next song.", "end", [
      ["End show", () => endShow(), "danger"], ["Keep going", () => banner("")],
    ]);
  }
  async function endShow(publicId) {
    const id = publicId || (live && live.public_id);
    if (!id) return;
    let recStopped = null;
    $("stGo").disabled = true;
    if (live && live.public_id === id) {
      banner("Ending the show: sending the last audio, then handing back to the AutoDJ…");
      armedAt = null;
      if (live.via === "bridge") addFrames([], true, bridge.rate); // what BUTT sent last
      else {
        recStopped = rec ? rec.stop() : null;
        // the worker sends the last frames back after everything before them; then the queue empties
        await new Promise((ok) => { encFlushed = ok; encoder.postMessage({ flush: { gen: encGen } }); setTimeout(ok, 3000); });
      }
      await waitForQueue(15000);
    }
    const r = await api("end", { body: { live: id } });
    log("show ended: " + JSON.stringify(r));
    finish();
    banner(r.ended ? `Handed back to the AutoDJ at ${at(r.stream_end, hms)}.` : (r.error || "The show had already ended."));
    await refresh();
    if (recStopped && await recStopped.catch(() => null)) uploadRec(true);
  }
  /** Upload the recordings kept on this device (not while on air); after End show, offer the editor. */
  async function uploadRec(afterShow = false) {
    if (!window.SwRecorder || live || SwRecorder.busy()) return;
    if (!S || (S.slot && S.slot.rejoin && S.slot.rejoin.open)) return; // first rejoin the show still on air; upload after
    recWaiting = await SwRecorder.waiting();
    if (!recWaiting) return;
    if (afterShow) banner("Uploading your show’s recording for the podcast. Keep this page open until it’s done…");
    try {
      const done = await SwRecorder.upload({ base: BASE, csrf: CSRF, log, onProgress: (p) => (recUp = p) });
      recWaiting = 0;
      const ep = done[done.length - 1];
      if (ep && !live && ep.state === "skipped") banner("Your show’s recording is uploaded. The show was under " + Math.round(((S && S.settings.podcast_min_seconds) || 120) / 60) + " minutes, so it isn’t made into a podcast episode.");
      else if (ep && !live) banner("Your show’s recording is uploaded. Trim it and publish it as a podcast episode.", "", [["Edit the episode", () => { location.href = BASE + "manage/podcast/" + ep.id; }]]);
    } catch (e) {
      log("recording upload: " + e.message);
      if (afterShow && !live) banner("The recording couldn’t upload yet (" + e.message + "). It’s kept on this device and tries again by itself.", "end");
    }
  }
  function serverEnded(reason) {
    log("server ended the show: " + reason);
    finish();
    const mins = S ? S.settings.rejoin_minutes : 0;
    banner(`The station went back to the AutoDJ because no audio reached it for ${S ? S.settings.live_timeout_seconds : 8} seconds. Check your connection, then ` +
      (mins > 0 ? `rejoin your show within ${mins} minutes to carry it on.` : "go live again."), "end");
    refresh();
  }
  function finish() {
    if (rec) rec.stop().catch((e) => log("recording: " + e.message)); // after a drop: kept here, uploaded when the studio is off air
    live = null; encGen++; encFlushed = null; armedAt = null; queue.length = 0;
    clearTimeout(bridge.endTimer); bridge.endTimer = null;
    if (wake) { wake.release().catch(() => {}); wake = null; }
    if (DROID) droidOnAir(false);
    if (talk) setTalk(false);
    controls();
  }

  // ---- The Windows app's bridge: BUTT or Mixxx as the sound --------------------------------------
  const bridgeName = () => {
    const c = (bridge.status && bridge.status.client) || "";
    return /butt/i.test(c) ? "BUTT" : /mixxx|libshout/i.test(c) ? "Mixxx" : "BUTT or Mixxx";
  };
  /** BUTT started sending: go live (or carry on the show), if the slot allows it. */
  async function bridgeConnected(status) {
    bridge.connected = true; bridge.status = status; bridge.rate = status.rate || RATE;
    clearTimeout(bridge.endTimer); bridge.endTimer = null;
    log(`bridge: ${status.client || "a source"} connected (${status.kbps} kbps, ${status.rate} Hz)`);
    if (live) {
      if (live.via === "bridge") { bridge.quick = true; banner(`${bridgeName()} is sending again.`); }
      else banner(`${bridgeName()} is sending, but you’re on air from the studio’s own decks and mic. End the show, and it goes live from ${bridgeName()}.`, "end");
      controls();
      return;
    }
    await refresh();
    if (live || !bridge.connected || !S) return;
    const rj = S.slot.rejoin, show = S.show;
    if (show && show.mine && !show.own) banner(`${bridgeName()} is sending, but ${show.dj || "another presenter"} is on air. Their show must end first.`, "end");
    else if (rj && (rj.open || rj.until > clock.now())) await goLive("rejoin", "bridge");
    else if (S.slot.may) await goLive("golive", "bridge");
    else banner(`${bridgeName()} is sending, but you can’t go live now: ${S.slot.reason}`, "end");
  }
  /** BUTT stopped: the show ends a few seconds later, unless it comes back. */
  function bridgeDisconnected(reason) {
    const was = bridge.connected;
    bridge.connected = false;
    log("bridge: " + reason);
    if (!was || !live || live.via !== "bridge") { controls(); return; }
    addFrames([], true, bridge.rate); // send what came in
    bridge.quickFrom = seq;
    const wait = 5;
    banner(`${reason} The show ends in ${wait} seconds and the AutoDJ takes over, unless ${bridgeName()} comes back.`, "end", [["End now", () => endShow(), "danger"]]);
    clearTimeout(bridge.endTimer);
    bridge.endTimer = setTimeout(() => { bridge.endTimer = null; if (!bridge.connected && live && live.via === "bridge") endShow(); }, wait * 1000);
    controls();
  }
  /** MP3 frames from BUTT: from the moment the station hands over, they go out as pieces. */
  function bridgeFrames(ev) {
    bridge.frames += ev.sizes.length;
    if (!live || live.via !== "bridge" || armedAt === null) return;
    const bin = atob(ev.data), bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    const per = ev.samples / ev.sizes.length, arrived = (ev.at + clock.offsetMs) / 1000; // server time
    const frames = [];
    let o = 0;
    ev.sizes.forEach((n, i) => {
      const t = arrived + (i * per) / ev.rate;
      if (t >= armedAt) frames.push({ bytes: bytes.subarray(o, o + n), samples: per });
      else bridge.dropped++;
      o += n;
    });
    if (!frames.length) return;
    if (!live.sending) {
      live.sending = true; live.firstAt = clock.now();
      log(`on air from the bridge at ${at(live.firstAt, hms)}`);
      banner(`You’re on air from ${bridgeName()}. Stop it there to end the show.`);
      setTimeout(() => { if (live && live.sending && $("stBannerText").textContent.startsWith("You’re on air")) banner(""); }, 6000);
      controls();
    }
    bridge.rate = ev.rate;
    // back after a drop: the first piece goes after 1 s, so the station hears from the show within its timeout
    if (bridge.quick) { addFrames(frames, false, ev.rate, 1); if (seq > bridge.quickFrom) bridge.quick = false; }
    else addFrames(frames, false, ev.rate);
  }
  function showBridge() {
    if (!APP) return;
    const s = bridge.status, b = $("stBridge");
    b.hidden = false;
    b.classList.toggle("on", bridge.connected);
    $("stBridgeState").textContent = s && s.error ? s.error
      : bridge.connected && s ? `${bridgeName()} sending · MP3 ${s.kbps || "?"} kbps` : s ? `waiting on 127.0.0.1:${s.port}` : "…";
    const busy = !!live || !!(window.SwRecorder && SwRecorder.busy());
    if (busy !== bridge.busy) { bridge.busy = busy; APP.core.invoke("set_busy", { busy }).catch(() => {}); }
  }
  const quitApp = () => (APP ? APP.core.invoke("quit_app") : DROID.quit());
  function askClose() {
    banner("Close the app? " + (live ? "You’re on air: the station goes back to the AutoDJ." : "Your show’s recording is still uploading; it carries on the next time you open the studio."), "end", [
      ...(live ? [["End show and close", async () => { await endShow(); quitApp(); }, "danger"]] : []),
      ["Close anyway", quitApp], ["Keep going", () => banner("")],
    ]);
  }

  // ---- The Android app ------------------------------------------------------------------------
  /** Studio ready: Do Not Disturb is the app's job here; it needs Android's OK once. */
  async function droidReady() {
    const d = await DROID.dndStatus().catch(() => ({ access: false }));
    log("android: do not disturb " + (d.access ? "allowed" : "not allowed yet"));
    if (d.access) banner("Studio ready. Load songs, check your mic, then press Go live. When you go live, the app turns on Do Not Disturb (alarms still ring) and puts it back when you end your show. Wear headphones.");
    else banner("Studio ready. Let the app turn on Do Not Disturb while you’re on air, so calls and messages can’t sound on air: tap Allow, then switch on Stationwagon Studio and come back. Wear headphones.", "", [
      ["Allow Do Not Disturb", () => DROID.allowDnd()], ["Not now", () => banner("")],
    ]);
  }
  /** On air: Android keeps the mic, the sound and Wi-Fi going with the screen off, and turns on Do Not Disturb. */
  function droidOnAir(on) {
    DROID.setOnAir({ on }).then((d) => log(`android: on air ${on ? "on" : "off"}, do not disturb ${d.on ? "on" : "off"}`))
      .catch((e) => log("android on air: " + e.message));
  }
  if (DROID) {
    DROID.addListener("back", () => (live || (window.SwRecorder && SwRecorder.busy()) ? askClose() : DROID.quit()));
    $("stDroidSet").hidden = false;
    $("stDroidSet").onclick = () => (live ? banner("End your show before you open the app’s settings.", "end") : DROID.openSettings());
    // back from Android's settings screen (Do Not Disturb)
    document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible" && on && !live && !$("stBanner").hidden && /tap Allow/.test($("stBannerText").textContent)) droidReady(); });
  } else if (/Android/.test(navigator.userAgent)) $("stGetAndroid").hidden = false;
  if (APP) {
    APP.event.listen("bridge", ({ payload: e }) => {
      if (e.type === "connect") bridgeConnected(e.status);
      else if (e.type === "frames") bridgeFrames(e);
      else if (e.type === "disconnect") bridgeDisconnected(e.reason);
      else if (e.type === "status") bridge.status = e.status;
      else if (e.type === "refused") { log("bridge refused: " + e.reason); banner(e.reason, "end"); }
      else if (e.type === "title" && bridge.status) bridge.status.title = e.title;
      showBridge();
    });
    APP.event.listen("close-requested", askClose);
    $("stAppSet").onclick = () => APP.core.invoke("open_settings");
  } else if (/Windows/.test(navigator.userAgent)) $("stGetApp").hidden = false;

  // ---- Running order ------------------------------------------------------------------------
  let saveTimer = null, orderDirty = false, orderJson = "", saving = 0;
  function renderOrder() {
    const list = $("ro"), items = order.items;
    const slot = S && S.slot.block, startAt = slot ? slot.start : (live ? live.start : clock.now());
    let t = 0;
    list.replaceChildren(...items.map((it, i) => {
      const li = el("li", i < nowItem ? "done" : i === nowItem ? "now" : "");
      li.append(el("span", "at", at(startAt + t)), el("span", "k " + (it.kind === "talk" ? "talk" : it.kind === "id" ? "jingle" : "song"), it.kind === "talk" ? "MIC" : it.kind === "id" ? "ID" : "♪"));
      li.append(el("span", "nm", it.kind === "talk" ? it.title : (it.artist ? it.artist + " — " : "") + it.title), el("span", "d", fmt(it.seconds)));
      const ld = el("span", "ld");
      if (it.kind !== "talk") ["A", "B"].forEach((k) => { const b = el("button", "btn sm", k); b.type = "button"; b.title = `Load on deck ${k}`; b.disabled = !on; b.onclick = () => loadItem(k, it, i); ld.append(b); });
      else if (i !== nowItem) { const b = el("button", "btn sm", "Now"); b.type = "button"; b.title = "Mark this talk item as now (opening the mic does it for the next one)"; b.disabled = !on; b.onclick = () => markNow(i); ld.append(b); }
      if (order.editable) {
        const up = el("button", "btn sm ghost", "↑"), dn = el("button", "btn sm ghost", "↓"), rm = el("button", "btn sm ghost", "✕");
        up.type = dn.type = rm.type = "button"; up.title = "Move up"; dn.title = "Move down"; rm.title = "Remove";
        up.disabled = i === 0; dn.disabled = i === items.length - 1;
        up.onclick = () => moveItem(i, -1); dn.onclick = () => moveItem(i, 1); rm.onclick = () => { items.splice(i, 1); if (nowItem >= i) nowItem--; changedOrder(); };
        ld.append(up, dn, rm);
      }
      li.append(ld);
      t += it.seconds;
      return li;
    }));
    $("roEmpty").hidden = items.length > 0;
    const slotLen = slot ? slot.end - slot.start : Math.max(t, 1);
    $("fillbar").replaceChildren(...items.map((it) => { const s = el("span", it.kind === "talk" ? "talk" : it.kind === "id" ? "jingle" : ""); s.style.flex = String(it.seconds); return s; }),
      ...(slotLen > t ? [Object.assign(el("span", "rest"), { style: `flex:${slotLen - t}` })] : []));
    $("fillTotal").textContent = slot ? `${fmt(t)} planned of ${fmt(slotLen)}` : `${fmt(t)} planned`;
    $("fillRest").textContent = !slot ? "" : t <= slotLen ? (t ? `AutoDJ fills the last ${fmt(slotLen - t)}` : "") : `Over by ${fmt(t - slotLen)}`;
    $("roAdd").hidden = !order.editable;
  }
  function moveItem(i, d) { const it = order.items.splice(i, 1)[0]; order.items.splice(i + d, 0, it); changedOrder(); }
  function changedOrder() {
    orderDirty = true;
    renderOrder();
    $("roSaved").textContent = "Saving…";
    clearTimeout(saveTimer);
    const mine = ++saving;
    saveTimer = setTimeout(async () => {
      const r = await api("order", { body: { scope: order.scope, items: order.items.map((i) => (i.kind === "talk" ? { kind: "talk", title: i.title, seconds: i.seconds } : { kind: i.kind, track_id: i.track_id })) } });
      if (mine !== saving) return; // another change came in meanwhile; its save reports
      $("roSaved").textContent = r.error ? "Not saved: " + r.error : "Saved";
      if (!r.error) { order.items = r.items; orderJson = JSON.stringify(r.items); orderDirty = false; renderOrder(); }
    }, 400);
  }
  async function loadItem(k, it, index) {
    if (!on) return;
    const d = decks[k];
    if (d.playing && (k === "A" ? xfValue < 60 : xfValue > -60)) { banner(`Deck ${k} is playing on air. Fade across to the other deck first, or press Stop.`, "end"); return; }
    await d.load(it, index >= 0 ? `Running order · item ${index + 1}` : "From the library", index);
  }

  let searchTimer = null, searchToken = 0;
  async function search() {
    const q = $("libQ").value.trim(), token = ++searchToken;
    if (!q) { $("libResults").replaceChildren(); return; }
    const r = await api("songs", { query: "?q=" + encodeURIComponent(q) });
    if (token !== searchToken) return;
    const songs = r.songs || [];
    $("libResults").replaceChildren(...(songs.length ? songs.map((s) => {
      const li = el("li");
      li.append(el("span", "nm", (s.artist ? s.artist + " — " : "") + s.title), el("span", "d", fmt(s.seconds)));
      const acts = el("span", "ld");
      ["A", "B"].forEach((k) => { const b = el("button", "btn sm", k); b.type = "button"; b.title = `Load on deck ${k}`; b.disabled = !on; b.onclick = () => loadItem(k, s, -1); acts.append(b); });
      [["+ Song", "song"], ["+ ID", "id"]].forEach(([label, kind]) => { const b = el("button", "btn sm", label); b.type = "button"; b.disabled = !order.editable; b.onclick = () => { if (order.items.length >= 60) return; order.items.push({ ...s, kind }); changedOrder(); }; acts.append(b); });
      li.append(acts);
      return li;
    }) : [el("li", "none", "No songs match.")]));
  }

  // ---- Presenter notes drawer ---------------------------------------------------------------
  let kind = "verse", notes = [], confirmRemove = null, editing = null;
  const KIND = { verse: "Verse", refs: "References", video: "Video", note: "Note" };
  function openDrawer(open) {
    $("stDrawer").hidden = !open;
    $("stNotesBtn").setAttribute("aria-expanded", String(open));
    document.body.classList.toggle("st-drawer-open", open);
    if (open) { const f = $("noteForm").querySelector(`.st-nf[data-for="${kind}"] input, .st-nf[data-for="${kind}"] textarea`); if (f) f.focus(); }
  }
  function setKind(k) {
    if (editing !== null && k !== kind) return; // an edit keeps its kind
    kind = k;
    document.querySelectorAll(".st-kinds [data-kind]").forEach((b) => b.setAttribute("aria-selected", String(b.dataset.kind === k)));
    document.querySelectorAll(".st-nf").forEach((f) => (f.hidden = f.dataset.for !== k));
    $("noteErr").hidden = true;
  }
  function noteFields() {
    const f = $("noteForm").querySelector(`.st-nf[data-for="${kind}"]`), out = {};
    f.querySelectorAll("input, textarea").forEach((i) => (out[i.name] = i.value));
    return out;
  }
  async function postNote(e) {
    e.preventDefault();
    const show = myShow();
    if (!show) return;
    $("notePost").disabled = true;
    const r = editing !== null
      ? await api("note-edit", { body: { live: show.public_id, id: editing, fields: noteFields() } })
      : await api("note", { body: { live: show.public_id, kind, fields: noteFields() } });
    $("notePost").disabled = false;
    if (r.error) { $("noteErr").textContent = r.error; $("noteErr").hidden = false; if (r.httpStatus === 404) { notes = notes.filter((n) => n.id !== editing); endEdit(); renderNotes(); } return; }
    $("noteErr").hidden = true;
    if (r.replaces) notes = notes.filter((n) => n.id !== r.replaces);
    notes.push(r.note);
    log(r.replaces ? `note ${r.replaces} changed, now ${r.note.id}` : `note ${r.note.id} posted`);
    endEdit();
    renderNotes();
  }
  /** Fill the note's form to change it; Save posts it again as new (listeners get it with a toast). */
  function startEdit(n) {
    editing = null; setKind(n.kind); editing = n.id;
    const f = $("noteForm").querySelector(`.st-nf[data-for="${n.kind}"]`);
    const v = { ref: n.ref, version: n.version, text: n.text, refs: (n.refs || []).map((r) => r.label).join("\n"), url: n.url };
    f.querySelectorAll("input, textarea").forEach((i) => (i.value = v[i.name] ?? ""));
    document.querySelectorAll(".st-kinds [data-kind]").forEach((b) => (b.disabled = b.dataset.kind !== n.kind));
    $("notePost").textContent = "Save changes"; $("noteCancel").hidden = false;
    $("noteHint").textContent = `Listeners get the changed note as new, ${DELAY} s after you save.`;
    $("noteErr").hidden = true;
    renderNotes();
    const first = f.querySelector("input, textarea"); if (first) first.focus();
  }
  function endEdit() {
    const was = editing;
    editing = null;
    const f = $("noteForm").querySelector(`.st-nf[data-for="${kind}"]`);
    f.querySelectorAll("input, textarea").forEach((i) => { if (i.name !== "version") i.value = ""; });
    if (was !== null) f.querySelectorAll('input[name="version"]').forEach((i) => (i.value = "KJV"));
    document.querySelectorAll(".st-kinds [data-kind]").forEach((b) => (b.disabled = false));
    $("notePost").textContent = "Post note"; $("noteCancel").hidden = true;
    $("noteHint").textContent = `Listeners see it ${DELAY} s after you post, when they hear this moment.`;
  }
  function summary(n) {
    if (n.kind === "verse") return `${n.ref} (${n.version})`;
    if (n.kind === "refs") return n.refs.map((r) => r.label).join(", ");
    return n.text.length > 90 ? n.text.slice(0, 88) + "…" : n.text;
  }
  function renderNotes() {
    const now = clock.now();
    $("noteList").replaceChildren(...[...notes].reverse().map((n) => {
      const li = el("li", editing === n.id ? "editing" : ""); li.dataset.id = n.id;
      const head = el("div", "st-nhead");
      head.append(el("b", "", KIND[n.kind] || "Note"), el("time", "", n.at));
      const seenAt = n.posted + DELAY;
      head.append(el("span", seenAt <= now ? "st-seen" : "st-wait", seenAt <= now ? "Showing to listeners" : `Listeners see it at ${at(seenAt, hms)}`));
      li.append(head, el("p", "", summary(n)));
      const acts = el("div", "st-nrm");
      if (confirmRemove === n.id) {
        acts.append(el("span", "", "Remove this note?"));
        const yes = el("button", "btn sm danger", "Remove"), no = el("button", "btn sm", "Keep");
        yes.type = no.type = "button";
        yes.onclick = () => removeNote(n.id); no.onclick = () => { confirmRemove = null; renderNotes(); };
        acts.append(yes, no);
      } else {
        const ed = el("button", "btn sm ghost", editing === n.id ? "Editing…" : "Edit"); ed.type = "button"; ed.disabled = editing === n.id || !(myShow() && live);
        ed.onclick = () => startEdit(n);
        const rm = el("button", "btn sm ghost", "Remove"); rm.type = "button"; rm.onclick = () => { confirmRemove = n.id; renderNotes(); };
        acts.append(ed, rm);
      }
      li.append(acts);
      return li;
    }));
    $("noteNone").hidden = notes.length > 0;
    $("stNotesCount").textContent = notes.length;
  }
  async function removeNote(id) {
    const show = myShow();
    confirmRemove = null;
    if (!show) return;
    const r = await api("note-remove", { body: { live: show.public_id, id } });
    if (!r.error || r.httpStatus === 404) { notes = notes.filter((n) => n.id !== id); if (editing === id) endEdit(); }
    renderNotes();
  }
  const myShow = () => (S && S.show && S.show.mine ? S.show : null);

  // ---- Banner, buttons, refresh ---------------------------------------------------------------
  let bannerOwner = "";
  /** The message under the top bar. Until the studio is on it always offers "Switch on". `owner`
   *  marks messages refresh() puts up and takes down by itself (the rest stay until replaced). */
  function banner(text, kindCls = "", acts = [], owner = "") {
    const b = $("stBanner");
    if (!on && !powering) {
      acts = [["Switch on the studio", powerOn, "primary"], ...acts];
      text = text || "The studio needs your microphone and sound. Wear headphones, so your speakers don’t feed back into the mic.";
    }
    bannerOwner = owner;
    b.className = "st-banner" + (kindCls ? " " + kindCls : "");
    $("stBannerText").textContent = text;
    $("stBannerActs").replaceChildren(...acts.map(([label, fn, cls]) => { const btn = el("button", "btn sm" + (cls ? " " + cls : ""), label); btn.type = "button"; btn.onclick = fn; return btn; }));
    b.hidden = !text;
  }

  function controls() {
    const go = $("stGo"), label = $("stGoLabel");
    go.classList.toggle("on", !!(live && live.sending));
    go.classList.toggle("joining", !!(live && !live.sending));
    if (live) { label.textContent = live.sending ? "On air " + fmt(clock.now() - live.start) : "Joining…"; go.disabled = !live.sending; }
    else { label.textContent = "Go live"; go.disabled = !(on || bridge.connected) || !S || !S.slot.may; }
    ["A", "B"].forEach((k) => {
      const d = decks[k];
      $("p" + k).disabled = !on || !d.buf; $("x" + k).disabled = !on || !d.buf; $("c" + k).disabled = !on || !d.buf;
      $("p" + k).setAttribute("aria-pressed", String(d.playing));
      $("p" + k).lastChild.textContent = d.playing ? "Pause" : "Play";
    });
    ["xf", "fadeBtn", "skipBtn", "talk"].forEach((id) => ($(id).disabled = !on));
    const toB = xfValue <= 0;
    if (S) {
      $("fadeBtn").textContent = `Fade to ${toB ? "B" : "A"} · ${S.settings.crossfade_seconds} s`;
      $("skipBtn").textContent = `Skip · ${S.settings.skip_fade_seconds} s fade`;
    }
    const show = myShow();
    $("notePost").disabled = !(show && live);
    $("stNotesHint").textContent = live ? `Posting to “${live.title}”.` : show ? "Your show is on air from before; end it, then go live again to post." : "Go live to post notes. Listeners see them beside the player.";
  }

  async function refresh() {
    let r;
    try { r = await api("state"); } catch (_) { return; }
    if (r.error) return;
    const first = !S || S.settings.duck_db !== r.settings.duck_db;
    S = r;
    if (first) applyMicUi(); // presets start from the station's ducking depth
    const slot = S.slot, b = slot.block;
    $("stShow").textContent = slot.title + (b ? " · " + (b.dj || S.me.name) : ""); // outside a block the title already names them
    $("stSlot").textContent = b ? (b.end - b.start >= 86400 ? "All day" : `${at(b.start)}–${at(b.end)}`) : (live ? "No set end" : "");
    const scopeChanged = order.scope !== S.order.scope, json = JSON.stringify(S.order.items);
    if (scopeChanged || (!orderDirty && json !== orderJson) || order.editable !== S.order.editable) {
      order = { scope: S.order.scope, items: S.order.items, editable: S.order.editable };
      orderJson = json; orderDirty = false;
      if (scopeChanged) nowItem = -1;
      renderOrder();
    }
    $("stPieceLen").textContent = `${S.settings.segment_seconds}-second pieces`;
    $("stListeners").textContent = `${S.listeners} listening`;
    const show = S.show;
    if (show && show.mine) {
      const ids = (show.notes || []).map((n) => n.id).join();
      if (ids !== notes.map((n) => n.id).join()) { notes = show.notes || []; if (editing !== null && !notes.some((n) => n.id === editing)) endEdit(); renderNotes(); }
    } else if (notes.length && !live) { notes = []; renderNotes(); }
    if (!live && !powering) {
      const rj = slot.rejoin;
      if (show && show.mine) banner(show.own ? `Your show “${show.title}” is still on air from before.` + (rj && rj.open && !on ? " Switch on the studio to rejoin it." : "") : `${show.dj || "Another presenter"} is on air with “${show.title}”. As a manager you can end it.`,
        "end", [...(show.own && rj && rj.open && on ? [["Rejoin your show", () => goLive("rejoin")]] : []), ["End that show", () => endShow(show.public_id), "danger"]], "slot");
      else if (rj && !rj.open) banner(`Your show “${rj.title}” stopped when no audio reached the station. You can carry it on, with its notes, until ${at(rj.until)}.` + (on ? "" : " Switch on the studio first."),
        "end", on ? [["Rejoin your show", () => goLive("rejoin")]] : [], "slot");
      else if (!slot.may) banner(slot.reason + (on ? "" : " You can still switch on the studio to get ready."), "end", [], "slot");
      else if (bannerOwner === "slot") banner("");
    }
    controls();
  }

  function ui() {
    const now = clock.now();
    $("stClock").textContent = at(now, hms);
    const b = S && S.slot.block;
    if (b) {
      $("stShowFill").style.width = Math.max(0, Math.min(100, (now - b.start) / (b.end - b.start) * 100)) + "%";
      $("stLeft").textContent = now < b.start ? `starts in ${fmt(b.start - now)}` : now < b.end ? `${fmt(b.end - now)} left` : `${fmt(now - b.end)} over`;
    } else {
      $("stShowFill").style.width = live ? "100%" : "0%";
      $("stLeft").textContent = live && live.sending ? `on air ${fmt(now - live.start)}` : "";
    }
    ["A", "B"].forEach((k) => { const d = decks[k]; $("r" + k).textContent = "-" + fmt(d.duration - (on ? d.position() : 0)); drawWave(k); });
    if (live) $("stGoLabel").textContent = live.sending ? "On air " + fmt(now - live.start) : "Joining…";
    $("lastSent").textContent = lastSentAt ? `${((performance.now() - lastSentAt) / 1000).toFixed(1)} s ago` : "–";
    $("stQueue").textContent = queue.length;
    const rs = $("stRec"), st = rec ? rec.state() : null;
    if (st && st.error) { rs.textContent = "Recording trouble: " + st.error; rs.className = "st-rec bad"; }
    else if (st && st.recording) { rs.textContent = "● Recording " + fmt(st.seconds); rs.className = "st-rec on"; }
    else if (recUp && recUp.total) { rs.textContent = `Uploading recording ${Math.round(recUp.sent / recUp.total * 100)}%`; rs.className = "st-rec"; }
    else if (recUp && recUp.error) { rs.textContent = "Recording waiting to upload"; rs.className = "st-rec warn"; }
    else rs.textContent = recWaiting ? "Recording waiting to upload" : rec ? "Records your shows" : window.SwRecorder ? "Recorder off" : "";
    const conn = $("stConn");
    if (!live) { conn.textContent = "● Not sending"; conn.className = "st-conn"; }
    else if (lastError && queue.length > 1) { conn.textContent = "● Connection trouble: " + lastError; conn.className = "st-conn bad"; }
    else if (queue.length > 1) { conn.textContent = "● Connection slow"; conn.className = "st-conn warn"; }
    else { conn.textContent = live.sending ? "● Connection good" : "● Joining"; conn.className = "st-conn good"; }
    const seen = notes.filter((n) => n.posted + DELAY <= now).length; // redraw only when a note reaches listeners
    if (seen !== ui.seen) { ui.seen = seen; renderNotes(); }
    if (!on) return;
    const level = (an) => { const buf = new Float32Array(an.fftSize); an.getFloatTimeDomainData(buf); let p = 0; for (const v of buf) p = Math.max(p, Math.abs(v)); return p > 0 ? 20 * Math.log10(p) : -100; };
    const pct = (db) => Math.max(0, Math.min(98, (db + 60) / 60 * 100)) + "%";
    const dbL = level(anL), dbR = level(anR);
    $("mL").style.height = pct(dbL); $("mR").style.height = pct(dbR);
    // Silence detection on air: warn when nothing goes out for DEAD_AIR_S seconds
    const quietDb = S && S.settings.silence_db < 0 ? S.settings.silence_db : -50;
    ui.quietSince = live && live.sending && Math.max(dbL, dbR) < quietDb ? (ui.quietSince ?? now) : null;
    const dead = ui.quietSince != null && now - ui.quietSince >= DEAD_AIR_S;
    if (dead && bannerOwner !== "deadair") banner(`Dead air: nothing has gone out for ${DEAD_AIR_S} seconds. Start a deck or open the mic.`, "end", [], "deadair");
    else if (!dead && bannerOwner === "deadair") banner("");
    const m = chain ? chain.meters() : { outDb: -100, gateOpen: false, reductionDb: 0 };
    $("mMic").style.height = pct(m.outDb);
    const gr = Math.abs(m.reductionDb || 0);
    $("gr").style.width = Math.min(100, gr * 6) + "%"; $("grv").textContent = "-" + gr.toFixed(1) + " dB";
    const lamp = $("gateLamp"); lamp.classList.toggle("open", m.gateOpen); lamp.lastChild.textContent = m.gateOpen ? "Open" : "Closed";
    const du = $("duck"), ducked = ducker && ducker.ducked;
    du.classList.toggle("on", !!ducked);
    du.textContent = ducked ? `Music ducked ${chain.settings.duckDepth} dB while you speak` : talk ? "Mic live · waiting for your voice" : "Music at full level";
  }

  // ---- Wiring ---------------------------------------------------------------------------------
  buildChainUi(); applyMicUi(); wireWave();
  $("stPower").onclick = powerOn;
  $("stGo").onclick = () => (live ? (live.sending ? askEnd() : null) : goLive());
  $("xf").oninput = (e) => { setXf(Number(e.target.value)); controls(); };
  $("fadeBtn").onclick = () => autoFade(S ? S.settings.crossfade_seconds : 6);
  $("skipBtn").onclick = () => autoFade(S ? S.settings.skip_fade_seconds : 2);
  $("talk").onclick = () => setTalk(!talk);
  $("stOutDev").onchange = () => setOutput($("stOutDev").value);
  if (navigator.mediaDevices) navigator.mediaDevices.addEventListener("devicechange", () => { if (on) listDevices().catch(() => {}); });
  $("preset").value = savedMic().preset || "Preacher";
  $("preset").onchange = (e) => { saveMic({ preset: e.target.value, custom: {} }); applyMicUi(); };
  $("dev").onchange = async (e) => { saveMic({ device: e.target.value }); try { await useMic(e.target.value); } catch (err) { banner("That microphone couldn’t start: " + err.message, "end"); } };
  ["A", "B"].forEach((k) => {
    const d = decks[k];
    $("p" + k).onclick = () => (d.playing ? d.pause() : d.play());
    $("x" + k).onclick = () => { d.stop(); controls(); };
    $("c" + k).onclick = () => { d.cue = !d.cue; $("c" + k).setAttribute("aria-pressed", String(d.cue)); d.cueGain.gain.setTargetAtTime(d.cue ? 1 : 0, ctx.currentTime, 0.02); };
    $("v" + k).oninput = (e) => { $("vo" + k).textContent = e.target.value; if (d.vol) d.vol.gain.setTargetAtTime(Number(e.target.value) / 100, ctx.currentTime, 0.02); };
    $("w" + k).onclick = (e) => { if (!d.buf) return; const r = e.target.getBoundingClientRect(); d.seek((e.clientX - r.left) / r.width * d.duration); };
  });
  $("libQ").oninput = () => { clearTimeout(searchTimer); searchTimer = setTimeout(search, 250); };
  $("talkForm").onsubmit = (e) => {
    e.preventDefault();
    const title = $("talkTitle").value.trim(), min = Number($("talkMin").value);
    if (!title || !(min > 0) || order.items.length >= 60) return;
    order.items.push({ kind: "talk", title, seconds: Math.round(min * 60) });
    $("talkTitle").value = "";
    changedOrder();
  };
  $("stNotesBtn").onclick = () => openDrawer($("stDrawer").hidden);
  $("stDrawerClose").onclick = () => openDrawer(false);
  document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !$("stDrawer").hidden) openDrawer(false); });
  document.querySelectorAll(".st-kinds [data-kind]").forEach((b) => (b.onclick = () => setKind(b.dataset.kind)));
  $("noteForm").onsubmit = postNote;
  $("noteCancel").onclick = () => { endEdit(); renderNotes(); };
  if (location.hash === "#notes") openDrawer(true);
  // on air, or the show's recording still uploading (it would carry on the next time the studio opens)
  window.addEventListener("beforeunload", (e) => { if (live || (window.SwRecorder && SwRecorder.busy())) { e.preventDefault(); e.returnValue = ""; } });

  (async () => {
    for (let i = 0; i < 3; i++) await clock.fetch().catch(() => null);
    await refresh();
    if (APP) {
      const st = await APP.core.invoke("bridge_status").catch(() => null);
      if (st) { bridge.status = st; if (st.connected && st.rate) bridgeConnected(st); }
      setInterval(showBridge, 1000); showBridge();
    }
    setInterval(() => clock.fetch().then(preloadOnAir).catch(() => {}), 3000);
    setInterval(refresh, 5000);
    setInterval(() => uploadRec(), 15000); uploadRec(); // what a closed or dropped page left behind
    setInterval(ui, 200);
    setInterval(planAhead, 500);
    ui();
  })();

  window.SwStudio = {
    state: () => ({
      on, handheld, encoder: encReady ? "worker" : null, rec: rec ? { ...rec.state(), upload: recUp, waiting: recWaiting } : null, live: live && { ...live }, sent: sentCount, queue: queue.length, errors: sendErrors, lastError,
      xf: xfValue, talk, mic: chain ? chain.meters().outDb : null, ducked: !!(ducker && ducker.ducked), notes: notes.map((n) => n.id), editing, output: ctx ? { sink: ctx.sinkId ?? null, choices: [...$("stOutDev").options].map((o) => o.value), shown: !$("stOutDev").hidden } : null, order: order.items.map((i) => i.kind + ":" + (i.track_id || i.title)), nowItem,
      slot: S && S.slot, show: S && S.show,
      // Chrome's own count of gaps in the sound engine's output (crackles), where it has playbackStats
      sound: ctx ? { rate: ctx.sampleRate, base: Math.round(ctx.baseLatency * 1000), stats: ctx.playbackStats ? { events: ctx.playbackStats.underrunEvents, ms: Math.round(ctx.playbackStats.underrunDuration * 1000), of: Math.round(ctx.playbackStats.totalDuration) } : null } : null,
      app: !!APP, bridge: APP ? { connected: bridge.connected, frames: bridge.frames, dropped: bridge.dropped, rate: bridge.rate, busy: bridge.busy, status: bridge.status } : null,
      decks: Object.fromEntries(["A", "B"].map((k) => [k, { title: decks[k].item && decks[k].item.title, playing: decks[k].playing, pos: on ? decks[k].position() : 0,
        drift: decks[k].handover && decks[k].playing ? decks[k].position() - (clock.now() - decks[k].handover.start) : null }])),
      events: events.slice(-60),
      serverNow: clock.now(),
      wave: { meter: wave ? wave.kind : null, ahead: wavePlan && ctx ? +((wavePlan.i0 + wavePlan.n) * SwOutWave.STEP - ctx.currentTime).toFixed(1) : null,
        open: !$("stOutBox").hidden, height: Math.round($("stOutCv").getBoundingClientRect().height) },
    }),
    powerOn, goLive, end: () => endShow(),
    // the output strip, for tests: context time now, and the highest peaks (A, B, mic, output)
    // measured or planned between two context times
    wave: {
      now: () => (ctx ? ctx.currentTime : null),
      measured: (t0, t1) => (wave ? SwOutWave.range(wave.cols, t0, t1) : null),
      planned: (t0, t1) => (wavePlan ? SwOutWave.range(wavePlan, t0, t1) : null),
    },
  };
})();
