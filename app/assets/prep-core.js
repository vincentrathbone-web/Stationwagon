// Song prep maths: loudness (ITU-R BS.1770-4 / EBU R128, gated), silence trim, levelling with a
// peak ceiling (optional), short anti-click fades and MP3 encoding with lamejs. No DOM: the browser runs it
// in a worker (prep-worker.js) and the Node test (tests/prep_test.mjs) runs it directly.
(function (root) {
  "use strict";

  const P = {
    TARGET_LUFS: -16,      // every song is levelled to this, unless the station sets its own target (opts.target)
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
    let start = 10, kept = [];
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
        if (!ours) kept.push(...f);
        i += 10 + n;
      }
    }
    const frames = [...kept, ...own.flatMap(frame)], pad = 256;
    const out = new Uint8Array(start + frames.length + pad);
    out.set([0x49, 0x44, 0x33, ver, 0, flags & 0x40, ...safe(start - 10 + frames.length + pad)]);
    if (start > 10) out.set(head.subarray(10, start), 10);
    out.set(frames, start);
    return out;
  }

  function round(v, d) { const f = Math.pow(10, d); return Math.round(v * f) / f; }

  const api = { P, kFilters, loudness, outro, peakDb, soundSpan, prepare, encode, tags, levelTags };
  if (typeof module === "object" && module.exports) module.exports = api;
  else root.SwPrepCore = api;
})(typeof self !== "undefined" ? self : this);
