// Full-page screenshots of the app with headless Edge (or Chrome), signed in when asked, so
// screens can be checked without a visible browser.
//   node tools/shot.mjs <out dir> <base url> <width> [--signin <email> <password file>] <path> [<path> ...]
//   node tools/shot.mjs shots http://localhost:8080/stationwagon-app 1280 --signin admin@stationwagon.test pw.txt manage manage/library
// The password is read from a file (first line) so it never sits on the command line.
// Files are named after the path and width, e.g. manage_library-1280.png.
import { spawn, spawnSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";

const args = process.argv.slice(2);
const [out, base, width] = args.splice(0, 3);
let email = null, password = null;
if (args[0] === "--signin") {
  email = args[1];
  password = readFileSync(args[2], "utf8").split(/\r?\n/)[0].trim();
  args.splice(0, 3);
}
const paths = args;
const browsers = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"];
const exe = browsers.find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
if (!exe || !out || !base || !width || !paths.length) {
  console.error("Usage: node tools/shot.mjs <out dir> <base url> <width> [--signin <email> <password file>] <path> ...");
  process.exit(1);
}
mkdirSync(out, { recursive: true });

const profile = mkdtempSync(join(tmpdir(), "sw-shot-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "--hide-scrollbars", "about:blank"], { stdio: "ignore" });

let version = null;
for (let i = 0; i < 50 && !version; i++) {
  await new Promise((r) => setTimeout(r, 200));
  try { version = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { /* starting */ }
}
const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
let id = 0;
const pending = new Map();
const events = [];
ws.onmessage = (m) => {
  const d = JSON.parse(m.data);
  if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } else if (d.method) { events.push(d); }
};
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const loaded = async () => {
  for (let i = 0; i < 100; i++) {
    const r = await call("Runtime.evaluate", { expression: "document.readyState", returnByValue: true });
    if (r.result?.result?.value === "complete") return;
    await new Promise((x) => setTimeout(x, 100));
  }
};
const go = async (url) => { await call("Page.navigate", { url }); await new Promise((x) => setTimeout(x, 300)); await loaded(); await new Promise((x) => setTimeout(x, 400)); };
const evaluate = async (expression) => (await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true })).result?.result?.value;

try {
  await call("Page.enable");
  await call("Emulation.setDeviceMetricsOverride", { width: Number(width), height: 900, deviceScaleFactor: 1, mobile: Number(width) < 600 });
  if (email) {
    await go(`${base}/signin`);
    const ok = await evaluate(`(() => { const f = document.querySelector('form'); f.email.value = ${JSON.stringify(email)}; f.password.value = ${JSON.stringify(password)}; f.submit(); return true; })()`);
    await new Promise((x) => setTimeout(x, 600));
    await loaded();
    const where = await evaluate("location.pathname");
    console.log(ok && !where.endsWith("/signin") ? `signed in (now at ${where})` : "SIGN-IN FAILED");
  }
  for (const p of paths) {
    await go(`${base}/${p}`);
    const h = await evaluate("Math.ceil(document.documentElement.scrollHeight)");
    await call("Emulation.setDeviceMetricsOverride", { width: Number(width), height: Math.min(h, 6000), deviceScaleFactor: 1, mobile: Number(width) < 600 });
    await new Promise((x) => setTimeout(x, 300));
    const shot = await call("Page.captureScreenshot", { format: "png", captureBeyondViewport: true });
    const file = join(out, `${(p || "home").replace(/[^a-z0-9]+/gi, "_")}-${width}.png`);
    writeFileSync(file, Buffer.from(shot.result.data, "base64"));
    const overflow = await evaluate("document.documentElement.scrollWidth > innerWidth + 1");
    console.log(`${file}  ${h}px tall${overflow ? "  (WIDER THAN THE SCREEN)" : ""}`);
    await call("Emulation.setDeviceMetricsOverride", { width: Number(width), height: 900, deviceScaleFactor: 1, mobile: Number(width) < 600 });
  }
} finally {
  ws.close();
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
  await new Promise((x) => setTimeout(x, 500));
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still locked */ }
}
