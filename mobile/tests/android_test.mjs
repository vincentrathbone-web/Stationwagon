// End-to-end test of Stationwagon Studio for Android (mobile/) on a real phone over USB, against
// Laragon (the phone reaches it on localhost through adb reverse, which the WebView treats as secure,
// so the mic works). Installs the test build of the older version, then:
//   - the start page: a wrong address refused, the station saved, the studio opened;
//   - the app's own parts refused from the station's pages (only the start page may save or update);
//   - the studio: a throwaway DJ signs in, switches on with the phone's mic, is asked to allow Do Not
//     Disturb, goes live: Do Not Disturb on (alarms only), the "On air" service running, Back asks
//     instead of leaving, App settings refused while on air; End show puts Do Not Disturb back;
//   - the offline page when the station can't be reached, and its way back to the start page;
//   - updates through the Stationwagon Updates plugin (PHP's own server, wordpress/tests/wp-stub.php)
//     and a stand-in for GitHub: nothing newer, Android's OK for installing asked for, a download that
//     doesn't match refused, then the newer version downloaded, confirmed in Android's dialog,
//     installed, and the app opened again by itself;
//   - opening the app: it checks for updates, then goes straight to the studio;
//   - a show with the screen off for 60 s: pieces keep reaching the station.
// Uninstalls the app afterwards and leaves no Do Not Disturb set by hand (a schedule that was on, like
// Bedtime, is paused for 30 minutes from its notification and comes back by itself). About 6 minutes.
//   bash tools/build-android.sh 0.7.0 test && bash tools/build-android.sh 0.7.1 test
//   node mobile/tests/android_test.mjs [old] [new] [base url]
// Needs the phone unlocked; SW_ADB is adb's path (default D:/Android/platform-tools/adb.exe), SW_PHP
// php.exe. It goes live on the station twice (about 30 s and 70 s) with whatever the phone's mic hears.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, existsSync, mkdtempSync } from "node:fs";
import { createServer } from "node:http";
import { createHash } from "node:crypto";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const OLD = process.argv[2] || "0.7.0", NEW = process.argv[3] || "0.7.1";
const base = (process.argv[4] || "http://localhost:8080/stationwagon-app").replace(/\/$/, "");
if (!base.startsWith("http://localhost:")) { console.error("Laragon only: node mobile/tests/android_test.mjs 0.7.0 0.7.1 http://localhost:8080/stationwagon-app"); process.exit(1); }
const PKG = "za.co.controll.stationwagon.studio";
const php = process.env.SW_PHP || "php";
const adbPath = process.env.SW_ADB || "D:/Android/platform-tools/adb.exe";
const adbRaw = (...args) => execFileSync(adbPath, args, { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
const adb = (...args) => adbRaw("shell", ...args);
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "../../app/tests/browser_helper.php"), ...args], { encoding: "utf8" }));
const apk = (v) => join(here, "../dist/test", `Stationwagon-Studio_${v}.apk`);
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

for (const v of [OLD, NEW]) if (!existsSync(apk(v))) { console.error(`Missing ${apk(v)}: bash tools/build-android.sh ${v} test`); process.exit(1); }
if (!adbRaw("devices").split("\n").slice(1).some((l) => /\tdevice$/.test(l.trim()) || /\tdevice\r?$/.test(l))) { console.error("No phone: plug it in over USB with USB debugging on."); process.exit(2); }
if (adb("dumpsys", "window").includes("isKeyguardShowing=true")) { console.log("The phone is locked: unlock it and run this again."); process.exit(2); }
const newApk = readFileSync(apk(NEW));
const versionOnPhone = () => (adb("dumpsys", "package", PKG).match(/versionName=(\S+)/) || [])[1] || null;
const XIAOMI = /^xiaomi$/i.test(adb("getprop", "ro.product.manufacturer").trim());
const zen = () => adb("settings", "get", "global", "zen_mode").trim();
const onAirRunning = () => { const s = adb("dumpsys", "activity", "services", PKG); return s.includes("OnAirService") && s.includes("isForeground=true"); };
const pid = () => { try { return adb("pidof", PKG).trim().split(/\s+/)[0] || ""; } catch { return ""; } };
const zenBefore = zen();

// ---- a stand-in for GitHub: the private repository's releases ----------------------------------
const TOKEN = "test-token-" + Math.random().toString(36).slice(2);
const gh = { mode: "same", files: 0, tokenAtFileHost: 0 };
const sha = createHash("sha256").update(newApk).digest("hex");
const github = createServer((req, res) => {
  const u = new URL(req.url, "http://x"), auth = req.headers.authorization || "";
  const send = (code, body, headers = {}) => { res.writeHead(code, headers); res.end(body); };
  if (u.pathname === "/repos/test/stationwagon/releases") {
    if (auth !== `Bearer ${TOKEN}`) return send(401, "{}");
    const at = (min) => new Date(Date.now() - min * 60000).toISOString();
    const list = [
      { tag_name: "v0.6.0", published_at: at(60), assets: [{ id: 11, name: "Stationwagon-Studio_0.6.0_x64-setup.exe", size: 5 }] },
      { tag_name: "android-v" + OLD, published_at: at(30), body: "First", assets: [{ id: 21, name: `Stationwagon-Studio_${OLD}.apk`, size: 5, digest: "sha256:00" }] },
    ];
    if (gh.mode !== "same") list.unshift({ tag_name: "android-v" + NEW, published_at: at(1), body: "Test notes",
      assets: [{ id: 22, name: `Stationwagon-Studio_${NEW}.apk`, size: newApk.length, digest: "sha256:" + (gh.mode === "tampered" ? "0".repeat(64) : sha) }] });
    return send(200, JSON.stringify(list), { "Content-Type": "application/json" });
  }
  const a = u.pathname.match(/^\/repos\/test\/stationwagon\/releases\/assets\/(\d+)$/);
  if (a) {
    if (auth !== `Bearer ${TOKEN}` || req.headers.accept !== "application/octet-stream") return send(404, "{}");
    return send(302, "", { Location: `http://127.0.0.1:${github.address().port}/files/${a[1]}?X-Amz-Signature=short-lived` });
  }
  const f = u.pathname.match(/^\/files\/(\d+)$/);
  if (f) {
    gh.files++;
    if (auth) { gh.tokenAtFileHost++; return send(400, "Only one auth mechanism allowed"); }
    if (f[1] === "22") return send(200, newApk, { "Content-Type": "application/octet-stream", "Content-Length": newApk.length });
    return send(404, "");
  }
  send(404, "");
});
await new Promise((ok) => github.listen(0, "127.0.0.1", ok));

// ---- the plugin, in PHP's own web server --------------------------------------------------------------
const tmp = mkdtempSync(join(tmpdir(), "sw-wp-"));
const store = join(tmp, "options.json");
const release = (mode) => { gh.mode = mode; writeFileSync(store, JSON.stringify({ options: { stationwagon_updates_token: TOKEN, stationwagon_updates_repo: "test/stationwagon" } })); };
release("same");
const wpPort = 18600 + Math.floor(Math.random() * 300);
const wp = spawn(php, ["-S", `127.0.0.1:${wpPort}`, join(here, "../../wordpress/tests/wp-stub.php")],
  { env: { ...process.env, SW_WP_STORE: store, SW_TEST_GITHUB: `http://127.0.0.1:${github.address().port}` }, stdio: "ignore" });
const WP = `http://localhost:${wpPort}/wp-json/stationwagon/v1/android`;
for (let i = 0; i < 50; i++) { try { await fetch(WP + "/latest-release"); break; } catch { await sleep(100); } }
for (const p of [8080, wpPort, github.address().port]) adbRaw("reverse", `tcp:${p}`, `tcp:${p}`);

// ---- the app's WebView over USB (test builds may be inspected) ----------------------------------------
const START = /^https:\/\/stationwagon\.app\/(index\.html)?(#.*)?$/;
const STATION = new RegExp("^" + base.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "/");
const STUDIO = new RegExp("^" + base.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "/studio");
const STUDIO_OR_SIGNIN = new RegExp("^" + base.replace(/[.*+?^${}()|[\]\\]/g, "\\$&") + "/(studio|signin\\?next=studio)");
const CDP = 9300 + Math.floor(Math.random() * 100);
const forward = () => { const p = pid(); if (p) adbRaw("forward", `tcp:${CDP}`, `localabstract:webview_devtools_remote_${p}`); return p; };
const connect = async (url) => {
  const ws = new WebSocket(url);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  let id = 0; const pending = new Map();
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  ws.onclose = () => { for (const [, ok] of pending) ok({ timeout: true }); pending.clear(); };
  const call = (method, params = {}, ms = 8000) => new Promise((ok) => {
    if (ws.readyState !== 1) return ok({ timeout: true });
    const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params }));
    setTimeout(() => { if (pending.has(i)) { pending.delete(i); ok({ timeout: true }); } }, ms);
  });
  return { ws, call };
};
let page = null;
/** The app's page whose address matches; reconnects after the app starts its page again. */
async function attach(match, seconds = 20) {
  try { page && page.ws.close(); } catch { /* gone */ }
  page = null;
  for (let i = 0; i < seconds * 4; i++) {
    forward();
    const list = await fetch(`http://localhost:${CDP}/json`).then((r) => r.json()).catch(() => []);
    const t = list.find((p) => p.type === "page" && match.test(p.url));
    if (t) {
      page = await connect(t.webSocketDebuggerUrl).catch(() => null);
      // a page still loading may be replaced: only one that answers, loaded, counts
      if (!page || (await evaluate("document.readyState", 2000)) !== "complete") { try { page && page.ws.close(); } catch { /* */ } page = null; await sleep(250); continue; }
      const ua = (await evaluate("navigator.userAgent")) || "";
      if (!ua.includes("StationwagonTest")) await page.call("Network.setUserAgentOverride", { userAgent: ua + " StationwagonTest" });
      // the listed address may be one a redirect was already leaving: the loaded page's own counts
      const href = await evaluate("location.href", 2000);
      if (typeof href === "string" && !match.test(href)) { try { page.ws.close(); } catch { /* */ } page = null; await sleep(250); continue; }
      return typeof href === "string" ? href : t.url;
    }
    await sleep(250);
  }
  return null;
}
const evaluate = async (expression, ms) => {
  if (!page) return null;
  const r = await page.call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true }, ms);
  if (process.env.SW_DEBUG && (r.timeout || r.result?.exceptionDetails)) console.log("    [evaluate] " + expression.slice(0, 60) + " -> " + JSON.stringify(r).slice(0, 300));
  return r.timeout || r.result?.exceptionDetails ? null : r.result?.result?.value;
};
/** A call to the app's own part from the page: { ok, value } or { error }. */
const native = (method, args = {}) => evaluate(`(async () => {
  for (let i = 0; i < 50 && !window.Capacitor; i++) await new Promise((r) => setTimeout(r, 100));
  if (!window.Capacitor) return { error: "no bridge on this page" };
  return Capacitor.Plugins.StationApp.${method}(${JSON.stringify(args)}).then((value) => ({ ok: true, value }), (e) => ({ error: e.message, code: e.code }));
})()`, 20000);
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);
const shown = (id) => evaluate(`!!document.getElementById(${JSON.stringify(id)}) && !document.getElementById(${JSON.stringify(id)}).hidden`);
const clickText = (sel, label) => evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(sel)})].find((x) => x.textContent.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);
const go = async (url) => { await page.call("Page.navigate", { url }); for (let i = 0; i < 60; i++) { await sleep(250); if ((await evaluate("document.readyState", 1000)) === "complete") return; } };
const state = () => evaluate("window.SwStudio && SwStudio.state()", 3000);
async function until(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state(); if (s && fn(s)) return s; await sleep(250); }
  return null;
}
async function waitFor(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const v = await fn(); if (v) return v; await sleep(250); }
  return null;
}
/** The app's open pages (only one should be left after it switches pages). */
const pages = async () => (await fetch(`http://localhost:${CDP}/json`).then((r) => r.json()).catch(() => [])).filter((p) => p.type === "page");
const start = (extras = []) => adb("am", "start", "-n", `${PKG}/.MainActivity`, ...extras);
/** Taps the button with this text (or description) on the phone's screen (Android's own dialogs). */
function tapText(re, pkg = null) {
  adb("uiautomator", "dump", "/sdcard/sw-ui.xml");
  const xml = adb("cat", "/sdcard/sw-ui.xml");
  for (const m of xml.matchAll(/<node [^>]*?text="([^"]*)"[^>]*?package="([^"]*)"[^>]*?content-desc="([^"]*)"[^>]*?bounds="\[(\d+),(\d+)\]\[(\d+),(\d+)\]"/g)) {
    const [, text, from, desc, x1, y1, x2, y2] = m;
    if (pkg && from !== pkg) continue;
    if (re.test(text) || re.test(desc)) { adb("input", "tap", String((+x1 + +x2) >> 1), String((+y1 + +y2) >> 1)); return text || desc; }
  }
  return null;
}
/**
 * Do Not Disturb off for the test. A schedule that's on (Bedtime) can't be paused over USB, only the
 * way a person does it: its notification's "Pause for 30 mins" (it comes back by itself), else the
 * Do Not Disturb tile in quick settings (the user's OK for the test, 2026-10-23).
 */
async function dndOff() {
  adb("cmd", "notification", "set_dnd", "off");
  await sleep(500);
  if (zen() === "0") return true;
  adb("cmd", "statusbar", "expand-settings");
  await sleep(1500);
  let tapped = tapText(/^Pause for 30 min/i);
  if (!tapped && tapText(/^Expand$/, "com.google.android.apps.wellbeing")) { await sleep(800); tapped = tapText(/^Pause for 30 min/i); }
  if (!tapped) { adb("cmd", "statusbar", "expand-settings"); await sleep(1500); tapped = tapText(/^Do not disturb/i); }
  await sleep(1500);
  adb("cmd", "statusbar", "collapse");
  return zen() === "0" || `still ${zen()} (tapped: ${tapped})`;
}

/** The screen's nodes (Android's own screens), with their text, class, switch state and bounds. */
function screenNodes() {
  adb("uiautomator", "dump", "/sdcard/sw-ui.xml");
  return [...adb("cat", "/sdcard/sw-ui.xml").matchAll(/<node [^>]*?text="([^"]*)"[^>]*?class="([^"]*)"[^>]*?checked="(\w+)"[^>]*?bounds="\[(\d+),(\d+)\]\[(\d+),(\d+)\]"/g)]
    .map(([, text, cls, checked, x1, y1, x2, y2]) => ({ text, cls, checked: checked === "true", x1: +x1, y1: +y1, x2: +x2, y2: +y2 }));
}
/** In a list of apps on Android's own screens: scrolls from the top to the app's name; returns the screen's nodes and that name's node. */
async function findApp(label = "Stationwagon Studio") {
  for (let i = 0; i < 6; i++) adb("input", "swipe", "540", "900", "540", "1900", "150");
  await sleep(700);
  let last = "";
  for (let i = 0; i < 25; i++) {
    const nodes = screenNodes();
    const name = nodes.find((n) => n.text === label && n.y2 < 2000);
    if (name) return { nodes, name };
    const seen = nodes.map((n) => n.text).join("|");
    if (seen === last) return null; // the end of the list
    last = seen;
    adb("input", "swipe", "540", "1700", "540", "900", "400");
    await sleep(700);
  }
  return null;
}
/**
 * Xiaomi's Autostart screen is open: switches the app on there, the way a person does. The list has
 * two groups (allowed first), so each look starts from the top. Returns "on" once its switch reads on.
 */
async function autostartOn() {
  for (let tries = 0; tries < 3; tries++) {
    const f = await findApp();
    const row = f && f.nodes.find((n) => n.cls.endsWith("Switch") && n.x2 - n.x1 > 500 && n.y1 <= f.name.y1 && n.y2 >= f.name.y2);
    if (!row) return "not found";
    if (row.checked) return "on";
    adb("input", "tap", String((row.x1 + row.x2) >> 1), String((row.y1 + row.y2) >> 1));
    await sleep(1000);
  }
  return "still off";
}
/** Android's "Display over other apps" list is open: the app's page, its switch on. Returns "on" once it reads on. */
async function overlayOn() {
  const f = await findApp();
  if (!f) return "not in the list";
  adb("input", "tap", "540", String((f.name.y1 + f.name.y2) >> 1));
  await sleep(1500);
  for (let tries = 0; tries < 3; tries++) {
    const sw = screenNodes().find((n) => n.cls.endsWith("Switch"));
    if (!sw) return "no switch";
    if (sw.checked) return "on";
    adb("input", "tap", String((sw.x1 + sw.x2) >> 1), String((sw.y1 + sw.y2) >> 1));
    await sleep(1000);
  }
  return "still off";
}
/** The texts on the phone's screen (Android's own sheets and dialogs). */
const screenTexts = () => screenNodes().map((n) => n.text).filter(Boolean);
/**
 * Android's Credential Manager sheet (Google Password Manager) whose title matches: its texts once it
 * shows (waiting up to seconds), else null. dismiss: closes it with Back (only if it showed).
 */
async function credentialSheet(title, seconds, dismiss = false) {
  const t = await waitFor(() => { const x = screenTexts(); return x.some((s) => title.test(s)) && x.join(" | "); }, seconds);
  // Back until it has gone (the sheet takes a moment to close)
  for (let i = 0; t && dismiss && i < 3 && screenTexts().some((x) => title.test(x)); i++) { adb("input", "keyevent", "KEYCODE_BACK"); await sleep(1200); }
  return t;
}
/** Back from Android's screens to the app (a page or two). */
async function backToApp() {
  for (let i = 0; i < 3 && !/topResumedActivity.*stationwagon/.test(adb("dumpsys", "activity", "activities")); i++) { adb("input", "keyevent", "KEYCODE_BACK"); await sleep(800); }
}

/** adb install; phones that ask before a USB install (Xiaomi's "Install via USB") get their Install tapped. */
async function installApk(file, tries = 3) {
  const p = spawn(adbPath, ["install", "-r", file], { stdio: ["ignore", "pipe", "pipe"] });
  let out = "", done = false;
  p.stdout.on("data", (d) => (out += d)); p.stderr.on("data", (d) => (out += d));
  const exit = new Promise((ok) => p.on("close", (code) => { done = true; ok(code); }));
  await sleep(1500);
  while (!done) {
    try { tapText(/^Install$/); } catch { /* the screen changed under the dump */ }
    await sleep(500);
  }
  if (await exit === 0) return;
  if (tries > 1 && /USER_RESTRICTED/.test(out)) return installApk(file, tries - 1);
  throw new Error("adb install: " + out.trim());
}

/** Signs the DJ in on the station's page and opens the studio. */
async function signIn(dj) {
  await go(`${base}/signin`);
  await credentialSheet(/^(Use your|Choose a) saved password/, 3, true);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(dj.email)}; f.password.value = ${JSON.stringify(dj.password)}; f.submit(); })()`);
  for (let i = 0; i < 40 && !((await evaluate("location.pathname", 1000)) || "").endsWith("/manage"); i++) await sleep(250);
  await go(`${base}/studio`);
}
async function liveAndSending() {
  await evaluate("document.getElementById('stGo').click()");
  return until((x) => x.live && x.live.sending, 30);
}
async function endShow() {
  await evaluate("document.getElementById('stGo').click()");
  await clickText("#stBannerActs button", "End show");
  return until((x) => !x.live, 25);
}

let dj = null, minBefore = null, liveId = null;
try {
  console.log(`Installing ${OLD} (test build)`);
  try { adbRaw("uninstall", PKG); } catch { /* not installed */ }
  await installApk(apk(OLD));
  check(`${OLD} is installed`, versionOnPhone() === OLD, versionOnPhone());
  for (const p of ["android.permission.RECORD_AUDIO", "android.permission.POST_NOTIFICATIONS"]) { try { adb("pm", "grant", PKG, p); } catch { /* older Android */ } }
  adb("cmd", "notification", "disallow_dnd", PKG);
  const off = await dndOff();
  check("Do Not Disturb is off for the test" + (zenBefore !== "0" ? " (a schedule was on: paused)" : ""), off === true, off === true ? "" : off);
  start(["--es", "sw_endpoint", `${WP}/latest-release`]);

  console.log("The start page");
  let url = await attach(START);
  check("the app opens on its own start page (no station yet)", !!url && await waitFor(() => shown("page"), 10), url);
  const info = await native("info");
  check("  it knows its version, and that it's the start page", info && info.ok && info.value.version === OLD && !info.value.studio && info.value.test, JSON.stringify(info));
  check("  Do Not Disturb isn't allowed yet, and the page offers to ask Android", (await text("#dndState")).includes("not allowed") && await shown("dndBtn"), await text("#dndState"));
  check("  opening again after an update isn't allowed yet, and the page offers Display over other apps", (await text("#reopenState")).includes("not allowed") && await shown("overlayBtn"), await text("#reopenState"));
  await evaluate("document.getElementById('overlayBtn').click()");
  let on = await waitFor(() => /topResumedActivity.*com\.android\.settings/.test(adb("dumpsys", "activity", "activities")), 10) ? await overlayOn() : "Android's screen didn't open";
  await backToApp();
  url = await attach(START);
  check("  Allow display over other apps: Android's list, the app's switch on, Back: allowed", on === "on" && !!url && (await waitFor(async () => (await text("#reopenState")).endsWith(": allowed"), 10)) !== null, `${on} · ${await text("#reopenState")}`);
  check(`  ${XIAOMI ? "a Xiaomi phone: the page also offers Allow Autostart" : "not a Xiaomi phone: no Autostart step"}`, (await shown("autoBtn")) === XIAOMI);
  if (XIAOMI) {
    await evaluate("document.getElementById('autoBtn').click()");
    on = await waitFor(() => /topResumedActivity.*com\.miui\.securitycenter/.test(adb("dumpsys", "activity", "activities")), 10) ? await autostartOn() : "Xiaomi's screen didn't open";
    await backToApp();
    url = await attach(START);
    check("  Allow Autostart opens Xiaomi's Autostart screen; switched on there, Back comes back to the page", on === "on" && !!url && await waitFor(() => shown("page"), 10), on);
  }
  await evaluate(`(() => { document.getElementById("station").value = "not an address!"; document.getElementById("form").requestSubmit(); })()`);
  check("a wrong address is refused", (await waitFor(async () => (await text("#msg")).includes("doesn’t look like a web address") && await text("#msg"), 5)) !== null, await text("#msg"));
  let r = await native("checkUpdate");
  check("Check for updates: nothing newer", r && r.ok && !r.value.version, JSON.stringify(r));
  await evaluate(`(() => { document.getElementById("station").value = ${JSON.stringify(base + "/studio")}; document.getElementById("form").requestSubmit(); })()`);
  url = await attach(new RegExp("^" + base.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&")), 20);
  check("Save and open: the studio page opens in the app (a page ending in /studio saved as the station's address)", !!url, url);
  const open = await waitFor(async () => { const p = await pages(); return p.length === 1 && p; }, 10);
  check("  and the start page is closed (one page left)", !!open, (await pages()).map((p) => p.url).join(" "));
  r = await waitFor(async () => { const x = await native("info"); return x && x.ok && x; }, 10);
  check("  the station's own page has the app's parts", r && r.ok && r.value.studio && r.value.station === base + "/", JSON.stringify(r));
  r = await native("saveStation", { station: "https://evil.example.com" });
  check("  but may not change the station's address", r && r.error === "Only the app’s own page can do that.", JSON.stringify(r));
  r = await native("installUpdate");
  check("  nor install updates", r && r.error === "Only the app’s own page can do that.", JSON.stringify(r));

  console.log("The studio");
  minBefore = helper("podcast-min", "5").before;
  dj = helper("dj", "block");
  await signIn(dj);
  let s = await until((x) => x.slot, 15);
  check("the DJ signs in and the studio opens, in the DJ's block", !!s && s.slot.may, s && s.slot.title);
  check("  with App settings, and no 'Get the Android app' link", await shown("stDroidSet") && !(await shown("stGetAndroid")));
  await clickText("#stBannerActs button", "Switch on the studio");
  s = await until((x) => x.on, 15);
  check("switching on starts sound and the phone's microphone", !!s, s ? "" : await text("#stBannerText"));
  let b = await waitFor(async () => { const t = await text("#stBannerText"); return t.includes("Do Not Disturb") && t; }, 5);
  check("  and asks to allow Do Not Disturb (not allowed yet)", !!b && b.includes("tap Allow") && (await evaluate("[...document.querySelectorAll('#stBannerActs button')].map((x) => x.textContent).join('|')")).includes("Allow Do Not Disturb"), b);
  adb("cmd", "notification", "allow_dnd", PKG);
  await evaluate("document.dispatchEvent(new Event('visibilitychange'))");
  b = await waitFor(async () => { const t = await text("#stBannerText"); return t.includes("puts it back") && t; }, 5);
  check("  once allowed: Do Not Disturb turns on when you go live, and back when you end", !!b, b || await text("#stBannerText"));
  s = await until((x) => x.mic !== null && x.mic > -100, 8);
  check("  the mic meter reads the room", !!s, s && s.mic.toFixed(1) + " dB");

  console.log("On air");
  check("Do Not Disturb is off before the show", zen() === "0", zen());
  s = await liveAndSending();
  liveId = s && s.live.public_id;
  check("Go live goes on air from the phone", !!s, s ? liveId : await text("#stBannerText"));
  check("  Do Not Disturb is on: alarms only (zen_mode 3)", await waitFor(() => zen() === "3", 5) !== null, zen());
  check("  the 'On air' service runs in the foreground", await waitFor(() => onAirRunning(), 5) !== null);
  check("  with its notification", adb("dumpsys", "notification", "--noredact").includes("You’re on air"));
  adb("input", "keyevent", "KEYCODE_BACK");
  b = await waitFor(async () => { const t = await text("#stBannerText"); return t.startsWith("Close the app?") && t; }, 5);
  check("Back while on air asks first, instead of leaving", !!b && !!pid(), b);
  await clickText("#stBannerActs button", "Keep going");
  await evaluate("document.getElementById('stDroidSet').click()");
  check("App settings while on air: refused", (await text("#stBannerText")).startsWith("End your show before"), await text("#stBannerText"));
  s = await until((x) => x.sent >= 2, 30);
  check("pieces go out every 6 s", !!s && s.errors === 0, s ? `${s.sent} sent, ${s.errors} errors` : "");
  s = await endShow();
  const ended = liveId ? helper("live-info", liveId) : null;
  check("End show hands back to the AutoDJ", !!s && ended && !ended.open && ended.closed_reason === "ended by DJ", ended && ended.closed_reason);
  check("  Do Not Disturb is put back (off)", await waitFor(() => zen() === "0", 5) !== null, zen());
  check("  and the 'On air' service stops", await waitFor(() => !onAirRunning(), 5) !== null);
  liveId = null;
  let up = await waitFor(async () => { const t = await text("#stBannerText"); return t.startsWith("Your show’s recording is uploaded") && t; }, 40);
  check("  the show's recording uploads from the phone", !!up, up || await text("#stBannerText"));
  adb("input", "keyevent", "KEYCODE_BACK");
  check("Back off air closes the app", await waitFor(() => !/mResumedActivity.*stationwagon|topResumedActivity.*stationwagon/.test(adb("dumpsys", "activity", "activities")), 8) !== null);

  console.log("Offline");
  start(["--es", "sw_station", "http://localhost:9/stationwagon-app"]);
  url = await attach(/offline\.html|^https:\/\/stationwagon\.app\//, 20);
  await sleep(2000); url = await attach(/offline\.html/, 15);
  check("a station that can't be reached shows the app's offline page", !!url && (await text("h1")) === "The station can’t be reached", url);
  await evaluate("document.getElementById('settings').click()");
  url = await attach(/^https:\/\/stationwagon\.app\/(index\.html)?$/, 15);
  check("  'Change the station' opens the start page", !!url && await waitFor(() => shown("page"), 10), url);
  await evaluate(`(() => { document.getElementById("station").value = ${JSON.stringify(base)}; document.getElementById("form").requestSubmit(); })()`);
  url = await attach(new RegExp("^" + base.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&") + "/studio"), 20);
  check("  and the right address opens the studio again, still signed in (the app keeps the sign-in over its own restarts)", !!url, url);
  await waitFor(() => shown("stDroidSet"), 10); // the studio sets its buttons up just after the page loads
  await evaluate("document.getElementById('stDroidSet').click()");
  url = await attach(START, 15);
  check("App settings (off air) opens the start page, without checking for updates first", !!url && await waitFor(() => shown("page"), 10) && (await text("#dndState")).includes("allowed") && !(await text("#dndState")).includes("not"), await text("#dndState"));

  console.log("Updates");
  r = await (await fetch(`${WP}/latest-release?current_version=${OLD}`)).status;
  check("the plugin: nothing newer than the newest Android release (a newer Windows one doesn't count)", r === 204, r);
  release("tampered");
  const m = await (await fetch(`${WP}/latest-release?current_version=${OLD}`)).json().catch(() => null);
  check("the plugin: a newer Android release, with its size and SHA-256, downloaded through this server", m && m.version === NEW && m.size === newApk.length && m.url === `${WP}/apk` && !JSON.stringify(m).includes(TOKEN), JSON.stringify(m));
  check("  and nothing newer for the newest version", (await fetch(`${WP}/latest-release?current_version=${NEW}`)).status === 204);
  // a fresh install hasn't Android's OK for installing (changing it stops the app, so it isn't reset here)
  r = await native("installUpdate");
  check("Install without Android's OK for installing: Android's settings screen opens to ask", r && r.code === "NEEDS_PERMISSION", JSON.stringify(r));
  await sleep(1500);
  const settingsShown = adb("dumpsys", "activity", "activities").match(/topResumedActivity.*/)?.[0] || "";
  check("  (that screen is showing)", /settings/i.test(settingsShown), settingsShown.trim());
  adb("input", "keyevent", "KEYCODE_BACK");
  adb("appops", "set", PKG, "REQUEST_INSTALL_PACKAGES", "allow");
  adb("am", "force-stop", PKG);
  const files0 = gh.files;
  start();
  url = await attach(STUDIO, 40);
  check("opening the app with a download that doesn't match the release: refused, and the studio opens as it is, still signed in", !!url && gh.files > files0 && versionOnPhone() === OLD, `${url} · ${versionOnPhone()}`);
  await waitFor(() => shown("stDroidSet"), 10);
  await evaluate("document.getElementById('stDroidSet').click()");
  url = await attach(START, 15);
  r = await native("installUpdate");
  check("  Install on the start page says why", r && /doesn’t match/.test(r.error || ""), JSON.stringify(r));
  release("newer");
  const before = pid();
  await evaluate("window.__progress = 0; Capacitor.Plugins.StationApp.addListener('updateProgress', (p) => { window.__progress = p.got / p.total; })");
  await evaluate("document.getElementById('updBtn').click()");
  const confirm = await waitFor(() => tapText(/^(Update|Install)$/i), 30);
  check("Check for updates downloads it and Android asks to confirm the update (the first one from the app)", !!confirm, confirm);
  // Android may show a second step (a scan, then "Continue"): answer it too while waiting
  const newVersion = await waitFor(() => versionOnPhone() === NEW ? NEW : (tapText(/^(Update|Install|Continue( updating| installation)?)$/i), false), 90);
  check(`  it installs: the phone has ${NEW}`, newVersion === NEW, versionOnPhone());
  check("  and the token never reached the file host", gh.tokenAtFileHost === 0 && gh.files >= 2, `${gh.files} downloads`);
  const after = await waitFor(() => { const p = pid(); return p && p !== before && p; }, 45);
  url = after && await attach(STUDIO, 30);
  check("  the app opens again by itself, as the new version, and goes to the studio, still signed in", !!after && !!url, `${before} -> ${after || "-"} · ${url}`);

  console.log("Opening the app, and the kept sign-in");
  const reopen = async (extras = []) => { adb("am", "force-stop", PKG); start(extras); return attach(STUDIO_OR_SIGNIN, 30); };
  url = await reopen();
  check("opening the app checks for updates (nothing newer), then goes straight to the studio, still signed in", !!url && STUDIO.test(url), url || `${pid() || "not running"}: ${(await pages()).map((p) => p.url).join(" ")} · ${(adb("dumpsys", "activity", "activities").match(/topResumedActivity=\S+ \S+ \S+/) || [""])[0]}`);
  r = await waitFor(async () => { const x = await native("info"); return x && x.ok && x; }, 10);
  check(`  as ${NEW}`, r && r.ok && r.value.version === NEW, JSON.stringify(r && r.value));
  url = await reopen(["--el", "sw_signin_age_ms", String(11 * 3600 * 1000)]);
  check("  closed 11 hours ago: still signed in", !!url && STUDIO.test(url), url);
  url = await reopen(["--el", "sw_signin_age_ms", String(13 * 3600 * 1000)]);
  check("  closed over 12 hours ago: the studio asks to sign in again", !!url && /signin\?next=studio/.test(url), url);
  await signIn(dj);
  await waitFor(() => evaluate(`!!document.querySelector('form[action$="signout"]')`, 1000), 15);
  await evaluate(`document.querySelector('form[action$="signout"]').submit()`);
  await waitFor(async () => !STUDIO.test((await evaluate("location.href", 1000)) || ""), 15);
  await sleep(1000);
  url = await reopen();
  check("  signed out, then closed: the studio asks to sign in again", !!url && /signin\?next=studio/.test(url), url);
  console.log("Remembering the password");
  const phoneDj = helper("dj", "phone");
  await page.call("Network.clearBrowserCookies");
  await go(`${base}/signin`);
  await credentialSheet(/^(Use your|Choose a) saved password/, 4, true); // one saved in an earlier run: not this time
  const send = (email, password) => evaluate(`(() => { const f = document.querySelector("main form"); f.email.value = ${JSON.stringify(email)}; f.password.value = ${JSON.stringify(password)}; f.querySelector("button[type=submit]").click(); })()`);
  await send(phoneDj.email, "not the password");
  const wrong = await waitFor(async () => { const t = await text(".alert"); return t && t; }, 15);
  check("a wrong password in the app: the sign-in page says so (not the offline page), and nothing is offered for saving",
    !!wrong && wrong.includes("don’t match") && !(await credentialSheet(/^(Save|Update) password/, 3, true)), wrong || await evaluate("location.href", 1000));
  await credentialSheet(/^(Use your|Choose a) saved password/, 4, true);
  await send(phoneDj.email, phoneDj.password);
  let sheet = await credentialSheet(/^(Save|Update) password/, 15);
  check("a DJ signs in: Android offers to save it in Google Password Manager", !!sheet && sheet.includes(phoneDj.email), sheet || "no offer");
  const saved = sheet && tapText(/^(Continue|Save|Update)$/);
  check("  Continue saves it", !!saved && await waitFor(() => !/(Save|Update) password/.test(screenTexts().join("|")), 10) !== null, saved || "");
  await page.call("Network.clearBrowserCookies"); // signed out: the next sign-in
  await go(`${base}/signin`);
  sheet = await credentialSheet(/^(Use your|Choose a) saved password/, 15);
  check("the next time, Android offers the saved sign-in", !!sheet && sheet.includes(phoneDj.email), sheet || "no offer");
  tapText(/^Continue$/) || tapText({ test: (t) => t === phoneDj.email }); // one saved sign-in, or a list of them
  const filled = await waitFor(async () => { const v = await evaluate("[document.querySelector('#email').value, document.querySelector('#password').value]"); return v && v[0] === phoneDj.email && v[1] === phoneDj.password; }, 10);
  check("  and fills the form in with it", filled !== null);
  await evaluate("document.getElementById('remember').click()");
  await evaluate("document.querySelector('main button[type=submit]').click()");
  url = await waitFor(async () => { const h = (await evaluate("location.href", 1000)) || ""; return h.endsWith("/manage") && h; }, 15);
  check("  Sign in, with Keep me signed in, signs in with it", !!url, url || await evaluate("location.href", 1000));
  await credentialSheet(/password/i, 4, true); // the same sign-in again: nothing new to save
  url = await reopen(["--el", "sw_signin_age_ms", String(13 * 3600 * 1000)]);
  check("Keep me signed in: closed over 12 hours ago, the studio still opens signed in (30 days)", !!url && STUDIO.test(url), url);
  helper("studio-cleanup", String(phoneDj.id));
  await signIn(dj);

  console.log("The screen off");
  s = await until((x) => x.slot, 15);
  await clickText("#stBannerActs button", "Switch on the studio");
  s = await until((x) => x.on, 15);
  adb("cmd", "notification", "set_dnd", "priority"); // the DJ's own Do Not Disturb, already on
  s = s && await liveAndSending();
  liveId = s && s.live.public_id;
  check("live again, with the DJ's own Do Not Disturb already on (priority)", !!s, s ? liveId : await text("#stBannerText"));
  check("  the app leaves it as it is", zen() === "1", zen());
  const count = () => (liveId ? helper("live-info", liveId).pieces.length : 0);
  const n0 = count();
  adb("input", "keyevent", "KEYCODE_SLEEP");
  await sleep(3000);
  check("  the screen is off", /mWakefulness=(Asleep|Dozing)/.test(adb("dumpsys", "power")), (adb("dumpsys", "power").match(/mWakefulness=\w+/) || [""])[0]);
  await sleep(60000);
  const n1 = count();
  const asleep = /mWakefulness=(Asleep|Dozing)/.test(adb("dumpsys", "power"));
  check("60 s with the screen off: the pieces keep reaching the station (about 10)", n1 - n0 >= 9 && asleep, `${n1 - n0} pieces, ${(adb("dumpsys", "power").match(/mWakefulness=\w+/) || [""])[0]}`);
  s = await state();
  check("  with no send errors", !!s && s.errors === 0 && s.live, s ? `${s.sent} sent, ${s.errors} errors` : "no answer");
  adb("input", "keyevent", "KEYCODE_WAKEUP");
  s = await endShow();
  check("End show (screen on again): the DJ's own Do Not Disturb stays on, the service stops", !!s && await waitFor(() => zen() === "1" && !onAirRunning(), 8) !== null, zen());
  adb("cmd", "notification", "set_dnd", "off");
  liveId = null;
} finally {
  try { if (liveId) { await endShow(); } } catch { /* gone */ }
  try { page && page.ws.close(); } catch { /* closed */ }
  try { adb("input", "keyevent", "KEYCODE_WAKEUP"); } catch { /* */ }
  try { adbRaw("uninstall", PKG); } catch { /* */ }
  // nothing set by hand left behind (a paused schedule comes back by itself)
  try { adb("cmd", "notification", "set_dnd", "off"); adb("cmd", "notification", "disallow_dnd", PKG); } catch { /* */ }
  try { adb("rm", "-f", "/sdcard/sw-ui.xml"); } catch { /* */ }
  try { if (minBefore !== null) helper("podcast-min", String(minBefore)); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (dj) helper("studio-cleanup", String(dj.id), String(dj.block || 0)); } catch (e) { console.log("cleanup: " + e.message); }
  try { adbRaw("forward", "--remove", `tcp:${CDP}`); } catch { /* */ }
  wp.kill(); github.close();
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
