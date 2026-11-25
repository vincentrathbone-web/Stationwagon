// Song files' peaks made in the manager's browser (round 87), on a station whose server can't run Node (src/Peaks.php
// says why the listeners' output strip needs them). While the Music library page is open it asks the station which
// song files have none yet (newest first, any song, ad or jingle), reads each one, has the song prep worker decode it
// and measure its 10 ms peaks (prep-worker.js, { peaksOnly }), and sends them back. One file at a time, quietly; a
// file that fails is left for next time. Stops when the page closes; carries on the next time it opens.
(() => {
  "use strict";
  const el = document.getElementById("peaksFill");
  if (!el) return;
  const { post: postUrl, csrf, worker: workerUrl } = el.dataset;
  let w = null;
  const send = async (data) => {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v);
    fd.append("csrf", csrf);
    const res = await fetch(postUrl, { method: "POST", body: fd, headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error("HTTP " + res.status);
    return res.json();
  };
  const measure = (buffer) => new Promise((resolve, reject) => {
    w = w || new Worker(workerUrl);
    w.onmessage = (e) => (e.data.error ? reject(new Error(e.data.error)) : e.data.peaks ? resolve(e.data.peaks) : null);
    w.onerror = (e) => reject(new Error(e.message || "the worker stopped"));
    w.postMessage({ peaksOnly: true, file: buffer }, [buffer]);
  });
  const say = (text) => { el.hidden = !text; el.textContent = text || ""; };
  const SAY = "Making song waveforms for the listeners’ player (this station’s server can’t, so this page does)";

  async function run() {
    const skip = new Set();
    let made = 0;
    for (;;) {
      const t = await send({ action: "peaks_todo", skip: [...skip].join(",") });
      const files = (t.files || []).filter((f) => !skip.has(f.file));
      if (!files.length) break;
      for (const f of files) {
        say(`${SAY}: ${made} made, ${Math.max(0, t.left - made)} to go…`);
        try {
          const res = await fetch(f.url);
          if (!res.ok) throw new Error("HTTP " + res.status);
          const peaks = await measure(await res.arrayBuffer());
          const r = await send({ action: "peaks_save", file: f.file, peaks: new Blob([peaks], { type: "application/octet-stream" }) });
          if (!r.ok) throw new Error(r.error || "not kept");
          made++;
        } catch (ex) {
          skip.add(f.file);
          console.warn("Stationwagon: no waveform for " + f.file + ": " + ex.message);
        }
      }
    }
    say(made ? `Song waveforms made for the listeners’ player: ${made}.` : "");
    if (w) { w.terminate(); w = null; }
    window.swPeaksFill = { done: true, made, skipped: skip.size }; // tests
  }
  window.swPeaksFill = { done: false };
  setTimeout(() => run().catch((ex) => { say(""); window.swPeaksFill = { done: true, error: ex.message }; }), 1500);
})();
