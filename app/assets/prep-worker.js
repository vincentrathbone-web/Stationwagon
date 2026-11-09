// Song prep off the main thread (round 51: the sound-quality chain). In order:
//   the decoded song at its own rate -> (precision) -> resampled to the station's rate when it differs
//   -> (precision) -> mastered when asked (master-core.js) -> (precision) -> measured, levelled when
//   asked, trimmed and faded (prep-core.js) -> 16-bit (dithered when asked) -> LAME 3.100 (WebAssembly).
// "(precision)": with 16- or 24-bit processing the samples are rounded to that size there (dithered
// when asked); 32-bit float leaves them as they are. When there is nothing to do (no mastering, no
// levelling, the rate kept) nothing is encoded: { keep } says to upload the original as it came.
// In: { channels: Float32Array[], rate (the song's own), kbps, master, level, target,
//       quality: { rate: 0 | 44100 | 48000, precision: 32 | 24 | 16, dither: bool, q: 0..9 } }
//   or, for an upload that isn't an MP3 (round 54), { file: ArrayBuffer, ... } instead of channels and rate:
//   it is decoded here (vendor/ffmpeg-decode-7.1.js, with its tags and cover), the MP3 the station keeps as
//   its original is made from that sound (320 kbps, SwPrepCore.ORIGINAL), and the song from the same sound
//   (so one MP3 conversion each, never the song from the original MP3). With nothing to change, the song
//   is still encoded (the station plays MP3s only).
// Out: progress messages, { notice } (a rate change, said before the work), then { done, chunks, info }
//   (with original: { chunks, rate }, tags, picture, source: { codec, rate, channels } for a converted
//   upload), { keep, info }, { skip: reason } or { error }.
// the worker's own ?v= tag covers prep-core.js, master-core.js and the vendor files too (App::asset), so new ones are fetched fresh
importScripts("prep-core.js" + location.search, "master-core.js" + location.search, "vendor/lame-3.100.js" + location.search);

let lameReady = null, decoderReady = null;
const lame = () => (lameReady = lameReady || SwLame());
const decoder = () => (decoderReady = decoderReady || (importScripts("vendor/ffmpeg-decode-7.1.js" + location.search), SwDecode()));

/** A whole file decoded with its tags and cover: { channels, rate, codec, tags, picture }. */
async function decodeFile(buffer) {
  const m = await decoder();
  const bytes = new Uint8Array(buffer), p = m._malloc(bytes.length);
  if (!p) throw new Error("not enough memory to read this file");
  try {
    m.HEAPU8.set(bytes, p);
    const opened = m._sw_dec_open(p, bytes.length);
    if (opened < 0) throw new Error(opened === -4 ? "the station can’t decode this kind of audio" : "this file holds no audio the station can read");
    const n = m._sw_dec_run();
    if (n <= 0) throw new Error(n === -7 ? "the song is too long to prepare in the browser" : "the audio in this file couldn’t be decoded");
    const rate = m._sw_dec_info(0), ch = m._sw_dec_info(1);
    const copy = (ptr) => new Float32Array(m.HEAPF32.buffer, ptr, n).slice();
    const channels = [copy(m._sw_dec_info(4))];
    if (ch > 1) channels.push(copy(m._sw_dec_info(5)));
    const tag = (k) => {
      const kp = m._malloc(32);
      m.HEAPU8.set(new TextEncoder().encode(k + "\0"), kp);
      const r = m._sw_dec_tag(kp);
      m._free(kp);
      return r ? m.UTF8ToString(r) : "";
    };
    const tags = {};
    for (const k of ["title", "artist", "album", "album_artist", "genre", "track", "date", "composer", "publisher", "copyright"]) {
      const v = tag(k);
      if (v) tags[k] = v;
    }
    let picture = null;
    const pp = m._sw_dec_info(6), ps = m._sw_dec_info(7);
    if (pp && ps > 0 && ps <= 3 * 1024 * 1024) {
      const data = m.HEAPU8.slice(pp, pp + ps);
      const mime = data[0] === 0xff && data[1] === 0xd8 ? "image/jpeg" : data[0] === 0x89 && data[1] === 0x50 ? "image/png" : null;
      if (mime) picture = { mime, data };
    }
    return { channels, rate, codec: m.UTF8ToString(m._sw_dec_codec()), tags, picture };
  } finally {
    m._sw_dec_close();
    m._free(p);
  }
}

self.onmessage = async (e) => {
  const { kbps, master = false, level = true, target } = e.data;
  let { channels, rate: source } = e.data;
  const Q = Object.assign({ rate: 0, precision: 32, dither: true, q: 0 }, e.data.quality || {});
  const core = self.SwPrepCore, random = core.rng();
  const step = (stage) => { let last = -1; return (p) => { if (p - last >= 0.05 || p === 1) { last = p; self.postMessage({ stage, progress: p }); } }; };
  try {
    let converted = null;
    if (e.data.file) {
      // an upload that isn't an MP3: decode it, then make the original the station keeps from that sound
      self.postMessage({ stage: "Decoding" });
      const d = await decodeFile(e.data.file);
      channels = d.channels; source = d.rate;
      const note = core.rateNote(source, Q.rate);
      if (note) self.postMessage({ notice: note.charAt(0).toUpperCase() + note.slice(1) });
      const two = channels.length === 1 ? [channels[0], channels[0]] : channels;
      const oRate = core.ORIGINAL.rate(source);
      self.postMessage({ stage: "Making the MP3 original", progress: 0 });
      const oSrc = oRate === source ? two : core.resample(two, source, oRate, step("Making the MP3 original"));
      const oEnc = core.encodeLame(await lame(), core.toInt16(oSrc, true, core.rng(0x51f15eed)), oRate, core.ORIGINAL.kbps, Q.q, step("Making the MP3 original"));
      converted = { original: { chunks: oEnc.chunks, rate: oRate }, tags: d.tags, picture: d.picture,
        source: { codec: d.codec, rate: source, channels: channels.length } };
    }
    let src = channels.length === 1 ? [channels[0], Float32Array.from(channels[0])] : channels;
    core.quantize(src, Q.precision, Q.dither, random);
    const rate = core.targetRate(source, Q.rate);
    if (rate !== source) {
      self.postMessage({ stage: "Resampling", progress: 0 });
      src = core.quantize(core.resample(src, source, rate, step("Resampling")), Q.precision, Q.dither, random);
    }
    if (master) {
      self.postMessage({ stage: "Mastering", progress: 0 });
      src = self.SwMaster.master(src, rate, step("Mastering"), typeof master === "object" ? master : undefined);
      core.quantize(src, Q.precision, Q.dither, random);
    }
    self.postMessage({ stage: level ? "Levelling" : "Measuring" });
    const r = core.prepare(src, rate, { level, target });
    if (r.skip) {
      self.postMessage({ skip: r.skip });
      return;
    }
    const quality = { source_rate: source, rate, precision: Q.precision, dither: !!Q.dither, kbps, q: Q.q, rate_note: core.rateNote(source, Q.rate) };
    r.info.mastered = !!master;
    if (!master && !level && rate === source && !converted) {
      // nothing changes the sound: the original goes up as it came (no trim, no fades, no encode)
      const n = src[0].length;
      self.postMessage({ keep: true, info: Object.assign(r.info, { trimmed_start_s: 0, trimmed_end_s: 0, seconds: n / rate, outro: core.outro(src, rate), kept: true }, { quality }) });
      return;
    }
    // a converted upload with nothing to change: the whole song as it came, only encoded (no trim, no fades)
    const untouched = converted && !master && !level && rate === source;
    const pcm = untouched ? src : r.channels;
    if (untouched) Object.assign(r.info, { trimmed_start_s: 0, trimmed_end_s: 0, seconds: src[0].length / rate, outro: core.outro(src, rate) });
    self.postMessage({ stage: "Encoding", progress: 0 });
    const enc = core.encodeLame(await lame(), core.toInt16(pcm, Q.dither, random), rate, kbps, Q.q, step("Encoding"));
    r.info.quality = Object.assign(quality, { encoder: "LAME 3.100", lowpass: enc.lowpass });
    if (converted) r.info.converted = { from: converted.source, original_kbps: core.ORIGINAL.kbps, original_rate: converted.original.rate };
    const send = enc.chunks.map((c) => c.buffer);
    if (converted) send.push(...converted.original.chunks.map((c) => c.buffer));
    self.postMessage(Object.assign({ done: true, chunks: enc.chunks, info: r.info }, converted || {}), send);
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex) });
  }
};
