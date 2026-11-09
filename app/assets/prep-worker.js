// Song prep off the main thread (round 51: the sound-quality chain). In order:
//   the decoded song at its own rate -> (precision) -> resampled to the station's rate when it differs
//   -> (precision) -> mastered when asked (master-core.js) -> (precision) -> measured, levelled when
//   asked, trimmed and faded (prep-core.js) -> 16-bit (dithered when asked) -> LAME 3.100 (WebAssembly).
// "(precision)": with 16- or 24-bit processing the samples are rounded to that size there (dithered
// when asked); 32-bit float leaves them as they are. When there is nothing to do (no mastering, no
// levelling, the rate kept) nothing is encoded: { keep } says to upload the original as it came.
// In: { channels: Float32Array[], rate (the song's own), kbps, master, level, target,
//       quality: { rate: 0 | 44100 | 48000, precision: 32 | 24 | 16, dither: bool, q: 0..9 } }
// Out: progress messages, then { done, chunks, info }, { keep, info }, { skip: reason } or { error }.
// the worker's own ?v= tag covers prep-core.js and master-core.js too (App::asset), so new ones are fetched fresh
importScripts("prep-core.js" + location.search, "master-core.js" + location.search, "vendor/lame-3.100.js" + location.search);

let lameReady = null;
const lame = () => (lameReady = lameReady || SwLame());

self.onmessage = async (e) => {
  const { channels, rate: source, kbps, master = false, level = true, target } = e.data;
  const Q = Object.assign({ rate: 0, precision: 32, dither: true, q: 0 }, e.data.quality || {});
  const core = self.SwPrepCore, random = core.rng();
  const step = (stage) => { let last = -1; return (p) => { if (p - last >= 0.05 || p === 1) { last = p; self.postMessage({ stage, progress: p }); } }; };
  try {
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
    if (!master && !level && rate === source) {
      // nothing changes the sound: the original goes up as it came (no trim, no fades, no encode)
      const n = src[0].length;
      self.postMessage({ keep: true, info: Object.assign(r.info, { trimmed_start_s: 0, trimmed_end_s: 0, seconds: n / rate, outro: core.outro(src, rate), kept: true }, { quality }) });
      return;
    }
    self.postMessage({ stage: "Encoding", progress: 0 });
    const enc = core.encodeLame(await lame(), core.toInt16(r.channels, Q.dither, random), rate, kbps, Q.q, step("Encoding"));
    r.info.quality = Object.assign(quality, { encoder: "LAME 3.100", lowpass: enc.lowpass });
    self.postMessage({ done: true, chunks: enc.chunks, info: r.info }, enc.chunks.map((c) => c.buffer));
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex) });
  }
};
