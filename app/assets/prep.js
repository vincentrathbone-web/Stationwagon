// Song prep before upload: decode the MP3 in the browser at its own sample rate, then (in
// prep-worker.js) resample it to the station's rate when that differs, master it (opts.master: true
// for the built-in Audition chain, or a preset's settings), level it to the station's loudness target
// (opts.level, opts.target in LUFS), trim silence at both ends, add 20 ms fades and encode it once with
// LAME 3.100 at the station's quality, carrying the original ID3 tags with its normalization and how
// it was made added to them (SwPrepCore.levelTags). With nothing to do (no mastering, no levelling, the
// rate kept) the original goes up as it came. When the browser can't, the original goes up as it is,
// with a note why.
// Other formats (round 54: WAV, FLAC, M4A/AAC/ALAC, Ogg, Opus, WMA) are decoded in the worker with the
// station's own decoder, then made into two MP3s from that one sound: the 320 kbps original the station
// keeps (for re-levelling) and the song, both tagged with the file's own title, artist, songwriters and
// cover. Only MP3s reach the server, so one that can't be converted isn't uploaded (refused: true).
//   SwPrep.prepare(file, onStage, { master, level, target, onNotice }) ->
//     { file, prepared, kept?, info?, note?, sourceSha1?, original?, name?, kind, refused? }
// original (converted uploads): the MP3 original to keep; name: the upload's name with .mp3; sourceSha1
// is then that original's SHA-1 (the station's fingerprint for the song).
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
  const MAX_OTHER = 400 * 1024 * 1024; // a WAV or FLAC: about 40 minutes of 24-bit 48 kHz WAV
  const NAMES = { wav: "WAV", flac: "FLAC", m4a: "M4A", ogg: "Ogg", wma: "WMA", aac: "AAC" };

  const hex = (buf) => [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, "0")).join("");
  const asIs = (file, sourceSha1, note) => ({ file, prepared: false, sourceSha1, note });

  const work = (channels, rate, onStage, opts) => new Promise((resolve, reject) => {
    const w = new Worker(workerUrl);
    const end = (fn, v) => { w.terminate(); fn(v); };
    w.onmessage = (e) => {
      const m = e.data;
      if (m.notice) { if (opts.onNotice) opts.onNotice(m.notice); }
      else if (m.stage) onStage(m.stage + (m.progress !== undefined ? " " + Math.round(m.progress * 100) + " %" : ""));
      else if (m.done || m.skip || m.keep) end(resolve, m);
      else end(reject, new Error(m.error || "Prep failed"));
    };
    w.onerror = (e) => { e.preventDefault(); end(reject, new Error(e.message || "Prep failed")); };
    const master = opts.master && typeof opts.master === "object" ? opts.master : !!opts.master;
    const job = { kbps: KBPS, master, level: opts.level !== false, target: opts.target, quality: QUALITY };
    if (opts.file) w.postMessage(Object.assign(job, { file: opts.file }), [opts.file]);
    else w.postMessage(Object.assign(job, { channels, rate }), channels.map((c) => c.buffer));
  });

  const sha1 = async (blob) => {
    try { return window.crypto && crypto.subtle ? hex(await crypto.subtle.digest("SHA-1", await blob.arrayBuffer())) : null; } catch (_) { return null; }
  };

  /** An upload that isn't an MP3: decoded and converted in the worker (round 54). */
  async function convert(file, kind, onStage, opts) {
    const refuse = (note) => ({ file, prepared: false, refused: true, kind, note });
    if (!window.Worker) return refuse("this browser can’t convert it to MP3");
    if (file.size > MAX_OTHER) return refuse("too long to convert in the browser (over " + Math.round(MAX_OTHER / 1048576) + " MB)");
    onStage("Decoding");
    let r;
    try {
      r = await work(null, null, onStage, Object.assign({}, opts, { file: await file.arrayBuffer() }));
    } catch (ex) {
      return refuse(ex.message);
    }
    if (r.skip) return refuse(r.skip);
    const C = window.SwPrepCore, head = C.id3(r.tags, r.picture);
    const original = new Blob([head, ...r.original.chunks], { type: "audio/mpeg" });
    const song = new Blob([C.levelTags(head, r.info), ...r.chunks], { type: "audio/mpeg" });
    const name = file.name.replace(/\.[^.\/]*$/, "") + ".mp3";
    r.info.converted.kind = kind === "ogg" && r.source.codec === "opus" ? "Opus" : NAMES[kind] || kind;
    return { file: song, prepared: true, info: r.info, sourceSha1: await sha1(original), original, name, kind, tags: r.tags || {}, picture: r.picture || null };
  }

  async function prepare(file, onStage = () => {}, opts = {}) {
    const kind = window.SwPrepCore.sniff(new Uint8Array(await file.slice(0, 65536).arrayBuffer()), file.name || "");
    if (kind && kind !== "mp3") return convert(file, kind, onStage, opts);
    if (!kind) return { file, prepared: false, refused: true, kind, note: "not an audio file the station can read (MP3, WAV, FLAC, M4A, AAC, Ogg, Opus or WMA)" };
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
