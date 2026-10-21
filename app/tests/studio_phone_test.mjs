// The live studio on a real Android phone (Chrome over USB) with the phone's own microphone: a
// throwaway DJ with a block signs in, switches the studio on (sound, mic, the MP3 encoder in its
// worker), goes live, sends pieces, and ends the show. About a minute. Laragon only: the phone
// opens http://localhost:8080 through adb reverse, which Chrome treats as secure (the mic needs that).
//   node app/tests/studio_phone_test.mjs http://localhost:8080/stationwagon-app
// Needs the phone unlocked with Chrome open, and
//   adb reverse tcp:8080 tcp:8080
//   adb forward tcp:9222 localabstract:chrome_devtools_remote
// SW_ADB is adb's path (default D:/Android/platform-tools/adb.exe); SW_PHP php.exe (for the helper).
// It goes live on the station for about 30 s with whatever the phone's mic hears.
import { execFileSync } from "node:child_process";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base.startsWith("http://localhost:")) { console.error("Usage: node app/tests/studio_phone_test.mjs http://localhost:8080/stationwagon-app"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const helper = (...args) => JSON.parse(execFileSync(process.env.SW_PHP || "php", [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
const adbPath = process.env.SW_ADB || "D:/Android/platform-tools/adb.exe";
const adb = (...args) => execFileSync(adbPath, ["shell", ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] });
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

if (adb("dumpsys", "window").includes("isKeyguardShowing=true")) {
  console.log("The phone is locked: unlock it, open Chrome, and run this again.");
  process.exit(2);
}
execFileSync(adbPath, ["reverse", "tcp:8080", "tcp:8080"], { stdio: "ignore" });
adb("monkey", "-p", "com.android.chrome", "-c", "android.intent.category.LAUNCHER", "1");
await sleep(2000);

// the browser connection grants the mic; the page connection drives the studio
const connect = async (url) => {
  const ws = new WebSocket(url);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  let id = 0; const pending = new Map();
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  const call = (method, params = {}, ms = 8000) => new Promise((ok) => {
    const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params }));
    setTimeout(() => { if (pending.has(i)) { pending.delete(i); ok({ timeout: true }); } }, ms);
  });
  return { ws, call };
};
const browser = await connect((await (await fetch("http://localhost:9222/json/version")).json()).webSocketDebuggerUrl);
const page = await connect((await (await fetch("http://localhost:9222/json")).json()).find((p) => p.type === "page").webSocketDebuggerUrl);
const evaluate = async (expression, ms) => {
  const r = await page.call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true }, ms);
  return r.timeout || r.result?.exceptionDetails ? null : r.result?.result?.value;
};
const go = async (url) => { await page.call("Page.navigate", { url }); for (let i = 0; i < 60; i++) { await sleep(250); if ((await evaluate("document.readyState", 1000)) === "complete") return; } };
const state = () => evaluate("window.SwStudio && SwStudio.state()", 3000);
async function until(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state(); if (s && fn(s)) return s; await sleep(250); }
  return null;
}
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);
const clickText = (sel, label) => evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(sel)})].find((x) => x.textContent.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);

let dj = null, liveId = null;
try {
  // the test mark keeps anything this run counts out of the station's stats (src/Agent.php)
  const ua = (await evaluate("navigator.userAgent")) || "";
  await page.call("Network.setUserAgentOverride", { userAgent: ua + " StationwagonTest" });
  const origin = new URL(base).origin;
  const granted = await browser.call("Browser.grantPermissions", { origin, permissions: ["audioCapture"] });

  console.log("Signing in on the phone");
  dj = helper("dj", "block");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(dj.email)}; f.password.value = ${JSON.stringify(dj.password)}; f.submit(); })()`);
  for (let i = 0; i < 40 && !((await evaluate("location.pathname", 1000)) || "").endsWith("/manage"); i++) await sleep(250);
  await go(`${base}/studio`);
  let s = await until((x) => x.slot, 15);
  check("the studio opens on the phone, in the DJ's block", !!s && s.slot.may, s && s.slot.title);
  const wide = await evaluate("document.documentElement.scrollWidth + ' / ' + innerWidth");
  check("  and fits the screen (no sideways scrolling)", !!wide && Number(wide.split(" / ")[0]) <= Number(wide.split(" / ")[1]), wide);

  console.log("Switching on");
  check("the mic may be used (granted for the test)", !granted.error && !granted.timeout, JSON.stringify(granted.error || ""));
  await clickText("#stBannerActs button", "Switch on the studio");
  s = await until((x) => x.on, 15);
  check("switching on starts sound and the phone's microphone", !!s, s ? "" : await text("#stBannerText"));
  check("  the MP3 encoder runs in its worker", !!s && s.encoder === "worker", s && String(s.encoder));
  check("  and lists the phone's microphone", ((await evaluate("document.getElementById('dev').options.length")) || 0) >= 1);
  s = await until((x) => x.mic !== null && x.mic > -100, 8);
  check("  the mic meter reads the room", !!s, s && s.mic.toFixed(1) + " dB");

  console.log("Going live");
  await evaluate("document.getElementById('stGo').click()");
  s = await until((x) => x.live && x.live.sending, 30);
  liveId = s && s.live.public_id;
  check("Go live hands over at the next piece boundary and goes on air", !!s, s ? liveId : await text("#stBannerText"));
  adb("input", "keyevent", "KEYCODE_WAKEUP");
  s = await until((x) => x.sent >= 2, 30);
  check("pieces go out every 6 s from the phone", !!s && s.errors === 0, s ? `${s.sent} sent, ${s.errors} errors` : "");
  const info = liveId ? helper("live-info", liveId) : null;
  const durations = info ? info.pieces.map((p) => p.scanned) : [];
  check("  the station has them, whole MP3 pieces of about 6 s", durations.length >= 2 && durations.slice(0, 2).every((d) => d && Math.abs(d - 6) < 0.1), durations.join(", "));

  console.log("Ending the show");
  await evaluate("document.getElementById('stGo').click()");
  await clickText("#stBannerActs button", "End show");
  s = await until((x) => !x.live, 25);
  const end = liveId ? helper("live-info", liveId) : null;
  check("End show sends the last audio and hands back to the AutoDJ", !!s && end && !end.open && end.closed_reason === "ended by DJ", end && `${end.closed_reason}, ${end.pieces.length} pieces`);
  liveId = null;
} finally {
  try { if (dj) helper("studio-cleanup", String(dj.id), String(dj.block || 0)); } catch (e) { console.log("cleanup: " + e.message); }
  try { page.ws.close(); browser.ws.close(); } catch { /* closed */ }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
