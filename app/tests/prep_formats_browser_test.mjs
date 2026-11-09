// Song prep's conversion of other formats (round 54) in each browser, without uploading: the station's
// own page loads prep-core.js and prep.js, and every sample (make_format_samples.mjs: WAV 16-bit and 24-bit
// 96 kHz, FLAC, M4A AAC and ALAC, Ogg Vorbis, Opus, WMA) is prepared the way the Music library does it.
// Checked for each: converted with the station's own decoder, the song an MP3 at 44.1 kHz and the station's
// bit rate, levelled to -16 LUFS (decoded back by the browser itself), its tags and cover in an ID3 tag,
// the 320 kbps original known by its fingerprint, and the time it took.
//   node app/tests/prep_formats_browser_test.mjs http://localhost:8080/stationwagon-app [--firefox | --webkit | --phone]
// Edge over DevTools, Firefox over WebDriver BiDi, --webkit: Playwright's WebKit (Safari's engine), --phone: Brave on the Android phone over USB (unlocked;
// adb at SW_ADB; Laragon and the samples reach it through `adb reverse`). Needs the lossless test music.
import { spawn, execFileSync } from "node:child_process";
import { createServer } from "node:http";
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { pathToFileURL } from "node:url";
import { createRequire } from "node:module";
import { makeSamples, SAMPLES, TAGS } from "./make_format_samples.mjs";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/prep_formats_browser_test.mjs <base url> [--firefox | --webkit | --phone]"); process.exit(1); }
const FIREFOX = process.argv.includes("--firefox"), PHONE = process.argv.includes("--phone"), WEBKIT = process.argv.includes("--webkit");
// --webkit: playwright-core and its WebKit in SW_PLAYWRIGHT (default D:/Dev/toolshed/playwright; see HANDOVER.md)
const PW = process.env.SW_PLAYWRIGHT || "D:/Dev/toolshed/playwright";
if (WEBKIT && !process.env.PLAYWRIGHT_BROWSERS_PATH) process.env.PLAYWRIGHT_BROWSERS_PATH = join(PW, "browsers");
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const formats = makeSamples();

// ---- the samples, served to the page (with CORS: the page is the station's own) -------------------
const samplePort = 9850 + Math.floor(Math.random() * 100);
const server = createServer((req, res) => {
  const name = decodeURIComponent(new URL(req.url, "http://x").pathname).slice(1);
  if (!SAMPLES.some((s) => s.file === name)) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { "Content-Type": "application/octet-stream", "Access-Control-Allow-Origin": "*" });
  res.end(readFileSync(join(formats, name)));
});
await new Promise((ok) => server.listen(samplePort, "127.0.0.1", ok));

// ---- the browser -----------------------------------------------------------------------------------
const profile = mkdtempSync(join(tmpdir(), "sw-formats-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
let proc, ws, id = 0;
const pending = new Map();
const send = (msg) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, ...msg })); });
const connect = async (url) => {
  ws = new WebSocket(url);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
};
const waitFor = async (url) => { for (let i = 0; i < 100; i++) { try { const r = await fetch(url); if (r.status < 500) return; } catch { /* starting */ } await sleep(200); } };
const adbPath = process.env.SW_ADB || "D:/Android/platform-tools/adb.exe";
let browser;
if (PHONE) {
  const APP = process.env.SW_PHONE_BROWSER || "com.brave.browser";
  const adb = (...args) => execFileSync(adbPath, ["shell", ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] });
  const call = (method, params = {}) => send({ method, params });
  browser = {
    name: `the phone's ${APP}`,
    async start() {
      if (adb("dumpsys", "window").includes("isKeyguardShowing=true")) throw new Error("the phone is locked: unlock it and run this again");
      for (const p of [new URL(base).port || "80", String(samplePort)]) execFileSync(adbPath, ["reverse", `tcp:${p}`, `tcp:${p}`], { stdio: "ignore" });
      adb("monkey", "-p", APP, "-c", "android.intent.category.LAUNCHER", "1");
      await sleep(2000);
      const pid = adb("pidof", APP).trim().split(/\s+/)[0];
      const own = pid && adb("cat", "/proc/net/unix").includes(`@chrome_devtools_remote_${pid}`) ? `chrome_devtools_remote_${pid}` : "chrome_devtools_remote";
      execFileSync(adbPath, ["forward", "tcp:9222", `localabstract:${own}`], { stdio: "ignore" });
      const held = await fetch("http://localhost:9222/json/version").then((r) => r.json()).catch(() => ({}));
      if (held["Android-Package"] !== APP) throw new Error(`port 9222 leads to ${held["Android-Package"] || "nothing"}, not ${APP}: open it on the phone`);
      const pg = (await (await fetch("http://localhost:9222/json")).json()).find((t) => t.type === "page");
      if (!pg) throw new Error("no tab open in the phone's browser");
      await connect(pg.webSocketDebuggerUrl);
      await call("Page.enable"); await call("Runtime.enable");
    },
    async open(url) { await call("Page.navigate", { url }); await sleep(2500); return null; },
    async evaluate(_, expression) {
      const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
      if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
      return r.result?.result?.value;
    },
    async close() { try { await call("Page.navigate", { url: `${base}/privacy` }); } catch { /* gone */ } },
  };
} else if (WEBKIT) {
  // Safari's engine: Playwright's WebKit build for Windows (not Safari itself, which Apple only makes for its
  // own devices; the same engine, but its own sound and video formats)
  const { webkit } = await import(pathToFileURL(join(PW, "node_modules", "playwright-core", "index.mjs")).href);
  let wk, ctx;
  browser = {
    name: "WebKit (Playwright, Safari's engine)",
    async start() { wk = await webkit.launch({ headless: true }); ctx = await wk.newContext(); ws = null; },
    async open(url) { const pg = await ctx.newPage(); await pg.goto(url, { waitUntil: "load" }); return pg; },
    async evaluate(pg, expression) { return pg.evaluate(expression); },
    async close() { await wk.close(); },
  };
} else if (!FIREFOX) {
  const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((b) => existsSync(b));
  proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "about:blank"], { stdio: "ignore" });
  const call = (method, params = {}, sessionId = undefined) => send({ method, params, sessionId });
  browser = {
    name: "Edge",
    async start() { await waitFor(`http://127.0.0.1:${port}/json/version`); await connect((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl); },
    async open(url) {
      const t = await call("Target.createTarget", { url: "about:blank" });
      const sid = (await call("Target.attachToTarget", { targetId: t.result.targetId, flatten: true })).result.sessionId;
      await call("Page.enable", {}, sid); await call("Runtime.enable", {}, sid);
      await call("Page.navigate", { url }, sid);
      await sleep(1500);
      return sid;
    },
    async evaluate(sid, expression) {
      const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true }, sid);
      if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
      return r.result?.result?.value;
    },
    async close() { ws.send(JSON.stringify({ id: ++id, method: "Browser.close" })); },
  };
} else {
  writeFileSync(join(profile, "user.js"), [["browser.shell.checkDefaultBrowser", false], ["browser.aboutwelcome.enabled", false],
    ["datareporting.policy.dataSubmissionEnabled", false], ["app.update.disabledForTesting", true], ["browser.startup.homepage_override.mstone", "ignore"]]
    .map(([k, v]) => `user_pref(${JSON.stringify(k)}, ${JSON.stringify(v)});`).join("\n"));
  proc = spawn("C:/Program Files/Mozilla Firefox/firefox.exe", ["-headless", "-no-remote", "-profile", profile, `--remote-debugging-port=${port}`, "about:blank"], { stdio: "ignore" });
  const call = async (method, params = {}) => {
    const r = await send({ method, params });
    if (r.type === "error" || r.error) throw new Error(`${method}: ${r.error} ${r.message || ""}`);
    return r.result;
  };
  browser = {
    name: "Firefox",
    async start() { await waitFor(`http://127.0.0.1:${port}/`); await connect(`ws://127.0.0.1:${port}/session`); await call("session.new", { capabilities: { alwaysMatch: {} } }); },
    async open(url) {
      const { context } = await call("browsingContext.create", { type: "tab" });
      await call("browsingContext.navigate", { context, url, wait: "complete" });
      return context;
    },
    async evaluate(context, expression) {
      const r = await call("script.evaluate", { expression: `(async () => JSON.stringify(await (${expression})))()`, target: { context }, awaitPromise: true });
      if (r.type === "exception") throw new Error(r.exceptionDetails?.text || "page error");
      return r.result?.value === undefined ? undefined : JSON.parse(r.result.value);
    },
    async close() { await call("browser.close").catch(() => {}); },
  };
}

// ---- in the page: load song prep as the Music library does, then prepare one sample ------------------
const LOAD = `(async () => {
  const add = (src, data = {}) => new Promise((ok, bad) => { const s = document.createElement("script"); s.src = src; Object.assign(s.dataset, data); s.onload = ok; s.onerror = bad; document.head.appendChild(s); });
  const v = "?v=" + Date.now();
  await add(${JSON.stringify(base)} + "/assets/prep-core.js" + v);
  await add(${JSON.stringify(base)} + "/assets/master-core.js" + v);
  await add(${JSON.stringify(base)} + "/assets/prep.js" + v, { kbps: "192", rate: "44100", precision: "32", dither: "1", quality: "0", worker: ${JSON.stringify(base)} + "/assets/prep-worker.js" + v });
  return typeof SwPrep === "object";
})()`;
const PREPARE = (url, name) => `(async () => {
  const t0 = performance.now();
  const blob = await (await fetch(${JSON.stringify(url)})).blob();
  const notices = [];
  const p = await SwPrep.prepare(new File([blob], ${JSON.stringify(name)}), () => {}, { master: {}, level: true, target: -16, onNotice: (t) => notices.push(t) });
  const secs = (performance.now() - t0) / 1000;
  if (!p.prepared) return { prepared: false, note: p.note, secs };
  const song = new Uint8Array(await p.file.arrayBuffer()), orig = new Uint8Array(await p.original.arrayBuffer());
  const hex = (b) => [...new Uint8Array(b)].map((x) => x.toString(16).padStart(2, "0")).join("");
  const frame = (bytes) => {
    let i = 10 + (((bytes[6] & 0x7f) << 21) | ((bytes[7] & 0x7f) << 14) | ((bytes[8] & 0x7f) << 7) | (bytes[9] & 0x7f));
    for (; i < bytes.length - 4; i++) if (bytes[i] === 0xff && (bytes[i + 1] & 0xfe) === 0xfa)
      return { kbps: [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320][bytes[i + 2] >> 4], rate: [44100, 48000, 32000][(bytes[i + 2] >> 2) & 3] };
    return null;
  };
  const text = (bytes) => new TextDecoder().decode(bytes.subarray(0, Math.min(bytes.length, 1200000)));
  // decoded back by the browser itself; a browser without Web Audio (Playwright's WebKit on Windows) hands
  // the song to the test, which decodes it with the test ffmpeg
  const Off = window.OfflineAudioContext || window.webkitOfflineAudioContext;
  const a = Off ? await new Off(2, 1, 44100).decodeAudioData(song.slice().buffer) : null;
  let b64 = null;
  if (!a) { let s = ""; for (let i = 0; i < song.length; i += 32768) s += String.fromCharCode.apply(null, song.subarray(i, i + 32768)); b64 = btoa(s); }
  return { prepared: true, secs, notices, b64, decodedBy: a ? "this browser" : "the test ffmpeg",
    seconds: a ? a.duration : 0, lufs: a ? SwPrepCore.loudness([a.getChannelData(0), a.getChannelData(1)], 44100) : 0, name: p.name, kind: p.info.converted && p.info.converted.kind, codec: p.info.converted && p.info.converted.from.codec,
    song: frame(song), orig: frame(orig), shaOk: hex(await crypto.subtle.digest("SHA-1", orig)) === p.sourceSha1,
    songTags: ["TIT2", "TPE1", "TALB", "TCOM", "APIC", "STATIONWAGON_PREP"].filter((k) => text(song).includes(k)),
    origTags: ["TIT2", "TPE1", "APIC", "STATIONWAGON_"].filter((k) => text(orig).includes(k)),
    title: text(song).includes(${JSON.stringify(TAGS.title)}) };
})()`;

// A song the browser can't decode back itself (no Web Audio): the test ffmpeg makes it a float WAV, measured
// with song prep's own loudness meter
const core = createRequire(import.meta.url)("../assets/prep-core.js");
function measure(mp3) {
  const dir = mkdtempSync(join(tmpdir(), "sw-formats-measure-"));
  try {
    writeFileSync(join(dir, "song.mp3"), mp3);
    const cli = process.env.SW_FFMPEG_CLI || "D:/Dev/toolshed/ffmpeg-cli/ffmpeg.js";
    execFileSync(process.execPath, [cli, "-hide_banner", "-loglevel", "error", "-y", "-i", "song.mp3", "-ac", "2", "-ar", "44100", "-c:a", "pcm_f32le", "song.wav"], { cwd: dir, stdio: ["ignore", "ignore", "pipe"] }); // its stderr only on a failure (it warns about getrusage)
    const w = readFileSync(join(dir, "song.wav"));
    let at = 12;
    while (at < w.length - 8 && w.toString("ascii", at, at + 4) !== "data") at += 8 + w.readUInt32LE(at + 4);
    const f = new Float32Array(w.buffer.slice(w.byteOffset + at + 8, w.byteOffset + w.length - ((w.length - at - 8) % 8)));
    const L = new Float32Array(f.length / 2), R = new Float32Array(f.length / 2);
    for (let i = 0; i < L.length; i++) { L[i] = f[2 * i]; R[i] = f[2 * i + 1]; }
    return { seconds: L.length / 44100, lufs: core.loudness([L, R], 44100) };
  } finally { rmSync(dir, { recursive: true, force: true }); }
}

try {
  await browser.start();
  console.log(`Converting every format in ${browser.name}`);
  const page = await browser.open(`${base}/privacy`);
  check("song prep loads on the station's page", await browser.evaluate(page, LOAD) === true);
  const host = PHONE ? "127.0.0.1" : "127.0.0.1";
  for (const s of SAMPLES) {
    const r = await browser.evaluate(page, PREPARE(`http://${host}:${samplePort}/${s.file}`, s.file)).catch((ex) => ({ prepared: false, note: ex.message }));
    const label = `${s.file} (${s.kind}${s.rate ? ", " + s.rate / 1000 + " kHz" : ""})`;
    check(`${label} is converted with the station's decoder`, r.prepared && r.kind === s.kind && r.name === s.file.replace(/\.[^.]+$/, ".mp3"), r.prepared ? `${r.kind}, ${r.codec}, ${r.secs.toFixed(1)} s` : r.note);
    if (!r.prepared) continue;
    if (r.b64) Object.assign(r, measure(Buffer.from(r.b64, "base64")));
    check(`  the song: an MP3 at 44.1 kHz and 192 kbps, about 30 s, at −16 LUFS (decoded by ${r.decodedBy})`,
      r.song && r.song.rate === 44100 && r.song.kbps === 192 && Math.abs(r.seconds - 30) < 0.8 && Math.abs(r.lufs + 16) <= 0.15, `${r.song && r.song.kbps} kbps, ${r.song && r.song.rate} Hz, ${r.seconds.toFixed(2)} s, ${r.lufs.toFixed(2)} LUFS`);
    check("  with the title, artist, album" + (s.writers === false ? "" : ", songwriter") + (s.cover ? ", cover" : "") + " and how it was made in its tag",
      r.title && ["TIT2", "TPE1", "TALB", "STATIONWAGON_PREP"].every((k) => r.songTags.includes(k)) && (s.writers === false || r.songTags.includes("TCOM")) && (!s.cover || r.songTags.includes("APIC")), r.songTags.join(" "));
    const oRate = s.rate ? (s.rate % 11025 === 0 ? 44100 : 48000) : 44100;
    check(`  the original: a 320 kbps MP3 at ${oRate / 1000} kHz with the same tags, known by its SHA-1`,
      r.orig && r.orig.kbps === 320 && r.orig.rate === oRate && r.shaOk && r.origTags.includes("TIT2") && !r.origTags.includes("STATIONWAGON_") && (!s.cover || r.origTags.includes("APIC")),
      `${r.orig && r.orig.kbps} kbps, ${r.orig && r.orig.rate} Hz, ${r.origTags.join(" ")}`);
    if (s.rate) check("  the rate change is said before the work", r.notices.some((n) => n.includes(`${s.rate / 1000} kHz to 44.1 kHz`)), r.notices.join(" | "));
  }
} catch (ex) {
  check("the test ran to the end", false, ex.message);
} finally {
  try { if (WEBKIT) await Promise.race([browser.close(), sleep(5000)]); else if (ws) { await Promise.race([browser.close(), sleep(3000)]); await sleep(500); ws.close(); } } catch { /* closed */ }
  if (proc) proc.kill();
  if (FIREFOX && proc) try { execFileSync("taskkill", ["/F", "/T", "/PID", String(proc.pid)], { stdio: "ignore" }); } catch { /* gone */ }
  if (PHONE) for (const a of [["forward", "--remove", "tcp:9222"], ["reverse", "--remove", `tcp:${samplePort}`]]) try { execFileSync(adbPath, a, { stdio: "ignore" }); } catch { /* none */ }
  server.close();
  await sleep(500);
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still held */ }
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
