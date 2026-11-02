// The spectrum (the "graphic EQ"), shared by the station's player and the embed: 28 bars on a musical
// (logarithmic) scale, 40 Hz to 16 kHz, read from an AnalyserNode. Bars rise at once and fall smoothly;
// a peak mark holds for 0.6 s, then falls. When playback stops the bars fall to nothing and drawing
// stops. With "reduce motion" set on the device it redraws once a second, without peak marks.
// Colours are the skin's --spec-* tokens. window.SwSpectrum.create(opts):
//   canvas      the <canvas> to draw on
//   analyser()  the AnalyserNode to read, or null
//   on()        whether it is playing
//   cost(ms)    optional: time spent per frame (the Diagnostics panel)
//   root        optional: the element whose tokens give the colours (default <html>)
(() => {
  "use strict";
  function create(opts) {
    const root = opts.root || document.documentElement;
    const spectrum = {
      BANDS: 28, LOW: 40, HIGH: 16000,
      bars: new Float32Array(28), peaks: new Float32Array(28), held: new Float32Array(28), last: 0, running: false, frames: 0, odd: false, ink: null,
      calm: matchMedia("(prefers-reduced-motion: reduce)").matches,
      /** The band (0–27) a frequency falls in. */
      band(f) { return Math.max(0, Math.min(this.BANDS - 1, Math.floor(Math.log(f / this.LOW) / Math.log(this.HIGH / this.LOW) * this.BANDS))); },
      edges() { return Array.from({ length: this.BANDS + 1 }, (_, i) => this.LOW * Math.pow(this.HIGH / this.LOW, i / this.BANDS)); },
      run() { if (!this.running) { this.running = true; this.last = performance.now(); requestAnimationFrame((t) => this.frame(t)); } },
      read() {
        const a = opts.on() && opts.analyser(), out = new Float32Array(this.BANDS);
        if (!a) return out;
        const bins = new Uint8Array(a.frequencyBinCount), hz = a.context.sampleRate / a.fftSize, e = this.edges();
        a.getByteFrequencyData(bins);
        for (let b = 0; b < this.BANDS; b++) {
          // the loudest bin in the band (a low band can be narrower than one bin: then the nearest bin)
          const lo = Math.floor(e[b] / hz), hi = Math.max(lo, Math.ceil(e[b + 1] / hz) - 1);
          let m = 0;
          for (let k = lo; k <= hi && k < bins.length; k++) m = Math.max(m, bins[k]);
          out[b] = m / 255;
        }
        return out;
      },
      frame(t) {
        const dt = Math.min(0.1, (t - this.last) / 1000), on = opts.on();
        if (this.calm && t - this.last < 1000 && on) { requestAnimationFrame((x) => this.frame(x)); return; }
        // 30 frames a second is plenty for bars, and halves the work on a phone
        if (!this.calm && (this.odd = !this.odd) && on) { requestAnimationFrame((x) => this.frame(x)); return; }
        this.last = t;
        const c0 = performance.now();
        const now = this.read();
        let any = false;
        for (let b = 0; b < this.BANDS; b++) {
          this.bars[b] = this.calm ? now[b] : Math.max(now[b], this.bars[b] - 1.6 * dt); // up at once, down smoothly
          if (this.bars[b] >= this.peaks[b]) { this.peaks[b] = this.bars[b]; this.held[b] = 0.6; }
          else if ((this.held[b] -= dt) < 0) this.peaks[b] = Math.max(this.bars[b], this.peaks[b] - 0.5 * dt);
          if (this.bars[b] > 0.005 || this.peaks[b] > 0.005) any = true;
        }
        this.draw();
        if (opts.cost) opts.cost(performance.now() - c0);
        this.frames++;
        if (opts.on() || any) requestAnimationFrame((x) => this.frame(x));
        else { this.running = false; this.bars.fill(0); this.peaks.fill(0); this.draw(); }
      },
      draw() {
        const c = opts.canvas;
        if (!c) return;
        const dpr = window.devicePixelRatio || 1, w = c.clientWidth, h = c.clientHeight;
        if (!w || !h) return;
        if (c.width !== Math.round(w * dpr) || c.height !== Math.round(h * dpr)) { c.width = Math.round(w * dpr); c.height = Math.round(h * dpr); }
        // the colours, read from the look once (reading styles every frame makes the browser work them out again)
        if (!this.ink) { const tok = (n) => getComputedStyle(root).getPropertyValue(n).trim(); this.ink = { hi: tok("--spec-hi"), mid: tok("--spec-mid"), lo: tok("--spec-lo"), pk: tok("--spec-peak") }; }
        const g = c.getContext("2d"), { hi, mid, lo, pk } = this.ink;
        g.setTransform(dpr, 0, 0, dpr, 0, 0); g.clearRect(0, 0, w, h);
        const n = this.BANDS, gap = 2, bw = (w - (n - 1) * gap) / n;
        // 2 px segments, drawn one colour (one height zone) at a time
        for (const [ink, from, to] of [[lo, h * 0.6, h + 1], [mid, h * 0.3, h * 0.6], [hi, -1, h * 0.3]]) {
          g.fillStyle = ink;
          for (let b = 0; b < n; b++) {
            const x = b * (bw + gap), top = h - this.bars[b] * h;
            for (let y = h; y > top + 0.5; y -= 3) if (y >= from && y < to) g.fillRect(x, y - 2, bw, 2);
          }
        }
        if (!this.calm) {
          g.fillStyle = pk;
          for (let b = 0; b < n; b++) if (this.peaks[b] > 0.02) g.fillRect(b * (bw + gap), h - this.peaks[b] * h - 2, bw, 1.5);
        }
      },
      /** For tests: the loudest band and its range, how many bars show, frames drawn. */
      state() {
        let top = 0;
        for (let b = 1; b < this.BANDS; b++) if (this.bars[b] > this.bars[top]) top = b;
        const e = this.edges();
        return { running: this.running, calm: this.calm, frames: this.frames, top, topHz: [Math.round(e[top]), Math.round(e[top + 1])], level: +this.bars[top].toFixed(3),
          lit: [...this.bars].filter((v) => v > 0.05).length, bars: [...this.bars].map((v) => +v.toFixed(2)) };
      },
    };
    window.addEventListener("sw:skin", () => { spectrum.ink = null; spectrum.draw(); }); // the viewer changed the look
    return spectrum;
  }

  /** An analyser set up as the spectrum wants it, fed from `node`. */
  function analyser(ctx, node) {
    const a = ctx.createAnalyser();
    a.fftSize = 4096; a.smoothingTimeConstant = 0.5; a.minDecibels = -90; a.maxDecibels = -20;
    node.connect(a);
    return a;
  }

  window.SwSpectrum = { create, analyser };
})();
