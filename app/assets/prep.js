// Song prep before upload: decode the MP3 in the browser, then (in prep-worker.js) master it like
// the station's Audition chain (opts.master), level it to -16 LUFS (opts.level), trim silence at
// both ends, add 20 ms fades and encode a new 192 kbps MP3 carrying the original ID3 tags. When the
// browser can't, the original goes up as it is, with a note why.
//   SwPrep.prepare(file, onStage, { master, level }) -> { file, prepared, info?, note?, sourceSha1? }
(() => {
  const here = document.currentScript.src.replace(/[^/?#]*([?#].*)?$/, "");
  const workerUrl = document.currentScript.dataset.worker || here + "prep-worker.js"; // tagged by content
  const RATE = 44100, KBPS = Number(document.currentScript.dataset.kbps) || 192; // Sound & safety: quality of prepared songs
  const MAX_BYTES = 60 * 1024 * 1024; // about an hour at 128 kbps; longer would need too much memory

  const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
  const asIs = (file, sourceSha1, note) => ({ file, prepared: false, sourceSha1, note });

  const work = (channels, onStage, opts) => new Promise((resolve, reject) => {
    const w = new Worker(workerUrl);
    const end = (fn, v) => { w.terminate(); fn(v); };
    w.onmessage = (e) => {
      const m = e.data;
      if (m.stage) onStage(m.stage + (m.progress !== undefined ? " " + Math.round(m.progress * 100) + " %" : ""));
      else if (m.done || m.skip) end(resolve, m);
      else end(reject, new Error(m.error || "Prep failed"));
    };
    w.onerror = (e) => { e.preventDefault(); end(reject, new Error(e.message || "Prep failed")); };
    w.postMessage({ channels, rate: RATE, kbps: KBPS, master: !!opts.master, level: opts.level !== false }, channels.map((c) => c.buffer));
  });

  async function prepare(file, onStage = () => {}, opts = {}) {
    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!Offline || !window.Worker) return asIs(file, null, "this browser can’t prepare songs");
    const bytes = new Uint8Array(await file.arrayBuffer());
    let sourceSha1 = null;
    try { sourceSha1 = window.crypto && crypto.subtle ? hex(await crypto.subtle.digest("SHA-1", bytes)) : null; } catch (_) { /* not a secure page */ }
    if (file.size > MAX_BYTES) return asIs(file, sourceSha1, "too long to prepare in the browser");
    onStage("Decoding");
    let audio;
    try {
      audio = await new Offline(2, 1, RATE).decodeAudioData(bytes.buffer.slice(0));
    } catch (_) {
      return asIs(file, sourceSha1, "the browser couldn’t read it as audio");
    }
    const channels = [];
    for (let c = 0; c < Math.min(2, audio.numberOfChannels); c++) channels.push(new Float32Array(audio.getChannelData(c)));
    let r;
    try {
      r = await work(channels, onStage, opts);
    } catch (ex) {
      return asIs(file, sourceSha1, "prep failed: " + ex.message);
    }
    if (r.skip) return asIs(file, sourceSha1, r.skip);
    const tags = window.SwPrepCore.tags(bytes);
    const blob = new Blob([tags.head, ...r.chunks, tags.tail], { type: "audio/mpeg" });
    return { file: blob, prepared: true, info: r.info, sourceSha1 };
  }

  window.SwPrep = { prepare };
})();
