// Sound quality of song prep (round 51) measured on lossless music, in headless Edge or Chrome with
// the app's own prep-core.js, master-core.js and LAME 3.100 (WebAssembly):
//   - the resampler on music: 44.1 -> 48 -> 44.1 kHz and back, level, length, speed;
//   - the noise each rounding adds: 16 and 24 bits, with and without TPDF dither;
//   - the encoder on 12 excerpts of the EBU SQAM disc at 160 kbps: LAME 3.100 at -q 0, 2, 5 and 9,
//     without the bit reservoir, and lamejs (the old encoder), each compared with its 16-bit input;
//   - an upload the way it happens (the lossless original sent as a 320 kbps MP3, then the station's
//     chain at 44.1 or 48 kHz, 32-, 24- or 16-bit processing, mastered or not), compared with the
//     lossless original, beside the old chain and one encode straight from the original.
// "band" is the signal-to-error ratio below 16 kHz (dB): higher is closer.
//   node app/tests/quality_browser_test.mjs [audio folder]
// The audio folder (default D:/Dev/toolshed/audio, or SW_AUDIO) holds sqam/x/NN.flac (EBU Tech 3253,
// R&D use only) and pdp8/01-amazon.flac (pdp8, "calling home", CC BY-SA 4.0, 24-bit). They are not
// in the repo. Nothing else is needed: the page is served from here, not from the app.
import { spawn } from "node:child_process";
import { createServer } from "node:http";
import { readFileSync, existsSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname, normalize } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const audio = process.argv[2] || process.env.SW_AUDIO || "D:/Dev/toolshed/audio";
const assets = join(here, "..", "assets");
if (!existsSync(join(audio, "sqam", "x", "69.flac")) || !existsSync(join(audio, "pdp8", "01-amazon.flac"))) {
  console.error(`The lossless test music isn't in ${audio} (sqam/x/NN.flac, pdp8/01-amazon.flac): see HANDOVER.md "Dev environment".`);
  process.exit(1);
}
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const f1 = (v) => (v === null || v === undefined ? "-" : v.toFixed(1));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- the page: the app's assets, the samples and quality_page.js -------------------------------
const types = { ".js": "text/javascript", ".flac": "audio/flac", ".html": "text/html" };
const server = createServer((req, res) => {
  const path = decodeURIComponent(new URL(req.url, "http://x").pathname);
  let file = null;
  if (path === "/") {
    res.writeHead(200, { "content-type": "text/html" });
    return res.end(`<!doctype html><meta charset="utf-8"><title>quality</title>
<script src="/assets/prep-core.js"></script><script src="/assets/master-core.js"></script>
<script src="/assets/vendor/lame-3.100.js"></script><script src="/assets/vendor/lame.min.js"></script>
<script src="/quality_page.js"></script>`);
  }
  if (path === "/quality_page.js") file = join(here, "quality_page.js");
  else if (path.startsWith("/assets/")) file = normalize(join(assets, path.slice(8)));
  else if (path.startsWith("/audio/")) file = normalize(join(audio, path.slice(7)));
  if (!file || !(file.startsWith(normalize(assets)) || file.startsWith(normalize(audio)) || file === join(here, "quality_page.js")) || !existsSync(file)) {
    res.writeHead(404); return res.end();
  }
  res.writeHead(200, { "content-type": types[file.slice(file.lastIndexOf("."))] || "application/octet-stream" });
  res.end(readFileSync(file));
});
await new Promise((ok) => server.listen(0, "127.0.0.1", ok));
const site = `http://127.0.0.1:${server.address().port}`;

// ---- browser -----------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((b) => existsSync(b));
const profile = mkdtempSync(join(tmpdir(), "sw-quality-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "--mute-audio", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map(), errors = [];
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const Q = (fn, ...args) => evaluate(`SwQuality.${fn}(${args.map((a) => JSON.stringify(a)).join(", ")})`);
const sqam = (n) => `/audio/sqam/x/${String(n).padStart(2, "0")}.flac`;
const PDP8 = "/audio/pdp8/01-amazon.flac";

try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    if (d.method === "Runtime.exceptionThrown") errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text);
  };
  await call("Page.enable"); await call("Runtime.enable");
  await call("Page.navigate", { url: site + "/" });
  for (let i = 0; i < 100 && !(await evaluate("!!(window.SwQuality && window.SwPrepCore && window.SwLame)").catch(() => false)); i++) await sleep(100);
  check("the page has the app's prep code, LAME 3.100 and lamejs", await Q("ready"));

  console.log("The level of a -6 dBFS 1 kHz tone after decoding");
  for (const r of [44100, 48000]) {
    const tl = await Q("toneLevel", r);
    check(`${r / 1000} kHz: a 16-bit WAV (the control) and LAME 3.100's MP3 decode at the level they were made (within 0.05 dB)`, Math.abs(tl.wav) < 0.05 && Math.abs(tl.lame) < 0.05,
      `WAV ${tl.wav.toFixed(3)} dB, LAME ${tl.lame.toFixed(3)} dB, lamejs ${tl.lamejs.toFixed(3)} dB`);
  }
  if (process.env.SW_QONLY) throw new Error("SW_QONLY: stopped after the tone check");

  console.log("The resampler on music (ABBA, 30 s)");
  const rs = await Q("resampler", sqam(69), 30);
  check("44.1 -> 48 -> 44.1 kHz comes back the same below 19.5 kHz (at least 90 dB)", rs.band >= 90, `${f1(rs.band)} dB; whole band ${f1(rs.snr)} dB`);
  check("  the level doesn't change", Math.abs(rs.level) < 0.01, `${rs.level.toFixed(4)} dB`);
  check("  48 kHz is exactly as long", Math.abs(rs.lengthUp - rs.expectUp) <= 1, `${rs.lengthUp} of ${rs.expectUp} samples`);
  check("  about a second per minute of stereo or less", rs.msPerMinute < 4000, `${Math.round(rs.msPerMinute)} ms per minute`);

  console.log("The noise each rounding adds (pdp8, 24-bit, 30 s)");
  const rn = await Q("rounding", PDP8, 30, 60);
  check("16 bits with TPDF dither: -96.3 dBFS (half a step RMS)", Math.abs(rn.d16 + 96.33) < 0.3, `${rn.d16.toFixed(2)} dBFS`);
  check("16 bits without dither: -101.1 dBFS (but tied to the music)", Math.abs(rn.n16 + 101.08) < 0.5, `${rn.n16.toFixed(2)} dBFS`);
  check("24 bits with TPDF dither: -144.5 dBFS", Math.abs(rn.d24 + 144.5) < 0.5, `${rn.d24.toFixed(2)} dBFS`);
  check("the final 16 bits for the encoder, dithered: -96.3 dBFS", Math.abs(rn.final16 + 96.33) < 0.3, `${rn.final16.toFixed(2)} dBFS; undithered ${rn.final16n.toFixed(2)}`);

  console.log("The encoder at 160 kbps on 12 EBU SQAM excerpts (15 s each), against its 16-bit input");
  const items = [[2, "pink noise"], [27, "castanets"], [28, "side drum"], [32, "triangles"], [35, "glockenspiel"], [40, "harpsichord"],
    [44, "soprano"], [49, "female speech"], [58, "guitar"], [60, "piano"], [65, "orchestra"], [69, "ABBA"]];
  const runs = ["q0", "q2", "q5", "q9", "q0_noReservoir", "lamejs"];
  const sum = Object.fromEntries(runs.map((r) => [r, 0])), nmr = Object.fromEntries(runs.map((r) => [r, 0])), lags = new Set(), table = [];
  let worst = Infinity, loss = Object.fromEntries(runs.map((r) => [r, [0, ""]])), sizeOk = true, music = 0, sizes = [];
  for (const [n, name] of items) {
    const e = await Q("encoders", sqam(n), 0, 15, 160);
    table.push(`    ${name.padEnd(14)} ${runs.map((r) => f1(e[r].band).padStart(6)).join("")}   ${runs.map((r) => f1(e[r].nmr).padStart(6)).join("")}`);
    for (const r of runs) {
      if (n !== 2) { sum[r] += e[r].band; nmr[r] += e[r].nmr; }
      if (e[r].levelBand < loss[r][0]) loss[r] = [e[r].levelBand, name];
      const want = 160000 / 8 * e.seconds;
      if (Math.abs(e[r].bytes / want - 1) > 0.03) { sizeOk = false; sizes.push(`${name} ${r} ${e[r].bytes}`); }
    }
    if (n !== 2) music++;
    lags.add(e.q0.lag); lags.add(e.q9.lag); lags.add(e.q0_noReservoir.lag);
    worst = Math.min(worst, e.q0.band);
  }
  const head = runs.map((r) => r.replace("q0_noReservoir", "q0-res").padStart(6)).join("");
  console.log(`    ${"".padEnd(14)} ${"SNR below 16 kHz (dB, higher is closer)".padEnd(36)}   NMR (dB, lower is better; under 0 masked)`);
  console.log(`    ${"".padEnd(14)} ${head}   ${head}`);
  for (const t of table) console.log(t);
  const avg = Object.fromEntries(runs.map((r) => [r, sum[r] / music])), avgN = Object.fromEntries(runs.map((r) => [r, nmr[r] / music]));
  console.log(`    ${"average music".padEnd(14)} ${runs.map((r) => f1(avg[r]).padStart(6)).join("")}   ${runs.map((r) => f1(avgN[r]).padStart(6)).join("")}`);
  check("every LAME 3.100 MP3 lines up at the same delay (encoder 576 + decoder 529)", lags.size === 1 && lags.has(1105), [...lags].join(", "));
  console.log(`    level lost below 16 kHz at most: ${runs.map((r) => `${r} ${(-loss[r][0]).toFixed(2)} dB (${loss[r][1]})`).join(", ")}`);
  check("  -q 0 with the reservoir (the station's setting) keeps the level below 16 kHz (within 0.25 dB)", loss.q0[0] >= -0.25, `${loss.q0[0].toFixed(3)} dB, ${loss.q0[1]}`);
  check("  each is 160 kbps (within 3 %)", sizeOk, sizes.slice(0, 3).join("; "));
  check("  each is close to its input (band above 5 dB: lined up, nothing broken)", worst > 5, `worst ${f1(worst)} dB`);
  check("-q 0 (the slider's best) hides its error better than -q 9 (fastest), on average", avgN.q0 <= avgN.q9 + 0.2,
    `NMR ${f1(avgN.q0)} vs ${f1(avgN.q9)} dB; SNR ${f1(avg.q0)} vs ${f1(avg.q9)}: -q 9 spreads its noise evenly, -q 0 puts it under the music`);
  check("LAME 3.100 at -q 0 is closer than lamejs (the old encoder) and hides its error better, on average", avg.q0 >= avg.lamejs - 0.2 && avgN.q0 <= avgN.lamejs + 0.2,
    `SNR ${f1(avg.q0)} vs ${f1(avg.lamejs)} dB, NMR ${f1(avgN.q0)} vs ${f1(avgN.lamejs)} dB`);

  console.log("An upload: the lossless original sent as 320 kbps MP3, then the station's chain, against the original");
  const cases = [
    ["ABBA, 30 s, 44.1 kHz, 32-bit float", sqam(69), 0, 30, {}],
    ["castanets, 15 s, 44.1 kHz, 32-bit float", sqam(27), 0, 15, {}],
    ["pdp8 (24-bit), 60 s, 44.1 kHz, 32-bit float", PDP8, 60, 60, {}],
    ["pdp8 (24-bit), 60 s, 44.1 kHz, 24-bit", PDP8, 60, 60, { precision: 24 }],
    ["pdp8 (24-bit), 60 s, 44.1 kHz, 16-bit", PDP8, 60, 60, { precision: 16 }],
    ["ABBA, 30 s, to 48 kHz, 32-bit float", sqam(69), 0, 30, { rate: 48000 }],
    ["pdp8 (24-bit), 60 s, mastered", PDP8, 60, 60, { master: true }],
  ];
  const up = {};
  for (const [label, url, from, seconds, opts] of cases) {
    const r = await Q("upload", url, from, seconds, opts);
    up[label] = r;
    const sn = (v) => (v ? `SNR ${f1(v.band)} / NMR ${f1(v.nmr)}` : "-");
    console.log(`    ${label}: the 320 upload SNR ${f1(r.sent)}; final vs its input ${sn(r.vsInput)}; vs the original ${sn(r.vsFlac)}` +
      (r.oldVsFlac !== undefined ? `; old chain ${sn(r.oldVsFlac)}; one encode from the original ${sn(r.oneEncodeVsFlac)}` : "") +
      `; gain ${r.gain} dB${r.capped ? " (capped by the peak ceiling)" : ""}, peak ${r.decodedPeak.toFixed(2)} dBFS decoded; LAME ${Math.round(r.encodeMsPerMinute)} ms/min`);
    const rate = opts.rate || 44100;
    check(`${label}: an MP3 at ${rate / 1000} kHz, lowpass ${r.lowpass} Hz`, r.mp3Rate === rate && r.lowpass > 16000, `${r.mp3Rate} Hz`);
    check("  levelled to -16 LUFS (or as near as the peak ceiling lets it), no 16-bit sample at full scale",
      (Math.abs(r.lufs + 16) < 0.1 || (r.capped && r.lufs < -16)) && r.overs16 === 0, `${r.lufs} LUFS${r.capped ? ", capped" : ""}, ${r.overs16} at full scale`);
    check("  the 16-bit step adds only its dither (-96.3 dBFS)", Math.abs(r.int16Noise + 96.33) < 0.5, `${r.int16Noise.toFixed(2)} dBFS`);
    check("  the decoded MP3 doesn't clip", r.decodedOvers === 0, `${r.decodedOvers} samples at or over full scale`);
    if (r.oldVsFlac !== undefined) {
      check("  closer to the original than the old chain, and its error better hidden", r.vsFlac.band >= r.oldVsFlac.band - 0.3 && r.vsFlac.nmr <= r.oldVsFlac.nmr + 0.3,
        `${sn(r.vsFlac)} vs ${sn(r.oldVsFlac)} dB`);
      check("  within 1.5 dB of one encode straight from the original (the MP3 upload's own cost)",
        r.vsFlac.band >= r.oneEncodeVsFlac.band - 1.5 && r.vsFlac.nmr <= r.oneEncodeVsFlac.nmr + 1.5, `${sn(r.vsFlac)} vs ${sn(r.oneEncodeVsFlac)} dB`);
    }
  }
  const p32 = up["pdp8 (24-bit), 60 s, 44.1 kHz, 32-bit float"].vsFlac, p24 = up["pdp8 (24-bit), 60 s, 44.1 kHz, 24-bit"].vsFlac, p16 = up["pdp8 (24-bit), 60 s, 44.1 kHz, 16-bit"].vsFlac;
  check("processing at 24 bits ends as close to the original as 32-bit float (within 0.1 dB)", Math.abs(p24.band - p32.band) < 0.1 && Math.abs(p24.nmr - p32.nmr) < 0.1,
    `SNR ${f1(p24.band)} vs ${f1(p32.band)}, NMR ${f1(p24.nmr)} vs ${f1(p32.nmr)} dB`);
  check("processing at 16 bits ends within 0.3 dB of 32-bit float (the MP3's own error is far larger)", Math.abs(p16.band - p32.band) < 0.3 && Math.abs(p16.nmr - p32.nmr) < 0.3,
    `SNR ${f1(p16.band)} vs ${f1(p32.band)}, NMR ${f1(p16.nmr)} vs ${f1(p32.nmr)} dB`);
  const a44 = up["ABBA, 30 s, 44.1 kHz, 32-bit float"].vsFlac, a48 = up["ABBA, 30 s, to 48 kHz, 32-bit float"].vsFlac;
  check("converted to 48 kHz, the song ends within 1.5 dB of staying at 44.1 kHz", a48.band >= a44.band - 1.5 && a48.nmr <= a44.nmr + 1.5,
    `SNR ${f1(a48.band)} vs ${f1(a44.band)}, NMR ${f1(a48.nmr)} vs ${f1(a44.nmr)} dB`);
  check("no errors in the page", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (ex) {
  check("the test ran to the end", false, ex.message);
} finally {
  try { ws && ws.close(); } catch { /* closed */ }
  proc.kill();
  server.close();
  await sleep(500);
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* the browser may still hold it */ }
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
