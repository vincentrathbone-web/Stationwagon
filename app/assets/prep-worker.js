// Song prep off the main thread (round 51: the sound-quality chain; round 85: the chain itself is in prep-run.js,
// shared with the server's Node runner, cli/prep-node.mjs, so both make the same song). See prep-run.js for the steps.
// In: { channels: Float32Array[], rate (the song's own), kbps, master, level, target,
//       quality: { rate: 0 | 44100 | 48000, precision: 32 | 24 | 16, dither: bool, q: 0..9 } }
//   or, for an upload that isn't an MP3 (round 54), { file: ArrayBuffer, ... } instead of channels and rate:
//   it is decoded here (vendor/ffmpeg-decode-7.1.js, with its tags and cover), the MP3 the station keeps as
//   its original is made from that sound (320 kbps, SwPrepCore.ORIGINAL), and the song from the same sound.
// Out: progress messages, { notice } (a rate change, said before the work), then { done, chunks, info }
//   (with original: { chunks, rate }, tags, picture, source: { codec, rate, channels } for a converted
//   upload), { keep, info }, { skip: reason } or { error }.
// the worker's own ?v= tag covers prep-core.js, master-core.js, prep-run.js and the vendor files too (App::asset), so new ones are fetched fresh
importScripts("prep-core.js" + location.search, "master-core.js" + location.search, "prep-run.js" + location.search, "vendor/lame-3.100.js" + location.search);

let lameReady = null, decoderReady = null;
const env = {
  core: self.SwPrepCore,
  master: self.SwMaster,
  lame: () => (lameReady = lameReady || SwLame()),
  decoder: () => (decoderReady = decoderReady || (importScripts("vendor/ffmpeg-decode-7.1.js" + location.search), SwDecode())),
  post: (m) => self.postMessage(m),
};

self.onmessage = async (e) => {
  try {
    if (e.data.tagsOnly) { // round 85: the details window's tags for a file the server prepares
      self.postMessage(Object.assign({ tagsDone: true }, self.SwPrepRun.readTags(await env.decoder(), e.data.file)));
      return;
    }
    const r = await self.SwPrepRun.run(e.data, env);
    const send = r.done ? r.chunks.map((c) => c.buffer) : [];
    if (r.done && r.original) send.push(...r.original.chunks.map((c) => c.buffer));
    self.postMessage(r, send);
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex) });
  }
};
