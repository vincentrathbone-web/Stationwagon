// Song mastering, modelled on the user's Adobe Audition chain: the Multiband Compressor preset
// "Broadcast1", then the Mastering preset "Master1". The stages were measured from Audition's own
// output (test signals and four songs, see tools/mastering-signals.mjs and HANDOVER.md) and fitted
// to it; prep_test.mjs still checks them against those measurements with Audition's settings.
// Since round 86 the built-in settings (P) are WordWave's tuning of that chain, its "Audition2"
// preset (the user, 2026-11-13: WordWave's sound is the default for every station).
// No DOM: song prep runs it in its worker, the Node tests and the fitting tool run it directly.
// It streams through the song in blocks, so it needs little memory beyond the song and its copy.
// A preset (the Song prep screen's, round 40) changes any setting; without one it is P, the
// built-in "Audition chain" (WordWave's Audition2 since round 86). In front of it there is a five-band EQ, flat in P.
//   SwMaster.master(channels, rate, onProgress?, preset?, meterStep?) -> [left, right] (Float32Array),
//   with .meter (dB of reduction every meterStep samples: .bands[4], .mb, .lim) when meterStep is given
(function (root) {
  "use strict";

  // The values a preset can set are WordWave's "Audition2" (round 86); the comments give what Audition's
  // own presets measured as (prep_test.mjs keeps those numbers to check the stages against Audition).
  const P = {
    // Multiband Compressor ("Broadcast1"): four bands, split with no phase shift as Audition does
    cross: [137, 1147, 7941],                 // Hz (Audition: 137, 1147, 6910)
    threshold: [-18, -15.4, -22.6, -22.8],    // dBFS, on the band's level read as a sine's peak (Audition: -20.2, -17.6, -24.8, -25)
    ratio: [2.44, 1.72, 1.72, 2.44],          // (Audition: 3, 2, 2, 3)
    knee: 2,                                  // dB, soft knee width (Audition: 3)
    gain: [1.5, 0, 0, 3],                     // dB, each band's gain (Audition: 1, 0, 0, 3)
    detector: "peak",                        // "peak" (held, falling over rmsMs) or "rms"
    rmsMs: 3,                                 // level detector's averaging (or fall) time
    attackMs: 0.3,                            // (Audition fitted: 0.5; its preset says 1 ms)
    releaseMs: 411,                           // (Audition fitted: 38, with the detector's 3 ms; its preset says 100)
    // its brickwall limiter: "Thresh" −5.5 and "Margin" −0.6 make 4.9 dB more gain, peaks held at −0.6
    mbThresh: -5.5, mbMargin: -0.6, mbAttackMs: 4, mbReleaseMs: 500,
    // Mastering ("Master1")
    shelfHz: 5000, shelfDb: -1.7, shelfQ: 0.6, // the exciter's ("Tube") brightness (Audition: +1.6 dB at 5 %)
    exciteHz: 3000, exciteDb: -72,            // the overtones' level against the band they come from (Audition: -48)
    sideHz: 1800, sideDb: 6.8, sideQ: 0.9,    // stereo widener: a high shelf on the sides only (Audition "64 %": 4.3)
    maxGain: 2.7,                             // dB, the loudness maximizer's gain (Audition: 3.9)
    ceiling: -0.1,                            // dBFS, where the maximizer stops every peak (Audition: -0.87)
    limAttackMs: 1.5, limReleaseMs: 4,        // the maximizer's limiter ("24 %")
    // round 40: the EQ in front (flat), and each section can be switched off; the flat EQ is off (round 86)
    eqOn: false, compOn: true, finishOn: true,
    eq: { hp: { on: false, hz: 30 }, low: { hz: 100, db: 0 }, bells: [{ hz: 250, db: 0, q: 1 }, { hz: 1000, db: 0, q: 1 }, { hz: 4000, db: 0, q: 1 }], high: { hz: 10000, db: 0 } },
  };

  // What a preset may set, and within what: [min, max] for numbers, per band where it is a list.
  const RANGES = {
    cross: [[40, 400], [400, 3000], [2000, 12000]], threshold: [-60, 0], ratio: [1, 20], knee: [0, 12], gain: [-12, 12],
    attackMs: [0.1, 100], releaseMs: [5, 2000], mbThresh: [-20, 0], mbMargin: [-6, 0],
    shelfDb: [-12, 12], exciteDb: [-120, -20], sideDb: [-12, 12], maxGain: [0, 12], ceiling: [-6, -0.1],
    hz: [20, 20000], db: [-15, 15], q: [0.2, 10], hpHz: [20, 300],
  };
  const clamp = (v, [a, b], d) => (typeof v === "number" && isFinite(v) ? Math.min(b, Math.max(a, v)) : d);
  const yes = (v, d) => (typeof v === "boolean" ? v : d);
  /** A preset made safe: P with whatever of `over` is known and in range. */
  function preset(over) {
    const o = over && typeof over === "object" ? over : {}, q = JSON.parse(JSON.stringify(P));
    const list = (k, r) => { if (Array.isArray(o[k])) q[k] = q[k].map((d, i) => clamp(o[k][i], Array.isArray(r[0]) ? r[i] : r, d)); };
    // crossovers: in order first, then each in its range and at least 1.5 times the one below
    if (Array.isArray(o.cross)) {
      const c = [0, 1, 2].map((i) => (typeof o.cross[i] === "number" && isFinite(o.cross[i]) ? o.cross[i] : P.cross[i])).sort((x, y) => x - y);
      q.cross = c.map((v, i) => clamp(v, RANGES.cross[i], P.cross[i]));
      for (let i = 1; i < 3; i++) q.cross[i] = Math.min(RANGES.cross[i][1], Math.max(q.cross[i], q.cross[i - 1] * 1.5));
    }
    list("threshold", RANGES.threshold); list("ratio", RANGES.ratio); list("gain", RANGES.gain);
    for (const k of ["knee", "attackMs", "releaseMs", "mbThresh", "mbMargin", "shelfDb", "exciteDb", "sideDb", "maxGain", "ceiling"]) q[k] = clamp(o[k], RANGES[k], q[k]);
    for (const k of ["eqOn", "compOn", "finishOn"]) q[k] = yes(o[k], q[k]);
    const e = o.eq && typeof o.eq === "object" ? o.eq : {};
    if (e.hp) { q.eq.hp.on = yes(e.hp.on, false); q.eq.hp.hz = clamp(e.hp.hz, RANGES.hpHz, 30); }
    for (const k of ["low", "high"]) if (e[k]) { q.eq[k].hz = clamp(e[k].hz, RANGES.hz, q.eq[k].hz); q.eq[k].db = clamp(e[k].db, RANGES.db, 0); }
    if (Array.isArray(e.bells)) q.eq.bells = q.eq.bells.map((d, i) => { const b = e.bells[i] || {}; return { hz: clamp(b.hz, RANGES.hz, d.hz), db: clamp(b.db, RANGES.db, 0), q: clamp(b.q, RANGES.q, d.q) }; });
    return q;
  }

  /**
   * The simple view's four controls, worked out into the full settings (from the built-in chain, P):
   * amount 0..1 (0.5 is the built-in chain; 0 lets everything through, 1 squeezes hard), punch 0..1
   * (slower attack and release let the starts of notes through), loudness (the maximizer's dB) and
   * ceiling (dBFS).
   */
  function simple({ amount = 0.5, punch = 0, loudness = P.maxGain, ceiling = P.ceiling } = {}) {
    const t = (Math.min(1, Math.max(0, amount)) - 0.5) * 2, pu = Math.min(1, Math.max(0, punch)); // t: -1..1
    return {
      ratio: P.ratio.map((r) => +(t >= 0 ? r + t * r * 2 : 1 + (r - 1) * (1 + t)).toFixed(2)),
      threshold: P.threshold.map((v) => +(v - t * 8).toFixed(1)),
      attackMs: +(P.attackMs + pu * 29.5).toFixed(1),
      releaseMs: Math.round(P.releaseMs + pu * 80),
      maxGain: clamp(loudness, RANGES.maxGain, P.maxGain), ceiling: clamp(ceiling, RANGES.ceiling, P.ceiling),
    };
  }

  // ---- biquads (RBJ cookbook) ----------------------------------------------------------------
  function biquad(type, f, rate, q = Math.SQRT1_2, gainDb = 0) {
    const w = 2 * Math.PI * f / rate, c = Math.cos(w), s = Math.sin(w), al = s / (2 * q);
    const A = Math.pow(10, gainDb / 40);
    let b0, b1, b2, a0, a1, a2;
    if (type === "hp") { b0 = (1 + c) / 2; b1 = -(1 + c); b2 = b0; a0 = 1 + al; a1 = -2 * c; a2 = 1 - al; }
    else if (type === "bell") { b0 = 1 + al * A; b1 = -2 * c; b2 = 1 - al * A; a0 = 1 + al / A; a1 = -2 * c; a2 = 1 - al / A; }
    else if (type === "low") { // low shelf
      const sq = 2 * Math.sqrt(A) * al;
      b0 = A * ((A + 1) - (A - 1) * c + sq); b1 = 2 * A * ((A - 1) - (A + 1) * c); b2 = A * ((A + 1) - (A - 1) * c - sq);
      a0 = (A + 1) + (A - 1) * c + sq; a1 = -2 * ((A - 1) + (A + 1) * c); a2 = (A + 1) + (A - 1) * c - sq;
    } else { // high shelf
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
  function bandSpectra(rate, p = P) {
    const lowOf = (f, fc) => 1 / (1 + Math.pow(f / fc, 4));
    const shapes = [
      (f) => lowOf(f, p.cross[0]),
      (f) => lowOf(f, p.cross[1]) - lowOf(f, p.cross[0]),
      (f) => lowOf(f, p.cross[2]) - lowOf(f, p.cross[1]),
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
  function splitBlocks(l, r, rate, onChunk, onProgress, p = P) {
    const n = l.length, specs = bandSpectra(rate, p), half = TAPS / 2;
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
  function compressor(band, rate, p = P) {
    const T = p.threshold[band], R = p.ratio[band], K = p.knee, mk = p.gain[band];
    const cr = coef(p.rmsMs, rate), ca = coef(p.attackMs, rate), cl = coef(p.releaseMs, rate), peak = p.detector === "peak";
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
  function limit(l, r, ceilingDb, attackMs, releaseMs, rate, meter = null, into = null) {
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
      if (into) { const k = (i / meter.step) | 0; if (v < into[k]) into[k] = v; }
    }
    if (into) for (let k = 0; k < into.length; k++) into[k] = -20 * Math.log10(into[k]);
  }

  /** The EQ in front, in place: high-pass, low shelf, three bells, high shelf (only what does something). */
  function eqInPlace(l, r, rate, p) {
    const e = p.eq, ks = [];
    if (e.hp.on) ks.push(biquad("hp", e.hp.hz, rate));
    if (e.low.db) ks.push(biquad("low", e.low.hz, rate, Math.SQRT1_2, e.low.db));
    for (const b of e.bells) if (b.db) ks.push(biquad("bell", b.hz, rate, b.q, b.db));
    if (e.high.db) ks.push(biquad("shelf", e.high.hz, rate, Math.SQRT1_2, e.high.db));
    for (const k of ks) for (const x of [l, r]) { const f = filterOf(k); for (let i = 0; i < x.length; i++) x[i] = f(x[i]); }
  }

  /** dB-of-reduction lists for the meters: one value every `step` samples (gains while measuring). */
  function meterFor(n, step) {
    const k = Math.ceil(n / step), one = () => new Float32Array(k).fill(1);
    return { step, bands: [one(), one(), one(), one()], mb: one(), lim: one() };
  }

  /** The Multiband Compressor step, into (outL, outR). */
  function multibandInto(l, r, outL, outR, rate, onProgress, p = P, meter = null) {
    const comp = [0, 1, 2, 3].map((b) => compressor(b, rate, p)), flat = p.gain.map((g) => Math.pow(10, g / 20));
    const mt = meter && meter.bands;
    splitBlocks(l, r, rate, (from, count, bl, br) => {
      for (let i = 0; i < count; i++) {
        const o = from + i;
        const l3 = l[o] - bl[0][i] - bl[1][i] - bl[2][i], r3 = r[o] - br[0][i] - br[1][i] - br[2][i];
        const g0 = comp[0](bl[0][i], br[0][i]), g1 = comp[1](bl[1][i], br[1][i]), g2 = comp[2](bl[2][i], br[2][i]), g3 = comp[3](l3, r3);
        outL[o] = bl[0][i] * g0 + bl[1][i] * g1 + bl[2][i] * g2 + l3 * g3;
        outR[o] = br[0][i] * g0 + br[1][i] * g1 + br[2][i] * g2 + r3 * g3;
        if (mt) {
          const k = (o / meter.step) | 0, gs = [g0, g1, g2, g3];
          for (let b = 0; b < 4; b++) { const v = gs[b] / flat[b]; if (v < mt[b][k]) mt[b][k] = v; }
        }
      }
    }, onProgress, p);
    if (mt) for (const a of mt) for (let k = 0; k < a.length; k++) a[k] = -20 * Math.log10(a[k]);
    const lg = Math.pow(10, (p.mbMargin - p.mbThresh) / 20);
    for (let i = 0; i < l.length; i++) { outL[i] *= lg; outR[i] *= lg; }
    limit(outL, outR, p.mbMargin, p.mbAttackMs, p.mbReleaseMs, rate, meter, meter && meter.mb);
  }

  /** The Mastering step, in place: brighter, a little wider, overtones in the highs, then louder. */
  function masterInPlace(l, r, rate, p = P, meter = null) {
    const n = l.length, mix = Math.pow(10, p.exciteDb / 20), ce = coef(10, rate);
    const sh = biquad("shelf", p.shelfHz, rate, p.shelfQ, p.shelfDb), hp = biquad("hp", p.exciteHz, rate);
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
    const side = filterOf(biquad("shelf", p.sideHz, rate, p.sideQ, p.sideDb)), g = Math.pow(10, p.maxGain / 20);
    for (let i = 0; i < n; i++) {
      const m = (l[i] + r[i]) / 2, s = side((l[i] - r[i]) / 2);
      l[i] = (m + s) * g; r[i] = (m - s) * g;
    }
    limit(l, r, p.ceiling, p.limAttackMs, p.limReleaseMs, rate, meter, meter && meter.lim);
  }

  const stereo = (channels) => (channels.length === 1 ? [channels[0], channels[0]] : channels);

  /** The Multiband Compressor step only (with a preset's settings when given, else P). */
  function multiband(channels, rate, onProgress, given) {
    const [l, r] = stereo(channels), outL = new Float32Array(l.length), outR = new Float32Array(l.length);
    multibandInto(l, r, outL, outR, rate, onProgress, given ? preset(given) : P);
    return [outL, outR];
  }

  /** The Mastering step only (on a copy; with a preset's settings when given, else P). */
  function mastering(channels, rate, given) {
    const [l, r] = stereo(channels).map((x) => Float32Array.from(x));
    masterInPlace(l, r, rate, given ? preset(given) : P);
    return [l, r];
  }

  /**
   * The whole chain: the EQ, the Multiband Compressor, then the Mastering step, each as the preset
   * says (P, the built-in chain, without one). onProgress(0..1) is told how far it is. With
   * meterStep, the result carries .meter: each band's, the multiband limiter's and the final
   * limiter's dB of reduction, the most in each meterStep samples (0 for a section switched off).
   */
  function master(channels, rate, onProgress, given, meterStep) {
    const p = given ? preset(given) : P, [l0, r0] = stereo(channels), n = l0.length;
    const meter = meterStep ? meterFor(n, meterStep) : null;
    let l = l0, r = r0;
    if (p.eqOn) { l = Float32Array.from(l0); r = Float32Array.from(r0); eqInPlace(l, r, rate, p); }
    let outL = l, outR = r;
    if (p.compOn) {
      outL = new Float32Array(n); outR = new Float32Array(n);
      multibandInto(l, r, outL, outR, rate, onProgress && ((f) => onProgress(f * 0.85)), p, meter);
    } else if (l === l0) { outL = Float32Array.from(l0); outR = Float32Array.from(r0); }
    if (p.finishOn) masterInPlace(outL, outR, rate, p, meter);
    if (meter) {
      if (!p.compOn) { for (const a of meter.bands) a.fill(0); meter.mb.fill(0); }
      if (!p.finishOn) meter.lim.fill(0);
    }
    if (onProgress) onProgress(1);
    const out = [outL, outR];
    if (meter) out.meter = meter;
    return out;
  }

  const api = { P, RANGES, preset, simple, biquad, split, multiband, mastering, master };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.SwMaster = api;
})(typeof self !== "undefined" ? self : this);
