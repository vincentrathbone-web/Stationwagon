// Upload songs to a station the way a manager does: signs in, drops the files on the Music library
// page in headless Edge (or Chrome), so each song gets the browser's song prep (mastered, levelled,
// trimmed) with the station's own switches, and prints each row's result.
//   node tools/upload-songs.mjs <base url> <sign-in file> <mp3s…>
// The sign-in file holds "Email: …" and "Password: …" lines (a gitignored *.local.txt).
import { spawn, spawnSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const [baseArg, signinFile, ...files] = process.argv.slice(2);
if (!baseArg || !signinFile || files.length === 0) {
  console.error("Usage: node tools/upload-songs.mjs <base url> <sign-in file> <mp3s…>");
  process.exit(1);
}
const base = baseArg.replace(/\/$/, "");
const field = (name) => (readFileSync(signinFile, "utf8").match(new RegExp(`^${name}:\\s*(.+)$`, "m")) || [])[1]?.trim();
const email = field("Email"), password = field("Password");
if (!email || !password) { console.error("The sign-in file needs Email: and Password: lines."); process.exit(1); }
const paths = files.map((f) => resolve(f));
for (const p of paths) { if (!existsSync(p)) { console.error(`No such file: ${p}`); process.exit(1); } }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((b) => existsSync(b));
const profile = mkdtempSync(join(tmpdir(), "sw-upload-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--mute-audio", "about:blank"], { stdio: "ignore" });
let ws, id = 0, failed = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const loaded = async () => { for (let i = 0; i < 300; i++) { if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; await sleep(100); } };
const go = async (url) => { await call("Page.navigate", { url }); await sleep(300); await loaded(); };

try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.enable");
  await call("DOM.enable");

  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('form'); f.email.value = ${JSON.stringify(email)}; f.password.value = ${JSON.stringify(password)}; f.submit(); })()`);
  await sleep(800); await loaded();
  if (!(await evaluate("location.pathname")).includes("/manage")) throw new Error("Sign-in failed: " + (await evaluate("location.href")));
  console.log(`Signed in to ${base} as ${email}`);

  // one file at a time: each row's prep runs in the page, and the page reloads after a batch
  for (const p of paths) {
    await go(`${base}/manage/library`);
    const doc = await call("DOM.getDocument", {});
    const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
    await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files: [p] });
    let row = null;
    const started = Date.now();
    for (let i = 0; i < 6000 && !row; i++) {
      const r = await evaluate(`(() => { const li = document.querySelector('#uplist li'); return li ? { cls: li.className, text: li.querySelector('.s').textContent } : null; })()`).catch(() => null);
      if (r && (r.cls === "ok" || r.cls === "bad") && !r.text.includes("keeping the original")) row = r; // after the original is sent too (round 40)
      else await sleep(100);
    }
    const secs = ((Date.now() - started) / 1000).toFixed(0);
    if (!row || row.cls !== "ok") failed++;
    console.log(`  ${row?.cls === "ok" ? "ok  " : "FAIL"} ${p.split(/[\\/]/).pop()}: ${row ? row.text : "no answer after 10 min"} (${secs} s)`);
  }
} catch (ex) {
  failed++;
  console.error(ex.message);
} finally {
  ws?.close();
  // msedge.exe hands over to the real browser and exits, so proc.kill() alone leaves Edge running:
  // close it over its debug port, then end anything still using this profile
  try {
    const b = new WebSocket((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    await new Promise((ok, bad) => { b.onopen = ok; b.onerror = bad; });
    b.send(JSON.stringify({ id: 1, method: "Browser.close" }));
    await sleep(1000);
  } catch { /* already closed */ }
  proc.kill();
  spawnSync("powershell", ["-NoProfile", "-Command", `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${profile}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: "ignore" });
  await sleep(500);
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still locked */ }
}
process.exit(failed ? 1 : 0);
