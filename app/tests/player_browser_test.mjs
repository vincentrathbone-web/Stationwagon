// End-to-end test of the listener player in a real browser (headless Edge or Chrome) on Laragon:
// it joins mid-song at the listener delay, crossfades at a song boundary, hands over to a live
// show (made here, with generated 6 s pieces) and back to the AutoDJ, with a presenter note that
// appears when listeners reach it, and the stream-only mode
// holds the same delay; the embed (framed by another page) shows what's on and plays at that
// delay too, on the same two decks with the same crossfades (round 60). Takes about five minutes.
//   node app/tests/player_browser_test.mjs http://localhost:8080/stationwagon-app
//   node app/tests/player_browser_test.mjs https://wordwave.controll.co.za/stationwagon-app --listen-only
// Needs SW_PHP (php.exe) unless php is on PATH. The live part goes live on the station (through the
// local database), so a public server gets --listen-only: joining, crossfade and stream only.
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync, unlinkSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import { createHash } from "node:crypto";

const base = (process.argv[2] || "").replace(/\/$/, "");
const listenOnly = process.argv.includes("--listen-only");
if (!base) { console.error("Usage: node app/tests/player_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- Live pieces: 6 s of tone each, as the studio would send them ---------------------------
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const dir = mkdtempSync(join(tmpdir(), "sw-player-test-"));
function piece(seq) {
  const RATE = 44100, n = 230 * 1152, l = new Int16Array(n);
  for (let i = 0; i < n; i++) l[i] = Math.round(0.3 * 32767 * Math.sin(2 * Math.PI * 330 * (seq * n + i) / RATE));
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, 192), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), l.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const file = join(dir, `piece-${seq}.mp3`);
  writeFileSync(file, Buffer.concat(out));
  return file;
}

// ---- Browser ----------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
const profile = mkdtempSync(join(tmpdir(), "sw-player-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
  "--autoplay-policy=no-user-gesture-required",
  // keep the page counted as in front: a page the browser thinks is hidden draws nothing (the spectrum), and
  // Windows marks even a headless window hidden once the PC is idle (CalculateNativeWinOcclusion, below)
  "--disable-backgrounding-occluded-windows", "--disable-renderer-backgrounding", "--disable-background-timer-throttling",
  // the test mark keeps this run's listening out of the station's stats (src/Agent.php)
  "--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0 StationwagonTest",
  // the embed part frames this server from a made-up church website, which Edge would otherwise
  // block from reaching a localhost address (a real website framing a public server is fine)
  "--disable-features=LocalNetworkAccessChecks,CalculateNativeWinOcclusion", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}, sessionId = undefined) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params, sessionId })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
// each page load brings the page to the front: headless Edge sometimes reports it hidden, and a hidden page draws nothing
const go = async (url) => { await call("Page.navigate", { url }); await call("Page.bringToFront"); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const state = () => evaluate("window.SwPlayer && SwPlayer.state()");
/** Headless Edge sometimes marks the page hidden (once the PC is idle), and a hidden page draws nothing: bring it back. */
const front = async () => { if (await evaluate("document.hidden").catch(() => false)) await call("Page.bringToFront"); };
/** Poll state() every 100 ms until fn(state) is true (returns it) or the time runs out (null). */
async function until(fn, seconds, log = null) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { await front(); const s = await state(); if (log) log.push(s); if (s && fn(s)) return s; await sleep(100); }
  return null;
}
const audible = (s) => s.decks.filter((d) => d.playing && d.gain > 0.5);
const drift = (s, d) => d.pos - (s.heard - s.current.start);

// Song file traffic, from the browser's own network log: bytes that came over the network per file
// (the decks and the strip's look-ahead together), and each file's size.
const files = new Map(), reqs = new Map();
function media({ method, params: p }) {
  if (method === "Network.responseReceived" && p.response.url.includes("storage/media")) {
    const url = p.response.url.split("?")[0], h = Object.fromEntries(Object.entries(p.response.headers).map(([k, v]) => [k.toLowerCase(), v]));
    const size = +((h["content-range"] || "").split("/")[1] || (p.response.status === 200 ? h["content-length"] : 0) || 0);
    const f = files.get(url) || { size: 0, sent: 0 };
    if (size) f.size = size;
    files.set(url, f); reqs.set(p.requestId, url);
  } else if (method === "Network.loadingFinished" && reqs.has(p.requestId)) {
    files.get(reqs.get(p.requestId)).sent += p.encodedDataLength;
  } else if (method === "Network.requestServedFromCache") {
    reqs.delete(p.requestId);
  }
}

let show = null, feeder = null, notesShow = null, onPaused = () => {};
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    else if (d.method === "Fetch.requestPaused") onPaused(d.params);
    else if (d.method) media(d);
  };
  await call("Page.enable");
  await call("Page.bringToFront"); // a page in the background draws nothing (the spectrum)
  await call("Network.enable");

  console.log("Joining");
  await go(`${base}/`);
  check("the station page has the player", await evaluate("!!document.getElementById('player') && typeof SwPlayer === 'object'"));
  const before = await evaluate("document.getElementById('lpTitle').textContent");
  check("it shows what's on before Listen is pressed", before !== "" && before !== "Loading…", before);
  await evaluate("SwPlayer.start()");
  let s = await until((x) => x.current && x.current.type === "song" && audible(x).some((d) => d.key === x.current.key), 10);
  check("Listen joins the song the delayed timeline is on", !!s, s && s.current.title);
  check("  at the right moment (within 0.3 s)", s && Math.abs(drift(s, audible(s)[0])) < 0.3, s && drift(s, audible(s)[0]).toFixed(3) + " s");
  check("  and says it's playing", s && s.status === "Playing" && s.delay === 20, s && s.status);
  // counted as listening: a ping with a random id, and the health line shows the count
  s = await until((x) => x.ping && x.listeners >= 1, 5);
  const shownCount = await evaluate("document.getElementById('lpListeners').textContent");
  check("  it counts itself as listening (a ping with a random id)", !!s && /^[0-9a-f]{16}$/.test(s.ping) && shownCount === String(s.listeners), s ? `${s.ping}, ${shownCount} listening` : "no ping");
  const listeningWhilePlaying = s ? s.listeners : 0;

  console.log("Spectrum");
  await call("Page.bringToFront"); // drawing only runs on a page in front
  // the test songs are pure tones: the tallest bar must be the band that holds the tone
  const BAND = (f) => Math.max(0, Math.min(27, Math.floor(Math.log(f / 40) / Math.log(16000 / 40) * 28)));
  const specPx = () => evaluate(`(() => { const c = document.getElementById('lpSpec'), d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0;
    for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 0) n++; return n; })()`);
  const toneOf = (x) => { const m = x && x.current && /(\d{3,4})\s*Hz/.exec(x.current.title || ""); return m ? Number(m[1]) : null; };
  // the test tones tick (1800 Hz) at every second and beep (1200 Hz) every 10 s, and a bar takes ~0.6 s to
  // fall back: look between the ticks, with one deck playing (a song fading out on the other lights its bands)
  const between = (d) => d.pos > 1.5 && d.pos % 1 > 0.7 && d.pos % 1 < 0.97 && d.pos % 10 > 1;
  // a station with real music (no "… Hz" titles) gets the music checks instead of the tone checks
  const tones = !!toneOf(await state());
  s = await until((x) => x.spectrum && x.spectrum.running && x.spectrum.frames > 90 && x.spectrum.level > 0.3 && (!tones || toneOf(x)) && x.decks.filter((d) => d.playing).length === 1
    && (!tones || between(x.decks.find((d) => d.playing))), 25);
  const tone = toneOf(s);
  check("the display's spectrum moves with the music", !!s && s.spectrum.frames > 10 && (await specPx()) > 200, s && `${s.spectrum.frames} frames, level ${s.spectrum.level}`);
  if (!tones) {
    check("  real music lights many of the 28 bars", !!s && s.spectrum.lit >= 10, s && `${s.spectrum.lit} lit`);
  } else {
  check("  its tallest bar is the band that holds the tone playing", !!s && Math.abs(s.spectrum.top - BAND(tone)) <= 1,
    s && `${tone} Hz → band ${BAND(tone)}; tallest ${s.spectrum.top} (${s.spectrum.topHz.join("–")} Hz)`);
  check("  a pure tone lights only a few of the 28 bars", !!s && s.spectrum.lit <= 8, s && `${s.spectrum.lit} lit` + (s.spectrum.lit > 8 ? " " + JSON.stringify(await evaluate(`({ hidden: document.hidden, bars: SwPlayer.state().spectrum.bars, decks: SwPlayer.state().decks, cur: SwPlayer.state().current && SwPlayer.state().current.title, ev: SwPlayer.state().events.slice(-5) })`)) : ""));
  }
  const loud = s ? s.spectrum.level : 0;
  await evaluate("(() => { const v = document.getElementById('lpVol'); v.value = 0; v.dispatchEvent(new Event('input')); })()");
  await sleep(600);
  s = await state();
  check("  turning the volume down doesn't shrink it (it reads the station's output)", s.spectrum.level > loud - 0.15, `${loud} → ${s.spectrum.level}`);
  await evaluate("(() => { const v = document.getElementById('lpVol'); v.value = 80; v.dispatchEvent(new Event('input')); })()");

  console.log("Crossfade");
  const log = [];
  const first = s && s.current.key;
  // test tones are under a minute; a real song may have minutes left: wait for its end on the timeline
  let wait = 75;
  if (!tones && s && s.current) {
    const tl = await (await fetch(`${base}/api/timeline.php`)).json();
    const e = tl.entries.find((x) => Math.abs(x.start - s.current.start) < 0.01);
    if (e) wait = Math.max(15, e.end - (tl.server_now - tl.settings.listener_delay_seconds) + 15);
    console.log(`  (waiting ${Math.round(wait)} s for this song's end)`);
  }
  s = await until((x) => x.current && x.current.key !== first, wait, log);
  check("the next song comes at its boundary", !!s, s && s.current.title);
  const both = (await until((x) => x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2, 3, log)) || log.find((x) => x && x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2);
  check("  with both decks blending", !!both, both && both.decks.map((d) => d.gain).join(" / "));
  s = await until((x) => audible(x).length === 1 && audible(x)[0].key === x.current.key && x.decks.filter((d) => d.playing).length === 1, 10);
  check("  then only the new song plays", !!s, s && s.current.title);
  check("  on time", s && Math.abs(drift(s, audible(s)[0])) < 0.3, s && drift(s, audible(s)[0]).toFixed(3) + " s");

  console.log("Output strip");
  const dB = (v) => (v > 1e-5 ? 20 * Math.log10(v) : -99), dBs = (a) => (a ? a.map((v) => dB(v).toFixed(1)).join(" / ") : "none");
  s = await until((x) => x.wave && x.wave.meter && x.wave.ahead !== null, 5);
  check("the output strip measures what goes out, in the sound engine", s && s.wave.meter === "sample-exact", s && s.wave.meter);
  check("  and reads the coming 20 s itself (song pieces)", s && s.wave.ahead > 15 && s.wave.pieces > 0, s && `${s.wave.ahead} s ahead, ${s.wave.pieces} pieces`);
  // what the strip mixed ahead must be what goes out when that moment comes (A / B / live / output)
  const aheadCheck = async (from) => {
    const p = await evaluate(`(() => { const n = SwPlayer.wave.now(); return { t0: n + ${from}, t1: n + ${from + 1}, p: SwPlayer.wave.planned(n + ${from}, n + ${from + 1}) }; })()`);
    await sleep((from + 1.6) * 1000);
    return { plan: p.p, got: await evaluate(`SwPlayer.wave.measured(${p.t0}, ${p.t1})`) };
  };
  const same = (c, series) => c.plan && c.got && series.every((j) => Math.abs(dB(c.plan[j]) - dB(c.got[j])) < 1);
  let cmp = await aheadCheck(4);
  check("  what it mixed ahead is what went out (within 1 dB)", same(cmp, [0, 1, 3]), `planned ${dBs(cmp.plan)}, measured ${dBs(cmp.got)} (A / B / live / out, dB)`);
  const reads = await evaluate("(() => { const r = performance.getEntriesByType('resource').filter((e) => e.initiatorType === 'fetch' && e.name.includes('storage/media')); return { n: r.length, sent: r.reduce((a, e) => a + e.transferSize, 0), body: r.reduce((a, e) => a + e.decodedBodySize, 0) }; })()");
  // The strip reads ranges of the same files the decks play, so between them each file should come
  // over the network once at most (the browser's cache serves the rest), never twice.
  const fl = [...files.values()].filter((f) => f.size), sent = fl.reduce((a, f) => a + f.sent, 0), size = fl.reduce((a, f) => a + f.size, 0);
  check("  those reads don't download the songs a second time", reads.n > 0 && fl.length > 0 && sent <= size * 1.05 + 4096 * fl.length,
    `${reads.n} reads of ${reads.body} bytes; ${fl.length} song files of ${size} bytes, ${sent} over the network: ` +
    fl.map((f) => `${f.sent}/${f.size}`).join(", "));
  // looked at a few times over 2 s: a look just as a song ends finds the strip nearly empty (quiet)
  let px = null;
  for (let i = 0; i < 6 && !(px && px.a + px.b > 100); i++) {
    if (i) await sleep(400);
    px = await evaluate(`(() => { const c = document.getElementById('lpWave'), g = c.getContext('2d'), d = g.getImageData(0, 0, c.width, c.height).data; let a = 0, b = 0;
      for (let i = 0; i < d.length; i += 4) { if (d[i + 2] > 150 && d[i] < 140 && d[i + 1] > 100) a++; if (d[i] > 150 && d[i + 2] > 100 && d[i + 1] < 140) b++; } return { a, b, w: c.width, h: c.height }; })()`);
  }
  check("  the strip draws the songs in their colours", px.w > 0 && px.a + px.b > 100, `${px.w}×${px.h}, ${px.a} blue and ${px.b} pink pixels`);

  // The white line (round 67): where the sound is heard, in the middle of every strip
  console.log("White line");
  const wt = await evaluate(`(() => {
    const c = SwPlayer.ctx ? SwPlayer.ctx() : null; if (!c) return null;
    const a = [], b = [];
    for (let i = 0; i < 40; i++) { a.push(SwOutWave.now(c)); }
    const out = SwOutWave.outputTime(c), cur = c.currentTime;
    return { mono: a.every((v, i) => i === 0 || v >= a[i - 1]), behind: cur - out, line: SwOutWave.now(c) - out };
  })()`);
  check("the white line follows the sound being output, never backwards", !!wt && wt.mono, wt && JSON.stringify(wt));
  check("  which is the output delay behind the engine's clock (0 to 0.5 s), and the line is a frame or two ahead of that", !!wt && wt.behind >= 0 && wt.behind < 0.5 && wt.line > 0.01 && wt.line < 0.06, wt && `${(wt.behind * 1000).toFixed(0)} ms behind, line +${(wt.line * 1000).toFixed(0)} ms`);
  // a tick scheduled for a known moment shows on the measured output at that moment (sample-exact meter)
  const tick = await evaluate(`(async () => {
    const c = SwPlayer.ctx(), W = SwPlayer.wave, rate = c.sampleRate, n = Math.round(rate * 0.004), buf = c.createBuffer(1, n, rate), d = buf.getChannelData(0);
    for (let i = 0; i < n; i++) d[i] = 0.9;
    const s = c.createBufferSource(); s.buffer = buf; s.connect(SwPlayer.mixNode()); const T = Math.ceil((c.currentTime + 0.6) * 100) / 100 + 0.005; s.start(T);
    await new Promise((r) => setTimeout(r, 1500));
    const col = (i) => { const r = W.measured(i * 0.01 + 0.001, i * 0.01 + 0.009); return r ? r[3] : null; }, i0 = Math.floor(T / 0.01);
    return { at: col(i0), before: col(i0 - 3), after: col(i0 + 3) };
  })()`);
  check("  a click scheduled for a known moment lands in that 10 ms column of the strip's data", tick.at > tick.before + 0.1 && tick.at > tick.after + 0.1, JSON.stringify(tick));
  console.log("Frozen sound engine (as a Xiaomi phone does some seconds after locking)");
  await evaluate(`(() => {
    const real = Object.getOwnPropertyDescriptor(BaseAudioContext.prototype, "currentTime"), held = new WeakMap();
    window.__unfreeze = () => Object.defineProperty(BaseAudioContext.prototype, "currentTime", real);
    Object.defineProperty(BaseAudioContext.prototype, "currentTime", { configurable: true, get() {
      if (!held.has(this)) held.set(this, real.get.call(this));
      return held.get(this);
    } });
  })()`);
  s = await until((x) => x.events.some((e) => e.endsWith("nudge 1")), 4);
  check("a stuck engine clock is noticed and nudged", !!s);
  s = await until((x) => x.events.some((e) => e.includes("starting again")), 6);
  check("  and when nudging doesn't help, the player starts again", !!s, s ? "" : (await state()).events.slice(-3).join(" | "));
  await evaluate("window.__unfreeze()");
  s = await until((x) => x.current && x.current.type === "song" && x.status === "Playing" && audible(x).some((d) => d.key === x.current.key), 10);
  check("  and plays on time again", s && Math.abs(drift(s, audible(s).find((d) => d.key === s.current.key))) < 0.3, s && drift(s, audible(s).find((d) => d.key === s.current.key)).toFixed(3) + " s");

  console.log("Diagnostics panel");
  check("it starts closed", (await evaluate("document.getElementById('lpDiag').open")) === false);
  await front(); // frames are only counted on a page in front
  await evaluate("SwDiag.open()");
  await sleep(2500);
  const panel = await evaluate(`(() => ({ rows: [...document.querySelectorAll('#lpDiagRows dt')].map((x) => x.textContent), verdict: document.getElementById('lpDiagVerdict').textContent, m: SwDiag.measure() }))()`);
  check("  open, it shows its five rows and a verdict", JSON.stringify(panel.rows) === '["Screen","Player","Sound","Song","Network"]' && panel.verdict.length > 10, JSON.stringify(panel.rows) + " " + panel.verdict);
  check("  and counts the screen's frames", panel.m && panel.m.fps > 5, panel.m && String(panel.m.fps));
  s = await state();
  check("  the frozen engine above is in its count and dropout list", s.diag.stalls >= 1 && s.diag.dropLog.length > 0, `${s.diag.stalls} stalls, ${s.diag.dropLog.map((x) => x.cause).join(", ")}`);
  check("  the song deck plays in step (drift under 0.3 s)", s.diag.drift !== null && Math.abs(s.diag.drift) < 0.3, String(s.diag.drift));
  const jumpsBefore = s.diag.corrections;
  check("  (a song made to slip 2 s)", await evaluate("SwPlayer.slip(2)"));
  s = await until((x) => x.diag.corrections > jumpsBefore, 6);
  check("  is put back in step and listed as a timing correction", !!s && s.diag.dropLog.some((x) => x.cause === "timing correction"), s ? "" : String((await state()).diag.drift));
  await sleep(3000); // the correction's own settling time
  await front(); // the check runs with the strip's drawing, which a hidden page pauses
  const gapsBefore = (await state()).diag.gaps;
  check("  (the output silenced for 0.6 s)", await evaluate("SwPlayer.hush(600)"));
  s = await until((x) => x.diag.gaps > gapsBefore, 8);
  check("  is caught as a sound gap", !!s && s.diag.dropLog.some((x) => x.cause === "sound gap"), String((await state()).diag.gaps));
  await sleep(1200);
  const report = await evaluate("SwDiag.report()");
  check("  the report has the rows and the dropouts", /\[(good|warn|bad)\] Sound:/.test(report) && /timing correction \(drift/.test(report) && /sound gap \(the output/.test(report), report.split("\n").filter((l) => /^  20/.test(l)).join(" | "));
  if (!listenOnly) for (const a of ["127.0.0.1", "::1"]) { // this copy: clear this computer's hourly count of reports
    const f = join(here, "..", "storage", "diag", `.limit-${createHash("sha256").update(`sw-diag|${a}`).digest("hex").slice(0, 16)}.json`);
    if (existsSync(f)) unlinkSync(f);
  }
  await evaluate("document.getElementById('lpDiagSend').click()");
  let sentMsg = "";
  for (let i = 0; i < 50 && !/^Sent|Couldn|plenty/.test(sentMsg); i++) { await sleep(100); sentMsg = await evaluate("document.getElementById('lpDiagMsg').textContent"); }
  const sentId = (sentMsg.match(/Report (\d{8}-\d{6}-[0-9a-f]{6})/) || [])[1];
  check("  Send report stores it and gives its number", !!sentId, sentMsg);
  if (sentId && !listenOnly) {
    const f = join(here, "..", "storage", "diag", `${sentId}.txt`);
    check("  (the file is on the station)", existsSync(f) && readFileSync(f, "utf8").includes("sound gap"));
    if (existsSync(f)) unlinkSync(f);
  }
  // left open, it opens and measures straight away on the next visit
  await evaluate("SwPlayer.stop()"); await sleep(1500); // off the listener count first (its last ping; the count is checked later)
  await go(`${base}/`);
  await front();
  for (let i = 0; i < 50 && !(await evaluate("typeof SwPlayer === 'object' && document.querySelectorAll('#lpDiagRows dt').length === 5")); i++) await sleep(100);
  const reopened = await evaluate(`({ open: document.getElementById('lpDiag').open, rows: document.querySelectorAll('#lpDiagRows dt').length })`);
  check("  left open, it is open and measuring on the next visit", reopened.open === true && reopened.rows === 5, JSON.stringify(reopened));
  await evaluate("(() => { const b = document.getElementById('lpDiag'); b.open = false; })()");
  await sleep(300);
  await evaluate("SwPlayer.start()");
  s = await until((x) => x.status === "Playing" && x.current && audible(x).length > 0, 15);
  check("  (playing again for the rest)", !!s);

  console.log(listenOnly ? "Live show: skipped (--listen-only)" : "Live show");
  // made beforehand and sent on a fixed beat, like the studio: piece n arrives 6 s × (n + 1) after
  // the start (the server ends a show after 8 s without a piece)
  const pieces = listenOnly ? [] : [0, 1, 2, 3, 4].map(piece);
  if (!listenOnly) show = helper("live-start", "Browser test show");
  if (!listenOnly) check("a test show goes live", !!show.public_id, JSON.stringify(show));
  if (show && show.public_id) {
    let seq = 0;
    const send = () => { if (seq < 5) { helper("live-piece", show.public_id, String(seq), pieces[seq], "auto"); seq++; } };
    feeder = setTimeout(function next() {
      send();
      if (seq < 5) feeder = setTimeout(next, Math.max(0, (show.start + 6.1 + 6 * seq) * 1000 - Date.now()));
    }, Math.max(0, (show.start + 6.1) * 1000 - Date.now()));
    // a presenter note, posted now: listeners 20 s behind must not see it before they hear this moment
    notesShow = show.public_id;
    // slides first (a second before the verse, so each reaches the listeners in its own tick)
    const pics = [helper("slide", "40"), helper("slide", "170", "320", "240")];
    const slidesPosted = helper("note", show.public_id, "slides", JSON.stringify({ text: "Today’s points", images: pics.map((x) => x.file) }));
    check("a slides note is posted with two generated pictures", !!(slidesPosted.note && slidesPosted.note.id) && pics.every((x) => !!x.file), JSON.stringify(slidesPosted));
    await sleep(1200);
    const postedAt = Date.now();
    const posted = helper("note", show.public_id, "verse", JSON.stringify({ ref: "Psalm 23:1", text: "The LORD is my shepherd; <b>I shall not want.</b>", passage: "Psalm 23" }));
    const nid = posted.note && posted.note.id;
    check("a presenter note is posted", !!nid, JSON.stringify(posted));
    const notes = () => evaluate("window.SwNotes && SwNotes.state()");
    const untilNotes = async (fn, seconds) => { const end = Date.now() + seconds * 1000; while (Date.now() < end) { const n = await notes(); if (n && fn(n)) return n; await sleep(100); } return null; };
    let n = await untilNotes((x) => x.held.includes(nid), 6);
    check("  listeners don't see it before they hear that moment", !!n && !n.shown.includes(nid) && n.hidden, JSON.stringify(n));
    n = await untilNotes((x) => x.shown.includes(nid), 30);
    const after = (Date.now() - postedAt) / 1000;
    check("  it appears 20 s after it was posted", !!n && after > 18.5 && after < 23, after.toFixed(1) + " s");
    check("  tagged Live and New", !!n && n.show.label === "Live" && n.fresh.includes(nid), n && `${n.show.label} / fresh ${n.fresh}`);
    const dom = await evaluate(`(() => { const li = document.querySelector('#lpNotesList li'), q = li.querySelector('blockquote'), t = document.getElementById('lpToast');
      return { text: q.textContent, tags: q.children.length, read: li.querySelector('a').href, toast: t.hidden ? '' : t.textContent }; })()`);
    check("  shown as text, with its Bible Gateway link and a toast", dom.text.includes("<b>I shall") && dom.tags === 0 && dom.read.startsWith("https://www.biblegateway.com/passage/?search=Psalm%2023") && dom.toast.startsWith("New note"), JSON.stringify(dom));
    const slidesDom = await evaluate(`(async () => {
      const box = document.querySelector('#lpNotesList .lp-slides'), img = box && box.querySelector('img'), btn = (t) => [...box.querySelectorAll('button')].find((b) => b.textContent === t), count = () => box.querySelector('.bar span').textContent;
      await new Promise((ok) => (img.complete && img.naturalWidth ? ok() : img.addEventListener('load', ok)));
      const first = { src: img.src, w: img.naturalWidth, count: count(), back: btn('Back').disabled, next: btn('Next').disabled, alt: img.alt };
      btn('Next').click();
      await img.decode().catch(() => {}); // the second picture loaded (until then the browser still reports the first one's size)
      const second = { src: img.src, w: img.naturalWidth, count: count(), back: btn('Back').disabled, next: btn('Next').disabled };
      btn('Back').click();
      return { first, second, again: count(), title: box.parentElement.querySelector('.txt').textContent, kind: box.closest('li').querySelector('.kind').textContent };
    })()`);
    check("  the slides note shows its title and the first picture, loaded from the station", slidesDom.title === "Today’s points" && slidesDom.first.src === `${base}/${pics[0].url}` && slidesDom.first.w === 640 && slidesDom.first.count === "1 / 2"
      && slidesDom.first.back && !slidesDom.first.next && slidesDom.kind.startsWith("Slides"), JSON.stringify(slidesDom));
    check("  Next shows the second (Back is then open, Next is not), Back returns", slidesDom.second.src === `${base}/${pics[1].url}` && slidesDom.second.w === 320 && slidesDom.second.count === "2 / 2" && !slidesDom.second.back && slidesDom.second.next && slidesDom.again === "1 / 2", JSON.stringify(slidesDom));
    // the presenter fixes the verse: it comes again as new, and the old one stays until listeners reach the edit
    await sleep(2700); // the first toast has gone
    // every toast is recorded as it shows (it hides after 2.6 s, maybe before the test looks)
    await evaluate(`(() => { const t = document.getElementById('lpToast'); window.__toasts = [];
      new MutationObserver(() => { if (!t.hidden) window.__toasts.push({ at: Date.now(), text: t.textContent }); }).observe(t, { attributes: true, childList: true, characterData: true, subtree: true }); return true; })()`);
    const editedAt = Date.now();
    const changed = helper("note-edit", show.public_id, String(nid), JSON.stringify({ ref: "Psalm 23:1-2", text: "The LORD is my shepherd; I shall not want. He maketh me to lie down in green pastures.", passage: "Psalm 23" }));
    const nid2 = changed.note && changed.note.id;
    check("the presenter changes the note", !!nid2 && changed.replaces === nid, JSON.stringify(changed));
    n = await untilNotes((x) => x.held.includes(nid2), 6);
    check("  listeners keep the old version until they reach the change", !!n && n.shown.includes(nid) && !n.shown.includes(nid2), n && `shown ${n.shown}, held ${n.held}`);
    s = await until((x) => x.current && x.current.type === "live", 45);
    check("the player reaches the show 20 s later", !!s, s && `heard ${(s.heard - show.start).toFixed(1)} s after its start`);
    s = await until((x) => x.current && x.current.type === "live" && x.stream && x.stream.gain > 0.9, 6);
    check("  and the stream deck takes over", !!s, s && JSON.stringify(s.stream));
    check("  held 20 s behind live", s && s.stream.lag !== null && Math.abs(s.stream.lag - 20) < 0.6, s && String(s.stream.lag && s.stream.lag.toFixed(2)));
    s = await state();
    check("  the output strip reads the live pieces ahead too", s.wave && s.wave.live > 0, s.wave && `${s.wave.live} live pieces`);
    const lc = await aheadCheck(3);
    check("  and what it mixed ahead of the show is what went out (within 1 dB)", same(lc, [2, 3]) && lc.got[2] > 0.01, `planned ${dBs(lc.plan)}, measured ${dBs(lc.got)}`);
    s = await until((x) => x.decks.every((d) => !d.playing), 9); // after the 6 s live cut-in fade
    check("  with the song decks quiet once the fade is done", !!s, s ? "" : JSON.stringify((await state()).decks));
    n = await untilNotes((x) => x.shown.includes(nid2), 30);
    const after2 = (Date.now() - editedAt) / 1000;
    check("the changed note replaces the old one 20 s after the change", !!n && !n.shown.includes(nid) && after2 > 18.5 && after2 < 23, n ? `${after2.toFixed(1)} s, shown ${n.shown}` : JSON.stringify(await notes()));
    const dom2 = await evaluate(`(() => ({ items: document.querySelectorAll('#lpNotesList li').length, text: document.querySelector('#lpNotesList li blockquote').textContent,
      toast: (window.__toasts.find((x) => x.at > ${editedAt} + 18000) || {}).text || '', all: (window.__toasts || null) && window.__toasts.map((x) => ((x.at - ${editedAt}) / 1000).toFixed(1) + ' s ' + x.text) }))()`);
    check("  as new, with a toast", !!n && n.fresh.includes(nid2) && dom2.items === 2 && dom2.text.includes("green pastures") && dom2.toast.startsWith("New note"), JSON.stringify({ ...dom2, show: n && n.show, fresh: n && n.fresh }));
    while (seq < 5) await sleep(500);
    // the listener's tab is in the background when the show ends (another tab in front), as happened in the live test
    const other = (await call("Target.createTarget", { url: "about:blank", background: false })).result?.targetId;
    await sleep(500);
    const vis = await evaluate("document.visibilityState");
    helper("live-end", show.public_id); show = null;
    s = await until((x) => x.current && x.current.type === "song" && audible(x).length === 1, 60);
    check("after the show the AutoDJ comes back", !!s, s && s.current.title);
    const back = s && s.events.filter((e) => e.includes(s.current.title));
    check(`  with a fade from the stream, not a fresh join (tab ${vis})`, !!back && back.some((e) => e.includes("handover to " + s.current.title)) && !back.some((e) => e.includes("join " + s.current.title)), back && back.join(" | "));
    if (other) await call("Target.closeTarget", { targetId: other });
    await call("Page.bringToFront"); // drawing (the spectrum) only runs on a page in front
    s = await until((x) => x.stream && !x.stream.active, 5);
    check("  and the stream deck stops", !!s);
    const kept = await untilNotes((x) => x.show && !x.show.live, 10);
    check("  the show's notes stay, no longer Live", !!kept && kept.shown.includes(nid2) && !kept.shown.includes(nid) && kept.fresh.length === 0, kept ? `${kept.show.label}; shown ${kept.shown}, fresh ${kept.fresh}, want ${nid2} not ${nid}` : "");
  }
  if (failed) console.log("Player events:\n    " + ((await state()).events || []).join("\n    "));
  await evaluate("SwPlayer.stop()");
  // Stop takes it off the count at once (its last ping)
  let after = null;
  for (let i = 0; i < 30 && after !== listeningWhilePlaying - 1; i++) { await sleep(200); after = (await (await fetch(`${base}/api/timeline.php`)).json()).listeners; }
  const hiddenAt = ((await state()).events || []).filter((e) => e.includes("page hidden") || e.includes("page shown"));
  await call("Page.bringToFront"); // drawing only runs on a page in front (headless Edge sometimes marks it hidden)
  s = await until((x) => x.spectrum && !x.spectrum.running, 4);
  check("after Stop the spectrum falls to nothing and stops drawing", !!s && (await specPx()) === 0, (s ? "" : JSON.stringify((await state()).spectrum) + ", page " + (await evaluate("document.visibilityState"))) + (hiddenAt.length ? ` (it was hidden: ${hiddenAt.join(" | ")})` : ""));
  check("Stop takes it off \"Listening now\" at once", listeningWhilePlaying > 0 && after === listeningWhilePlaying - 1 && (await state()).ping === null, `${listeningWhilePlaying} → ${after}`);

  console.log("Stream only");
  // with "reduce motion" set on the device the spectrum redraws once a second, without peak marks
  await call("Emulation.setEmulatedMedia", { features: [{ name: "prefers-reduced-motion", value: "reduce" }] });
  await go(`${base}/`);
  await evaluate("SwPlayer.start()");
  s = await until((x) => x.spectrum && x.spectrum.running && x.spectrum.level > 0.3, 15);
  const f0 = s ? s.spectrum.frames : 0;
  for (let i = 0; i < 8; i++) { await front(); await sleep(500); }
  s = await state();
  check("with reduced motion the spectrum still shows, redrawn about once a second", !!s && s.spectrum.calm && s.spectrum.frames - f0 >= 2 && s.spectrum.frames - f0 <= 6, s && `${s.spectrum.frames - f0} frames in 4 s` + (s.spectrum.frames - f0 < 2 ? "; " + JSON.stringify({ status: s.status, spectrum: s.spectrum, decks: s.decks, events: (s.events || []).slice(-8) }) : ""));
  await evaluate("SwPlayer.stop()");
  await call("Emulation.setEmulatedMedia", { features: [] });
  await go(`${base}/?mode=stream`);
  await evaluate("SwPlayer.start()");
  s = await until((x) => x.stream && x.stream.active && x.stream.lag !== null && Math.abs(x.stream.lag - 20) < 0.6, 30);
  check("?mode=stream plays the stream at the same 20 s delay", !!s && s.mode === "stream", s && String(s.stream.lag.toFixed(2)));
  const streamRates = new Set();
  for (let i = 0; i < 40; i++) { const x = await state(); if (x && x.stream && x.stream.active) streamRates.add(x.stream.rate); await sleep(100); }
  check("  and never plays it faster or slower (the time-stretch is audible)", streamRates.size === 1 && streamRates.has(1), JSON.stringify([...streamRates]));
  check("  without Web Audio there is no spectrum (an empty display, nothing drawing)", !!s && !s.spectrum.running && s.spectrum.frames === 0 && (await specPx()) === 0, s && JSON.stringify(s.spectrum));
  await evaluate("SwPlayer.stop()");

  console.log("Embed");
  /** Poll a page expression every 200 ms until it is truthy (returns it) or the time runs out (null). */
  const waitFor = async (expr, seconds) => {
    for (const end = Date.now() + seconds * 1000; Date.now() < end; await sleep(200)) { const v = await evaluate(expr).catch(() => null); if (v) return v; }
    return null;
  };
  // framed by a church website (a made-up address; the browser is handed its page here)
  const host = "https://church.example/";
  onPaused = (p) => call("Fetch.fulfillRequest", { requestId: p.requestId, responseCode: 200, responseHeaders: [{ name: "Content-Type", value: "text/html" }],
    body: Buffer.from(`<!doctype html><title>Church</title><iframe src="${base}/embed" width="470" height="124" allow="autoplay"></iframe>`).toString("base64") });
  await call("Fetch.enable", { patterns: [{ urlPattern: host + "*" }] });
  await call("Page.navigate", { url: host });
  let framed = null, smallHides = null;
  for (let i = 0; i < 50 && !framed; i++) {
    await sleep(200);
    // a frame from another site runs as a target of its own
    const t = (await call("Target.getTargets")).result.targetInfos.find((x) => x.type === "iframe" && x.url.startsWith(base));
    if (!t) continue;
    const { sessionId } = (await call("Target.attachToTarget", { targetId: t.targetId, flatten: true })).result;
    for (let j = 0; j < 25 && !framed; j++) {
      framed = (await call("Runtime.evaluate", { expression: "!!document.getElementById('embed') && location.href", returnByValue: true }, sessionId)).result?.result?.value || null;
      // a 124 px frame keeps the small layout: no art, copyright line, spectrum or strip
      // (once the frame's stylesheet has loaded: over the internet the page can be there before it)
      for (let k = 0; framed && k < 25 && smallHides !== true; k++) {
        smallHides = (await call("Runtime.evaluate", { expression: "document.readyState === 'complete' && ['emArt', 'emRights', 'emSpec', 'emWave'].every((id) => getComputedStyle(document.getElementById(id)).display === 'none')", returnByValue: true }, sessionId)).result?.result?.value;
        if (smallHides !== true) await sleep(200);
      }
      if (!framed) await sleep(200);
    }
    if (!framed) framed = (await call("Runtime.evaluate", { expression: "'no player: ' + location.href", returnByValue: true }, sessionId)).result?.result?.value;
  }
  await call("Fetch.disable");
  check("a church website can frame the embed", typeof framed === "string" && !framed.startsWith("no player"), framed || "no frame");
  check("  its small (124 px) frame keeps the small layout: no art, copyright line, spectrum or strip", smallHides === true, String(smallHides));
  await go(`${base}/embed`);
  const np = await waitFor("window.swEmbed && swEmbed.np", 10);
  const shown = await evaluate("document.getElementById('emTitle').textContent");
  check("the embed shows what's on air, from the now-playing data", !!(np && np.on_air) && shown.startsWith(np.on_air.title), shown);
  await evaluate("document.getElementById('emPlay').click()");
  const lag = await waitFor("swEmbed.heard && Math.abs(swEmbed.lag - 20) < 0.6 && swEmbed.lag", 30);
  check("Listen plays the songs at the same 20 s delay (silent until then), on the station page's player", !!lag && (await evaluate("SwPlayer.state().decks.length")) === 2, lag ? lag.toFixed(2) + " s" : String(await evaluate("swEmbed.lag + ' s, page ' + document.visibilityState")));
  check("  and it counts itself as listening too", /^[0-9a-f]{16}$/.test((await evaluate("swEmbed.ping")) || ""), String(await evaluate("swEmbed.ping")));
  const meta = await waitFor("/^\\d+:\\d\\d \\/ \\d+:\\d\\d$|^Live for /.test(document.getElementById('emState').textContent) && document.getElementById('emState').textContent", 3);
  check("  with the time in the song", !!meta, meta || await evaluate("document.getElementById('emState').textContent"));
  // never faster or slower: the browser's time-stretch was heard as a squeezed, "64 kbps" sound on wordwave.co.za
  await evaluate("window.__rates = new Set(); window.__rateTimer = setInterval(() => swEmbed.media && __rates.add(swEmbed.media.playbackRate), 100); 1");
  await evaluate("swEmbed.slip(-0.6)");
  await sleep(4000);
  check("  a small slip (0.6 s) is left alone: no jump", !(await evaluate("swEmbed.events.some((e) => /timing correction/.test(e))")), JSON.stringify(await evaluate("swEmbed.events.slice(-4)")));
  await evaluate("swEmbed.slip(-2)");
  const back = await waitFor("swEmbed.events.some((e) => /timing correction/.test(e)) && swEmbed.heard && Math.abs(swEmbed.lag - 20) < 0.9 && swEmbed.lag", 6);
  check("  a real slip (2 s) is put back with one jump", !!back && (await evaluate("swEmbed.events.filter((e) => /timing correction/.test(e)).length")) === 1, back ? back.toFixed(2) + " s" : JSON.stringify(await evaluate("swEmbed.events.slice(-4)")));
  // the shared now-playing answer is cached at Cloudflare for 2 s, so its server time can be up to 2 s old: made 2 s old here
  onPaused = async (p) => {
    const b = (await call("Fetch.getResponseBody", { requestId: p.requestId })).result;
    const j = JSON.parse(b.base64Encoded ? Buffer.from(b.body, "base64").toString() : b.body);
    j.server_now -= 2;
    call("Fetch.fulfillRequest", { requestId: p.requestId, responseCode: 200, responseHeaders: [{ name: "Content-Type", value: "application/json" }], body: Buffer.from(JSON.stringify(j)).toString("base64") });
  };
  await call("Fetch.enable", { patterns: [{ urlPattern: "*/api/nowplaying.php", requestStage: "Response" }] });
  const off0 = await evaluate("swEmbed.offset");
  await evaluate("swEmbed.poll()"); await sleep(1000); await evaluate("swEmbed.poll()"); await sleep(1500);
  const off1 = await evaluate("swEmbed.offset"), lagNow = await evaluate("swEmbed.lag");
  await call("Fetch.disable");
  check("  an old (cached) now-playing answer doesn't move its clock", Math.abs(off1 - off0) < 0.05 && Math.abs(lagNow - 20) < 0.9, `${(off1 - off0).toFixed(3)} s, lag ${lagNow.toFixed(2)} s`);
  const rates = await evaluate("clearInterval(__rateTimer); [...__rates]");
  check("  and it never played faster or slower", rates.length === 1 && rates[0] === 1, JSON.stringify(rates));
  // a tall frame (the website's Listen page; this test's whole window) shows the album art, the copyright line,
  // the spectrum and the output strip, each song in its own colour as on the station's page
  const parts = await evaluate("['emArt', 'emSpec', 'emWave'].map((id) => { const r = document.getElementById(id).getBoundingClientRect(); return r.width > 0 && r.height > 0; })");
  // the copyright line shows only for a song that has copyright details (library_browser_test.mjs checks it with
  // them); a live station's song may have them, so it follows what is on air (read with the line, in one go)
  const look = await evaluate(`(() => { const on = swEmbed.np && swEmbed.np.on_air, song = on && on.type !== 'live' ? on : null, b = document.getElementById('emRights');
    const shown = !b.hidden && getComputedStyle(b).display !== 'none';
    return { rights: !!(song && song.rights), shown, line: b.textContent, want: song && song.rights ? song.rights.line : '', art: !!(song && song.art),
      cd: !!document.querySelector('#emArt .cd-disc'), img: !document.getElementById('emArtImg').hidden }; })()`);
  check("  a tall frame shows the album art (a CD in its case when the song has none), the spectrum and the strip; the copyright line only with copyright details",
    parts.every(Boolean) && look.shown === look.rights && look.line === look.want && (look.art ? look.img : look.cd), JSON.stringify({ parts, ...look }));
  const vis = await waitFor("(() => { const v = swEmbed.vis, o = (r) => r && r[r.length - 1]; return v.on && v.spectrum && v.spectrum.lit > 0 && o(v.measured) > 0.01 && o(v.planned) > 0.01 && v; })()", 15);
  check("  the spectrum lights and the strip shows the measured sound and the coming seconds", !!vis, JSON.stringify(await evaluate("swEmbed.vis")));
  // the reason for round 60: the embed crossfades from one song into the next, on its two decks (the strip's blue and pink)
  {
    const elog = [], efirst = (await state()).current.key;
    let ewait = 75;
    if (!tones) {
      const tl = await (await fetch(`${base}/api/timeline.php`)).json(), cur = (await state()).current;
      const e = tl.entries.find((x) => Math.abs(x.start - cur.start) < 0.01);
      if (e) ewait = Math.max(15, e.end - (tl.server_now - tl.settings.listener_delay_seconds) + 15);
      console.log(`  (waiting ${Math.round(ewait)} s for this song's end)`);
    }
    const nx = await until((x) => x.current && x.current.key !== efirst, ewait, elog);
    const blend = (await until((x) => x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2, 3, elog)) || elog.find((x) => x && x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2);
    check("the embed crossfades into the next song: both decks blending, not a cut", !!nx && !!blend, blend ? blend.decks.map((d) => d.gain).join(" / ") : nx ? "no blend" : "no next song");
    const after = await until((x) => audible(x).length === 1 && audible(x)[0].key === x.current.key && x.decks.filter((d) => d.playing).length === 1, 12);
    check("  then only the new song plays", !!after, after ? `${after.current.title} on ${audible(after)[0].name}` : "");
  }
  const pingBefore = await evaluate("swEmbed.ping");
  await evaluate("swEmbed.pauseFromOutside()");
  // the player joins the song again where it should be (its first try comes 4 s after the last one)
  const again = await waitFor("swEmbed.events.slice(-4).some((e) => / join /.test(' ' + e)) && swEmbed.playing && swEmbed.heard && Math.abs(swEmbed.lag - 20) < 0.6 && swEmbed.lag", 30);
  check("paused from outside (as a phone's power manager does), it starts again by itself, back at 20 s", !!again, again ? again.toFixed(2) + " s" : JSON.stringify(await evaluate("swEmbed.events.slice(-6)")));
  check("  still counted as the same listener", (await evaluate("swEmbed.ping")) === pingBefore && !!pingBefore);
  // the sound stops moving without a pause (a sound output stopped under it): after 8 s the deck gets a new element.
  // That takes about 20 s of one song (5.5 s here, then 8 s and the join): with less left, wait for the next song,
  // so a song change (a join, a wait for data, a timing correction) doesn't land in it (a 20 s song did, round 85)
  const songLeft = () => evaluate(`(() => { const s = SwPlayer.state(), tl = SwPlayer.timeline(); const e = s.current && tl && tl.entries.find((x) => x.type === 'song' && Math.abs(x.start - s.current.start) < 0.01); return e ? e.end - s.heard : 0; })()`);
  if ((await songLeft()) < 22) {
    const was = (await state()).current.key;
    await waitFor(`swEmbed.playing && swEmbed.heard && SwPlayer.state().current && SwPlayer.state().current.key !== ${JSON.stringify(was)}`, 60);
  }
  await sleep(5500); // past the 4 s between joins
  await evaluate("(() => { const el = swEmbed.media, t = el.currentTime; Object.defineProperty(el, 'currentTime', { configurable: true, get: () => t, set: () => {} }); return 1; })()");
  const moving = await waitFor("swEmbed.events.some((e) => /stopped moving: starting it again/.test(e)) && swEmbed.playing && swEmbed.heard && Math.abs(swEmbed.lag - 20) < 0.6 && swEmbed.lag", 30);
  check("  and when the sound stops moving while it should play, it starts again (within 8 s)", !!moving, moving ? moving.toFixed(2) + " s" : JSON.stringify(await evaluate("swEmbed.events.slice(-6)")));
  await evaluate("document.getElementById('emPlay').click()");
  check("  and stops again", !(await evaluate("swEmbed.playing")) && (await evaluate("document.getElementById('emPlay').getAttribute('aria-pressed')")) === "false");
} finally {
  clearTimeout(feeder);
  if (show && show.public_id) { try { helper("live-end", show.public_id); } catch { /* ended */ } }
  if (notesShow) { try { helper("notes-clear", notesShow); } catch { /* gone */ } }
  try { ws && ws.close(); } catch { /* closed */ }
  // msedge.exe hands over to the real browser and exits, so proc.kill() alone leaves Edge running
  // (still playing sound): close it over its debug port, then end anything still using this profile
  try {
    const b = new WebSocket((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    await new Promise((ok, bad) => { b.onopen = ok; b.onerror = bad; });
    b.send(JSON.stringify({ id: 1, method: "Browser.close" }));
    await new Promise((r) => setTimeout(r, 1000));
  } catch { /* already closed */ }
  proc.kill();
  spawnSync("powershell", ["-NoProfile", "-Command", `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${profile}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: "ignore" });
  await sleep(500);
  for (const d of [dir, profile]) { try { rmSync(d, { recursive: true, force: true }); } catch { /* still locked */ } }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
