// The output strip, shared by the player and the studio (the "Output waveform" prototype, see
// design/DESIGN.md). It shows the wet mix scrolling right to left past a centred "now": each source
// in its own colour, stacked and mirrored around the middle, the output itself as a white line,
// over faint level lines in dBFS. Left of now is what was measured going out (a meter inside the
// sound engine, outwave-meter.js); right of now, dimmed, is the coming output, mixed ahead by the
// page from audio it already holds (render). window.SwOutWave.
(() => {
  "use strict";
  const STEP = 0.01;                  // one column: 10 ms
  const KEEP = 45;                    // seconds of measured history kept
  const LEVELS = [-1, -6, -12, -20];  // level lines; −1 dB is song prep's peak ceiling

  /** Peaks per 10 ms column for k series (the sources, then the output), in a ring. */
  class Columns {
    constructor(k, seconds) {
      this.k = k; this.N = Math.ceil(seconds / STEP);
      this.idx = new Float64Array(this.N).fill(-1); this.v = new Float32Array(this.N * k); this.last = -Infinity;
    }
    put(i, vals) {
      const s = ((i % this.N) + this.N) % this.N, o = s * this.k;
      if (this.idx[s] !== i) { this.idx[s] = i; this.v.fill(0, o, o + this.k); }
      for (let j = 0; j < this.k; j++) if (vals[j] > this.v[o + j]) this.v[o + j] = vals[j];
      if (i > this.last) this.last = i;
    }
    get(i, j) { const s = ((i % this.N) + this.N) % this.N; return this.idx[s] === i ? this.v[s * this.k + j] : null; }
  }

  /** The highest peak of each series between context times t0 and t1, or null if none (tests). */
  function range(cols, t0, t1) {
    if (!cols) return null;
    let out = null;
    for (let i = Math.floor(t0 / STEP); i < Math.ceil(t1 / STEP); i++) {
      if (cols.get(i, 0) === null) continue;
      out = out || new Array(cols.k).fill(0);
      for (let j = 0; j < cols.k; j++) out[j] = Math.max(out[j], cols.get(i, j));
    }
    return out;
  }

  /**
   * Measure the wet signals `taps` (sources first, the output last; a source may be null) inside
   * the sound engine. Returns { cols, kind, stop() }; kind is "sample-exact" with the meter
   * worklet at `url`, or "approximate" where AudioWorklet is missing (analysers, polled).
   */
  async function meter(ctx, taps, url) {
    const k = taps.length, cols = new Columns(k, KEEP), m = { cols, kind: "starting", stop() {} };
    // a block may span two columns: it counts in each
    const put = (t0, dur, peaks) => { for (let i = Math.floor(t0 / STEP + 1e-6); i * STEP < t0 + dur - 1e-6; i++) cols.put(i, peaks); };
    try {
      await ctx.audioWorklet.addModule(url);
      const node = new AudioWorkletNode(ctx, "sw-outmeter", { numberOfInputs: k, numberOfOutputs: 1, outputChannelCount: [1], processorOptions: { inputs: k } });
      taps.forEach((t, j) => { if (t) t.connect(node, 0, j); });
      const quiet = ctx.createGain(); quiet.gain.value = 0;
      node.connect(quiet).connect(ctx.destination); // keeps the engine pulling the meter
      node.port.onmessage = (e) => { const b = e.data, w = k + 2; for (let o = 0; o + w <= b.length; o += w) put(b[o], b[o + 1], b.slice(o + 2, o + w)); };
      m.kind = "sample-exact";
      m.stop = () => { node.port.onmessage = null; try { node.disconnect(); quiet.disconnect(); taps.forEach((t) => t && t.disconnect(node)); } catch (_) { /* gone */ } };
    } catch (_) {
      // each poll reads the last 46 ms, so polls a little apart leave no gaps
      const an = taps.map((t) => { if (!t) return null; const a = ctx.createAnalyser(); a.fftSize = 2048; t.connect(a); return a; });
      const buf = new Float32Array(2048);
      const timer = setInterval(() => {
        if (ctx.state !== "running") return;
        const block = Math.round(ctx.sampleRate * STEP), n = Math.floor(2048 / block), end = ctx.currentTime;
        const p = Array.from({ length: n }, () => new Float32Array(k));
        an.forEach((a, j) => {
          if (!a) return;
          a.getFloatTimeDomainData(buf);
          for (let c = 0; c < n; c++) for (let s = 2048 - (c + 1) * block; s < 2048 - c * block; s++) { const v = buf[s] < 0 ? -buf[s] : buf[s]; if (v > p[c][j]) p[c][j] = v; }
        });
        for (let c = n - 1; c >= 0; c--) put(end - (c + 1) * STEP, STEP, p[c]);
      }, 20);
      m.kind = "approximate";
      m.stop = () => { clearInterval(timer); an.forEach((a) => a && a.disconnect()); };
    }
    return m;
  }

  /**
   * Mix ahead: per 10 ms column, the peak of each source's wet signal and of their sum (the output)
   * between context times t0 and t1. sources: [{ slot, ch: [left, right], rate, start (the context
   * time of sample 0), gain(t) (the gain the mix will apply at context time t) }], all at one rate.
   */
  function render(sources, t0, t1, k) {
    const i0 = Math.floor(t0 / STEP), n = Math.max(0, Math.ceil(t1 / STEP) - i0), v = new Float32Array(n * k);
    const out = { i0, n, k, v, get(i, j) { const c = i - this.i0; return c >= 0 && c < this.n ? this.v[c * this.k + j] : null; } };
    if (!sources.length) return out;
    const rate = sources[0].rate, len = Math.round(rate * STEP), L = new Float32Array(len), R = new Float32Array(len);
    for (let c = 0; c < n; c++) {
      const tc = (i0 + c) * STEP;
      let any = false;
      L.fill(0); R.fill(0);
      for (const s of sources) {
        const from = Math.round((tc - s.start) * rate), a = s.ch[0], b = s.ch[1] || a;
        if (from + len <= 0 || from >= a.length) continue;
        const g = s.gain(tc + STEP / 2);
        if (!(g > 1e-5)) continue;
        let p = v[c * k + s.slot];
        for (let j = Math.max(0, -from), e = Math.min(len, a.length - from); j < e; j++) {
          const x = a[from + j] * g, y = b[from + j] * g;
          L[j] += x; R[j] += y;
          const q = Math.max(x < 0 ? -x : x, y < 0 ? -y : y);
          if (q > p) p = q;
        }
        v[c * k + s.slot] = p; any = true;
      }
      if (any) { let m = 0; for (let j = 0; j < len; j++) { const x = L[j] < 0 ? -L[j] : L[j], y = R[j] < 0 ? -R[j] : R[j]; if (x > m) m = x; if (y > m) m = y; } v[c * k + k - 1] = m; }
    }
    return out;
  }

  /**
   * Draw the strip on `canvas` (about 30 frames a second while it is on screen). opts: colors (one
   * per series, the output last), measured() (Columns or null), plan() (render() result or null),
   * now() (the context time in the middle), span() (seconds shown), marks() ([{ t, color }]).
   */
  function strip(canvas, opts) {
    const c = canvas.getContext("2d"), css = (n, f) => getComputedStyle(canvas).getPropertyValue(n).trim() || f;
    let warn, ink, mono, line, labelBg;
    const colours = () => { warn = css("--warn", "#ffa42b"); ink = css("--ink", "#ffffff"); mono = css("--f-mono", "monospace"); line = css("--wave-grid", "rgba(255,255,255,.1)"); labelBg = css("--wave-label-bg", "rgba(18,18,18,.55)"); };
    colours();
    window.addEventListener("sw:skin", colours); // the viewer changed the look
    let raf = 0, running = false, odd = false;

    /** Level lines on both halves, faint on purpose; −1 dB dashed amber. Labels on the left. */
    function grid(w, mid, half, fs) {
      const lines = [];
      c.save(); c.lineWidth = 1;
      for (const L of LEVELS) {
        const off = Math.pow(10, L / 20) * half, ceiling = L === -1;
        if (off < 2) continue; // a short strip: too close to the middle
        lines.push([L, off]);
        c.strokeStyle = ceiling ? warn : line; c.globalAlpha = ceiling ? 0.35 : 1; c.setLineDash(ceiling ? [4, 3] : []);
        for (const s of [-1, 1]) { const y = Math.round(mid - s * off) + 0.5; c.beginPath(); c.moveTo(0, y); c.lineTo(w, y); c.stroke(); }
      }
      c.setLineDash([]);
      c.font = `500 ${fs}px ${mono}`; c.textBaseline = "middle";
      let prev = -1e9;
      for (const [L, off] of lines) {
        const y = Math.max(fs / 2 + 1, mid - off);
        if (y - prev < fs) continue;
        const label = "−" + Math.abs(L), tw = c.measureText(label).width;
        c.globalAlpha = 1; c.fillStyle = labelBg; c.fillRect(1, y - fs / 2 - 1, tw + 6, fs + 2);
        c.globalAlpha = 0.5; c.fillStyle = L === -1 ? warn : ink; c.fillText(label, 4, y + 0.5);
        prev = y;
      }
      c.restore();
    }

    function draw() {
      const r = canvas.getBoundingClientRect(), d = window.devicePixelRatio || 1, w = r.width, h = r.height;
      if (!w || !h) return;
      if (canvas.width !== Math.round(w * d) || canvas.height !== Math.round(h * d)) { canvas.width = Math.round(w * d); canvas.height = Math.round(h * d); }
      c.setTransform(d, 0, 0, d, 0, 0); c.clearRect(0, 0, w, h);
      const k = opts.colors.length, ns = k - 1, mid = h / 2, half = h / 2 - 1;
      const meas = opts.measured(), plan = opts.plan ? opts.plan() : null, now = opts.now(), span = opts.span();
      // one column per CSS pixel: on a phone's 2.75x screen that is a third of the work, and looks the same
      const t0 = now - span / 2, pps = w / span, cw = 1, line = [], vals = new Float32Array(k);
      if (meas || plan) {
        for (let xd = 0, nx = Math.round(w); xd < nx; xd++) {
          const x = xd * cw, ta = t0 + x / pps, ia = Math.floor(ta / STEP), ib = Math.max(ia, Math.floor((ta + cw / pps) / STEP));
          let measured = false, planned = false;
          vals.fill(0);
          if (meas && ia <= meas.last) for (let i = ia; i <= ib; i++) { if (meas.get(i, 0) === null) continue; measured = true; for (let j = 0; j < k; j++) vals[j] = Math.max(vals[j], meas.get(i, j)); }
          if (!measured && plan && ta >= now - STEP) for (let i = ia; i <= ib; i++) { if (plan.get(i, 0) === null) continue; planned = true; for (let j = 0; j < k; j++) vals[j] = Math.max(vals[j], plan.get(i, j)); }
          if (!measured && !planned) { line.push(null); continue; }
          let total = 0;
          for (let j = 0; j < ns; j++) total += vals[j];
          if (total > 1e-6) {
            const H = Math.min(1, total) * half;
            let acc = 0;
            c.globalAlpha = measured ? 0.92 : 0.32;
            for (let j = 0; j < ns; j++) {
              if (vals[j] <= 0) continue;
              const part = vals[j] / total * H;
              c.fillStyle = opts.colors[j];
              c.fillRect(x, mid - acc - part, cw, part); c.fillRect(x, mid + acc, cw, part);
              acc += part;
            }
          }
          line.push([x, vals[k - 1], measured]);
        }
        // the output: solid where measured, faint where planned
        c.strokeStyle = opts.colors[k - 1]; c.lineWidth = 1;
        for (const [alpha, which] of [[0.9, true], [0.4, false]]) {
          c.globalAlpha = alpha;
          for (const s of [-1, 1]) {
            c.beginPath();
            let pen = false;
            for (const p of line) {
              if (!p || p[2] !== which || p[1] <= 1e-6) { pen = false; continue; }
              const y = mid - s * Math.min(1, p[1]) * half;
              if (pen) c.lineTo(p[0], y); else { c.moveTo(p[0], y); pen = true; }
            }
            c.stroke();
          }
        }
        c.globalAlpha = 1;
      }
      grid(w, mid, half, h >= 90 ? 10 : 9);
      for (const mk of opts.marks ? opts.marks() : []) {
        const x = Math.round((mk.t - t0) * pps);
        if (x < 0 || x >= w) continue;
        c.fillStyle = mk.color; c.fillRect(x, 0, 1, 6); c.fillRect(x, h - 6, 1, 6);
      }
      c.fillStyle = ink; c.fillRect(Math.round(w / 2) - 1, 0, 2, h);
    }
    function loop() {
      raf = 0;
      if (!running) return;
      odd = !odd;
      if (odd && !document.hidden && canvas.offsetParent !== null) {
        const t0 = performance.now();
        draw();
        if (opts.cost) opts.cost(performance.now() - t0);
      }
      raf = requestAnimationFrame(loop);
    }
    return {
      start() { if (!running) { running = true; loop(); } },
      stop() { running = false; if (raf) cancelAnimationFrame(raf); raf = 0; draw(); },
      draw,
    };
  }

  window.SwOutWave = { STEP, Columns, range, meter, render, strip };
})();
