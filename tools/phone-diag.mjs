// Watch the player on the Android phone over USB: opens the station in the phone's Chrome, presses
// Listen, opens Diagnostics and prints its rows every 15 s, then the full report. For troubleshooting
// dropouts and a slow screen on a real phone (the panel's numbers, measured there).
//   node tools/phone-diag.mjs <base url> [seconds, default 240] [report file]
// Needs the phone unlocked, with USB debugging on. SW_ADB is adb's path (default D:/Android/platform-tools/adb.exe).
import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";

const [baseArg, secsArg = "240", out = ""] = process.argv.slice(2);
if (!baseArg) { console.error("Usage: node tools/phone-diag.mjs <base url> [seconds] [report file]"); process.exit(1); }
const base = baseArg.replace(/\/$/, "");
const adbPath = process.env.SW_ADB || "D:/Android/platform-tools/adb.exe";
// the phone's browser: Brave (the user's default, 2026-10-30); SW_PHONE_BROWSER=com.android.chrome for Chrome
const BROWSER = process.env.SW_PHONE_BROWSER || "com.brave.browser";
const adb = (...args) => execFileSync(adbPath, ["shell", ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

if (adb("dumpsys", "window").includes("isKeyguardShowing=true")) { console.log("The phone is locked: unlock it and run this again."); process.exit(2); }
adb("am", "start", "-a", "android.intent.action.VIEW", "-d", `${base}/`, "-p", BROWSER);
await sleep(3000);
const pid = adb("pidof", BROWSER).trim().split(/\s+/)[0];
const own = pid && adb("cat", "/proc/net/unix").includes(`@chrome_devtools_remote_${pid}`) ? `chrome_devtools_remote_${pid}` : "chrome_devtools_remote";
execFileSync(adbPath, ["forward", "tcp:9222", `localabstract:${own}`], { stdio: "ignore" });
const held = await fetch("http://localhost:9222/json/version").then((r) => r.json()).catch(() => ({}));
if (held["Android-Package"] !== BROWSER) { console.log(`Port 9222 leads to ${held["Android-Package"] || "nothing"}, not ${BROWSER}: open ${BROWSER} on the phone and run this again.`); process.exit(2); }
await sleep(1000);
// one tab of the station, in front (a tab in the background is paused by Chrome): close the others this left open
const pages = (await (await fetch("http://localhost:9222/json")).json()).filter((p) => p.type === "page");
const ours = pages.filter((p) => p.url.startsWith(base));
const page = ours[0] || pages[0];
for (const p of ours.slice(1)) await fetch(`http://localhost:9222/json/close/${p.id}`).catch(() => {});
await fetch(`http://localhost:9222/json/activate/${page.id}`).catch(() => {});
await sleep(1000);
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
let id = 0;
const pending = new Map();
ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
const call = (method, params = {}, ms = 8000) => new Promise((ok) => {
  const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params }));
  setTimeout(() => { if (pending.has(i)) { pending.delete(i); ok({ timeout: true }); } }, ms);
});
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  return r.timeout || r.result?.exceptionDetails ? null : r.result?.result?.value;
};

if (!(await evaluate("location.href.startsWith(" + JSON.stringify(base) + ")"))) { await call("Page.navigate", { url: `${base}/` }); await sleep(4000); }
await evaluate("location.reload()"); await sleep(4000); // the newest scripts
const ua = await evaluate("navigator.userAgent");
console.log(`Phone: ${ua}`);
await evaluate("document.getElementById('lpPlay').click()");
await evaluate("SwDiag.open(); document.getElementById('lpDiag').scrollIntoView()");
const end = Date.now() + Number(secsArg) * 1000;
while (Date.now() < end) {
  await sleep(15000);
  adb("input", "keyevent", "KEYCODE_WAKEUP"); // keep the screen on
  const r = await evaluate("SwDiag.report()");
  if (!r) { console.log("  (no answer from the page)"); continue; }
  const lines = r.split("\n");
  const t = Math.round((Number(secsArg) * 1000 - (end - Date.now())) / 1000);
  console.log(`--- ${t} s: ${lines.find((l) => /^(All good|Likely|Watch|Dropouts in|Press)/.test(l))}`);
  for (const l of lines.filter((x) => /^\[(good|warn|bad)\]/.test(x))) console.log("  " + l);
}
const report = await evaluate("SwDiag.report()");
if (out && report) { writeFileSync(out, report); console.log(`Report saved to ${out}`); }
else if (report) console.log(report.split("\n").filter((l) => /^Dropouts|^  20\d\d/.test(l)).join("\n"));
await evaluate("document.getElementById('lpStop').click()");
ws.close();
process.exit(0);
