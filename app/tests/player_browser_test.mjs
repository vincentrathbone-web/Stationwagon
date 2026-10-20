// End-to-end test of the listener player in a real browser (headless Edge or Chrome) on Laragon:
// it joins mid-song at the listener delay, crossfades at a song boundary, hands over to a live
// show (made here, with generated 6 s pieces) and back to the AutoDJ, and the stream-only mode
// holds the same delay. Takes about three minutes.
//   node app/tests/player_browser_test.mjs http://localhost:8080/stationwagon-app
//   node app/tests/player_browser_test.mjs https://wordwave.controll.co.za/stationwagon-app --listen-only
// Needs SW_PHP (php.exe) unless php is on PATH. The live part goes live on the station (through the
// local database), so a public server gets --listen-only: joining, crossfade and stream only.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

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
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run",
  "--autoplay-policy=no-user-gesture-required", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const state = () => evaluate("window.SwPlayer && SwPlayer.state()");
/** Poll state() every 100 ms until fn(state) is true (returns it) or the time runs out (null). */
async function until(fn, seconds, log = null) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state(); if (log) log.push(s); if (s && fn(s)) return s; await sleep(100); }
  return null;
}
const audible = (s) => s.decks.filter((d) => d.playing && d.gain > 0.5);
const drift = (s, d) => d.pos - (s.heard - s.current.start);

let show = null, feeder = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.enable");

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

  console.log("Crossfade");
  const log = [];
  const first = s && s.current.key;
  s = await until((x) => x.current && x.current.key !== first, 75, log);
  check("the next song comes at its boundary", !!s, s && s.current.title);
  const both = (await until((x) => x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2, 3, log)) || log.find((x) => x && x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2);
  check("  with both decks blending", !!both, both && both.decks.map((d) => d.gain).join(" / "));
  s = await until((x) => audible(x).length === 1 && audible(x)[0].key === x.current.key && x.decks.filter((d) => d.playing).length === 1, 10);
  check("  then only the new song plays", !!s, s && s.current.title);
  check("  on time", s && Math.abs(drift(s, audible(s)[0])) < 0.3, s && drift(s, audible(s)[0]).toFixed(3) + " s");

  console.log(listenOnly ? "Live show: skipped (--listen-only)" : "Live show");
  if (!listenOnly) show = helper("live-start", "Browser test show");
  if (!listenOnly) check("a test show goes live", !!show.public_id, JSON.stringify(show));
  if (show && show.public_id) {
    let seq = 0;
    const send = () => { if (seq < 5) helper("live-piece", show.public_id, String(seq), piece(seq++), "auto"); };
    // the first piece arrives one piece after the show starts, like the studio's
    feeder = setTimeout(function next() { send(); if (seq < 5) feeder = setTimeout(next, 6000); }, Math.max(0, (show.start + 6.1) * 1000 - Date.now()));
    s = await until((x) => x.current && x.current.type === "live", 45);
    check("the player reaches the show 20 s later", !!s, s && `heard ${(s.heard - show.start).toFixed(1)} s after its start`);
    s = await until((x) => x.current && x.current.type === "live" && x.stream && x.stream.gain > 0.9, 6);
    check("  and the stream deck takes over", !!s, s && JSON.stringify(s.stream));
    check("  held 20 s behind live", s && s.stream.lag !== null && Math.abs(s.stream.lag - 20) < 0.6, s && String(s.stream.lag && s.stream.lag.toFixed(2)));
    s = await until((x) => x.decks.every((d) => !d.playing), 9); // after the 6 s live cut-in fade
    check("  with the song decks quiet once the fade is done", !!s, s ? "" : JSON.stringify((await state()).decks));
    while (seq < 5) await sleep(500);
    helper("live-end", show.public_id); show = null;
    s = await until((x) => x.current && x.current.type === "song" && audible(x).length === 1, 60);
    check("after the show the AutoDJ comes back", !!s, s && s.current.title);
    s = await until((x) => x.stream && !x.stream.active, 5);
    check("  and the stream deck stops", !!s);
  }
  if (failed) console.log("Player events:\n    " + ((await state()).events || []).join("\n    "));
  await evaluate("SwPlayer.stop()");

  console.log("Stream only");
  await go(`${base}/?mode=stream`);
  await evaluate("SwPlayer.start()");
  s = await until((x) => x.stream && x.stream.active && x.stream.lag !== null && Math.abs(x.stream.lag - 20) < 0.6, 30);
  check("?mode=stream plays the stream at the same 20 s delay", !!s && s.mode === "stream", s && String(s.stream.lag.toFixed(2)));
  await evaluate("SwPlayer.stop()");
} finally {
  clearTimeout(feeder);
  if (show && show.public_id) { try { helper("live-end", show.public_id); } catch { /* ended */ } }
  try { ws && ws.close(); } catch { /* closed */ }
  proc.kill();
  await sleep(500);
  for (const d of [dir, profile]) { try { rmSync(d, { recursive: true, force: true }); } catch { /* still locked */ } }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
