// Stationwagon Studio for Windows with the real BUTT (https://danielnoethen.de/butt/, its portable
// build will do) on Laragon: BUTT, set up the way the app's settings page says, connects to the app's
// bridge in each of its three ways (Icecast with SOURCE, Icecast with PUT, Shoutcast), goes live by
// itself, sends its song title, and ends the show when it disconnects. BUTT is driven by its own
// command line (butt-client) with a config made for the test; your own BUTT settings aren't touched.
// BUTT sends what its audio input hears (the default input), so pieces may be quiet. ~3 minutes.
//   node desktop/tests/butt_test.mjs <folder with butt.exe> [base url] [path to stationwagon-studio.exe]
// Needs SW_PHP (php.exe) unless php is on PATH.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, existsSync, mkdirSync, rmSync, mkdtempSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const buttDir = process.argv[2];
const base = (process.argv[3] || "http://localhost:8080/stationwagon-app").replace(/\/$/, "");
const exe = process.argv[4] || join(here, "../src-tauri/target/debug/stationwagon-studio.exe");
if (!buttDir || !existsSync(join(buttDir, "butt.exe"))) { console.error("Usage: node desktop/tests/butt_test.mjs <folder with butt.exe> [base url] [app exe]"); process.exit(1); }
const php = process.env.SW_PHP || "php";
// SW_HELPER_SSH="<ssh host>:<app folder>" runs the helper on that server, for a test against it
const remote = process.env.SW_HELPER_SSH ? process.env.SW_HELPER_SSH.split(":") : null;
const helper = (...args) => JSON.parse(remote
  ? execFileSync("ssh", [remote[0], `cd ${remote[1]} && php tests/browser_helper.php ${args.map((a) => "'" + String(a).replace(/'/g, "'\''") + "'").join(" ")}`], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] })
  : execFileSync(php, [join(here, "../../app/tests/browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- the app's settings for the test; yours back afterwards ---------------------------------------
const cfgDir = join(process.env.APPDATA, "za.co.controll.stationwagon.studio"), cfgFile = join(cfgDir, "settings.json");
const saved = existsSync(cfgFile) ? readFileSync(cfgFile) : null;
const PORT = 18000 + Math.floor(Math.random() * 1000), PASSWORD = "butt" + Math.random().toString(36).slice(2, 10);
mkdirSync(cfgDir, { recursive: true });
writeFileSync(cfgFile, JSON.stringify({ station: base + "/", port: PORT, password: PASSWORD }));

// ---- BUTT's config: three servers pointing at the bridge, as the settings page says ------------------
// type 1 = Icecast (protocol 0 = PUT, 1 = SOURCE), type 0 = Shoutcast (which BUTT sends to port + 1)
const tmp = mkdtempSync(join(tmpdir(), "sw-butt-"));
const buttrc = join(tmp, "buttrc");
const server = (name, type, port, protocol) => `[${name}]\naddress = 127.0.0.1\nport = ${port}\npassword = ${PASSWORD}\ntype = ${type}\ntls = 0\ncert_hash = \nmount = live\nusr = source\nprotocol = ${protocol}\n`;
// BUTT connects to the server picked in its settings (butt-client's "-s <name>" doesn't switch), so
// each way gets its own BUTT run with that server picked, as a presenter would set it up
const config = (pick) => writeFileSync(buttrc, `[main]\nserver = SW-Source;SW-Put;SW-Shoutcast;\nsrv_ent = ${pick}\nnum_of_srv = 3\nicy = \nicy_ent = \nnum_of_icy = 0\n` +
  `connect_at_startup = 0\ncheck_for_update = 0\nstart_agent = 0\nsignal_threshold = 0.000000\nsilence_threshold = 0.000000\n` +
  `[audio]\ndevice = 0\ndevice2 = -1\nsamplerate = 44100\nbitrate = 128\nchannel = 2\ncodec = mp3\n` +
  server("SW-Source", 1, PORT, 1) + server("SW-Put", 1, PORT, 0) + server("SW-Shoutcast", 0, PORT - 1, 0));
const cmdPort = 12560 + Math.floor(Math.random() * 300);
const butt = (...args) => { try { return execFileSync(join(buttDir, "butt-client.exe"), [...args, "-p", String(cmdPort)], { encoding: "utf8", timeout: 10000 }).trim(); } catch (e) { return "error: " + (e.stdout || e.message); } };

// ---- the app over DevTools --------------------------------------------------------------------------
const cdpPort = 9700 + Math.floor(Math.random() * 90);
let app = null, buttProc = null, ws = null, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok, bad) => {
  const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params }));
  setTimeout(() => { if (pending.delete(i)) bad(new Error(`no answer to ${method} in 30 s`)); }, 30000);
});
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
async function attach(match) {
  for (let i = 0; i < 100; i++) {
    const t = (await (await fetch(`http://127.0.0.1:${cdpPort}/json/list`)).json().catch(() => [])).find((x) => x.type === "page" && match(x));
    if (t) {
      ws = new WebSocket(t.webSocketDebuggerUrl);
      await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
      ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
      return t;
    }
    await sleep(300).catch(() => {});
  }
  return null;
}
const path = () => evaluate("location.pathname").catch(() => "");
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const state = () => evaluate("window.SwStudio && SwStudio.state()");
async function until(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state().catch(() => null); if (s && fn(s)) return s; await sleep(250); }
  return null;
}
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);

let dj = null, minBefore = null;
try {
  minBefore = helper("podcast-min", "30").before;
  app = spawn(exe, [], { env: { ...process.env, WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS: `--remote-debugging-port=${cdpPort}`, SW_UPDATE_ENDPOINT: "http://127.0.0.1:9/none" }, stdio: "ignore" });
  for (let i = 0; i < 60; i++) { try { await fetch(`http://127.0.0.1:${cdpPort}/json/version`); break; } catch { await sleep(300); } }
  await attach((x) => x.url.startsWith(base));
  for (let i = 0, last = ""; i < 50; i++) { await sleep(200); const p = await path(); if (p && p === last) break; last = p; }
  dj = helper("dj", "block");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(dj.email)}; f.password.value = ${JSON.stringify(dj.password)}; f.submit(); })()`);
  for (let i = 0; i < 50 && !(await path()).endsWith("/manage"); i++) await sleep(100);
  await go(`${base}/studio`);
  check("the DJ's studio is open in the app", !!(await until((s) => s.slot && s.slot.may && s.app, 10)));

  const shows = [];
  for (const [name, protocol] of [["SW-Source", "Icecast (SOURCE)"], ["SW-Put", "Icecast (PUT)"], ["SW-Shoutcast", "Shoutcast"]]) {
    console.log(`BUTT, server ${name}`);
    config(name);
    buttProc = spawn(join(buttDir, "butt.exe"), ["-c", buttrc, "-p", String(cmdPort)], { stdio: "ignore" });
    let st = "";
    for (let i = 0; i < 40 && !/connected:/.test(st); i++) { await sleep(500); st = butt("-S"); }
    check("BUTT starts with the test config and answers its command line", st.includes("connected: 0"), st.split("\n")[0]);
    // the first connect can come before BUTT is ready for it: ask again
    let s = null;
    for (let i = 0; i < 4 && !s; i++) { butt("-s", name); s = await until((x) => x.bridge.connected && x.bridge.status, 8); }
    check(`BUTT connects to the bridge (${protocol})`, !!s && s.bridge.status.protocol === protocol, s ? `${s.bridge.status.protocol}, ${s.bridge.status.client}` : butt("-S").replace(/\s+/g, " ") + " | " + JSON.stringify((await state()).events.slice(-4).map((e) => e.m)));
    check("  sending MP3 at 128 kbps, 44.1 kHz stereo", s && s.bridge.status.kbps === 128 && s.bridge.status.rate === 44100 && s.bridge.status.channels === 2, s && JSON.stringify(s.bridge.status));
    s = await until((x) => x.live && x.live.via === "bridge" && x.live.sending, 20);
    check("  and the show goes live by itself", !!s, s ? s.live.public_id : await text("#stBannerText"));
    const quit = async () => { butt("-q"); await sleep(1000); try { execFileSync("taskkill", ["/PID", String(buttProc.pid), "/T", "/F"], { stdio: "ignore" }); } catch { /* gone */ } buttProc = null; };
    if (!s) { butt("-d"); await until((x) => !x.live && !x.bridge.connected, 15); await quit(); continue; }
    const pid = s.live.public_id; shows.push(pid);
    if (protocol === "Icecast (SOURCE)") check("  the studio names BUTT", (await text("#stBannerText")).includes("on air from BUTT") || (await text("#stBridgeState")).startsWith("BUTT sending"), await text("#stBridgeState"));
    butt("-u", "Test Song - Test Artist");
    s = await until((x) => x.bridge.status && x.bridge.status.title === "Test Song - Test Artist", 10);
    check("  BUTT's song title reaches the studio", !!s, (await state()).bridge.status.title);
    s = await until((x) => x.sent >= 2, 25);
    const info = helper("live-info", pid);
    check("  its pieces reach the station, in order, whole MP3s of about 6 s", !!s && info.pieces.length >= 2 && info.pieces.every((p, i) => p.seq === i && Math.abs(p.duration - 6) < 0.1 && Math.abs(p.scanned - p.duration) < 0.05),
      info.pieces.map((p) => `${p.seq}:${p.duration}/${p.scanned}`).join(" "));
    butt("-d");
    s = await until((x) => !x.bridge.connected, 10);
    check("  BUTT's Disconnect is seen at once", !!s);
    s = await until((x) => !x.live, 12);
    const end = helper("live-info", pid);
    check("  and the show ends a few seconds later, back to the AutoDJ", !!s && !end.open && end.closed_reason === "ended by DJ", `${end.open} ${end.closed_reason}`);
    await quit();
  }
} catch (e) {
  failed++;
  console.log("  FAIL " + (e.stack || e.message));
} finally {
  try { butt("-q"); } catch { /* gone */ }
  await sleep(1000);
  for (const p of [buttProc, app]) { try { if (p) execFileSync("taskkill", ["/PID", String(p.pid), "/T", "/F"], { stdio: "ignore" }); } catch { /* gone */ } }
  try { if (ws) ws.close(); } catch { /* closed */ }
  if (saved) writeFileSync(cfgFile, saved); else rmSync(cfgFile, { force: true });
  rmSync(tmp, { recursive: true, force: true });
  try { if (minBefore !== null) helper("podcast-min", String(minBefore)); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (dj) helper("studio-cleanup", String(dj.id), String(dj.block || 0)); } catch (e) { console.log("cleanup: " + e.message); }
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
