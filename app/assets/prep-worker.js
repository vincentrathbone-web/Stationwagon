// Song prep off the main thread: master (master-core.js, when asked: master is false, true for the
// built-in Audition chain, or a preset's settings), measure, level (when asked), trim, fade
// (prep-core.js), then encode (lamejs).
// In: { channels: Float32Array[], rate, kbps, master, level, target }. Out: progress messages, then
// { done, chunks, info }, { skip: reason } when there's nothing to level, or { error }.
// the worker's own ?v= tag covers prep-core.js and master-core.js too (App::asset), so new ones are fetched fresh
importScripts("prep-core.js" + location.search, "master-core.js" + location.search, "vendor/lame.min.js");

self.onmessage = (e) => {
  const { channels, rate, kbps, master = false, level = true, target } = e.data;
  try {
    let src = channels;
    if (master) {
      self.postMessage({ stage: "Mastering", progress: 0 });
      let last = 0;
      src = self.SwMaster.master(channels, rate, (p) => { if (p - last >= 0.05 || p === 1) { last = p; self.postMessage({ stage: "Mastering", progress: p }); } },
        typeof master === "object" ? master : undefined);
    }
    self.postMessage({ stage: level ? "Levelling" : "Measuring" });
    const r = self.SwPrepCore.prepare(src, rate, { level, target });
    if (r.skip) {
      self.postMessage({ skip: r.skip });
      return;
    }
    r.info.mastered = !!master;
    const chunks = self.SwPrepCore.encode(lamejs, r.channels, rate, kbps, (p) => self.postMessage({ stage: "Encoding", progress: p }));
    self.postMessage({ done: true, chunks, info: r.info }, chunks.map((c) => c.buffer));
  } catch (ex) {
    self.postMessage({ error: String((ex && ex.message) || ex) });
  }
};
