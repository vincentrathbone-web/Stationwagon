// Song prep off the main thread: measure, level, trim, fade (prep-core.js), then encode (lamejs).
// In: { channels: Float32Array[], rate, kbps }. Out: progress messages, then { done, chunks, info },
// { skip: reason } when there's nothing to level, or { error }.
importScripts("prep-core.js", "vendor/lame.min.js");

self.onmessage = (e) => {
  const { channels, rate, kbps } = e.data;
  try {
    self.postMessage({ stage: "Levelling" });
    const r = self.SwPrepCore.prepare(channels, rate);
    if (r.skip) {
      self.postMessage({ skip: r.skip });
      return;
    }
    const chunks = self.SwPrepCore.encode(lamejs, r.channels, rate, kbps, (p) => self.postMessage({ stage: "Encoding", progress: p }));
    self.postMessage({ done: true, chunks, info: r.info }, chunks.map((c) => c.buffer));
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex) });
  }
};
