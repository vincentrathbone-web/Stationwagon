// End-to-end test of Stationwagon Studio for Windows (desktop/) on Laragon: the real app (a debug or
// release build) opens the station's studio by itself, a DJ signs in, and a stand-in for BUTT sends a
// test MP3 to the app's bridge at the pace of real audio. It checks the bridge's refusals (wrong
// password, Ogg, a second source), going live by itself, the pieces reaching the station whole and
// in order, song titles, a short drop that carries on the show, Stop ending it (and its podcast from
// the aired sound), Mixxx's and Shoutcast's ways of connecting, a DJ outside their slot, the close
// question while on air, the settings window, what the station's page may not do, and finding the station from the website's
// address (round 59), and the bar under the station's screens (round 61).
// Takes about 4 minutes.
//   node desktop/tests/app_test.mjs [base url] [path to stationwagon-studio.exe]
// Needs SW_PHP (php.exe) unless php is on PATH. Your own app settings are put back afterwards.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, existsSync, mkdirSync, rmSync } from "node:fs";
import { connect } from "node:net";
import { createServer as httpServer } from "node:http";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const base = (process.argv[2] || "http://localhost:8080/stationwagon-app").replace(/\/$/, "");
const exe = process.argv[3] || join(here, "../src-tauri/target/debug/stationwagon-studio.exe");
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

// ---- the app's settings: ours for the test, yours back afterwards ----------------------------------
const cfgDir = join(process.env.APPDATA, "za.co.controll.stationwagon.studio");
const cfgFile = join(cfgDir, "settings.json");
const saved = existsSync(cfgFile) ? readFileSync(cfgFile) : null;
const BRIDGE_PORT = 18000 + Math.floor(Math.random() * 1000), PASSWORD = "test" + Math.random().toString(36).slice(2, 10);
mkdirSync(cfgDir, { recursive: true });
writeFileSync(cfgFile, JSON.stringify({ station: base + "/", port: BRIDGE_PORT, password: PASSWORD }));

// ---- a stand-in update server: "no update" (the update test is desktop/tests/update_test.mjs) -------
const upd = httpServer((req, res) => { upd.hits = (upd.hits || 0) + 1; res.writeHead(204); res.end(); });
await new Promise((ok) => upd.listen(0, "127.0.0.1", ok));

// ---- the test MP3, cut into frames so the stand-in can send it at the pace of real audio ------------
const tone = readFileSync(join(here, "../../prototypes/media/Test_Tone_A_440Hz.mp3"));
const id3 = tone[0] === 0x49 ? 10 + ((tone[6] & 127) << 21 | (tone[7] & 127) << 14 | (tone[8] & 127) << 7 | (tone[9] & 127)) : 0;
const frames = [];
for (let o = id3; o + 4 <= tone.length;) {
  const b1 = tone[o + 1], b2 = tone[o + 2];
  if (tone[o] !== 0xff || (b1 & 0xe0) !== 0xe0) { o++; continue; }
  const kbps = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320][b2 >> 4], rate = [44100, 48000, 32000][(b2 >> 2) & 3];
  const len = Math.floor(144 * kbps * 1000 / rate) + ((b2 >> 1) & 1);
  frames.push(tone.subarray(o, o + len)); o += len;
}
const FRAME_S = 1152 / 44100, music = Buffer.concat(frames);

/** A stand-in for BUTT (or Mixxx, or a Shoutcast source): connects, then sends frames in real time. */
function source({ head, shoutcast = false, expectReply = true, withTag = false, ctype = "audio/mpeg" } = {}) {
  const s = { reply: "", sent: 0, closed: false, sock: null };
  s.ready = new Promise((ok) => {
    const sock = connect(BRIDGE_PORT, "127.0.0.1");
    s.sock = sock;
    sock.on("data", (d) => { s.reply += d.toString("latin1"); });
    sock.on("close", () => { s.closed = true; ok(); });
    sock.on("error", () => {});
    sock.on("connect", async () => {
      if (shoutcast) {
        sock.write((head || PASSWORD) + "\r\n");
        for (let i = 0; i < 40 && !s.reply; i++) await sleep(50);
        if (!s.reply.startsWith("OK2")) { ok(); return; }
        sock.write(`icy-name:Test show\r\ncontent-type:${ctype}\r\nicy-br:128\r\n\r\n`);
      } else sock.write(head);
      if (expectReply && !shoutcast) for (let i = 0; i < 40 && !s.reply; i++) await sleep(50);
      ok();
      if (!/^(HTTP\/1\.[01] (200|100)|OK2)/.test(s.reply)) return;
      if (withTag) sock.write(tone.subarray(0, id3));
      const t0 = Date.now();
      let i = 0;
      s.timer = setInterval(() => {
        const due = Math.floor((Date.now() - t0) / 1000 / FRAME_S) + 4; // a little ahead, as an encoder's buffer is
        const out = [];
        while (i < due) { out.push(frames[i % frames.length]); i++; }
        if (out.length && !sock.destroyed) { sock.write(Buffer.concat(out)); s.sent = i; }
      }, 50);
    });
  });
  s.stop = () => { clearInterval(s.timer); s.sock.end(); };
  return s;
}
const basic = (pw = PASSWORD, user = "source") => "Basic " + Buffer.from(`${user}:${pw}`).toString("base64");
const icecast = (opts = {}) => `${opts.method || "SOURCE"} /live HTTP/1.${opts.method === "PUT" ? 1 : 0}\r\nHost: 127.0.0.1:${BRIDGE_PORT}\r\nAuthorization: ${basic(opts.pw)}\r\n` +
  `User-Agent: ${opts.agent || "butt 1.45.0"}\r\nContent-Type: ${opts.ctype || "audio/mpeg"}\r\nice-name: Test show\r\nice-public: 0\r\n${opts.expect ? "Expect: 100-continue\r\n" : ""}\r\n`;

// ---- the app, with its WebView2 open to the test over DevTools ------------------------------------
const cdpPort = 9400 + Math.floor(Math.random() * 400);
let app = null, ws = null, id = 0;
const pending = new Map();
// no answer in 30 s (the page or the app stuck) fails the test, which still cleans up
const call = (method, params = {}) => new Promise((ok, bad) => {
  const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params }));
  setTimeout(() => { if (pending.delete(i)) bad(new Error(`no answer to ${method} in 30 s`)); }, 30000);
});
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const targets = async () => (await (await fetch(`http://127.0.0.1:${cdpPort}/json/list`)).json()).filter((t) => t.type === "page");
async function attach(match) {
  for (let i = 0; i < 100; i++) {
    const t = (await targets().catch(() => [])).find(match);
    if (t) {
      if (ws) ws.close();
      ws = new WebSocket(t.webSocketDebuggerUrl);
      await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
      ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
      return t;
    }
    await sleep(200);
  }
  return null;
}
const path = () => evaluate("location.pathname").catch(() => "");
/** The station's page in the main view (not the bar under it). */
const isScreen = (x) => x.url.startsWith(base) && !/\/bar(\?|$)/.test(x.url);
/** Runs an expression in the bar's view (round 61), over its own DevTools connection. */
async function inBar(expression) {
  const t = (await targets().catch(() => [])).find((x) => /\/bar(\?|$)/.test(x.url));
  if (!t) return undefined;
  const b = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { b.onopen = ok; b.onerror = bad; });
  const r = await new Promise((ok) => { b.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id === 1) ok(d); }; b.send(JSON.stringify({ id: 1, method: "Runtime.evaluate", params: { expression, returnByValue: true, awaitPromise: true } })); setTimeout(() => ok(null), 15000); });
  b.close();
  return r && r.result && r.result.result ? r.result.result.value : undefined;
}
/** The bar view's window and its region, as Windows has them (round 66: its view is always 234 px tall; the region shows its foot, or all of it while the slider is up). */
const barRegion = () => {
  const out = execFileSync("powershell", ["-NoProfile", "-Command", `Add-Type -TypeDefinition 'using System; using System.Collections.Generic; using System.Runtime.InteropServices; public class BR { public delegate bool E(IntPtr h, IntPtr l); [DllImport("user32.dll")] static extern bool EnumChildWindows(IntPtr p, E cb, IntPtr l); [DllImport("user32.dll")] static extern bool GetWindowRect(IntPtr h, out RC r); [DllImport("user32.dll")] static extern int GetWindowRgnBox(IntPtr h, out RC r); [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetClassName(IntPtr h, System.Text.StringBuilder s, int n); [DllImport("user32.dll")] static extern IntPtr FindWindow(string c, string t); [StructLayout(LayoutKind.Sequential)] public struct RC { public int L, T, R, B; } public static string Go() { var top = FindWindow(null, "Stationwagon Studio"); var rows = new List<string>(); EnumChildWindows(top, (h, l) => { var cn = new System.Text.StringBuilder(64); GetClassName(h, cn, 64); if (cn.ToString() != "WRY_WEBVIEW") return true; RC r, g; GetWindowRect(h, out r); int k = GetWindowRgnBox(h, out g); rows.Add((r.B - r.T) + "," + (k == 0 ? "none" : g.T + "-" + g.B)); return true; }, IntPtr.Zero); return string.Join(";", rows); } }'; [BR]::Go()`], { encoding: "utf8" }).trim();
  const bar = out.split(";").map((x) => x.split(",")).find((x) => x[0] === "234");
  return bar ? { height: 234, region: bar[1] } : { height: null, region: out };
};
const barTargets = async () => (await targets().catch(() => [])).filter((x) => /\/bar(\?|$)/.test(x.url));
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const state = () => evaluate("window.SwStudio && SwStudio.state()");
async function until(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state().catch(() => null); if (s && fn(s)) return s; await sleep(200); }
  return null;
}
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);
const clickText = (sel, label) => evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(sel)})].find((x) => x.textContent.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);
async function signIn(user) {
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(user.email)}; f.password.value = ${JSON.stringify(user.password)}; f.submit(); })()`);
  for (let i = 0; i < 50 && !(await path()).endsWith("/manage"); i++) await sleep(100);
  await go(`${base}/studio`);
  await until((s) => s.slot, 10);
}
const signOut = async () => { await evaluate("document.querySelector('header form[action$=\"signout\"]').submit()"); await sleep(800); };
/** Closes one of the app's windows as its X button does (WM_CLOSE), found by its title. */
const closeWindow = (title) => execFileSync("powershell", ["-NoProfile", "-Command",
  `Add-Type -Namespace W -Name U -MemberDefinition '[DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern IntPtr FindWindow(string c, string t); [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr h, uint m, IntPtr w, IntPtr l);';` +
  ` $h = [W.U]::FindWindow([NullString]::Value, '${title}'); if ($h -ne [IntPtr]::Zero) { [W.U]::PostMessage($h, 0x10, [IntPtr]::Zero, [IntPtr]::Zero) | Out-Null; 'closed' } else { 'none' }`], { encoding: "utf8" }).trim();
const alive = () => { try { execFileSync("tasklist", ["/FI", `PID eq ${app.pid}`], { encoding: "utf8" }).includes(String(app.pid)) || (() => { throw 0; })(); return true; } catch { return false; } };

// the test's own WebView2 profile, so the passwords it saves don't stay in yours
const profile = join(process.env.TEMP || here, `sw-app-test-${process.pid}`);
const launch = () => spawn(exe, [], { env: { ...process.env, WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS: `--remote-debugging-port=${cdpPort} --autoplay-policy=no-user-gesture-required`,
  WEBVIEW2_USER_DATA_FOLDER: profile, SW_UPDATE_ENDPOINT: `http://127.0.0.1:${upd.address().port}/latest?v={{current_version}}` }, stdio: "ignore" });
/** Types into a field the way a person does (focus, then key input), so Edge's password manager sees it. */
const typeInto = async (sel, value) => { await evaluate(`document.querySelector(${JSON.stringify(sel)}).focus()`); await call("Input.insertText", { text: value }); };
const pressEnter = async () => { for (const type of ["keyDown", "keyUp"]) await call("Input.dispatchKeyEvent", { type, key: "Enter", code: "Enter", windowsVirtualKeyCode: 13, ...(type === "keyDown" ? { text: String.fromCharCode(13) } : {}) }); };
/** A real mouse click in the middle of an element. */
const clickOn = async (sel) => {
  const r = await evaluate(`(() => { const b = document.querySelector(${JSON.stringify(sel)}).getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; })()`);
  for (const type of ["mousePressed", "mouseReleased"]) await call("Input.dispatchMouseEvent", { type, x: r.x, y: r.y, button: "left", clickCount: 1 });
};

let lone = null, dj = null, minBefore = null;
const shows = [];
try {
  minBefore = helper("podcast-min", "30").before;
  if (!existsSync(exe)) throw new Error("No app at " + exe + " (cargo build in desktop/src-tauri first)");
  app = launch();

  console.log("Opening the app");
  check("the update dialog is the same file in the Windows and the Android app", readFileSync(join(here, "../ui/update-dialog.js"), "utf8") === readFileSync(join(here, "../../mobile/www/update-dialog.js"), "utf8"));
  let t = await attach((x) => /\/(studio|signin)/.test(x.url));
  for (let i = 0, last = ""; i < 50; i++) { await sleep(200); const p = await path(); if (p && p === last && (await evaluate("document.readyState").catch(() => "")) === "complete") break; last = p; }
  check("it checks for an update, then opens the saved station's studio by itself", !!t && upd.hits >= 1, t ? t.url : "no page");
  check("  the station's page can reach the app (window.__TAURI__)", await evaluate("!!(window.__TAURI__ && window.__TAURI__.core)"));
  check("  the bridge listens on 127.0.0.1 only", await new Promise((ok) => { const c = connect(BRIDGE_PORT, "127.0.0.1"); c.on("connect", () => { c.destroy(); ok(true); }); c.on("error", () => ok(false)); }));
  const status = await fetch(`http://127.0.0.1:${BRIDGE_PORT}/`).then((r) => r.text()).catch((e) => e.message);
  check("  and answers a browser with what it is", status.startsWith("Stationwagon Studio bridge"), status.trim());

  console.log("A DJ without a show");
  lone = helper("dj");
  await signIn(lone);
  let s = await state();
  check("the station's page may use the bridge, but not the app's settings, windows or updates",
    await evaluate("Promise.all([window.__TAURI__.core.invoke('bridge_status').then(() => true, () => false), ...['get_info', 'renew_password', 'plugin:window|close', 'plugin:updater|check'].map((c) => window.__TAURI__.core.invoke(c).then(() => false, () => true))]).then((r) => r.every(Boolean))"));
  check("the studio knows it runs in the app, and shows the bridge", s && s.app && !(await evaluate("document.getElementById('stBridge').hidden")) && (await text("#stBridgeState")).includes(`127.0.0.1:${BRIDGE_PORT}`),
    await text("#stBridgeState"));
  check("  without the 'Get the Windows app' link", await evaluate("document.getElementById('stGetApp').hidden"));

  // ---- round 61: the bar, a second view under the station's screens -------------------------------------------
  console.log("The bar under the screens");
  check("the app marks itself in the user agent, so the station sends the studio itself (not its frame page)",
    (await evaluate("navigator.userAgent")).includes("StationwagonStudio/") && !(await evaluate("!!window.swShell || !!document.getElementById('swScreen')")), await evaluate("navigator.userAgent"));
  let bar = null;
  for (let i = 0; i < 50 && !(bar && bar.playing); i++) { await sleep(200); bar = await inBar("({ playing: swEmbed.playing, muted: SwBar.muted, h: innerHeight, w: innerWidth, vis: document.visibilityState, play: !!document.getElementById('emPlay'), t: performance.timeOrigin })").catch(() => null); }
  const winH = await evaluate("innerHeight"), winW = await evaluate("innerWidth");
  check("the bar opens under the studio by itself: running, muted, with no play button", bar && bar.playing && bar.muted && !bar.play && bar.vis === "visible", JSON.stringify(bar));
  check("  84 px tall, the window's width; the studio above it (its view is 234 px, only the foot shows)", bar && bar.h === 234 && (await inBar("document.getElementById('embed').getBoundingClientRect().height")) === 84 && Math.abs(bar.w - winW) <= 1, bar && `bar view ${bar.w}×${bar.h}, studio ${winW}×${winH}`);
  const reg = barRegion();
  check("  Windows shows only the bottom 84 px of that view (the rest is out of sight and out of the mouse's way: no resizing, so no flicker)", reg.height === 234 && reg.region === "150-234", JSON.stringify(reg));
  check("  the bar's look for its room (bigger art and strip), the strip using its width", (await inBar("document.getElementById('embed').classList.contains('roomy') && document.getElementById('emWave').getBoundingClientRect().width > 300")) === true);
  check("  the bar is the station's own page (one view, not counted as a listener)", (await barTargets()).length === 1 && (await inBar("swEmbed.ping")) === null);
  check("  the bar may make room for its slider, and nothing else", await inBar("Promise.all([window.__TAURI__.core.invoke('bar_tall', { tall: false }).then(() => true, () => false), ...['get_info', 'bridge_status', 'set_busy', 'quit_app'].map((c) => window.__TAURI__.core.invoke(c, { busy: false }).then(() => false, () => true))]).then((r) => r.every(Boolean))"));
  check("  and the studio may not", await evaluate("window.__TAURI__.core.invoke('bar_tall', { tall: true }).then(() => false, () => true)"));
  await inBar("SwBar.mute(false)");
  await go(`${base}/manage/library`);
  await sleep(1500);
  let bar2 = await inBar("({ playing: swEmbed.playing, muted: SwBar.muted, t: performance.timeOrigin, h: innerHeight })");
  check("going to the manager: the same bar plays on (not loaded again), still heard", bar2 && bar && bar2.t === bar.t && bar2.playing && !bar2.muted, JSON.stringify(bar2));
  check("  the manager's page has the window less the bar", Math.abs((await evaluate("innerHeight")) - winH) <= 1);
  await go(`${base}/manage/songprep`);
  await sleep(1500);
  bar2 = await inBar("({ muted: SwBar.muted, t: performance.timeOrigin })");
  check("  Song prep mutes the bar by itself", bar2 && bar2.muted && bar2.t === bar.t, JSON.stringify(bar2));
  // round 68: the station's About screen shows the app's version and may check for updates (and nothing else of the app's)
  await go(`${base}/manage/about`);
  await sleep(1500);
  const about = await evaluate("({ ver: document.getElementById('aboutAppVer').textContent, shown: !document.getElementById('aboutUpd').hidden, plain: document.getElementById('aboutBrowser').hidden })");
  check("the About screen shows this app's version and a Check for updates button", /^\d+\.\d+\.\d+/.test(about.ver) && about.shown && about.plain, JSON.stringify(about));
  check("  and may call only the version and the update commands (not the app's settings)", await evaluate("Promise.all([window.__TAURI__.core.invoke('app_version').then(() => true, () => false), window.__TAURI__.core.invoke('get_info').then(() => false, () => true), window.__TAURI__.core.invoke('save_settings', { station: 'x', port: 8000 }).then(() => false, () => true)]).then((r) => r.every(Boolean))"));
  await inBar("(() => { const e = document.getElementById('emSound'); e.dispatchEvent(new PointerEvent('pointerenter', { pointerType: 'mouse' })); })()");
  await sleep(700);
  bar2 = await inBar("({ h: innerHeight, open: SwBar.open, tall: getComputedStyle(document.getElementById('emVolPop')).display })");
  const regUp = barRegion();
  check("pointing at the speaker: the volume slider comes up, and Windows shows the whole view over the page's foot", bar2 && bar2.open && bar2.tall !== "none" && regUp.region === "0-234", JSON.stringify([bar2, regUp]));
  check("  the page above keeps its size", Math.abs((await evaluate("innerHeight")) - winH) <= 1);
  // SW_SHOT=<file.png>: a picture of the window now, to see the page through the bar around the slider
  if (process.env.SW_SHOT) execFileSync("powershell", ["-NoProfile", "-Command",
    `Add-Type -AssemblyName System.Drawing; Add-Type -Namespace W -Name P -MemberDefinition '[DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern IntPtr FindWindow(string c, string t); [DllImport("user32.dll")] public static extern bool PrintWindow(IntPtr h, IntPtr dc, uint f); [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr h, out RECT r); public struct RECT { public int L, T, R, B; }';` +
    ` $h = [W.P]::FindWindow([NullString]::Value, 'Stationwagon Studio'); $r = New-Object W.P+RECT; [W.P]::GetWindowRect($h, [ref]$r) | Out-Null;` +
    ` $b = New-Object System.Drawing.Bitmap ($r.R - $r.L), ($r.B - $r.T); $g = [System.Drawing.Graphics]::FromImage($b); $dc = $g.GetHdc(); [W.P]::PrintWindow($h, $dc, 2) | Out-Null; $g.ReleaseHdc($dc); $b.Save('${process.env.SW_SHOT.replace(/'/g, "''")}')`], { encoding: "utf8" });
  await inBar("(() => { const e = document.getElementById('emSound'); e.dispatchEvent(new PointerEvent('pointerleave', { pointerType: 'mouse' })); })()");
  await sleep(1200);
  bar2 = await inBar("({ h: innerHeight, open: SwBar.open })");
  check("  and it goes down again when the pointer leaves (the view keeps its size)", bar2 && !bar2.open && bar2.h === 234 && barRegion().region === "150-234", JSON.stringify([bar2, barRegion()]));
  await inBar("SwBar.volume(0.5)");
  await go(`${base}/`);
  await sleep(1200);
  bar2 = await inBar("({ vis: document.visibilityState })");
  check("the station page (not a screen): the bar is hidden and the page has the whole window", bar2 && bar2.vis === "hidden" && Math.abs((await evaluate("innerHeight")) - (winH + 84)) <= 1, `${JSON.stringify(bar2)} ${await evaluate("innerHeight")}`);
  await go(`${base}/studio`);
  await sleep(1500);
  bar2 = await inBar("({ vis: document.visibilityState, t: performance.timeOrigin, vol: SwBar.volume() })");
  check("  back in the studio: the bar again, the same one, with the volume set", bar2 && bar2.vis === "visible" && bar2.t === bar.t && bar2.vol === 0.5 && Math.abs((await evaluate("innerHeight")) - winH) <= 1, JSON.stringify(bar2));
  // round 75 (v1.5): the bar's stream picker in the app; a plain list, so Windows opens its own menu outside the 84 px view
  console.log("The bar's stream picker");
  const pickFx = helper("stream-fixture");
  try {
    await inBar("location.reload()");
    let pk = null;
    for (let i = 0; i < 50 && !(pk && pk.on && pk.opts.length > 1); i++) { await sleep(200); pk = await inBar("({ on: !!SwPlayer.state().on, opts: [...document.querySelectorAll('#emStream option')].map((o) => o.value), h: document.getElementById('emStream') ? document.getElementById('emStream').getBoundingClientRect().height : 0, bottom: document.getElementById('emStream') ? innerHeight - document.getElementById('emStream').getBoundingClientRect().bottom : 0 })").catch(() => null); }
    check("with two streams the app's bar has the stream picker, small, in the bar's foot", pk && pk.opts.includes(pickFx.stream) && pk.h > 10 && pk.h < 30 && pk.bottom > 0 && pk.bottom < 84, JSON.stringify(pk));
    const screenBefore = await evaluate("location.href");
    await inBar(`(() => { const s = document.getElementById('emStream'); s.value = ${JSON.stringify(pickFx.stream)}; s.dispatchEvent(new Event('change')); })()`);
    let on2 = null;
    for (let i = 0; i < 50 && !(on2 && on2.name === pickFx.name && on2.status === "Playing"); i++) { await sleep(200); on2 = await inBar("({ stream: SwPlayer.stream(), name: SwPlayer.timeline() && SwPlayer.timeline().station.name, status: SwPlayer.state().status, muted: SwBar.muted })").catch(() => null); }
    check("  picking a stream plays it in the bar, muted as it was", on2 && on2.stream === pickFx.stream && on2.name === pickFx.name && on2.status === "Playing", JSON.stringify(on2));
    check("  and the studio above stays as it was", (await evaluate("location.href")) === screenBefore);
    await inBar("(() => { const s = document.getElementById('emStream'); s.value = ''; s.dispatchEvent(new Event('change')); })()");
    for (let i = 0; i < 50 && !(on2 && on2.stream === "" && on2.status === "Playing"); i++) { await sleep(200); on2 = await inBar("({ stream: SwPlayer.stream(), status: SwPlayer.state().status })").catch(() => null); }
    check("  and back to the main stream", on2 && on2.stream === "" && on2.status === "Playing", JSON.stringify(on2));
  } finally {
    helper("stream-clean", String(pickFx.id));
  }
  await inBar("location.reload()");
  await sleep(2500);
  await until((x) => x.slot !== undefined, 10);
  // round 58: the app's own page and its update dialog follow the look picked in the studio
  const lookNow = () => { try { return JSON.parse(readFileSync(cfgFile, "utf8")).look || null; } catch { return null; } };
  const skin0 = await evaluate("SwSkin.current()"), accent0 = await evaluate("getComputedStyle(document.documentElement).getPropertyValue('--amber').trim()");
  let look = null;
  for (let i = 0; i < 20 && !(look && look.skin === skin0); i++) { await sleep(250); look = lookNow(); }
  check("the studio passes its look to the app (the skin, its accent as the app page's --accent)", look && look.skin === skin0 && look.vars["--accent"] === accent0 && look.vars["--ink-2"], look && `${look.skin} ${look.vars["--accent"]}`);
  const other = skin0 === "hifi" ? "green" : "hifi";
  await evaluate(`SwSkin.set(${JSON.stringify(other)})`);
  const accent1 = await evaluate("getComputedStyle(document.documentElement).getPropertyValue('--amber').trim()");
  look = null;
  for (let i = 0; i < 20 && !(look && look.skin === other); i++) { await sleep(250); look = lookNow(); }
  check("  picking another look passes that one at once", look && look.skin === other && look.vars["--accent"] === accent1 && accent1 !== accent0, look && `${look.skin} ${look.vars["--accent"]}`);
  await evaluate(`SwSkin.set(${JSON.stringify(skin0)})`);
  check("  the app refuses anything that isn't a plain look (a url, a broken-out rule, an unknown key)",
    await evaluate(`Promise.all([{ skin: "hifi", vars: { "--accent": "url(https://example.com/x)" } }, { skin: "hifi", vars: { "--accent": "red; } body { display: none" } }, { skin: "hifi", vars: { color: "red" } }]
      .map((look) => window.__TAURI__.core.invoke("set_look", { look }).then(() => false, () => true))).then((r) => r.every(Boolean))`));
  let src = source({ head: icecast() });
  await src.ready;
  s = await until((x) => x.bridge.connected, 6);
  check("BUTT connects", src.reply.startsWith("HTTP/1.0 200 OK") && !!s, src.reply.split("\r\n")[0]);
  await sleep(1500);
  s = await state();
  check("  but outside their slot the show doesn't go live", s && !s.live, s && JSON.stringify(s.live));
  check("  and the banner says why", (await text("#stBannerText")).includes("can’t go live now"), await text("#stBannerText"));
  src.stop();
  await until((x) => !x.bridge.connected, 5);
  await signOut();

  console.log("A DJ in their block, with BUTT");
  dj = helper("dj", "block");
  await signIn(dj);
  s = await state();
  check("the studio is ready, the studio itself not switched on", s && s.slot.may && !s.on, s && JSON.stringify(s.slot.title));
  src = source({ head: icecast({ pw: "wrong" }) });
  await src.ready;
  await sleep(500);
  check("a wrong password is refused (401)", src.reply.startsWith("HTTP/1.0 401"), src.reply.split("\r\n")[0]);
  check("  and the studio says so", (await text("#stBannerText")).includes("wrong password"), await text("#stBannerText"));
  src = source({ head: icecast({ ctype: "application/ogg" }) });
  await src.ready;
  await sleep(500);
  check("Ogg is refused (415)", src.reply.startsWith("HTTP/1.0 415"), src.reply.split("\r\n")[0]);
  check("  and the studio says to pick MP3", (await text("#stBannerText")).includes("Set it to MP3"), await text("#stBannerText"));

  src = source({ head: icecast(), withTag: true });
  await src.ready;
  s = await until((x) => x.live && x.live.via === "bridge", 10);
  check("BUTT connecting goes live by itself", !!s, s ? s.live.public_id : JSON.stringify((await state()).events.slice(-4)));
  const live1 = s && s.live.public_id; shows.push(live1);
  s = await until((x) => x.live && x.live.sending, 15);
  check("  BUTT's sound goes out from the handover", !!s, s ? "" : await text("#stBannerText"));
  check("  the banner says it's on air from BUTT", (await text("#stBannerText")).includes("on air from BUTT"), await text("#stBannerText"));
  check("  the status strip names BUTT and its quality", /BUTT sending · MP3 128 kbps/.test(await text("#stBridgeState")), await text("#stBridgeState"));
  check("  and Go live shows On air", (await text("#stGoLabel")).startsWith("On air"), await text("#stGoLabel"));
  const second = source({ head: icecast() });
  await second.ready;
  check("a second source is refused while BUTT sends (403)", second.reply.startsWith("HTTP/1.0 403"), second.reply.split("\r\n")[0]);
  const meta = await fetch(`http://127.0.0.1:${BRIDGE_PORT}/admin/metadata?mount=/live&mode=updinfo&song=${encodeURIComponent("Amazing Grace - Test Choir")}`, { headers: { Authorization: basic() } });
  check("BUTT's song title is taken (Icecast's metadata call)", meta.status === 200 && (await meta.text()).includes("<return>1</return>"));
  s = await until((x) => x.bridge.status && x.bridge.status.title === "Amazing Grace - Test Choir", 3);
  check("  and reaches the studio", !!s);
  s = await until((x) => x.sent >= 4, 40);
  check("pieces go out every 6 s", !!s, s ? `${s.sent} sent` : JSON.stringify((await state()).events.slice(-4)));
  check("  with nothing waiting and no errors", s && s.queue <= 1 && s.errors === 0, s && `queue ${s.queue}, errors ${s.errors}`);
  let info = helper("live-info", live1);
  check("the server has them, in order, as whole MP3s of about 6 s", info.pieces.length >= 4 && info.pieces.every((p, i) => p.seq === i && Math.abs(p.duration - 6) < 0.1 && Math.abs(p.scanned - p.duration) < 0.05),
    info.pieces.map((p) => `${p.seq}:${p.duration}/${p.scanned}`).join(" "));
  const ring = Buffer.concat([music, music]);
  const pieces = await Promise.all(info.pieces.map(async (p) => Buffer.from(await (await fetch(`${base}/storage/live/${live1}/${String(p.seq).padStart(5, "0")}.mp3`)).arrayBuffer())));
  const joined = Buffer.concat(pieces), at0 = ring.indexOf(joined.subarray(0, 4096));
  check("  byte for byte BUTT's frames, nothing lost between pieces", at0 >= 0 && ring.subarray(at0, at0 + joined.length).equals(joined.subarray(0, Math.min(joined.length, ring.length - at0))),
    `${joined.length} bytes from frame offset ${at0}`);
  check("  under the DJ's name and their block's title", info.dj_user_id === dj.id && info.title === "[test] Studio test show");
  const playlist = await (await fetch(`${base}/api/stream.php`, { cache: "no-store" })).text();
  check("listeners' stream carries the live pieces", playlist.includes(`storage/live/${live1}/`));

  console.log("Closing while on air");
  check("the app knows the studio is on air", s && s.bridge.busy);
  closeWindow("Stationwagon Studio");
  await sleep(1200);
  check("closing the window asks first instead", alive() && (await text("#stBannerText")).startsWith("Close the app?"), await text("#stBannerText"));
  await clickText("#stBannerActs button", "Keep going");
  check("  Keep going carries on", alive() && !!(await state()).live);

  console.log("A short drop");
  src.stop();
  s = await until((x) => !x.bridge.connected, 12);
  check("BUTT stopping is seen at once", !!s);
  check("  the banner says the show ends in 5 seconds unless it comes back", (await text("#stBannerText")).includes("ends in 5 seconds"), await text("#stBannerText"));
  await sleep(2000);
  src = source({ head: icecast() });
  await src.ready;
  s = await until((x) => x.bridge.connected, 5);
  await sleep(4500);
  s = await state();
  check("BUTT back within 5 s: the same show carries on", s && s.live && s.live.public_id === live1, s && s.live && s.live.public_id);
  const sentBefore = s.sent;
  s = await until((x) => x.sent >= sentBefore + 1, 12);
  info = helper("live-info", live1);
  check("  and its pieces go on, the first one after 1 s", !!s && info.open && info.pieces.every((p, i) => p.seq === i) && info.pieces.some((p) => Math.abs(p.duration - 1) < 0.05),
    s ? info.pieces.map((p) => `${p.seq}:${p.duration}`).join(" ") : JSON.stringify((await state()).events.slice(-5)));

  console.log("Stop in BUTT");
  src.stop();
  s = await until((x) => !x.live, 12);
  check("stopping BUTT ends the show about 5 s later", !!s, s ? "" : await text("#stBannerText"));
  info = helper("live-info", live1);
  check("  the station closes it as ended by the DJ", !info.open && info.closed_reason === "ended by DJ", `${info.open} ${info.closed_reason}`);
  check("  and the banner says the AutoDJ has it", (await text("#stBannerText")).startsWith("Handed back to the AutoDJ"), await text("#stBannerText"));
  const ep = helper("episode-info", live1);
  check("its podcast episode is made from the sound that went on air", ep && ep.takes.length >= 1 && ep.takes.every((x) => x.kind !== "device") && ep.takes.some((x) => x.kind === "aired"),
    ep ? ep.takes.map((x) => x.kind).join(",") : "no episode");

  console.log("Mixxx and Shoutcast");
  src = source({ head: icecast({ method: "PUT", expect: true, agent: "libshout/2.4.6" }) });
  await src.ready;
  check("Mixxx's way (PUT, Expect: 100-continue) is answered as Icecast does: 100 Continue, then 200 OK", src.reply.startsWith("HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\n\r\n"), JSON.stringify(src.reply.slice(0, 60)));
  s = await until((x) => x.live && x.live.sending && x.live.public_id !== live1, 20);
  check("  and goes live as a new show", !!s, s ? "" : await text("#stBannerText"));
  check("  the studio calls it Mixxx", (await text("#stBridgeState")).startsWith("Mixxx sending"), await text("#stBridgeState"));
  if (s) shows.push(s.live.public_id);
  s = await until((x) => x.sent >= 1, 12);
  check("  and sends its pieces", !!s);
  src.stop();
  s = await until((x) => !x.live, 12);
  check("  stopping Mixxx ends it", !!s);
  src = source({ shoutcast: true, head: "wrong" });
  await src.ready;
  check("a Shoutcast source with a wrong password is refused", src.reply.startsWith("invalid password"), src.reply.trim());
  src = source({ shoutcast: true });
  await src.ready;
  check("Shoutcast's way (the password, then OK2) works too", src.reply.startsWith("OK2"), src.reply.split("\r\n")[0]);
  s = await until((x) => x.live && x.live.sending, 20);
  check("  and goes live", !!s);
  if (s) shows.push(s.live.public_id);
  const sc = (pass) => fetch(`http://127.0.0.1:${BRIDGE_PORT - 1}/admin.cgi?pass=${pass}&mode=updinfo&song=${encodeURIComponent("Shoutcast Song")}`).then((r) => r.status).catch((e) => e.message);
  check("  a title with the wrong password is refused (403)", (await sc("wrong")) === 403);
  check("  its title (admin.cgi, on the port below, as Shoutcast does) reaches the studio", (await sc(PASSWORD)) === 200 && !!(await until((x) => x.bridge.status && x.bridge.status.title === "Shoutcast Song", 3)));
  src.stop();
  s = await until((x) => !x.live, 12);
  check("  and ends when it stops", !!s);

  console.log("The app's settings window");
  await evaluate("window.__TAURI__.core.invoke('open_settings')");
  const main = await targets();
  const st = await attach((x) => x.url.includes("#settings"));
  check("App settings opens the settings window", !!st, main.map((x) => x.url).join(" "));
  await sleep(800);
  const pageLook = await evaluate("[document.documentElement.getAttribute('data-skin'), getComputedStyle(document.documentElement).getPropertyValue('--accent').trim()].join(' ')");
  check("  in the studio's look", pageLook === `${skin0} ${accent0}`, `${pageLook} vs ${skin0} ${accent0}`);
  check("  it shows the station, the bridge's port and password", (await evaluate("document.getElementById('station').value")) === base + "/" &&
    (await text("#portShow")) === String(BRIDGE_PORT) && (await text("#pw")) === PASSWORD);
  check("  and that the bridge is waiting", (await text("#bridgeState")).startsWith("Waiting for BUTT"), await text("#bridgeState"));
  check("  closing it leaves the studio open", closeWindow("Stationwagon Studio: settings") === "closed" && (await sleep(800), alive()) && !(await targets()).some((x) => x.url.includes("#settings")));
  await attach(isScreen);

  console.log("Remembering the password");
  await call("Network.clearBrowserCookies");
  await go(`${base}/signin`);
  await typeInto("#email", lone.email);
  await typeInto("#password", lone.password);
  await pressEnter();
  for (let i = 0; i < 50 && !(await path()).endsWith("/manage"); i++) await sleep(100);
  check("a DJ signs in by typing", (await path()).endsWith("/manage"), await path());
  const bubble = execFileSync("powershell", ["-NoProfile", "-ExecutionPolicy", "Bypass", "-File", join(here, "press-bubble.ps1"), String(app.pid)], { encoding: "utf8" }).trim();
  check("  Edge asks \"Save your password?\", and Save keeps it (in the app's own WebView2 profile)", bubble === "pressed", bubble);
  await call("Network.clearBrowserCookies"); // signed out: the next sign-in
  await go(`${base}/signin`);
  const filled = await (async () => { for (let i = 0; i < 30; i++) { if (await evaluate("document.querySelector('#password').matches(':autofill')").catch(() => false)) return true; await sleep(200); } return false; })();
  check("the next time, the app fills in the saved email and password", filled);
  await clickOn("main h1"); // Edge hands a filled password to the page only once the person touches it
  check("  the filled-in email is the DJ's", (await evaluate("document.querySelector('#email').value")) === lone.email);
  await clickOn("#remember");
  await clickOn("main button[type=submit]");
  for (let i = 0; i < 50 && !(await path()).endsWith("/manage"); i++) await sleep(100);
  check("  Sign in (with Keep me signed in) signs in with it, without typing", (await path()).endsWith("/manage"), await path());
  closeWindow("Stationwagon Studio");
  for (let i = 0; i < 30 && alive(); i++) await sleep(200);
  app = launch();
  t = await attach((x) => /\/(studio|signin)/.test(x.url));
  for (let i = 0, last = ""; i < 50; i++) { await sleep(200); const p = await path(); if (p && p === last && (await evaluate("document.readyState").catch(() => "")) === "complete") break; last = p; }
  check("Keep me signed in: the app closed and opened again goes straight to the studio, signed in", (await path()).endsWith("/studio") && !!(await until((x) => x.slot, 10)), await path());
  check("  the test used its own WebView2 profile (yours is left alone)", existsSync(join(profile, "EBWebView")));

  console.log("Finding the station (round 59)");
  // a stand-in website: its home page links to a station in /myradio/, which answers like one
  const site = httpServer((req, res) => {
    if (req.url === "/") { res.writeHead(200, { "Content-Type": "text/html" }); res.end('<p><a href="/listen/">Listen</a> <a href="/myradio/signin">Sign in</a></p>'); return; }
    if (req.url.startsWith("/myradio/api/nowplaying.php")) { res.writeHead(200, { "Content-Type": "application/json" }); res.end('{"station":{"name":"Stand-in Radio"}}'); return; }
    res.writeHead(404); res.end("not found");
  });
  await new Promise((ok) => site.listen(0, "127.0.0.1", ok));
  const SITE = `http://127.0.0.1:${site.address().port}/`;
  const NAME = (await (await fetch(`${base}/api/nowplaying.php`)).json()).station.name;
  const root = new URL(base).origin + "/";
  closeWindow("Stationwagon Studio");
  for (let i = 0; i < 30 && alive(); i++) await sleep(200);
  writeFileSync(cfgFile, JSON.stringify({ ...JSON.parse(readFileSync(cfgFile, "utf8")), station: root }));
  app = launch();
  await attach((x) => x.url.startsWith("http://tauri.localhost"));
  const finder = async () => evaluate(`({ box: document.getElementById("station").value, found: document.getElementById("found").textContent, cls: document.getElementById("found").className,
    open: !document.getElementById("openBtn").disabled, page: !document.getElementById("page").hidden, msg: document.getElementById("msg").textContent })`);
  const waitFinder = async (ok, seconds = 25) => { let f = null; for (let i = 0; i < seconds * 4; i++) { f = await finder().catch(() => null); if (f && ok(f)) return f; await sleep(250); } return f; };
  let f = await waitFinder((x) => x.page && x.cls.includes("ok"));
  check(`saved as the website's address (${root}): the app doesn't open a page that isn't there; its page shows the station it found instead`,
    f && f.page && f.box === base + "/" && f.found === `✓ Found: ${NAME}` && f.open && /isn’t the station’s own/.test(f.msg), f && `${f.box} · ${f.found} · ${f.msg}`);
  check("  (the main window is still the app's own page)", (await targets()).some((x) => x.url.startsWith("http://tauri.localhost")) && !(await targets()).some((x) => x.url.startsWith(base)));
  const typeAddress = (a) => evaluate(`(() => { const i = document.getElementById("station"); i.value = ${JSON.stringify(a)}; i.dispatchEvent(new Event("input")); })()`);
  await typeAddress(`${root}nothing-here/`);
  f = await waitFinder((x) => x.cls.includes("bad"));
  check("typing an address with no station: '✗ There's no Stationwagon station at …', and Save and open can't be pressed", f && /no Stationwagon station at/.test(f.found) && !f.open, f && f.found);
  await typeAddress("http://localhost:9/");
  f = await waitFinder((x) => x.cls.includes("bad"));
  check("  a server that doesn't answer: 'Couldn't reach …' with Check again", f && /Couldn’t reach/.test(f.found) && /Check again/.test(f.found) && !f.open, f && f.found);
  await typeAddress(SITE);
  f = await waitFinder((x) => x.cls.includes("ok"));
  check("a website whose home page links to its station: found through that link", f && f.box === `${SITE}myradio/` && f.found === "✓ Found: Stand-in Radio" && f.open, f && `${f.box} · ${f.found}`);
  await typeAddress(root.replace(/\/$/, ""));
  f = await waitFinder((x) => x.cls.includes("ok"));
  check(`the website's address typed again: the station found in its folder (${base}/)`, f && f.box === base + "/" && f.found === `✓ Found: ${NAME}`, f && `${f.box} · ${f.found}`);
  await evaluate(`document.getElementById("form").requestSubmit()`);
  t = await attach(isScreen);
  check("  Save and open: the station's studio opens, and the station's own address is saved", !!t && JSON.parse(readFileSync(cfgFile, "utf8")).station === base + "/", t ? t.url : "no studio");
  site.close();

  console.log("The station picker on the sign-in card (round 81)");
  // a second station: a stand-in that answers like one in /myradio/ (the studio page itself is a 404, which is enough to see the app go there)
  const stand = httpServer((req, res) => {
    if (req.url.startsWith("/myradio/api/nowplaying.php")) { res.writeHead(200, { "Content-Type": "application/json" }); res.end('{"station":{"name":"Stand-in Radio"}}'); return; }
    if (req.url === "/") { res.writeHead(200, { "Content-Type": "text/html" }); res.end('<a href="/myradio/signin">Sign in</a>'); return; }
    res.writeHead(404); res.end("not found");
  });
  await new Promise((ok) => stand.listen(0, "127.0.0.1", ok));
  const STAND = `http://127.0.0.1:${stand.address().port}/`, STAND_BASE = STAND + "myradio/";
  const settingsNow = () => JSON.parse(readFileSync(cfgFile, "utf8"));
  const restart = async (cfg) => {
    closeWindow("Stationwagon Studio");
    for (let i = 0; i < 30 && alive(); i++) await sleep(200);
    writeFileSync(cfgFile, JSON.stringify({ ...settingsNow(), ...cfg }));
    app = launch();
    await attach(isScreen);
    await call("Network.clearBrowserCookies");
    await go(`${base}/signin`);
  };
  const picker = async () => { let p = null; for (let i = 0; i < 40; i++) { p = await evaluate(`(() => { const s = document.getElementById("stationPick"); return s ? { opts: [...s.options].map((o) => o.textContent), val: s.value, note: document.querySelector(".stpick .hint").textContent } : null; })()`).catch(() => null); if (p && p.opts.length > 1) return p; await sleep(250); } return p; };
  const pick = (v) => evaluate(`(() => { const s = document.getElementById("stationPick"); s.value = ${JSON.stringify(v)}; s.dispatchEvent(new Event("change")); })()`);

  // two saved stations: the card lists both (the open one picked) and "Add a station…"
  await restart({ station: base + "/", stations: [{ base: base + "/", name: NAME }, { base: STAND_BASE, name: "Stand-in Radio" }] });
  let p = await picker();
  check("the sign-in card lists the saved stations and 'Add a station…', the open one picked", p && JSON.stringify(p.opts) === JSON.stringify([NAME, "Stand-in Radio", "Add a station…"]) && p.val === base + "/", p && JSON.stringify(p));
  check("  the station's email and password fields are still there, and the picker is not sent with the sign-in", await evaluate(`!!document.getElementById("email") && !!document.getElementById("password") && !document.getElementById("stationPick").name`));
  // adding an address with no station: said on the card, nothing saved
  await pick("\u0000add");
  await evaluate(`(() => { document.querySelector(".stpick .stadd input").value = ${JSON.stringify(root + "nothing-here/")}; document.querySelector(".stpick .stadd button").click(); })()`);
  let note = ""; for (let i = 0; i < 60 && !/no Stationwagon station/.test(note); i++) { await sleep(250); note = await evaluate(`document.querySelector(".stpick .hint").textContent`); }
  check("an address with no station: the card says so and nothing is saved", /no Stationwagon station/.test(note) && settingsNow().stations.length === 2 && settingsNow().station === base + "/", note);
  // picking the other saved station: the app opens it and makes it the default
  await pick(STAND_BASE);
  t = await attach((x) => x.url.startsWith(STAND_BASE));
  check("picking a saved station opens it in the app and makes it the default", !!t && settingsNow().station === STAND_BASE, t ? t.url : "no page");
  // the station's page can open only a saved station, and can't forget one
  await restart({ station: base + "/" });
  const refused = await evaluate(`Promise.all([window.__TAURI__.core.invoke("switch_station", { base: "http://evil.example/" }).then(() => false, () => true),
    window.__TAURI__.core.invoke("forget_station", { base: ${JSON.stringify(STAND_BASE)} }).then(() => false, () => true)]).then((r) => r.every(Boolean))`);
  check("a station's page can't open one that isn't saved, or take one off the list", refused);

  // older settings (one station, no list): it becomes the list, and a new station is added from the card
  await restart({ station: base + "/", stations: undefined });
  p = await picker().catch(() => null);
  check("older settings with one station: it shows as the only saved station", p && p.opts.length === 2 && p.val === base + "/" && p.opts[1] === "Add a station…", p && JSON.stringify(p));
  await pick("\u0000add");
  await evaluate(`(() => { document.querySelector(".stpick .stadd input").value = ${JSON.stringify(STAND)}; document.querySelector(".stpick .stadd button").click(); })()`);
  t = await attach((x) => x.url.startsWith(STAND_BASE));
  const after = settingsNow();
  check("adding a station from the card finds it (through the website's link), saves it by its name, opens it and makes it the default",
    !!t && after.station === STAND_BASE && after.stations.length === 2 && after.stations.some((x) => x.base === STAND_BASE && x.name === "Stand-in Radio"), JSON.stringify(after.stations));
  // the app's own page lists the saved stations, and Remove takes one off the list
  closeWindow("Stationwagon Studio");
  for (let i = 0; i < 30 && alive(); i++) await sleep(200);
  writeFileSync(cfgFile, JSON.stringify({ ...settingsNow(), station: root, stations: [{ base: root, name: "The website" }, { base: STAND_BASE, name: "Stand-in Radio" }] }));
  app = launch();
  await attach((x) => x.url.startsWith("http://tauri.localhost"));
  const rows = () => evaluate(`({ shown: !document.getElementById("stationsCard").hidden, names: [...document.querySelectorAll("#stationList li b")].map((b) => b.textContent) })`);
  let lst = null; for (let i = 0; i < 40; i++) { lst = await rows().catch(() => null); if (lst && lst.shown) break; await sleep(250); }
  check("the app's own page lists the saved stations", lst && lst.shown && JSON.stringify(lst.names) === JSON.stringify(["The website", "Stand-in Radio"]), lst && JSON.stringify(lst));
  await evaluate(`[...document.querySelectorAll("#stationList li")].find((li) => li.querySelector("b").textContent === "Stand-in Radio").querySelector("button").click()`);
  for (let i = 0; i < 40 && (await rows()).names.length !== 1; i++) await sleep(250);
  lst = await rows();
  check("  Remove takes one off the list (in the page and in the app's settings), the default stays", lst.names.length === 1 && settingsNow().stations.length === 1 && settingsNow().station === root, JSON.stringify(lst) + " " + JSON.stringify(settingsNow().stations));
  stand.close();
  await restart({ station: base + "/", stations: undefined });

  console.log("Closing");
  closeWindow("Stationwagon Studio");
  for (let i = 0; i < 30 && alive(); i++) await sleep(200);
  check("off air, closing the window closes the app", !alive());
} catch (e) {
  failed++;
  console.log("  FAIL " + (e.stack || e.message));
} finally {
  try { if (ws) ws.close(); } catch { /* closed */ }
  try { if (app && alive()) execFileSync("taskkill", ["/PID", String(app.pid), "/T", "/F"], { stdio: "ignore" }); } catch { /* gone */ }
  upd.close();
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still in use */ }
  if (saved) writeFileSync(cfgFile, saved); else rmSync(cfgFile, { force: true });
  try { if (minBefore !== null) helper("podcast-min", String(minBefore)); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (dj) helper("studio-cleanup", String(dj.id), String(dj.block || 0)); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (lone) helper("studio-cleanup", String(lone.id)); } catch { /* gone */ }
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
