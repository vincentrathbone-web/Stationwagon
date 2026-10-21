// The studio's podcast recorder: while the DJ is on air it records two tracks on this device, in
// step with what goes out: the music bus (stereo, as it went out, ducking included) and the mic as
// it goes out (mono). Each track is encoded to MP3 in its own worker (studio-encoder.js) and kept
// in this browser (IndexedDB "stationwagon-rec") in 30-second pieces cut on whole frames, with the
// waveform peaks and the clips (podcast-core.js) saved alongside. After End show the pieces are
// uploaded (podcast/api/rec-chunk, then rec-done) and deleted here; what a closed or crashed page
// left behind is uploaded the next time the studio opens.
//   const rec = SwRecorder.create({ ctx, music, mic, encoder, base, csrf, log });
//   rec.arm(showId) at go live; rec.startAt(frame) at the first sample on air; await rec.stop();
//   await SwRecorder.upload({ base, csrf, onProgress }) → [{ show, episode, id }]
(() => {
  "use strict";
  const DB = "stationwagon-rec", CHUNK_SECONDS = 10; // a reload or a crash loses at most this much
  const KBPS = { music: 128, mic: 96 };
  const P = window.SwPodcast;

  const REC_WORKLET = `
class SwRec extends AudioWorkletProcessor {
  constructor(){ super(); this.size = 4096; this.a = new Float32Array(this.size); this.b = new Float32Array(this.size); this.m = new Float32Array(this.size); this.n = 0; this.on = false;
    this.port.onmessage = (e) => { this.on = !!e.data.on; this.n = 0; }; }
  process(inputs){
    if (!this.on) return true;
    const mu = inputs[0], mi = inputs[1], L = mu && mu[0], R = mu && (mu[1] || mu[0]), M = mi && mi[0];
    const len = (L || M || { length: 128 }).length;
    if (this.n === 0) this.start = currentFrame;
    for (let i = 0; i < len; i++) { this.a[this.n] = L ? L[i] : 0; this.b[this.n] = R ? R[i] : 0; this.m[this.n] = M ? M[i] : 0; this.n++; }
    if (this.n >= this.size) { this.port.postMessage({ frame: this.start, l: this.a.slice(0, this.n), r: this.b.slice(0, this.n), m: this.m.slice(0, this.n) }); this.n = 0; }
    return true;
  }
}
registerProcessor("sw-rec", SwRec);`;

  // ---- The store ------------------------------------------------------------------------------
  let dbp = null;
  function db() {
    if (!dbp) dbp = new Promise((ok, fail) => {
      const r = indexedDB.open(DB, 1);
      r.onupgradeneeded = () => { r.result.createObjectStore("takes", { keyPath: "show" }); r.result.createObjectStore("chunks", { keyPath: ["show", "track", "n"] }); };
      r.onsuccess = () => ok(r.result); r.onerror = () => { dbp = null; fail(r.error); };
    });
    return dbp;
  }
  async function tx(stores, mode, fn) {
    const d = await db();
    return new Promise((ok, fail) => {
      const t = d.transaction(stores, mode); let out;
      Promise.resolve(fn(t)).then((v) => (out = v));
      t.oncomplete = () => ok(out); t.onerror = () => fail(t.error); t.onabort = () => fail(t.error || new Error("aborted"));
    });
  }
  const req = (r) => new Promise((ok, fail) => { r.onsuccess = () => ok(r.result); r.onerror = () => fail(r.error); });
  const allTakes = () => tx(["takes"], "readonly", (t) => req(t.objectStore("takes").getAll()));
  const chunksOf = (show) => tx(["chunks"], "readonly", (t) => req(t.objectStore("chunks").getAll(IDBKeyRange.bound([show, "", -1], [show, "￿", Infinity]))));

  const snapshot = (an) => ({ rate: P.PEAK_RATE, tracks: Object.fromEntries(Object.entries(an).map(([k, a]) => { const r = a.result(); return [k, { peaks: P.toBase64(r.peaks), clips: r.clips }]; })) });
  const finished = (an) => ({ rate: P.PEAK_RATE, tracks: Object.fromEntries(Object.entries(an).map(([k, a]) => { const r = a.finish(); return [k, { peaks: P.toBase64(r.peaks), clips: r.clips }]; })) });

  // ---- Recording --------------------------------------------------------------------------------
  async function create({ ctx, music, mic, encoder, log = () => {} }) {
    await ctx.audioWorklet.addModule(URL.createObjectURL(new Blob([REC_WORKLET], { type: "text/javascript" })));
    const node = new AudioWorkletNode(ctx, "sw-rec", { numberOfInputs: 2, numberOfOutputs: 1, outputChannelCount: [1] });
    const micIn = ctx.createGain(); micIn.channelCount = 1; micIn.channelCountMode = "explicit"; micIn.channelInterpretation = "speakers";
    music.connect(node, 0, 0); mic.connect(micIn).connect(node, 0, 1);
    const silent = ctx.createGain(); silent.gain.value = 0; node.connect(silent).connect(ctx.destination);
    await db();

    const workers = {};
    for (const track of ["music", "mic"]) {
      const w = new Worker(encoder);
      await new Promise((ok, fail) => { w.onmessage = (e) => (e.data.ready ? ok() : null); w.onerror = () => fail(new Error("the encoder didn’t load")); setTimeout(ok, 5000); });
      workers[track] = w;
    }

    let take = null, gen = 0, failed = null;
    const rec = {
      /** Get ready for a show: blocks are kept from now, and trimmed to the first frame on air. */
      arm(show) {
        gen++;
        take = { show, gen, startFrame: null, pending: [], frames: { music: [], mic: [] }, samples: { music: 0, mic: 0 }, counts: { music: 0, mic: 0 },
          total: { music: 0, mic: 0 }, analyzers: { music: new P.Analyzer(ctx.sampleRate), mic: new P.Analyzer(ctx.sampleRate) }, flushed: {}, writing: Promise.resolve(), seconds: 0 };
        failed = null;
        for (const track of ["music", "mic"]) {
          workers[track].postMessage({ init: { gen, rate: ctx.sampleRate, kbps: KBPS[track], channels: track === "music" ? 2 : 1 } });
          workers[track].onmessage = (e) => onFrames(track, e.data);
        }
        node.port.postMessage({ on: true });
        tx(["takes"], "readwrite", (t) => t.objectStore("takes").put({ show, counts: take.counts, analysis: null, stopped: false, at: Date.now() })).catch(fail);
        log(`recording armed for ${show}`);
      },
      /** The first sample listeners hear: the recording starts at this context frame. */
      startAt(frame) {
        if (!take || take.startFrame !== null) return;
        take.startFrame = frame;
        for (const b of take.pending) onBlock(b);
        take.pending = [];
      },
      /** Stop and keep the last pieces; resolves when everything is stored. */
      async stop() {
        if (!take) return null;
        const t = take; take = null;
        node.port.postMessage({ on: false });
        if (t.startFrame === null) { await tx(["takes"], "readwrite", (x) => x.objectStore("takes").delete(t.show)).catch(() => {}); return null; } // never went on air
        const done = ["music", "mic"].map((track) => new Promise((ok) => { t.flushed[track] = ok; workers[track].postMessage({ flush: { gen: t.gen } }); setTimeout(ok, 4000); }));
        stopping = t;
        await Promise.all(done);
        stopping = null;
        await t.writing;
        await tx(["takes"], "readwrite", (x) => x.objectStore("takes").put({ show: t.show, counts: t.counts, analysis: finished(t.analyzers), stopped: true, at: Date.now() })).catch(fail);
        log(`recording of ${t.show} stored: ${t.counts.music} + ${t.counts.mic} pieces, ${(t.total.music / ctx.sampleRate).toFixed(1)} s`);
        return t.show;
      },
      state: () => ({ recording: !!(take && take.startFrame !== null), seconds: take ? take.seconds : 0, show: take && take.show, error: failed,
        pieces: take ? { ...take.counts } : null }),
    };
    let stopping = null;
    const fail = (e) => { failed = (e && e.message) || String(e); log("recording: " + failed); };

    node.port.onmessage = (e) => {
      if (!take) return;
      if (take.startFrame === null) { take.pending.push(e.data); if (take.pending.length > 40) take.pending.shift(); return; }
      onBlock(e.data);
    };
    function onBlock({ frame, l, r, m }) {
      const t = take; if (!t) return;
      const end = frame + l.length; if (end <= t.startFrame) return;
      const from = Math.max(0, t.startFrame - frame);
      const L = l.slice(from), R = r.slice(from), M = m.slice(from);
      const loud = new Float32Array(L.length); for (let i = 0; i < L.length; i++) loud[i] = Math.max(Math.abs(L[i]), Math.abs(R[i]));
      t.analyzers.music.push(loud); t.analyzers.mic.push(M);
      t.seconds += L.length / ctx.sampleRate;
      workers.music.postMessage({ pcm: { gen: t.gen, l: L, r: R } }, [L.buffer, R.buffer]);
      workers.mic.postMessage({ pcm: { gen: t.gen, l: M } }, [M.buffer]);
    }
    function onFrames(track, msg) {
      if (msg.error) { fail(new Error(msg.error)); return; }
      const t = (take && take.gen === msg.gen) ? take : (stopping && stopping.gen === msg.gen ? stopping : null);
      if (!t || !msg.frames) return;
      for (const f of msg.frames) { t.frames[track].push(f.bytes); t.samples[track] += f.samples; }
      if (t.samples[track] >= CHUNK_SECONDS * ctx.sampleRate || (msg.final && t.frames[track].length)) store(t, track);
      if (msg.final && t.flushed[track]) t.flushed[track]();
    }
    function store(t, track) {
      const parts = t.frames[track], samples = t.samples[track], n = t.counts[track];
      const bytes = new Uint8Array(parts.reduce((a, b) => a + b.length, 0)); let o = 0; for (const b of parts) { bytes.set(b, o); o += b.length; }
      t.frames[track] = []; t.samples[track] = 0; t.counts[track] = n + 1; t.total[track] += samples;
      const counts = { ...t.counts }, analysis = snapshot(t.analyzers);
      t.writing = t.writing.then(() => tx(["takes", "chunks"], "readwrite", (x) => {
        x.objectStore("chunks").put({ show: t.show, track, n, samples, bytes });
        x.objectStore("takes").put({ show: t.show, counts, analysis, stopped: false, at: Date.now() });
      })).catch((e) => fail(e.name === "QuotaExceededError" ? new Error("this device is out of space for the recording") : e));
    }
    return rec;
  }

  // ---- Uploading --------------------------------------------------------------------------------
  let uploading = null, progress = null;
  /** Upload every recording kept here (except `except`, the one still being made). */
  function upload({ base, csrf, except = null, onProgress = () => {}, log = () => {} }) {
    if (uploading) return uploading;
    uploading = (async () => {
      const out = [];
      try {
        const all = await allTakes();
        // armed but never got a piece (the page closed within seconds): nothing to send
        for (const t of all.filter((t) => t.show !== except && !t.analysis && Date.now() - t.at > 3600e3)) await tx(["takes"], "readwrite", (x) => x.objectStore("takes").delete(t.show));
        const takes = all.filter((t) => t.show !== except && t.analysis);
        for (const take of takes) {
          const chunks = (await chunksOf(take.show)).sort((a, b) => (a.track === b.track ? a.n - b.n : a.track < b.track ? -1 : 1));
          const total = chunks.length; let sent = 0, drop = false;
          for (const c of chunks) {
            progress = { show: take.show, sent, total }; onProgress(progress);
            const res = await post(base, csrf, `podcast/api/rec-chunk?show=${encodeURIComponent(take.show)}&track=${c.track}&n=${c.n}&samples=${c.samples}`, c.bytes, true);
            if (res.status === 409 || res.status === 404 || res.status === 403) { log(`recording of ${take.show}: ${res.json.error}; dropped here`); drop = true; break; }
            if (res.status !== 200) throw new Error(res.json.error || "HTTP " + res.status);
            await tx(["chunks"], "readwrite", (x) => x.objectStore("chunks").delete([c.show, c.track, c.n]));
            sent++;
          }
          if (!drop) {
            const res = await post(base, csrf, "podcast/api/rec-done", JSON.stringify({ show: take.show, counts: take.counts, analysis: take.analysis }), false);
            if (res.status === 200) out.push({ show: take.show, episode: res.json.episode, id: res.json.id, state: res.json.state });
            else if (res.status >= 500 || res.status === 0) throw new Error(res.json.error || "HTTP " + res.status);
            else log(`recording of ${take.show} not accepted: ${res.json.error}; dropped here`);
          }
          await tx(["takes", "chunks"], "readwrite", (x) => {
            x.objectStore("takes").delete(take.show);
            x.objectStore("chunks").delete(IDBKeyRange.bound([take.show, "", -1], [take.show, "￿", Infinity]));
          });
          log(`recording of ${take.show} uploaded`);
        }
        progress = null; onProgress(null);
        return out;
      } catch (e) {
        progress = { error: e.message }; onProgress(progress);
        throw e;
      } finally { uploading = null; }
    })();
    return uploading;
  }
  async function post(base, csrf, path, body, raw) {
    try {
      const r = await fetch(base + path, { method: "POST", cache: "no-store", body, headers: { "X-CSRF": csrf, "Content-Type": raw ? "audio/mpeg" : "application/json" } });
      return { status: r.status, json: await r.json().catch(() => ({})) };
    } catch (e) { return { status: 0, json: { error: "no connection" } }; }
  }
  /** How many recordings are kept here, waiting to upload (not counting `except`). */
  const waiting = async (except = null) => (await allTakes().catch(() => [])).filter((t) => t.show !== except && t.analysis).length;

  window.SwRecorder = { create, upload, waiting, busy: () => !!uploading, progress: () => progress };
})();
