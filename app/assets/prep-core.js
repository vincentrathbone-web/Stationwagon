// Song prep maths: loudness (ITU-R BS.1770-4 / EBU R128, gated), silence trim, levelling with a
// peak ceiling (optional), short anti-click fades and MP3 encoding with lamejs. No DOM: the browser runs it
// in a worker (prep-worker.js) and the Node test (tests/prep_test.mjs) runs it directly.
(function (root) {
  "use strict";

  const P = {
    TARGET_LUFS: -11,      // every song is levelled to this, unless the station sets its own target (opts.target); -11 since round 86 (was -16)
    PEAK_CEILING_DB: -1,   // ...unless that would push a sample peak above this (no limiter)
    MAX_GAIN_DB: 12,       // and never louder than this, so a quiet hiss isn't turned into noise
    SILENCE_DB: -50,       // quieter than this at the very start or end counts as silence
    MARGIN_S: 0.05,        // left before the first and after the last sound
    FADE_S: 0.02,          // fade in and out so the cut edges don't click
    KBPS: 192,
    OUTRO_S: 60,           // seconds at the end measured for silence detection
  };

  // ---- K-weighting filters at any sample rate (as in libebur128) --------------------------
  function kFilters(rate) {
    let f0 = 1681.974450955533, G = 3.999843853973347, Q = 0.7071752369554196;
    let K = Math.tan(Math.PI * f0 / rate);
    const Vh = Math.pow(10, G / 20), Vb = Math.pow(Vh, 0.4996667741545416);
    let a0 = 1 + K / Q + K * K;
    const shelf = { b: [(Vh + Vb * K / Q + K * K) / a0, 2 * (K * K - Vh) / a0, (Vh - Vb * K / Q + K * K) / a0],
                    a: [2 * (K * K - 1) / a0, (1 - K / Q + K * K) / a0] };
    f0 = 38.13547087602444; Q = 0.5003270373238773;
    K = Math.tan(Math.PI * f0 / rate);
    a0 = 1 + K / Q + K * K;
    const highpass = { b: [1, -2, 1], a: [2 * (K * K - 1) / a0, (1 - K / Q + K * K) / a0] };
    return [shelf, highpass];
  }

  function filter(x, f) {
    const [b0, b1, b2] = f.b, [a1, a2] = f.a;
    const y = new Float32Array(x.length);
    let x1 = 0, x2 = 0, y1 = 0, y2 = 0;
    for (let i = 0; i < x.length; i++) {
      const v = b0 * x[i] + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2;
      x2 = x1; x1 = x[i]; y2 = y1; y1 = v; y[i] = v;
    }
    return y;
  }

  /** Integrated loudness in LUFS of 1 or 2 channels (mono counts as both speakers), or null. */
  function loudness(channels, rate) {
    const ch = channels.length === 1 ? [channels[0], channels[0]] : channels.slice(0, 2);
    const [shelf, hp] = kFilters(rate);
    const sq = ch.map((x) => { const y = filter(filter(x, shelf), hp); for (let i = 0; i < y.length; i++) y[i] *= y[i]; return y; });
    const block = Math.round(0.4 * rate), step = Math.round(0.1 * rate), n = sq[0].length;
    if (n < block) return null;
    // running sums make each 400 ms block cheap
    const sum = new Float64Array(n + 1);
    for (let i = 0; i < n; i++) sum[i + 1] = sum[i] + sq[0][i] + sq[1][i];
    const powers = [];
    for (let s = 0; s + block <= n; s += step) powers.push((sum[s + block] - sum[s]) / block);
    const lufs = (p) => -0.691 + 10 * Math.log10(p);
    const abs = powers.filter((p) => p > 0 && lufs(p) > -70);
    if (!abs.length) return null;
    const rel = lufs(abs.reduce((a, b) => a + b, 0) / abs.length) - 10;
    const kept = abs.filter((p) => lufs(p) > rel);
    return lufs(kept.reduce((a, b) => a + b, 0) / kept.length);
  }

  /**
   * The loudness (LUFS, 1 s blocks) of each second of the last `seconds`, oldest first, for the
   * planner's silence detection: −99 for a silent second. The last value ends at the last sample.
   */
  function outro(channels, rate, seconds = P.OUTRO_S) {
    const n = channels[0].length, sec = Math.round(rate), count = Math.min(seconds, Math.floor(n / sec)), out = [];
    for (let i = count; i > 0; i--) {
      const v = loudness(channels.map((x) => x.subarray(n - i * sec, n - (i - 1) * sec)), rate);
      out.push(v === null ? -99 : Math.round(v * 10) / 10);
    }
    return out;
  }

  function peakDb(channels, from = 0, to = channels[0].length) {
    let m = 0;
    for (const x of channels) for (let i = from; i < to; i++) { const v = Math.abs(x[i]); if (v > m) m = v; }
    return m > 0 ? 20 * Math.log10(m) : -Infinity;
  }

  /** First and last sample (end exclusive) with sound, plus the margins; null when silent. */
  function soundSpan(channels, rate) {
    const lim = Math.pow(10, P.SILENCE_DB / 20), n = channels[0].length;
    const loud = (i) => channels.some((x) => Math.abs(x[i]) > lim);
    let a = 0; while (a < n && !loud(a)) a++;
    if (a === n) return null;
    let b = n - 1; while (b > a && !loud(b)) b--;
    const m = Math.round(P.MARGIN_S * rate);
    return [Math.max(0, a - m), Math.min(n, b + 1 + m)];
  }

  /**
   * Measure and level. Returns { channels (stereo, trimmed, levelled, faded), info } or
   * { skip: reason } when there's nothing to level. With { level: false } the loudness is only
   * measured: the song keeps its own level (still trimmed and faded). opts.target: the station's
   * loudness target in LUFS (default P.TARGET_LUFS).
   */
  function prepare(channels, rate, opts = {}) {
    const level = opts.level !== false;
    const target = typeof opts.target === "number" && isFinite(opts.target) ? opts.target : P.TARGET_LUFS;
    // Measure only what will play: half-silent blocks at the edges would read too quiet
    const span = soundSpan(channels, rate);
    const before = span && loudness(channels.map((x) => x.subarray(span[0], span[1])), rate);
    if (before === null || span === null) return { skip: "it is silent or too short to measure" };
    const [a, b] = span;
    const peak = peakDb(channels, a, b);
    const gainDb = level ? Math.min(target - before, P.PEAK_CEILING_DB - peak, P.MAX_GAIN_DB) : 0;
    const g = Math.pow(10, gainDb / 20), len = b - a, fade = Math.min(Math.round(P.FADE_S * rate), Math.floor(len / 2));
    const src = channels.length === 1 ? [channels[0], channels[0]] : channels.slice(0, 2);
    const out = src.map((x) => {
      const y = new Float32Array(len);
      for (let i = 0; i < len; i++) y[i] = x[a + i] * g;
      for (let i = 0; i < fade; i++) { const w = 0.5 - 0.5 * Math.cos(Math.PI * i / fade); y[i] *= w; y[len - 1 - i] *= w; }
      return y;
    });
    return {
      channels: out,
      info: {
        lufs_before: round(before, 2), lufs: round(before + gainDb, 2), gain_db: round(gainDb, 2),
        peak_db: round(peak + gainDb, 2), trimmed_start_s: round(a / rate, 3), trimmed_end_s: round((channels[0].length - b) / rate, 3),
        seconds: round(len / rate, 3), levelled: level, target: level ? target : null, capped: level && gainDb < target - before - 0.01,
        outro: outro(out, rate),
      },
    };
  }

  // ---- Sound quality (round 51): the rate, resampling, precision and dither, LAME 3.100 -------------

  /**
   * The rate a song is prepared at. setting: 44100 or 48000, or 0 for the song's own rate; a song's
   * own rate that is neither goes to its family (multiples of 11025 to 44.1 kHz, the rest to 48 kHz),
   * so the ratio is as simple as it can be.
   */
  function targetRate(source, setting = 0) {
    if (setting === 44100 || setting === 48000) return setting;
    if (source === 44100 || source === 48000) return source;
    return source % 11025 === 0 ? 44100 : 48000;
  }

  /**
   * Why a song's rate changes, in words for the uploader, or "" when it doesn't.
   * setting as for targetRate.
   */
  function rateNote(source, setting = 0) {
    const to = targetRate(source, setting), k = (r) => String(r / 1000).replace(/\.0+$/, "") + " kHz";
    if (to === source) return "";
    return setting === to ? `converted from ${k(source)} to ${k(to)}, the station’s setting`
      : `converted from ${k(source)} to ${k(to)}: the station plays songs at 44.1 or 48 kHz`;
  }

  const gcd = (a, b) => (b ? gcd(b, a % b) : a);
  function besselI0(x) { let s = 1, t = 1; for (let k = 1; k < 60; k++) { t *= (x / (2 * k)) * (x / (2 * k)); s += t; if (t < 1e-21 * s) break; } return s; }

  /**
   * The resampler's filter: a Kaiser-windowed sinc, flat to 20 kHz (or 90.7 % of the lower rate's
   * half, whichever is lower), at least ATTEN dB down from the lower rate's half on, so nothing folds
   * back. One row of taps per output phase; each row adds up to exactly 1. For from → to.
   */
  const ATTEN = 150;
  function resampler(from, to) {
    const g = gcd(from, to), L = to / g, M = from / g, low = Math.min(from, to);
    const pass = Math.min(20000, 0.4535 * low), stop = low / 2, cut = (pass + stop) / 2 / from; // cut in cycles per input sample
    const width = (stop - pass) / from, beta = 0.1102 * (ATTEN - 8.7);
    const half = Math.ceil((ATTEN - 7.95) / (2.285 * 2 * Math.PI * width) / 2) + 1, taps = 2 * half, i0b = besselI0(beta);
    const rows = [];
    for (let p = 0; p < L; p++) {
      const row = new Float64Array(taps), frac = p / L;
      let sum = 0;
      for (let j = 0; j < taps; j++) {
        const t = frac + half - 1 - j; // the input sample's distance from the output point
        const r = t / half;
        const w = Math.abs(r) >= 1 ? 0 : besselI0(beta * Math.sqrt(1 - r * r)) / i0b;
        const s = t === 0 ? 2 * cut : Math.sin(2 * Math.PI * cut * t) / (Math.PI * t);
        row[j] = s * w; sum += row[j];
      }
      for (let j = 0; j < taps; j++) row[j] /= sum;
      rows.push(row);
    }
    return { L, M, half, taps, rows, pass, stop };
  }

  /** Resample each channel from → to (Float32Array in, Float32Array out); the same channels when equal. */
  function resample(channels, from, to, onProgress = null) {
    if (from === to) return channels;
    const f = resampler(from, to), n = channels[0].length, out = Math.ceil(n * f.L / f.M);
    return channels.map((x, c) => {
      const y = new Float32Array(out);
      for (let i = 0; i < out; i++) {
        const pos = i * f.M, base = Math.floor(pos / f.L), row = f.rows[pos - base * f.L], k0 = base - f.half + 1;
        let s = 0;
        const j0 = Math.max(0, -k0), j1 = Math.min(f.taps, n - k0);
        for (let j = j0; j < j1; j++) s += row[j] * x[k0 + j];
        y[i] = s;
        if (onProgress && (i & 0xffff) === 0) onProgress((c + i / out) / channels.length);
      }
      return y;
    });
  }

  /** A small fast random source (xorshift32), seeded so a song comes out the same every time. */
  function rng(seed = 0x9e3779b9) { let s = seed >>> 0 || 1; return () => { s ^= s << 13; s >>>= 0; s ^= s >>> 17; s ^= s << 5; s >>>= 0; return s / 4294967296; }; }

  /**
   * Round each channel to `bits` (16 or 24) in place, with TPDF dither (two uniform randoms, ±1 LSB)
   * when asked; 32 leaves floats as they are. Clipped to the range a signed integer of that size holds.
   */
  function quantize(channels, bits, dither, random = rng()) {
    if (bits >= 32) return channels;
    const scale = Math.pow(2, bits - 1), hi = (scale - 1) / scale;
    for (const x of channels) {
      for (let i = 0; i < x.length; i++) {
        let v = x[i] * scale;
        if (dither) v += random() - random();
        v = Math.round(v) / scale;
        x[i] = v > hi ? hi : v < -1 ? -1 : v;
      }
    }
    return channels;
  }

  /** The final 16-bit samples for the encoder (Int16Array per channel), dithered when asked. */
  function toInt16(channels, dither, random = rng(0x2545f491)) {
    return channels.map((x) => {
      const y = new Int16Array(x.length);
      for (let i = 0; i < x.length; i++) {
        let v = x[i] * 32768;
        if (dither) v += random() - random();
        v = Math.round(v);
        y[i] = v > 32767 ? 32767 : v < -32768 ? -32768 : v;
      }
      return y;
    });
  }

  /**
   * Encode 16-bit stereo as CBR MP3 with LAME 3.100 (the WebAssembly build, `lame` from SwLame()).
   * quality: LAME's -q, 0 (best, slowest) to 9 (fastest). The bit reservoir is on, as in the lame
   * command line (reservoir false turns it off). Returns { chunks: Uint8Array[], lowpass, delay, quality }.
   */
  function encodeLame(lame, pcm, rate, kbps = P.KBPS, quality = 0, onProgress = null, reservoir = true) {
    const h = lame._sw_open(rate, 2, kbps, quality, reservoir ? 1 : 0);
    if (!h) throw new Error("LAME refused these settings (" + rate + " Hz, " + kbps + " kbps)");
    // LAME quietly picks another rate or bit rate when it can't do the one asked for: refuse that instead
    if (lame._sw_get(h, 3) !== rate || lame._sw_get(h, 4) !== kbps) {
      const got = lame._sw_get(h, 3) + " Hz, " + lame._sw_get(h, 4) + " kbps";
      lame._sw_close(h);
      throw new Error("LAME refused these settings (" + rate + " Hz, " + kbps + " kbps; it offered " + got + ")");
    }
    const n = pcm[0].length, chunk = 1152 * 32, outSize = Math.ceil(1.25 * chunk + 7200);
    const pl = lame._malloc(chunk * 2), pr = lame._malloc(chunk * 2), po = lame._malloc(outSize), out = [];
    try {
      for (let s = 0; s < n; s += chunk) {
        const m = Math.min(chunk, n - s);
        lame.HEAP16.set(pcm[0].subarray(s, s + m), pl >> 1);
        lame.HEAP16.set(pcm[1].subarray(s, s + m), pr >> 1);
        const k = lame._sw_encode(h, pl, pr, m, po, outSize);
        if (k < 0) throw new Error("LAME stopped (" + k + ")");
        if (k) out.push(lame.HEAPU8.slice(po, po + k));
        if (onProgress && (s / chunk) % 8 === 0) onProgress(s / n);
      }
      const k = lame._sw_flush(h, po, outSize);
      if (k > 0) out.push(lame.HEAPU8.slice(po, po + k));
      if (onProgress) onProgress(1);
      return { chunks: out, lowpass: lame._sw_get(h, 2), delay: lame._sw_get(h, 5), quality: lame._sw_get(h, 0) };
    } finally {
      lame._sw_close(h); lame._free(pl); lame._free(pr); lame._free(po);
    }
  }

  /** The sample rate of an MP3 from its first frame (after any ID3v2 tag), or null. */
  function mp3Rate(bytes) {
    let i = 0;
    if (bytes.length >= 10 && bytes[0] === 0x49 && bytes[1] === 0x44 && bytes[2] === 0x33) {
      i = 10 + (((bytes[6] & 0x7f) << 21) | ((bytes[7] & 0x7f) << 14) | ((bytes[8] & 0x7f) << 7) | (bytes[9] & 0x7f)) + (bytes[5] & 0x10 ? 10 : 0);
    }
    const RATES = { 3: [44100, 48000, 32000], 2: [22050, 24000, 16000], 0: [11025, 12000, 8000] };
    for (const end = Math.min(bytes.length - 4, i + 65536); i < end; i++) {
      if (bytes[i] !== 0xff || (bytes[i + 1] & 0xe0) !== 0xe0) continue;
      const v = (bytes[i + 1] >> 3) & 3, layer = (bytes[i + 1] >> 1) & 3, bi = bytes[i + 2] >> 4, ri = (bytes[i + 2] >> 2) & 3;
      if (v === 1 || layer === 0 || bi === 0 || bi === 15 || ri === 3) continue;
      return RATES[v][ri];
    }
    return null;
  }

  /** Encode stereo floats as CBR MP3 with lamejs. Returns a list of Uint8Array chunks. */
  function encode(lamejs, channels, rate, kbps = P.KBPS, onProgress = null) {
    const enc = new lamejs.Mp3Encoder(2, rate, kbps);
    const n = channels[0].length, chunk = 1152 * 20, out = [];
    const l = new Int16Array(chunk), r = new Int16Array(chunk);
    const toInt = (v) => (v >= 1 ? 32767 : v <= -1 ? -32768 : Math.round(v * 32767));
    for (let s = 0; s < n; s += chunk) {
      const m = Math.min(chunk, n - s);
      for (let i = 0; i < m; i++) { l[i] = toInt(channels[0][s + i]); r[i] = toInt(channels[1][s + i]); }
      const buf = enc.encodeBuffer(m === chunk ? l : l.subarray(0, m), m === chunk ? r : r.subarray(0, m));
      if (buf.length) out.push(new Uint8Array(buf));
      if (onProgress && (s / chunk) % 10 === 0) onProgress(s / n);
    }
    const end = enc.flush();
    if (end.length) out.push(new Uint8Array(end));
    if (onProgress) onProgress(1);
    return out;
  }

  /** The ID3v2 tag at the start and the ID3v1 tag at the end of an MP3, to copy across. */
  function tags(bytes) {
    let head = new Uint8Array(0), tail = new Uint8Array(0);
    if (bytes.length >= 10 && bytes[0] === 0x49 && bytes[1] === 0x44 && bytes[2] === 0x33) {
      const size = ((bytes[6] & 0x7f) << 21) | ((bytes[7] & 0x7f) << 14) | ((bytes[8] & 0x7f) << 7) | (bytes[9] & 0x7f);
      head = bytes.slice(0, Math.min(bytes.length, 10 + size + (bytes[5] & 0x10 ? 10 : 0)));
    }
    const t = bytes.length - 128;
    if (t > head.length && bytes[t] === 0x54 && bytes[t + 1] === 0x41 && bytes[t + 2] === 0x47) tail = bytes.slice(t);
    return { head, tail };
  }

  /**
   * The ID3v2 tag at the start of a prepared song, with its normalization added as TXXX frames
   * (round 41): STATIONWAGON_SOURCE_LUFS (its loudness before levelling), and when levelled
   * STATIONWAGON_LEVEL_GAIN and STATIONWAGON_LEVEL_TARGET. Not REPLAYGAIN_*: players that read those
   * would adjust the already levelled song a second time. Frames of these names already in the tag
   * are replaced. An ID3v2.3/2.4 tag gets the frames; with no tag a v2.4 one is made; a v2.2 tag or
   * one with unsynchronisation is left as it is (rare, and the station keeps the values anyway).
   */
  function levelTags(head, info) {
    const own = [["STATIONWAGON_SOURCE_LUFS", info.lufs_before.toFixed(2) + " LUFS"]];
    if (info.levelled) own.push(["STATIONWAGON_LEVEL_GAIN", (info.gain_db >= 0 ? "+" : "") + info.gain_db.toFixed(2) + " dB"],
      ["STATIONWAGON_LEVEL_TARGET", Number(info.target).toFixed(1) + " LUFS"]);
    // how it was made (round 51): "LAME 3.100 q0, 160 kbps CBR, 48000 Hz from 44100 Hz, 32-bit float, TPDF dither"
    const q = info.quality;
    if (q && q.encoder) own.push(["STATIONWAGON_PREP", `${q.encoder} q${q.q}, ${q.kbps} kbps CBR, ${q.rate} Hz` +
      (q.source_rate !== q.rate ? ` from ${q.source_rate} Hz` : "") + `, ${q.precision === 32 ? "32-bit float" : q.precision + "-bit"}, ${q.dither ? "TPDF dither" : "no dither"}`]);
    const safe = (n) => [(n >>> 21) & 0x7f, (n >>> 14) & 0x7f, (n >>> 7) & 0x7f, n & 0x7f];
    const unsafe = (b, i) => ((b[i] & 0x7f) << 21) | ((b[i + 1] & 0x7f) << 14) | ((b[i + 2] & 0x7f) << 7) | (b[i + 3] & 0x7f);
    const ascii = (t) => Array.from(t, (c) => c.charCodeAt(0) & 0x7f);
    const hasTag = head.length >= 10 && head[0] === 0x49 && head[1] === 0x44 && head[2] === 0x33;
    const ver = hasTag ? head[3] : 4, flags = hasTag ? head[5] : 0;
    if (hasTag && (ver < 3 || ver > 4 || flags & 0x80)) return head;
    const frame = ([desc, value]) => {
      const body = [0, ...ascii(desc), 0, ...ascii(value)], n = body.length;
      return [0x54, 0x58, 0x58, 0x58, ...(ver === 4 ? safe(n) : [(n >>> 24) & 0xff, (n >>> 16) & 0xff, (n >>> 8) & 0xff, n & 0xff]), 0, 0, ...body];
    };
    // the frames already there, without our own; the extended header (if any) is kept in front
    // (kept as pieces, not spread into one array: an album-art frame can hold a million bytes)
    let start = 10;
    const kept = [];
    if (hasTag) {
      const end = Math.min(head.length, 10 + unsafe(head, 6));
      if (flags & 0x40) start += ver === 4 ? unsafe(head, 10) : 4 + ((head[10] << 24) | (head[11] << 16) | (head[12] << 8) | head[13]);
      for (let i = start; i + 10 <= end && head[i] !== 0;) {
        const n = ver === 4 ? unsafe(head, i + 4) : ((head[i + 4] << 24) | (head[i + 5] << 16) | (head[i + 6] << 8) | head[i + 7]) >>> 0;
        if (n <= 0 || i + 10 + n > end) break;
        const f = head.subarray(i, i + 10 + n);
        let ours = false;
        if (f[0] === 0x54 && f[1] === 0x58 && f[2] === 0x58 && f[3] === 0x58) {
          let j = 11; while (j < f.length && f[j] !== 0) j++;
          ours = String.fromCharCode(...f.subarray(11, j)).startsWith("STATIONWAGON_");
        }
        if (!ours) kept.push(f);
        i += 10 + n;
      }
    }
    kept.push(Uint8Array.from(own.flatMap(frame)));
    const size = kept.reduce((s, f) => s + f.length, 0), pad = 256;
    const out = new Uint8Array(start + size + pad);
    out.set([0x49, 0x44, 0x33, ver, 0, flags & 0x40, ...safe(start - 10 + size + pad)]);
    if (start > 10) out.set(head.subarray(10, start), 10);
    let at = start;
    for (const f of kept) { out.set(f, at); at += f.length; }
    return out;
  }

  /**
   * An ID3v2.4 tag (UTF-8 text) for an MP3 made from another format (round 54), from that file's own
   * tags as the decoder names them (title, artist, album, album_artist, genre, track, date, composer,
   * publisher, copyright) and its cover (picture: { mime, data }), so the station reads the same title,
   * artist, songwriters and album art as from an MP3. Empty values are left out.
   */
  function id3(t = {}, picture = null) {
    const utf8 = (s) => new TextEncoder().encode(s);
    const safe = (n) => [(n >>> 21) & 0x7f, (n >>> 14) & 0x7f, (n >>> 7) & 0x7f, n & 0x7f];
    const parts = [];
    const add = (id, body) => {
      parts.push(Uint8Array.from([...Array.from(id, (c) => c.charCodeAt(0)), ...safe(body.length), 0, 0]), body);
    };
    const year = String(t.date || t.year || "").match(/\d{4}/);
    const text = { TIT2: t.title, TPE1: t.artist, TALB: t.album, TPE2: t.album_artist, TCON: t.genre, TRCK: t.track,
      TDRC: year ? year[0] : "", TCOM: t.composer, TPUB: t.publisher, TCOP: t.copyright, TSRC: t.isrc || t.ISRC, TEXT: t.lyricist };
    for (const [id, v] of Object.entries(text)) {
      const s = String(v || "").replace(/\0/g, " ").trim();
      if (s) { const b = utf8(s), body = new Uint8Array(b.length + 1); body[0] = 3; body.set(b, 1); add(id, body); }
    }
    if (picture && picture.data && picture.data.length) {
      const mime = Array.from(picture.mime || "image/jpeg", (c) => c.charCodeAt(0) & 0x7f);
      const body = new Uint8Array(1 + mime.length + 1 + 1 + 1 + picture.data.length);
      body.set([0, ...mime, 0, 3, 0]); // Latin-1, the mime type, front cover, no description
      body.set(picture.data, mime.length + 4);
      add("APIC", body);
    }
    const size = parts.reduce((s, p) => s + p.length, 0), pad = 256;
    const out = new Uint8Array(10 + size + pad);
    out.set([0x49, 0x44, 0x33, 4, 0, 0, ...safe(size + pad)]);
    let at = 10;
    for (const p of parts) { out.set(p, at); at += p.length; }
    return out;
  }

  /**
   * What kind of audio file this is, by its first bytes (round 54): "mp3", "wav", "flac", "m4a", "ogg",
   * "wma", "aac" (raw ADTS), or null. A FLAC or AAC with an ID3 tag in front is looked for past the tag.
   * name (optional) settles an MP3 that starts with neither a tag nor a frame.
   */
  function sniff(bytes, name = "") {
    const at = (i, s) => Array.from(s).every((c, k) => bytes[i + k] === c.charCodeAt(0));
    if (bytes.length < 12) return null;
    if (at(0, "RIFF") && at(8, "WAVE") || at(0, "RF64") && at(8, "WAVE")) return "wav";
    if (at(0, "fLaC")) return "flac";
    if (at(4, "ftyp")) return "m4a";
    if (at(0, "OggS")) return "ogg";
    if ([0x30, 0x26, 0xb2, 0x75, 0x8e, 0x66, 0xcf, 0x11].every((b, k) => bytes[k] === b)) return "wma";
    let i = 0;
    if (at(0, "ID3")) i = 10 + (((bytes[6] & 0x7f) << 21) | ((bytes[7] & 0x7f) << 14) | ((bytes[8] & 0x7f) << 7) | (bytes[9] & 0x7f)) + (bytes[5] & 0x10 ? 10 : 0);
    if (i && i + 4 <= bytes.length && at(i, "fLaC")) return "flac";
    if (i + 2 <= bytes.length && bytes[i] === 0xff && (bytes[i + 1] & 0xf6) === 0xf0) return "aac"; // ADTS: layer 0
    if (i + 2 <= bytes.length && bytes[i] === 0xff && (bytes[i + 1] & 0xe0) === 0xe0 && (bytes[i + 1] & 0x06) !== 0) return "mp3";
    if (i || /\.mp3$/i.test(name)) return "mp3"; // a tag, then the frames a little further on
    return null;
  }

  /**
   * An MP3's ID3v2.3/2.4 tag read for the upload window (round 56): { title, artist, album, year, track,
   * writers, lyricists, publisher, copyright, isrc, label, iswc } (only those it has) and its picture
   * ({ mime, data }: the front cover, or the first one), or null without a tag. The same frames the server
   * reads (Mp3::tags), plus TXXX LABEL and ISWC.
   */
  function readTags(bytes) {
    if (bytes.length < 10 || bytes[0] !== 0x49 || bytes[1] !== 0x44 || bytes[2] !== 0x33 || (bytes[3] !== 3 && bytes[3] !== 4)) return null;
    const v4 = bytes[3] === 4;
    const safe = (i) => ((bytes[i] & 0x7f) << 21) | ((bytes[i + 1] & 0x7f) << 14) | ((bytes[i + 2] & 0x7f) << 7) | (bytes[i + 3] & 0x7f);
    const plain = (i) => ((bytes[i] << 24) | (bytes[i + 1] << 16) | (bytes[i + 2] << 8) | bytes[i + 3]) >>> 0;
    const end = Math.min(bytes.length, 10 + safe(6));
    let at = 10;
    if (bytes[5] & 0x40) at += v4 ? safe(10) : 4 + plain(10); // an extended header
    const NAMES = { TIT2: "title", TPE1: "artist", TALB: "album", TCOP: "copyright", TPUB: "publisher", TCOM: "writers",
      TYER: "year", TDRC: "year", TSRC: "isrc", TEXT: "lyricists", TRCK: "track" };
    const decode = (enc, b) => {
      if (enc === 0) return Array.from(b, (c) => String.fromCharCode(c)).join("");
      if (enc === 3) return new TextDecoder("utf-8").decode(b);
      let le = enc === 1, s = 0;
      if (enc === 1 && b.length >= 2) { le = !(b[0] === 0xfe && b[1] === 0xff); s = b[0] === 0xff && b[0 + 1] === 0xfe || b[0] === 0xfe && b[1] === 0xff ? 2 : 0; }
      return new TextDecoder(le ? "utf-16le" : "utf-16be").decode(b.subarray(s, s + ((b.length - s) & ~1)));
    };
    // a text frame: its encoding, then the text (several values split by NULs: joined with ", ")
    const text = (b) => b.length < 2 ? "" : decode(b[0], b.subarray(1)).split("\0").map((x) => x.trim()).filter(Boolean).join(", ");
    const out = {}, pictures = {};
    while (at + 10 <= end) {
      const id = String.fromCharCode(bytes[at], bytes[at + 1], bytes[at + 2], bytes[at + 3]);
      if (!/^[A-Z0-9]{4}$/.test(id)) break; // padding
      const size = v4 ? safe(at + 4) : plain(at + 4);
      const body = bytes.subarray(at + 10, Math.min(end, at + 10 + size));
      if (NAMES[id]) { const t = text(body); if (t && !out[NAMES[id]]) out[NAMES[id]] = t; }
      else if (id === "TXXX" && body.length > 2) {
        const [desc, ...rest] = decode(body[0], body.subarray(1)).split("\0");
        const key = { LABEL: "label", ISWC: "iswc", PUBLISHER: "publisher" }[desc.trim().toUpperCase()];
        const val = rest.filter(Boolean).join(", ").trim();
        if (key && val && !out[key]) out[key] = val;
      } else if (id === "APIC" && body.length > 20) {
        const enc = body[0];
        let p = body.indexOf(0, 1); // the mime type ends in a NUL
        if (p > 0) {
          const type = body[p + 1];
          p += 2;
          if (enc === 1 || enc === 2) { while (p + 1 < body.length && (body[p] || body[p + 1])) p += 2; p += 2; }
          else { const z = body.indexOf(0, p); p = z < 0 ? body.length : z + 1; }
          const data = body.slice(p);
          const mime = data[0] === 0xff && data[1] === 0xd8 ? "image/jpeg" : data[0] === 0x89 && data[1] === 0x50 ? "image/png" : null;
          if (mime && !(type in pictures)) pictures[type] = { mime, data };
        }
      }
      at += 10 + size;
    }
    if (out.year) { const y = out.year.match(/\d{4}/); out.year = y ? y[0] : out.year; }
    const pic = pictures[3] || Object.values(pictures)[0] || null;
    return { tags: out, picture: pic };
  }

  /** The MP3 the station keeps as a converted upload's original (round 54): 320 kbps at its own rate when
   *  that is 44.1 or 48 kHz, otherwise at the nearest of the two. */
  const ORIGINAL = { kbps: 320, rate: (source) => targetRate(source, 0) };

  function round(v, d) { const f = Math.pow(10, d); return Math.round(v * f) / f; }

  const api = { P, kFilters, loudness, outro, peakDb, soundSpan, prepare, encode, tags, levelTags, id3, sniff, readTags, ORIGINAL,
    targetRate, rateNote, resampler, resample, rng, quantize, toInt16, encodeLame, mp3Rate };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.SwPrepCore = api;
})(typeof self !== "undefined" ? self : this);
