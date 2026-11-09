// The measurements for quality_browser_test.mjs, run in the page next to the app's own prep-core.js,
// master-core.js and LAME 3.100 (WebAssembly), with lamejs for the comparison with the old encoder.
// Every function returns plain numbers so the runner can check and print them.
(() => {
  const core = window.SwPrepCore;
  let lameReady = null;
  const lame = () => (lameReady = lameReady || SwLame());
  const dB = (v) => 10 * Math.log10(v);

  /** A lossless or MP3 file decoded at `rate` (its own, so the browser doesn't resample), cut to [from, from+seconds). */
  async function decode(url, rate, from = 0, seconds = 0) {
    const bytes = await (await fetch(url)).arrayBuffer();
    const a = await new OfflineAudioContext(2, 1, rate).decodeAudioData(bytes.slice(0));
    const s = Math.round(from * rate), e = seconds ? Math.min(a.length, s + Math.round(seconds * rate)) : a.length;
    const ch = [];
    for (let c = 0; c < Math.min(2, a.numberOfChannels); c++) ch.push(a.getChannelData(c).slice(s, e));
    if (ch.length === 1) ch.push(ch[0].slice());
    return { ch, rate: a.sampleRate, bytes: new Uint8Array(bytes) };
  }
  const copy = (ch) => ch.map((x) => x.slice());
  const scale = (ch, g) => ch.map((x) => x.map((v) => v * g));
  const toFloat = (pcm) => pcm.map((x) => Float32Array.from(x, (v) => v / 32768));

  /** Encode floats as MP3: 16-bit (TPDF dither unless told not to), then LAME 3.100 or lamejs. */
  async function mp3(ch, rate, kbps, { q = 0, reservoir = true, dither = true, encoder = "lame" } = {}) {
    const t = performance.now();
    let chunks;
    if (encoder === "lamejs") chunks = core.encode(window.lamejs, ch, rate, kbps);
    else chunks = core.encodeLame(await lame(), core.toInt16(ch, dither, core.rng(7)), rate, kbps, q, null, reservoir).chunks;
    const blob = new Blob(chunks, { type: "audio/mpeg" });
    return { url: URL.createObjectURL(blob), bytes: blob.size, ms: performance.now() - t };
  }

  // ---- comparing -------------------------------------------------------------------------------
  /** The lag (0..maxLag samples) at which `test` lines up with `ref`: the top of their cross-correlation (by FFT, both channels, the whole length). */
  function lagOf(ref, test, maxLag = 4000) {
    const len = Math.min(ref[0].length, test[0].length);
    let n = 1; while (n < len + maxLag) n <<= 1;
    const ar = new Float64Array(n), ai = new Float64Array(n), br = new Float64Array(n), bi = new Float64Array(n);
    for (let i = 0; i < len; i++) { ar[i] = ref[0][i] + ref[1][i]; br[i] = test[0][i] + test[1][i]; }
    fft(ar, ai); fft(br, bi);
    // conj(A) * B, then the inverse FFT (as the forward FFT of the conjugate; only the real part is used)
    for (let k = 0; k < n; k++) { const r = ar[k] * br[k] + ai[k] * bi[k], i = ar[k] * bi[k] - ai[k] * br[k]; ar[k] = r; ai[k] = -i; }
    fft(ar, ai);
    let lag = 0;
    for (let L = 1; L <= maxLag; L++) if (ar[L] > ar[lag]) lag = L;
    return lag;
  }

  function fft(re, im) {
    const n = re.length;
    for (let i = 1, j = 0; i < n; i++) { let b = n >> 1; for (; j & b; b >>= 1) j ^= b; j ^= b; if (i < j) { let t = re[i]; re[i] = re[j]; re[j] = t; t = im[i]; im[i] = im[j]; im[j] = t; } }
    for (let len = 2; len <= n; len <<= 1) {
      const half = len >> 1, a = -2 * Math.PI / len, tw = twiddles(half, a);
      for (let i = 0; i < n; i += len) {
        for (let k = 0; k < half; k++) {
          const cr = tw[2 * k], ci = tw[2 * k + 1], p = i + k, q = p + half, tr = re[q] * cr - im[q] * ci, ti = re[q] * ci + im[q] * cr;
          re[q] = re[p] - tr; im[q] = im[p] - ti; re[p] += tr; im[p] += ti;
        }
      }
    }
  }
  const twCache = new Map();
  function twiddles(half, a) {
    let t = twCache.get(half);
    if (!t) { t = new Float64Array(half * 2); for (let k = 0; k < half; k++) { t[2 * k] = Math.cos(a * k); t[2 * k + 1] = Math.sin(a * k); } twCache.set(half, t); }
    return t;
  }

  // ---- a simple noise-to-mask ratio (after Brandenburg's NMR) ---------------------------------------
  // 2048-point Hann frames, half overlap; signal and error power in 1-Bark bands up to `upTo`; the
  // masking threshold is the signal's power spread over the bands (Schroeder's spreading function)
  // less an offset between tone and noise masking (10 + z/2 dB), and never below the threshold of
  // hearing (Terhardt) with full scale at 96 dB SPL. NMR is the error over that threshold: below 0 dB
  // the error is masked. SNR alone can't judge an encoder whose -q puts its noise where it is masked.
  const NF = 2048;
  const bark = (f) => 13 * Math.atan(0.00076 * f) + 3.5 * Math.atan((f / 7500) * (f / 7500));
  const ath = (f) => { const k = Math.max(f, 20) / 1000; return 3.64 * Math.pow(k, -0.8) - 6.5 * Math.exp(-0.6 * (k - 3.3) * (k - 3.3)) + 1e-3 * Math.pow(k, 4); };
  const layouts = {};
  function layout(rate, upTo) {
    const key = rate + ":" + upTo;
    if (layouts[key]) return layouts[key];
    const top = Math.floor(upTo / rate * NF), band = new Int32Array(top);
    for (let b = 1; b < top; b++) band[b] = Math.floor(bark(b * rate / NF));
    const K = band[top - 1] + 1, sf = new Float64Array(K * K), athE = new Float64Array(K), full = 3 * NF * NF / 32;
    for (let k = 0; k < K; k++) {
      for (let j = 0; j < K; j++) { const dz = k - j + 0.474; sf[k * K + j] = Math.pow(10, (15.81 + 7.5 * dz - 17.5 * Math.sqrt(1 + dz * dz) - (10 + k / 2)) / 10); }
      let lo = Infinity; for (let b = 1; b < top; b++) if (band[b] === k) lo = Math.min(lo, ath(b * rate / NF));
      athE[k] = full * Math.pow(10, (lo - 96) / 10);
    }
    return (layouts[key] = { top, band, K, sf, athE });
  }

  /**
   * How close `test` is to `ref` (both channels): after lining them up, the error is test - ref.
   * band: signal to error below `upTo` Hz (dB; LAME keeps up to 17.5 kHz at 160 kbps); snr: the
   * whole band; nmr: the noise-to-mask ratio (dB, lower is better); audible: the share of frames
   * where some band's error is above its mask; level: the difference in RMS (dB); lag in samples.
   */
  function compare(ref, test, rate, { upTo = 16000, lag = null, edge = 0.1, mask = true } = {}) {
    if (lag === null) lag = lagOf(ref, test);
    const skip = Math.round(edge * rate), n = Math.min(ref[0].length, test[0].length - lag) - 2 * skip;
    const L = layout(rate, upTo), win = new Float64Array(NF).map((_, i) => 0.5 - 0.5 * Math.cos(2 * Math.PI * i / NF));
    const re = new Float64Array(NF), im = new Float64Array(NF), er = new Float64Array(NF), ei = new Float64Array(NF);
    const Es = new Float64Array(L.K), Ee = new Float64Array(L.K);
    let sr = 0, se = 0, st = 0, br = 0, be = 0, bt = 0, nmrSum = 0, frames = 0, audible = 0;
    for (let c = 0; c < 2; c++) {
      const r = ref[c].subarray(skip, skip + n), t = test[c].subarray(skip + lag, skip + lag + n);
      for (let i = 0; i < n; i++) { const e = t[i] - r[i]; sr += r[i] * r[i]; se += e * e; st += t[i] * t[i]; }
      if (!mask) continue;
      for (let s = 0; s + NF <= n; s += NF / 2) {
        for (let i = 0; i < NF; i++) { re[i] = r[s + i] * win[i]; im[i] = 0; er[i] = (t[s + i] - r[s + i]) * win[i]; ei[i] = 0; }
        fft(re, im); fft(er, ei);
        Es.fill(0); Ee.fill(0);
        for (let b = 1; b < L.top; b++) { const ps = re[b] * re[b] + im[b] * im[b], pe = er[b] * er[b] + ei[b] * ei[b]; Es[L.band[b]] += ps; Ee[L.band[b]] += pe; br += ps; be += pe; bt += (re[b] + er[b]) * (re[b] + er[b]) + (im[b] + ei[b]) * (im[b] + ei[b]); }
        let sum = 0, worst = 0;
        for (let k = 0; k < L.K; k++) {
          let T = 0; for (let j = 0; j < L.K; j++) T += Es[j] * L.sf[k * L.K + j];
          const ratio = Ee[k] / Math.max(T, L.athE[k]);
          sum += ratio; if (ratio > worst) worst = ratio;
        }
        nmrSum += sum / L.K; frames++; if (worst > 1) audible++;
      }
    }
    const out = { lag, length: n, snr: dB(sr / se), level: dB(st / sr), errDbfs: dB(se / (2 * n)) };
    if (mask) Object.assign(out, { levelBand: dB(bt / br), band: dB(br / be), nmr: dB(nmrSum / frames), audible: audible / frames });
    return out;
  }
  const rms = (ch) => { let s = 0, n = 0; for (const x of ch) for (const v of x) { s += v * v; n++; } return dB(s / n); };
  const peak = (ch) => { let p = 0; for (const x of ch) for (const v of x) p = Math.max(p, Math.abs(v)); return p; };
  const overs = (ch) => { let k = 0; for (const x of ch) for (const v of x) if (Math.abs(v) >= 1) k++; return k; };

  // ---- the tests' building blocks ----------------------------------------------------------------
  /** The resampler on real music: there and back (44.1 -> 48 -> 44.1), the level, the time. */
  async function resampler(url, seconds) {
    const { ch, rate } = await decode(url, 44100, 0, seconds);
    const t = performance.now();
    const up = core.resample(copy(ch), 44100, 48000);
    const ms = performance.now() - t;
    const back = core.resample(up, 48000, 44100);
    const r = compare(ch, back, 44100, { upTo: 19500, lag: 0 });
    return { band: r.band, snr: r.snr, level: rms(up) - rms(ch), lengthUp: up[0].length, expectUp: Math.round(ch[0].length * 48000 / 44100), msPerMinute: ms / seconds * 60 };
  }

  /** The noise each rounding adds (dBFS RMS), with and without TPDF dither, at 16 and 24 bits. */
  async function rounding(url, seconds, from = 0) {
    const { ch } = await decode(url, 44100, from, seconds);
    const one = (bits, dither) => { const q = core.quantize(copy(ch), bits, dither, core.rng(3)); return compare(ch, q, 44100, { lag: 0, edge: 0, mask: false }).errDbfs; };
    const int16 = (dither) => compare(ch, toFloat(core.toInt16(ch, dither, core.rng(5))), 44100, { lag: 0, edge: 0, mask: false }).errDbfs;
    return { d16: one(16, true), n16: one(16, false), d24: one(24, true), final16: int16(true), final16n: int16(false) };
  }

  /** One excerpt through LAME at several -q, the reservoir off, and lamejs; each compared with the 16-bit input. */
  async function encoders(url, from, seconds, kbps, rate = 44100) {
    const { ch } = await decode(url, rate, from, seconds);
    const ref = toFloat(core.toInt16(ch, true, core.rng(7)));
    const out = {};
    const runs = { q0: { q: 0 }, q2: { q: 2 }, q5: { q: 5 }, q9: { q: 9 }, q0_noReservoir: { q: 0, reservoir: false }, lamejs: { encoder: "lamejs" } };
    for (const [name, o] of Object.entries(runs)) {
      const m = await mp3(ch, rate, kbps, o);
      const d = await decode(m.url, rate);
      URL.revokeObjectURL(m.url);
      const r = compare(ref, d.ch, rate);
      out[name] = { levelBand: r.levelBand, band: r.band, nmr: r.nmr, audible: r.audible, snr: r.snr, level: r.level, lag: r.lag, bytes: m.bytes, ms: m.ms, extra: d.ch[0].length - ch[0].length };
    }
    out.seconds = ch[0].length / rate;
    return out;
  }

  /**
   * An upload the way it happens: the lossless original as the MP3 an uploader sends (320 kbps), then
   * the station's chain (levelled to -16 LUFS, `rate`, `precision`, LAME q0 at `kbps`), the result
   * compared with the lossless original at the same gain. Beside it: the old chain (lamejs, no dither)
   * and the best case of one encode straight from the lossless original.
   */
  const pick = (r) => ({ band: r.band, nmr: r.nmr, audible: r.audible });
  async function upload(url, from, seconds, { kbps = 160, rate = 44100, precision = 32, dither = true, master = false } = {}) {
    const flac = await decode(url, 44100, from, seconds);
    const sent = await mp3(flac.ch, 44100, 320, { q: 0 });
    const got = await decode(sent.url, 44100);
    URL.revokeObjectURL(sent.url);
    // the uploaded MP3 starts later by the encoder's and decoder's delay: line it up with the original first
    const lag0 = lagOf(flac.ch, got.ch);
    const src = got.ch.map((x) => x.slice(lag0, lag0 + flac.ch[0].length));
    const sentVsFlac = compare(flac.ch, src, 44100, { lag: 0, mask: true });
    const random = core.rng(11);
    let x = core.quantize(copy(src), precision, dither, random);
    let refFlac = flac.ch;
    if (rate !== 44100) {
      x = core.quantize(core.resample(x, 44100, rate), precision, dither, random);
      refFlac = core.resample(copy(flac.ch), 44100, rate);
    }
    if (master) x = core.quantize(window.SwMaster.master(x, rate), precision, dither, random);
    const p = core.prepare(x, rate, { level: true, target: -16 });
    const g = Math.pow(10, p.info.gain_db / 20), a = Math.round(p.info.trimmed_start_s * rate);
    const pcm = core.toInt16(p.channels, dither, random);
    const finalRef = toFloat(pcm);
    const t = performance.now();
    const enc = core.encodeLame(await lame(), pcm, rate, kbps, 0);
    const ms = performance.now() - t;
    const blob = new Blob(enc.chunks, { type: "audio/mpeg" }), u = URL.createObjectURL(blob);
    const head = new Uint8Array(await blob.slice(0, 4096).arrayBuffer());
    const out = await decode(u, rate);
    URL.revokeObjectURL(u);
    const flacRef = scale(refFlac, g).map((y) => y.subarray(a, a + p.channels[0].length));
    const res = {
      sent: sentVsFlac.band, gain: p.info.gain_db, lufs: p.info.lufs, capped: p.info.capped, mp3Rate: core.mp3Rate(head), lowpass: enc.lowpass,
      vsInput: pick(compare(finalRef, out.ch, rate)), vsFlac: master ? null : pick(compare(flacRef, out.ch, rate)),
      int16Noise: compare(p.channels, finalRef, rate, { lag: 0, edge: 0, mask: false }).errDbfs,
      overs16: overs(finalRef), decodedPeak: 20 * Math.log10(peak(out.ch)), decodedOvers: overs(out.ch), encodeMsPerMinute: ms / seconds * 60,
    };
    if (rate === 44100 && !master) {
      // the old chain (rounds 1-49): the browser's float straight into lamejs, no dither
      const old = await mp3(scale(src, g).map((y) => y.subarray(a, a + p.channels[0].length)), 44100, kbps, { encoder: "lamejs" });
      const od = await decode(old.url, 44100);
      URL.revokeObjectURL(old.url);
      res.oldVsFlac = pick(compare(flacRef, od.ch, 44100));
      // one encode straight from the lossless original: the best a 160 kbps MP3 of this song can be
      const best = await mp3(flacRef, 44100, kbps, { q: 0 });
      const bd = await decode(best.url, 44100);
      URL.revokeObjectURL(best.url);
      res.oneEncodeVsFlac = pick(compare(flacRef, bd.ch, 44100));
    }
    return res;
  }


  /** The decoded level of a -6 dBFS 1 kHz tone: as a 16-bit WAV (the control), through LAME 3.100 and through lamejs. */
  async function toneLevel(rate = 44100, kbps = 160) {
    const n = rate * 5, x = new Float32Array(n).map((_, i) => 0.5 * Math.sin(2 * Math.PI * 1000 * i / rate)), ch = [x, x.slice()];
    const mid = (y) => { const a = y[0].subarray(rate, 4 * rate); let s = 0; for (const v of a) s += v * v; return 20 * Math.log10(Math.sqrt(s / a.length) * Math.SQRT2 / 0.5); };
    const wav = (() => { const pcm = core.toInt16(ch, false), b = new DataView(new ArrayBuffer(44 + n * 4));
      const w = (o, t) => [...t].forEach((c, i) => b.setUint8(o + i, c.charCodeAt(0)));
      w(0, "RIFF"); b.setUint32(4, 36 + n * 4, true); w(8, "WAVEfmt "); b.setUint32(16, 16, true); b.setUint16(20, 1, true); b.setUint16(22, 2, true);
      b.setUint32(24, rate, true); b.setUint32(28, rate * 4, true); b.setUint16(32, 4, true); b.setUint16(34, 16, true); w(36, "data"); b.setUint32(40, n * 4, true);
      for (let i = 0; i < n; i++) { b.setInt16(44 + i * 4, pcm[0][i], true); b.setInt16(46 + i * 4, pcm[1][i], true); }
      return URL.createObjectURL(new Blob([b.buffer], { type: "audio/wav" })); })();
    const out = { wav: mid((await decode(wav, rate)).ch) };
    for (const [name, o] of [["lame", { q: 0 }], ["lamejs", { encoder: "lamejs" }]]) { const m = await mp3(ch, rate, kbps, o); out[name] = mid((await decode(m.url, rate)).ch); }
    return out;
  }

  window.SwQuality = { toneLevel, decode, compare, resampler, rounding, encoders, upload, ready: async () => (await lame())._sw_version ? true : false };
})();
