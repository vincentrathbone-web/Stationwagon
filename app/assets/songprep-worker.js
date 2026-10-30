// The Song prep screen's renderer (assets/songprep.js): runs pieces of a song through the same
// mastering chain song prep uses on import (master-core.js), off the main thread.
// In:  { load: true, channels: [L, R], rate }   the song (kept here; pieces are cut from it)
//      { measure: true }                        the original's loudness, for the A/B level match
//      { render: true, gen, piece, from, to, preset }
// Out: { measured, ms, peak }                   ms: K-weighted mean square of each 100 ms block
//      { rendered, gen, piece, from, L, R, meter, ms, peak }  samples from..to, processed
//      { error }
// Each piece is processed with RUN_UP seconds before it (so the compressors and limiters have
// settled, as in the whole-song run on import) and RUN_OUT after (the band split looks ahead).
importScripts("prep-core.js" + location.search, "master-core.js" + location.search);

const RUN_UP = 3, RUN_OUT = 0.5, METER_S = 0.01, BLOCK_S = 0.1;
let song = null, rate = 44100;

/** K-weighted mean square (both channels added, as BS.1770 does) of each whole 100 ms block of [a, b). */
function blocks(L, R, a, b) {
  const [f1, f2] = self.SwPrepCore.kFilters(rate), n = Math.round(BLOCK_S * rate), out = [];
  const [p0, p1, p2] = f1.b, [q1, q2] = f1.a, [r0, r1, r2] = f2.b, [t1, t2] = f2.a;
  // the two biquads of K-weighting, from the start (the run-up) so their memory is right at a
  const run = (x) => {
    const y = new Float64Array(b);
    let x1 = 0, x2 = 0, u1 = 0, u2 = 0, w1 = 0, w2 = 0;
    for (let i = 0; i < b; i++) {
      const v = x[i], u = p0 * v + p1 * x1 + p2 * x2 - q1 * u1 - q2 * u2;
      const w = r0 * u + r1 * u1 + r2 * u2 - t1 * w1 - t2 * w2;
      x2 = x1; x1 = v; u2 = u1; u1 = u; w2 = w1; w1 = w; y[i] = w;
    }
    return y;
  };
  const kl = run(L), kr = run(R);
  for (let o = a; o + n <= b; o += n) {
    let sum = 0;
    for (let i = o; i < o + n; i++) sum += kl[i] * kl[i] + kr[i] * kr[i];
    out.push(sum / n);
  }
  return out;
}

const peakOf = (L, R, a, b) => { let p = 0; for (let i = a; i < b; i++) { const v = Math.max(Math.abs(L[i]), Math.abs(R[i])); if (v > p) p = v; } return p; };

self.onmessage = (e) => {
  const m = e.data;
  try {
    if (m.load) {
      rate = m.rate;
      song = m.channels.length === 1 ? [m.channels[0], m.channels[0]] : m.channels;
      return;
    }
    if (m.measure) {
      const [L, R] = song;
      self.postMessage({ measured: true, ms: blocks(L, R, 0, L.length), peak: peakOf(L, R, 0, L.length) });
      return;
    }
    if (m.render) {
      const [L, R] = song, n = L.length;
      const a = Math.max(0, m.from - Math.round(RUN_UP * rate)), b = Math.min(n, m.to + Math.round(RUN_OUT * rate));
      const step = Math.round(METER_S * rate);
      // the run-up starts on a meter step, so the piece's own meter values line up with the song's
      const a0 = a - (a % step) <= m.from ? a - (a % step) : a;
      const out = self.SwMaster.master([L.subarray(a0, b), R.subarray(a0, b)], rate, null, m.preset, step);
      const k = m.from - a0, len = m.to - m.from;
      const pl = out[0].slice(k, k + len), pr = out[1].slice(k, k + len);
      const s0 = Math.round(k / step), s1 = s0 + Math.ceil(len / step);
      const cut = (x) => x.slice(s0, s1);
      const meter = { bands: out.meter.bands.map(cut), mb: cut(out.meter.mb), lim: cut(out.meter.lim) };
      // loudness blocks of the piece itself, measured with the run-up in the filters' memory
      const bk = Math.round(BLOCK_S * rate);
      const ms = blocks(out[0], out[1], k, k + Math.floor(len / bk) * bk);
      self.postMessage({ rendered: true, gen: m.gen, piece: m.piece, from: m.from, L: pl, R: pr, meter, ms, peak: peakOf(pl, pr, 0, len) },
        [pl.buffer, pr.buffer]);
    }
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex), gen: m.gen, piece: m.piece });
  }
};
