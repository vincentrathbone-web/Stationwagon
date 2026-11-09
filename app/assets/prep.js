// Song prep before upload: decode the MP3 in the browser at its own sample rate, then (in
// prep-worker.js) resample it to the station's rate when that differs, master it (opts.master: true
// for the built-in Audition chain, or a preset's settings), level it to the station's loudness target
// (opts.level, opts.target in LUFS), trim silence at both ends, add 20 ms fades and encode it once with
// LAME 3.100 at the station's quality, carrying the original ID3 tags with its normalization and how
// it was made added to them (SwPrepCore.levelTags). With nothing to do (no mastering, no levelling, the
// rate kept) the original goes up as it came. When the browser can't, the original goes up as it is,
// with a note why.
//   SwPrep.prepare(file, onStage, { master, level, target, onNotice }) -> { file, prepared, kept?, info?, note?, sourceSha1? }
// onNotice(text) is told, before the work starts, when the song's sample rate will be converted.
// The station's quality settings come from this script tag: data-kbps, data-rate (0 = the song's own,
// 44100 (the default), 48000), data-precision (32, 24, 16), data-dither (1/0) and data-quality (LAME's -q, 0 best).
(() => {
  const here = document.currentScript.src.replace(/[^/?#]*([?#].*)?$/, "");
  const ds = document.currentScript.dataset;
  const workerUrl = ds.worker || here + "prep-worker.js"; // tagged by content
  const num = (v, d) => (v !== undefined && v !== "" && isFinite(Number(v)) ? Number(v) : d);
  const KBPS = num(ds.kbps, 160);
  const QUALITY = { rate: num(ds.rate, 44100), precision: num(ds.precision, 32), dither: num(ds.dither, 1) === 1, q: num(ds.quality, 0) };
  const MAX_BYTES = 60 * 1024 * 1024; // about an hour at 128 kbps; longer would need too much memory

  const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
  const asIs = (file, sourceSha1, note) => ({ file, prepared: false, sourceSha1, note });

  const work = (channels, rate, onStage, opts) => new Promise((resolve, reject) => {
    const w = new Worker(workerUrl);
    const end = (fn, v) => { w.terminate(); fn(v); };
    w.onmessage = (e) => {
      const m = e.data;
      if (m.stage) onStage(m.stage + (m.progress !== undefined ? " " + Math.round(m.progress * 100) + " %" : ""));
      else if (m.done || m.skip || m.keep) end(resolve, m);
      else end(reject, new Error(m.error || "Prep failed"));
    };
    w.onerror = (e) => { e.preventDefault(); end(reject, new Error(e.message || "Prep failed")); };
    const master = opts.master && typeof opts.master === "object" ? opts.master : !!opts.master;
    w.postMessage({ channels, rate, kbps: KBPS, master, level: opts.level !== false, target: opts.target, quality: QUALITY }, channels.map((c) => c.buffer));
  });

  async function prepare(file, onStage = () => {}, opts = {}) {
    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    if (!Offline || !window.Worker) return asIs(file, null, "this browser can’t prepare songs");
    const bytes = new Uint8Array(await file.arrayBuffer());
    let sourceSha1 = null;
    try { sourceSha1 = window.crypto && crypto.subtle ? hex(await crypto.subtle.digest("SHA-1", bytes)) : null; } catch (_) { /* not a secure page */ }
    if (file.size > MAX_BYTES) return asIs(file, sourceSha1, "too long to prepare in the browser");
    // decode at the song's own rate, so the browser doesn't resample it (our resampler does, when needed)
    const rate = window.SwPrepCore.mp3Rate(bytes) || 44100;
    const note = window.SwPrepCore.rateNote(rate, QUALITY.rate);
    if (note && opts.onNotice) opts.onNotice(note.charAt(0).toUpperCase() + note.slice(1));
    onStage("Decoding");
    let audio;
    try {
      audio = await new Offline(2, 1, rate).decodeAudioData(bytes.buffer.slice(0));
    } catch (_) {
      return asIs(file, sourceSha1, "the browser couldn’t read it as audio");
    }
    const channels = [];
    for (let c = 0; c < Math.min(2, audio.numberOfChannels); c++) channels.push(new Float32Array(audio.getChannelData(c)));
    let r;
    try {
      r = await work(channels, audio.sampleRate, onStage, opts);
    } catch (ex) {
      return asIs(file, sourceSha1, "prep failed: " + ex.message);
    }
    if (r.skip) return asIs(file, sourceSha1, r.skip);
    if (r.keep) return { file, prepared: true, kept: true, info: r.info, sourceSha1 };
    const tags = window.SwPrepCore.tags(bytes);
    const blob = new Blob([window.SwPrepCore.levelTags(tags.head, r.info), ...r.chunks, tags.tail], { type: "audio/mpeg" });
    return { file: blob, prepared: true, info: r.info, sourceSha1 };
  }

  window.SwPrep = { prepare, quality: () => Object.assign({ kbps: KBPS }, QUALITY) };
})();
