// Podcast recording and editing, the parts without a page: the analyser (waveform peaks, and clips
// split at every silence), the mixdown plan (which stretches of each take go into the episode, and
// where each track is muted), MP3 frame helpers, and a chunk reader that decodes a track a chunk at
// a time. Used by the studio's recorder (studio-recorder.js), the editor (podcast-editor.js) and
// the Node test (tests/podcast_test.mjs).
(function (root) {
  "use strict";

  const PEAK_RATE = 10;          // waveform: one peak per 0.1 s
  const SILENCE_DB = -50;        // quieter than this counts as silence
  const SPLIT_SECONDS = 2;       // a silence this long ends a clip
  const BLOCK_SECONDS = 0.01;    // the analyser looks at 10 ms at a time
  const GAP_KEEP = 1;            // an empty stretch over SPLIT_SECONDS is shortened to this
  const FADE = 0.01;             // 10 ms ramps where a deleted clip mutes its track
  const OVERLAP_FRAMES = 10;     // frames of the previous chunk decoded first, then dropped
  const FRAME_SAMPLES = 1152;
  // lamejs (the studio's encoder) delays its audio by 576 samples and an MP3 decoder by 529 more,
  // so decoded sample n is the sound recorded at n - 1105: readers skip that much
  const CODEC_DELAY = 1105;

  /**
   * Peaks and clips of one track, fed blocks of samples (one channel, or the louder of two):
   *   const a = new Analyzer(44100); a.push(block) …; a.finish() → { peaks: Uint8Array, clips: [[s, e]…] }
   * A peak byte is the level from −60 dB (0) to 0 dB (255).
   */
  class Analyzer {
    constructor(sampleRate) {
      this.rate = sampleRate;
      this.peakLen = Math.round(sampleRate / PEAK_RATE);
      this.blockLen = Math.round(sampleRate * BLOCK_SECONDS);
      this.limit = Math.pow(10, SILENCE_DB / 20);
      this.peaks = [];
      this.peak = 0; this.peakN = 0;
      this.block = 0; this.blockN = 0;
      this.samples = 0;            // samples seen
      this.clips = [];             // closed clips, in samples
      this.open = null;            // [start, end of the last loud block] of the clip being built
    }
    push(x) {
      for (let i = 0; i < x.length; i++) {
        const v = Math.abs(x[i]);
        if (v > this.peak) this.peak = v;
        if (v > this.block) this.block = v;
        if (++this.peakN === this.peakLen) { this.peaks.push(level(this.peak)); this.peak = 0; this.peakN = 0; }
        if (++this.blockN === this.blockLen) { this.endBlock(this.samples + i + 1); this.block = 0; this.blockN = 0; }
      }
      this.samples += x.length;
    }
    endBlock(end) {
      const start = end - this.blockLen, splitLen = SPLIT_SECONDS * this.rate;
      if (this.block > this.limit) {
        if (this.open && start - this.open[1] < splitLen) this.open[1] = end;
        else { if (this.open) this.clips.push(this.open); this.open = [start, end]; }
      }
    }
    /** The state so far as peaks and clips in seconds, cut at `samples` (default: all seen). */
    result(samples = this.samples) {
      const peaks = this.peaks.slice(0, Math.floor(samples / this.peakLen));
      if (samples >= this.samples && this.peakN > 0) peaks.push(level(this.peak));
      const clips = [...this.clips, ...(this.open ? [this.open] : [])]
        .filter((c) => c[0] < samples).map((c) => [round3(c[0] / this.rate), round3(Math.min(c[1], samples) / this.rate)]).filter((c) => c[1] > c[0]);
      return { peaks: Uint8Array.from(peaks), clips };
    }
    finish() { if (this.blockN > 0) this.endBlock(this.samples); return this.result(); }
  }
  const level = (v) => (v <= 0.001 ? 0 : Math.max(0, Math.min(255, Math.round((20 * Math.log10(v) + 60) / 60 * 255))));
  const round3 = (v) => Math.round(v * 1000) / 1000;

  /**
   * What goes into the episode from one take: `segments` are [from, to] seconds of the take, in
   * order; where nothing is kept on any track for longer than SPLIT_SECONDS, only GAP_KEEP of that
   * stretch stays (half at each side). `muted[track]` are the deleted clips' [from, to] for that
   * track. take: { id, duration, tracks: {name: …}, analysis: { tracks: {name: {clips}} } };
   * deleted: a Set of "<take>:<track>:<index>".
   */
  function planTake(take, deleted) {
    const names = Object.keys(take.tracks), kept = [], muted = {};
    for (const name of names) {
      muted[name] = [];
      const clips = (take.analysis && take.analysis.tracks[name] && take.analysis.tracks[name].clips) || [];
      clips.forEach((c, i) => { if (c[1] > c[0]) (deleted.has(`${take.id}:${name}:${i}`) ? muted[name] : kept).push(c); });
    }
    kept.sort((a, b) => a[0] - b[0]);
    const union = [];
    for (const c of kept) {
      const last = union[union.length - 1];
      if (last && c[0] <= last[1]) last[1] = Math.max(last[1], c[1]); else union.push([c[0], c[1]]);
    }
    const segments = [], half = GAP_KEEP / 2, end = take.duration;
    if (!union.length) return { segments, muted };
    let from = Math.max(0, union[0][0] - half);
    for (let i = 0; i < union.length; i++) {
      const next = union[i + 1];
      if (next && next[0] - union[i][1] <= SPLIT_SECONDS) continue; // a short pause stays as it is
      segments.push([round3(from), round3(Math.min(end, union[i][1] + half))]);
      if (next) from = next[0] - half;
    }
    return { segments, muted };
  }

  /** The episode's length after mixdown, in seconds. */
  function planLength(takes, deleted) {
    return takes.reduce((n, t) => n + planTake(t, deleted).segments.reduce((m, s) => m + s[1] - s[0], 0), 0);
  }

  /** Gain of a track at take time t: 0 inside a muted clip, 1 elsewhere, with 10 ms ramps. */
  function maskAt(muted, t) {
    let g = 1;
    for (const [a, b] of muted) {
      if (t < a - FADE || t > b + FADE) continue;
      const v = t < a ? (a - t) / FADE : t > b ? (t - b) / FADE : 0;
      g = Math.min(g, v);
    }
    return g;
  }

  /** Where each whole MP3 frame starts in `bytes` (MPEG 1/2 layer III). @return number[] */
  function frameStarts(bytes) {
    const BR1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0];
    const BR2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0];
    const RATES = { 3: [44100, 48000, 32000], 2: [22050, 24000, 16000], 0: [11025, 12000, 8000] };
    const out = [];
    let pos = 0;
    while (pos + 4 <= bytes.length) {
      const b1 = bytes[pos], b2 = bytes[pos + 1], b3 = bytes[pos + 2];
      const version = (b2 >> 3) & 3, layer = (b2 >> 1) & 3, bi = (b3 >> 4) & 15, ri = (b3 >> 2) & 3;
      if (b1 !== 0xff || (b2 & 0xe0) !== 0xe0 || version === 1 || layer !== 1 || bi === 0 || bi === 15 || ri === 3) { pos++; continue; }
      const kbps = (version === 3 ? BR1 : BR2)[bi], rate = RATES[version][ri], pad = (b3 >> 1) & 1;
      const len = Math.floor(((version === 3 ? 144 : 72) * kbps * 1000) / rate) + pad;
      if (pos + len > bytes.length) break;
      out.push(pos);
      pos += len;
    }
    return out;
  }

  /**
   * Reads one track forward, a chunk at a time: read(from, count) → Float32Array[] (its channels),
   * from and count in samples of the take. chunks: [{ n, samples }]; fetchChunk(n) → Uint8Array;
   * decode(Uint8Array) → AudioBuffer-like { numberOfChannels, getChannelData(c), length }.
   * Each chunk after the first is decoded with the previous chunk's last OVERLAP_FRAMES frames in
   * front (an MP3 frame can lean on the one before), which are then dropped, so the joins are seamless.
   * `delay` (default CODEC_DELAY) is skipped at the start, so read(t) is the sound recorded at t.
   */
  class ChunkReader {
    constructor(chunks, fetchChunk, decode, channels, delay = CODEC_DELAY) {
      this.chunks = chunks; this.fetchChunk = fetchChunk; this.decode = decode; this.channels = channels; this.delay = delay;
      this.starts = []; let s = 0;
      for (const c of chunks) { this.starts.push(s); s += c.samples; }
      this.length = s - delay;
      this.cur = -1; this.data = null; this.raw = new Map();
    }
    async bytes(i) {
      if (!this.raw.has(i)) this.raw.set(i, await this.fetchChunk(this.chunks[i].n));
      for (const k of [...this.raw.keys()]) if (k < i - 1) this.raw.delete(k); // keep this one and the one before
      return this.raw.get(i);
    }
    async load(i) {
      if (this.cur === i) return;
      const own = await this.bytes(i);
      let input = own, drop = 0;
      if (i > 0) {
        const prev = await this.bytes(i - 1), starts = frameStarts(prev);
        const k = Math.min(OVERLAP_FRAMES, starts.length);
        if (k > 0) {
          const tail = prev.subarray(starts[starts.length - k]);
          input = new Uint8Array(tail.length + own.length); input.set(tail); input.set(own, tail.length);
          drop = k * FRAME_SAMPLES;
        }
      }
      const buf = await this.decode(input);
      const want = this.chunks[i].samples;
      this.data = [];
      for (let c = 0; c < this.channels; c++) {
        const src = buf.getChannelData(Math.min(c, buf.numberOfChannels - 1)), out = new Float32Array(want);
        out.set(src.subarray(drop, Math.min(src.length, drop + want)));
        this.data.push(out);
      }
      this.cur = i;
    }
    async read(from, count) {
      const out = Array.from({ length: this.channels }, () => new Float32Array(count));
      let done = 0;
      while (done < count) {
        const pos = from + done + this.delay;
        if (pos >= this.length + this.delay) break;
        let i = this.cur >= 0 && pos >= this.starts[this.cur] && pos < this.starts[this.cur] + this.chunks[this.cur].samples ? this.cur : -1;
        if (i < 0) { i = this.starts.findIndex((s, k) => pos >= s && pos < s + this.chunks[k].samples); await this.load(i); }
        const off = pos - this.starts[i], n = Math.min(count - done, this.chunks[i].samples - off);
        for (let c = 0; c < this.channels; c++) out[c].set(this.data[c].subarray(off, off + n), done);
        done += n;
      }
      return out;
    }
  }

  const toBase64 = (u8) => { let s = ""; for (let i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000)); return btoa(s); };
  const fromBase64 = (b) => Uint8Array.from(atob(b), (ch) => ch.charCodeAt(0));

  root.SwPodcast = { Analyzer, planTake, planLength, maskAt, frameStarts, ChunkReader, toBase64, fromBase64,
    PEAK_RATE, SILENCE_DB, SPLIT_SECONDS, GAP_KEEP, FRAME_SAMPLES, OVERLAP_FRAMES, CODEC_DELAY };
})(typeof self !== "undefined" ? self : globalThis);
