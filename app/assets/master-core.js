// Song mastering, modelled on the user's Adobe Audition chain: the Multiband Compressor preset
// "Broadcast1", then the Mastering preset "Master1". Measured from Audition's own output (test
// signals and four songs, see tools/mastering-signals.mjs and HANDOVER.md) and fitted to it.
// No DOM: song prep runs it in its worker, the Node tests and the fitting tool run it directly.
// It streams through the song in blocks, so it needs little memory beyond the song and its copy.
//   SwMaster.master(channels, rate, onProgress?) -> [left, right] (Float32Array)
(function (root) {
  "use strict";

  const P = {
    // Multiband Compressor ("Broadcast1"): four bands, split with no phase shift as Audition does
    cross: [137, 1147, 6910],
    threshold: [-20.2, -17.6, -24.8, -25],  // dBFS, on the band's level read as a sine's peak
    ratio: [3, 2, 2, 3],
    knee: 3,                                  // dB, soft knee width
    gain: [1, 0, 0, 3],                       // dB, each band's gain
    detector: "peak",                        // "peak" (held, falling over rmsMs) or "rms"
    rmsMs: 3,                                 // level detector's averaging (or fall) time
    attackMs: 0.5,                            // fitted (the preset says 1 ms; its release 100 ms is the
    releaseMs: 38,                            // detector and gain together, fitted as 3 + 38 ms here)
    // its brickwall limiter: "Thresh" −5.5 and "Margin" −0.6 make 4.9 dB more gain, peaks held at −0.6
    mbThresh: -5.5, mbMargin: -0.6, mbAttackMs: 4, mbReleaseMs: 500,
    // Mastering ("Master1")
    shelfHz: 5000, shelfDb: 1.6, shelfQ: 0.6, // the exciter's ("Tube", 5 %) brightness; the EQ is flat
    exciteHz: 3000, exciteDb: -48,            // the overtones' level against the band they come from
    sideHz: 1800, sideDb: 4.3, sideQ: 0.9,    // stereo widener ("64 %"): a high shelf on the sides only
    maxGain: 3.9,                             // dB, the loudness maximizer's gain
    ceiling: -0.87,                           // dBFS, where the maximizer stops every peak
    limAttackMs: 1.5, limReleaseMs: 4,        // the maximizer's limiter ("24 %")
  };

  // ---- biquads (RBJ cookbook) ----------------------------------------------------------------
  function biquad(type, f, rate, q = Math.SQRT1_2, gainDb = 0) {
    const w = 2 * Math.PI * f / rate, c = Math.cos(w), s = Math.sin(w), al = s / (2 * q);
    const A = Math.pow(10, gainDb / 40);
    let b0, b1, b2, a0, a1, a2;
    if (type === "hp") { b0 = (1 + c) / 2; b1 = -(1 + c); b2 = b0; a0 = 1 + al; a1 = -2 * c; a2 = 1 - al; }
    else { // high shelf
      const sq = 2 * Math.sqrt(A) * al;
      b0 = A * ((A + 1) + (A - 1) * c + sq); b1 = -2 * A * ((A - 1) + (A + 1) * c); b2 = A * ((A + 1) + (A - 1) * c - sq);
      a0 = (A + 1) - (A - 1) * c + sq; a1 = 2 * ((A - 1) - (A + 1) * c); a2 = (A + 1) - (A - 1) * c - sq;
    }
    return [b0 / a0, b1 / a0, b2 / a0, a1 / a0, a2 / a0];
  }
  /** A biquad with its own memory: f(v) filters one sample. */
  function filterOf(k) {
    const [b0, b1, b2, a1, a2] = k;
    let x1 = 0, x2 = 0, y1 = 0, y2 = 0;
    return (v) => { const y = b0 * v + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2; x2 = x1; x1 = v; y2 = y1; y1 = y; return y; };
  }

  // ---- linear-phase band split (Audition's crossovers shift no phase) -------------------------
  // In-place radix-2 complex FFT; inverse when inv. The twiddles come from a table per size.
  const tables = {};
  function twiddles(n) {
    if (!tables[n]) {
      const c = new Float64Array(n / 2), s = new Float64Array(n / 2);
      for (let k = 0; k < n / 2; k++) { c[k] = Math.cos(2 * Math.PI * k / n); s[k] = Math.sin(2 * Math.PI * k / n); }
      tables[n] = [c, s];
    }
    return tables[n];
  }
  function fft(re, im, inv) {
    const n = re.length, [tc, ts] = twiddles(n), sign = inv ? 1 : -1;
    for (let i = 1, j = 0; i < n; i++) {
      let bit = n >> 1;
      for (; j & bit; bit >>= 1) j ^= bit;
      j ^= bit;
      if (i < j) { let t = re[i]; re[i] = re[j]; re[j] = t; t = im[i]; im[i] = im[j]; im[j] = t; }
    }
    for (let len = 2; len <= n; len <<= 1) {
      const h = len >> 1, stride = n / len;
      for (let i = 0; i < n; i += len) {
        for (let k = 0; k < h; k++) {
          const cr = tc[k * stride], ci = sign * ts[k * stride], a = i + k, b = a + h;
          const xr = re[b] * cr - im[b] * ci, xi = re[b] * ci + im[b] * cr;
          re[b] = re[a] - xr; im[b] = im[a] - xi; re[a] += xr; im[a] += xi;
        }
      }
    }
    if (inv) for (let i = 0; i < n; i++) { re[i] /= n; im[i] /= n; }
  }

  // The bands' shapes: Linkwitz-Riley magnitudes (1 / (1 + (f/fc)^4) below each crossover), which
  // add up to exactly 1, as zero-phase FIR filters of TAPS taps, applied by FFT in blocks.
  const TAPS = 8192, BLOCK = 32768, STEP = BLOCK - TAPS;
  function bandSpectra(rate) {
    const lowOf = (f, fc) => 1 / (1 + Math.pow(f / fc, 4));
    const shapes = [
      (f) => lowOf(f, P.cross[0]),
      (f) => lowOf(f, P.cross[1]) - lowOf(f, P.cross[0]),
      (f) => lowOf(f, P.cross[2]) - lowOf(f, P.cross[1]),
    ];
    return shapes.map((shape) => {
      // the impulse response for this shape, centred, windowed (Blackman), then its spectrum
      const re = new Float64Array(TAPS), im = new Float64Array(TAPS);
      for (let k = 0; k <= TAPS / 2; k++) { re[k] = shape(k * rate / TAPS); if (k > 0 && k < TAPS / 2) re[TAPS - k] = re[k]; }
      fft(re, im, true);
      const h = new Float64Array(BLOCK);
      for (let i = 0; i < TAPS; i++) {
        const w = 0.42 - 0.5 * Math.cos(2 * Math.PI * i / (TAPS - 1)) + 0.08 * Math.cos(4 * Math.PI * i / (TAPS - 1));
        h[i] = re[(i + TAPS / 2) % TAPS] * w;
      }
      const hi = new Float64Array(BLOCK);
      fft(h, hi, false);
      return [h, hi];
    });
  }

  /**
   * Stream both channels through the band split, STEP samples at a time, in order:
   * onChunk(from, count, L, R) gets the first three bands of each side (Float64Array, index 0 is
   * sample `from`); the fourth band is what's left of the input. The filters are real, so left and
   * right go through one complex FFT together (left real, right imaginary) and come out apart.
   */
  function splitBlocks(l, r, rate, onChunk, onProgress) {
    const n = l.length, specs = bandSpectra(rate), half = TAPS / 2;
    const tr = specs.map(() => new Float64Array(BLOCK)), ti = specs.map(() => new Float64Array(BLOCK));
    const re = new Float64Array(BLOCK), im = new Float64Array(BLOCK), yr = new Float64Array(BLOCK), yi = new Float64Array(BLOCK);
    // each filter is centred, so a result at t[i] belongs TAPS/2 samples earlier
    for (let s0 = 0; s0 < n + half; s0 += STEP) {
      re.fill(0); im.fill(0);
      for (let i = 0; i < STEP && s0 + i < n; i++) { re[i] = l[s0 + i]; im[i] = r[s0 + i]; }
      fft(re, im, false);
      for (let b = 0; b < specs.length; b++) {
        const [hr, hi] = specs[b];
        for (let k = 0; k < BLOCK; k++) { yr[k] = re[k] * hr[k] - im[k] * hi[k]; yi[k] = re[k] * hi[k] + im[k] * hr[k]; }
        fft(yr, yi, true);
        const a = tr[b], c = ti[b];
        for (let i = 0; i < BLOCK; i++) { a[i] += yr[i]; c[i] += yi[i]; }
      }
      const from = Math.max(0, s0 - half), to = Math.min(n, s0 - half + STEP);
      if (to > from) onChunk(from, to - from, tr.map((a) => a.subarray(from - (s0 - half))), ti.map((a) => a.subarray(from - (s0 - half))));
      for (let b = 0; b < specs.length; b++) {
        tr[b].copyWithin(0, STEP); tr[b].fill(0, BLOCK - STEP); ti[b].copyWithin(0, STEP); ti[b].fill(0, BLOCK - STEP);
      }
      if (onProgress && (s0 / STEP) % 8 === 0) onProgress(Math.min(1, s0 / n));
    }
  }

  /** The four bands of both channels as whole arrays (for tests; the chain itself streams). */
  function split(l, r, rate) {
    const n = l.length, L = [0, 1, 2, 3].map(() => new Float32Array(n)), R = [0, 1, 2, 3].map(() => new Float32Array(n));
    splitBlocks(l, r, rate, (from, count, bl, br) => {
      for (let i = 0; i < count; i++) {
        const o = from + i;
        L[0][o] = bl[0][i]; L[1][o] = bl[1][i]; L[2][o] = bl[2][i]; L[3][o] = l[o] - bl[0][i] - bl[1][i] - bl[2][i];
        R[0][o] = br[0][i]; R[1][o] = br[1][i]; R[2][o] = br[2][i]; R[3][o] = r[o] - br[0][i] - br[1][i] - br[2][i];
      }
    });
    return [L, R];
  }

  const coef = (ms, rate) => Math.exp(-1 / (ms / 1000 * rate));

  /** One band's compressor, linked across both channels: step(l, r) gives the gain (linear) for that sample. */
  function compressor(band, rate) {
    const T = P.threshold[band], R = P.ratio[band], K = P.knee, mk = P.gain[band];
    const cr = coef(P.rmsMs, rate), ca = coef(P.attackMs, rate), cl = coef(P.releaseMs, rate), peak = P.detector === "peak";
    // below the knee with nothing to let go of, the gain is just the band's own: skip the maths
    const quiet = Math.pow(10, (T - K / 2) / 10), flat = Math.pow(10, mk / 20), LN = Math.LN10 / 20, slope = 1 - 1 / R;
    let ms = 0, gr = 0;
    return (l, r) => {
      if (peak) { const v = Math.max(Math.abs(l), Math.abs(r)), p = v * v; ms = p > ms ? p : cr * ms + (1 - cr) * p; }
      else ms = cr * ms + (1 - cr) * (l * l + r * r); // twice the mean square: a sine's peak, as Audition reads it
      if (ms < quiet && gr < 1e-4) { gr = 0; return flat; }
      const over = 10 * Math.log10(ms + 1e-20) - T;
      let want = 0; // dB of reduction
      if (over > K / 2) want = over * slope;
      else if (over > -K / 2) { const o = over + K / 2; want = slope * o * o / (2 * K); }
      gr = want > gr ? ca * gr + (1 - ca) * want : cl * gr + (1 - cl) * want;
      return Math.exp((mk - gr) * LN);
    };
  }

  /**
   * A look-ahead peak limiter, linked across both channels, in place, in one pass: each sample
   * gets the lowest gain any peak in the next attackMs needs (a running minimum), smoothed.
   */
  function limit(l, r, ceilingDb, attackMs, releaseMs, rate) {
    const n = l.length, c = Math.pow(10, ceilingDb / 20), look = Math.max(1, Math.round(attackMs / 1000 * rate));
    const rel = coef(releaseMs, rate), att = coef(attackMs / 3, rate);
    const need = (j) => { const p = Math.max(Math.abs(l[j]), Math.abs(r[j])); return p > c ? c / p : 1; };
    // a ring of (index, need) with rising needs: its head is the window's minimum
    const size = look + 2, qi = new Int32Array(size), qv = new Float64Array(size);
    let h = 0, t = 0;
    const push = (j) => {
      const v = need(j);
      while (t !== h && qv[(t - 1 + size) % size] >= v) t = (t - 1 + size) % size;
      qi[t] = j; qv[t] = v; t = (t + 1) % size;
    };
    for (let j = 0; j < Math.min(look, n); j++) push(j);
    let g = 1;
    for (let i = 0; i < n; i++) {
      if (i + look < n) push(i + look);
      while (qi[h] < i) h = (h + 1) % size;
      const w = qv[h], own = need(i);
      g = w < g ? att * g + (1 - att) * w : rel * g + (1 - rel) * w;
      const v = Math.min(g, own); // never let a peak through
      l[i] *= v; r[i] *= v;
    }
  }

  /** The Multiband Compressor step, into (outL, outR). */
  function multibandInto(l, r, outL, outR, rate, onProgress) {
    const comp = [0, 1, 2, 3].map((b) => compressor(b, rate));
    splitBlocks(l, r, rate, (from, count, bl, br) => {
      for (let i = 0; i < count; i++) {
        const o = from + i;
        const l3 = l[o] - bl[0][i] - bl[1][i] - bl[2][i], r3 = r[o] - br[0][i] - br[1][i] - br[2][i];
        const g0 = comp[0](bl[0][i], br[0][i]), g1 = comp[1](bl[1][i], br[1][i]), g2 = comp[2](bl[2][i], br[2][i]), g3 = comp[3](l3, r3);
        outL[o] = bl[0][i] * g0 + bl[1][i] * g1 + bl[2][i] * g2 + l3 * g3;
        outR[o] = br[0][i] * g0 + br[1][i] * g1 + br[2][i] * g2 + r3 * g3;
      }
    }, onProgress);
    const lg = Math.pow(10, (P.mbMargin - P.mbThresh) / 20);
    for (let i = 0; i < l.length; i++) { outL[i] *= lg; outR[i] *= lg; }
    limit(outL, outR, P.mbMargin, P.mbAttackMs, P.mbReleaseMs, rate);
  }

  /** The Mastering step, in place: brighter, a little wider, overtones in the highs, then louder. */
  function masterInPlace(l, r, rate) {
    const n = l.length, mix = Math.pow(10, P.exciteDb / 20), ce = coef(10, rate);
    const sh = biquad("shelf", P.shelfHz, rate, P.shelfQ, P.shelfDb), hp = biquad("hp", P.exciteHz, rate);
    for (const x of [l, r]) {
      // the high shelf, then the exciter: the highs made into overtones at a fixed level against
      // themselves (a waveshaper on the signal divided by its own level), added back
      const shelf = filterOf(sh), hp1 = filterOf(hp), hp2 = filterOf(hp);
      let env = 1e-9;
      for (let i = 0; i < n; i++) {
        const v = shelf(x[i]), h = hp2(hp1(v));
        const a = Math.abs(h); env = a > env ? a : ce * env + (1 - ce) * a;
        const u = h / (env + 1e-9);
        x[i] = v + mix * env * (u * u - 0.5 + (u * u * u - 0.75 * u));
      }
    }
    // widener: the sides (left minus right) get brighter, the middle stays as it is; then the gain
    const side = filterOf(biquad("shelf", P.sideHz, rate, P.sideQ, P.sideDb)), g = Math.pow(10, P.maxGain / 20);
    for (let i = 0; i < n; i++) {
      const m = (l[i] + r[i]) / 2, s = side((l[i] - r[i]) / 2);
      l[i] = (m + s) * g; r[i] = (m - s) * g;
    }
    limit(l, r, P.ceiling, P.limAttackMs, P.limReleaseMs, rate);
  }

  const stereo = (channels) => (channels.length === 1 ? [channels[0], channels[0]] : channels);

  /** The Multiband Compressor step only. */
  function multiband(channels, rate, onProgress) {
    const [l, r] = stereo(channels), outL = new Float32Array(l.length), outR = new Float32Array(l.length);
    multibandInto(l, r, outL, outR, rate, onProgress);
    return [outL, outR];
  }

  /** The Mastering step only (on a copy). */
  function mastering(channels, rate) {
    const [l, r] = stereo(channels).map((x) => Float32Array.from(x));
    masterInPlace(l, r, rate);
    return [l, r];
  }

  /** Both steps, as in Audition. onProgress(0..1) is told how far it is. */
  function master(channels, rate, onProgress) {
    const [l, r] = stereo(channels), outL = new Float32Array(l.length), outR = new Float32Array(l.length);
    multibandInto(l, r, outL, outR, rate, onProgress && ((f) => onProgress(f * 0.85)));
    masterInPlace(outL, outR, rate);
    if (onProgress) onProgress(1);
    return [outL, outR];
  }

  const api = { P, split, multiband, mastering, master };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.SwMaster = api;
})(typeof self !== "undefined" ? self : this);
