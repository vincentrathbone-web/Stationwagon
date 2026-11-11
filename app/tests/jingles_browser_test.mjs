// The Jingles screen in a real browser (headless Edge or Chrome), round 70: a throwaway manager uploads a jingle
// (prepared and levelled in the browser like a song, with no details window), it is stored as a jingle with
// no art or copyright and switched on, it is edited and saved, the jingle settings are saved, the Music library
// doesn't list it, the phone screen has no sideways scroll, and Remove works.
//   node app/tests/jingles_browser_test.mjs http://localhost:8080/stationwagon-app
// SW_PHP is the PHP for the helper (Laragon only: it makes and removes the throwaway manager and jingles).
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, existsSync, writeFileSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("usage: node jingles_browser_test.mjs <app url>"); process.exit(2); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args.map(String)], { encoding: "utf8" }));

let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  if (ok) passed++; else failed++;
  console.log(`  [${ok ? "PASS" : "FAIL"}] ${label}${detail ? " · " + detail : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// a 14 s tone with a little silence at both ends, as an MP3 with a title tag (long enough for song prep to measure)
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
function tone(title) {
  const RATE = 44100, parts = [[0, 1], [0.3, 12], [0, 1]];
  const n = parts.reduce((s, [, sec]) => s + Math.round(sec * RATE), 0);
  const l = new Int16Array(n);
  let i = 0;
  for (const [amp, sec] of parts) for (let k = 0, m = Math.round(sec * RATE); k < m; k++, i++) l[i] = Math.round(amp * 32767 * Math.sin(2 * Math.PI * 440 * i / RATE));
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, 128), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), l.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const text = Buffer.from("\u0003" + title, "utf8");
  const frame = Buffer.concat([Buffer.from("TIT2"), Buffer.from([0, 0, 0, text.length]), Buffer.from([0, 0]), text]);
  return Buffer.concat([Buffer.concat([Buffer.from("ID3"), Buffer.from([4, 0, 0, 0, 0, 0, frame.length]), frame]), ...out]);
}

const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((p) => existsSync(p));
const profile = mkdtempSync(join(tmpdir(), "sw-jingles-profile-"));
const dir = mkdtempSync(join(tmpdir(), "sw-jingles-files-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
  "--window-size=1280,900", "about:blank"], { stdio: "ignore" });

async function tab(wsUrl) {
  const ws = new WebSocket(wsUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  let id = 0; const pending = new Map();
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
  const evaluate = async (expression) => {
    const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
    if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
    return r.result?.result?.value;
  };
  const go = async (url) => {
    await call("Page.navigate", { url });
    for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") break; }
    await sleep(300);
  };
  const until = async (expr, ms = 8000) => { for (let t = 0; t < ms; t += 100) { if (await evaluate(expr).catch(() => false)) return true; await sleep(100); } return false; };
  await call("Page.enable"); await call("Runtime.enable"); await call("DOM.enable");
  return { call, evaluate, go, until };
}

let manager = null;
const tag = Math.random().toString(16).slice(2, 8);
const jTitle = `Web Test Jingle ${tag}`;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const first = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  const A = await tab(first.webSocketDebuggerUrl);
  manager = helper("manager");
  await A.call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  await A.go(`${base}/signin`);
  await A.evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(manager.email)}; f.password.value = ${JSON.stringify(manager.password)}; f.submit(); })()`);
  await sleep(1500);
  await A.call("Emulation.setDeviceMetricsOverride", { width: 1500, height: 900, deviceScaleFactor: 1, mobile: false });

  console.log("The Jingles screen");
  await A.go(`${base}/manage/jingles`);
  check("it opens with the jingle settings, the drop zone and the list, and the rail marks Jingles", await A.evaluate(`!!document.getElementById("jinglesEvery") && !!document.getElementById("drop") && !!document.getElementById("jinglesTable")
    && document.querySelector('.rail a[aria-current="page"]').textContent.trim() === "Jingles"`));
  check("  the manager uses the whole window", await A.evaluate(`document.querySelector("main.page").getBoundingClientRect().width > 1440`));

  console.log("Uploading a jingle");
  const file = join(dir, "jingle.mp3");
  writeFileSync(file, tone(jTitle));
  const doc = await A.call("DOM.getDocument", {});
  const input = await A.call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await A.call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files: [file] });
  check("the browser prepares it and the station takes it", await A.until(`[...document.querySelectorAll("#uplist li")].some((li) => li.className === "ok")`, 60000));
  await sleep(2500); // the page reloads itself after an upload
  await A.until(`document.readyState === "complete" && !!document.getElementById("jinglesTable")`);
  const info = helper("jingles-info", jTitle);
  check("it is stored as a jingle: no art, artist, album or copyright, levelled, and on", !!info && info.kind === "jingle" && info.art === null && info.artist === null && info.album === null && info.rights === null
    && info.loudness_lufs !== null && Number(info.active) === 1, JSON.stringify(info && { kind: info.kind, lufs: info.loudness_lufs, active: info.active }));
  check("  its original is kept (so Song prep can make it again)", !!info && info.original === true);
  check("  the list shows it as on", await A.evaluate(`(() => { const r = document.getElementById("j${info && info.id}"); return !!r && /On/.test(r.querySelector(".c-status").textContent) && r.querySelector(".tt").textContent === ${JSON.stringify(jTitle)}; })()`));

  console.log("Editing it");
  await A.go(`${base}/manage/jingles?edit=${info.id}`);
  check("Edit opens the title, on and notes under the jingle", await A.evaluate(`!!document.getElementById("jingleEdit") && !!document.querySelector('#jingleEdit input[name="active"]')`));
  await A.evaluate(`(() => { const f = document.getElementById("jingleEdit"); f.title.value = ${JSON.stringify(jTitle + " (edited)")}; f.active.checked = false; f.notes.value = "Morning ID"; f.requestSubmit(); })()`);
  check("Save keeps it and the list shows it off, with its note", await A.until(`/Off/.test(document.getElementById("jinglesBody").textContent) && /Morning ID/.test(document.getElementById("jinglesBody").textContent) && !document.getElementById("jingleEdit")`));
  const saved = helper("jingles-info", jTitle + " (edited)");
  check("  on the server: the new title, off, the note", !!saved && saved.title === jTitle + " (edited)" && Number(saved.active) === 0 && saved.notes === "Morning ID");
  await A.go(`${base}/manage/jingles?edit=${info.id}`);
  await A.evaluate(`(() => { const f = document.getElementById("jingleEdit"); f.noValidate = true; f.title.value = ""; f.requestSubmit(); })()`);
  check("a missing title is said in words and the form is still there", await A.until(`/A jingle needs a title/.test(document.body.textContent) && !!document.getElementById("jingleEdit")`));

  console.log("The jingle settings");
  await A.go(`${base}/manage/jingles`);
  const before = await A.evaluate(`({ every: document.getElementById("jinglesEvery").value, on: document.getElementById("jinglesOn").checked })`);
  await A.evaluate(`(() => { document.getElementById("jinglesEvery").value = "7"; document.getElementById("jinglesEvery").form.requestSubmit(); })()`);
  check("they are saved and said back", await A.until(`document.getElementById("jinglesEvery").value === "7" && /After every 7 songs the AutoDJ plays a jingle/.test(document.body.textContent)`));
  await A.evaluate(`(() => { document.getElementById("jinglesEvery").value = ${JSON.stringify(before.every)}; document.getElementById("jinglesOn").checked = ${before.on}; document.getElementById("jinglesEvery").form.requestSubmit(); })()`);
  check("  (put back as they were)", await A.until(`document.getElementById("jinglesEvery").value === ${JSON.stringify(before.every)} && document.getElementById("jinglesOn").checked === ${before.on}`));

  console.log("Apart from the songs and the ads");
  await A.go(`${base}/manage/library?q=${encodeURIComponent(jTitle)}`);
  check("the Music library doesn't list the jingle", await A.evaluate(`!document.getElementById("t${info.id}") && /No songs match/.test(document.body.textContent)`));
  await A.go(`${base}/manage/ads`);
  check("  nor do the Ads", await A.evaluate(`!document.getElementById("a${info.id}")`));

  console.log("On a phone");
  await A.call("Emulation.setDeviceMetricsOverride", { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  await A.go(`${base}/manage/jingles?edit=${info.id}`);
  check("the Jingles screen, with its form open, has no sideways scroll", await A.evaluate(`document.documentElement.scrollWidth <= innerWidth + 1`));
  await A.call("Emulation.clearDeviceMetricsOverride");

  console.log("Removing it");
  await A.go(`${base}/manage/jingles?confirm=${info.id}`);
  check("Remove asks first", await A.evaluate(`/Remove this jingle\\?/.test(document.getElementById("j${info.id}").textContent)`));
  await A.evaluate(`[...document.querySelectorAll('#j${info.id} button')].find((b) => /Yes/.test(b.textContent)).click(); 1`);
  // a jingle that is on air, or aired in the last two minutes, waits (like a song: listeners are a little behind)
  const gone = await A.until(`!document.getElementById("j${info.id}") || /is on air/.test(document.body.textContent)`);
  const removed = helper("jingles-info", jTitle + " (edited)") === null;
  check("  and Yes takes it away (or says it is on air and to try again, as for a song)", gone && (removed || await A.evaluate(`/is on air/.test(document.body.textContent)`)), removed ? "removed" : "on air: kept for now");
} catch (e) {
  failed++;
  console.log("  [FAIL] " + (e.stack || e.message));
} finally {
  try { helper("jingles-clean"); } catch { /* nothing left */ }
  try { if (manager) helper("cleanup", String(manager.id)); } catch { /* already gone */ }
  try { proc.kill(); } catch { /* gone */ }
  try { rmSync(dir, { recursive: true, force: true }); } catch { /* still locked */ }
  console.log(failed ? `${failed} check(s) failed, ${passed} passed` : `All checks passed (${passed})`);
}
process.exit(failed ? 1 : 0);
