// The Ads screen in a real browser (headless Edge or Chrome), round 63: a throwaway manager uploads an ad
// (prepared and levelled in the browser like a song, with no details window), it is stored as an ad with
// no art or copyright and a plan of 5 plays a day, the plan is edited and saved, the ad-break settings
// are saved, the Music library doesn't list the ad, the phone screen has no sideways scroll, and Remove works.
//   node app/tests/ads_browser_test.mjs http://localhost:8080/stationwagon-app
// SW_PHP is the PHP for the helper (Laragon only: it makes and removes the throwaway manager and ads).
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, existsSync, writeFileSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("usage: node ads_browser_test.mjs <app url>"); process.exit(2); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args.map(String)], { encoding: "utf8" }));

let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  if (ok) passed++; else failed++;
  console.log(`  [${ok ? "PASS" : "FAIL"}] ${label}${detail ? " · " + detail : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// a 14 s tone with a little silence at both ends, as an MP3 with a title tag
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
const profile = mkdtempSync(join(tmpdir(), "sw-ads-profile-"));
const dir = mkdtempSync(join(tmpdir(), "sw-ads-files-"));
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
const adTitle = `Web Test Ad ${tag}`;
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

  console.log("The Ads screen");
  await A.go(`${base}/manage/ads`);
  check("it opens with the ad-break settings, the drop zone and the list, and the rail marks Ads", await A.evaluate(`!!document.getElementById("adsEvery") && !!document.getElementById("drop") && !!document.getElementById("adsTable")
    && document.querySelector('.rail a[aria-current="page"]').textContent.trim() === "Ads"`));
  check("  the manager uses the whole window", await A.evaluate(`document.querySelector("main.page").getBoundingClientRect().width > 1440`));

  console.log("Uploading an ad");
  const file = join(dir, "ad.mp3");
  writeFileSync(file, tone(adTitle));
  const doc = await A.call("DOM.getDocument", {});
  const input = await A.call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await A.call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files: [file] });
  check("the browser prepares it and the station takes it", await A.until(`[...document.querySelectorAll("#uplist li")].some((li) => li.className === "ok")`, 60000));
  await sleep(2500); // the page reloads itself after an upload
  await A.until(`document.readyState === "complete" && !!document.getElementById("adsTable")`);
  const info = helper("ads-info", adTitle);
  check("it is stored as an ad: no art, artist, album or copyright, levelled, with a plan of 5 plays a day all day", !!info && info.kind === "ad" && info.art === null && info.artist === null && info.album === null && info.rights === null
    && info.loudness_lufs !== null && Number(info.daily_target) === 5 && Number(info.days) === 127 && info.window_start === null, JSON.stringify(info && { kind: info.kind, lufs: info.loudness_lufs, target: info.daily_target }));
  check("  its original is kept (so Song prep can make it again)", !!info && info.original === true);
  check("  the list shows it as running, every day, all day", await A.evaluate(`(() => { const r = document.getElementById("a${info && info.id}"); return !!r && /Every day · all day/.test(r.textContent) && /Running/.test(r.textContent) && r.querySelector(".tt").textContent === ${JSON.stringify(adTitle)}; })()`));

  console.log("Its plan");
  await A.go(`${base}/manage/ads?edit=${info.id}`);
  check("Edit opens the plan under the ad", await A.evaluate(`!!document.getElementById("adEdit") && document.querySelectorAll('#adEdit input[name="days[]"]').length === 7`));
  await A.evaluate(`(() => { const f = document.getElementById("adEdit");
    f.daily_target.value = "12"; f.advertiser.value = "Acme Ltd"; f.window_start.value = "06:00"; f.window_end.value = "18:00"; f.start_date.value = "2027-01-01"; f.end_date.value = "2027-12-31";
    f.querySelectorAll('input[name="days[]"]').forEach((c, i) => { c.checked = i < 5; }); f.notes.value = "Weekday mornings"; f.requestSubmit(); })()`);
  check("Save keeps it and the list says it in words", await A.until(`/Mon–Fri · 06:00–18:00/.test(document.getElementById("adsBody").textContent) && !document.getElementById("adEdit")`));
  const saved = helper("ads-info", adTitle);
  check("  on the server: 12 a day, Mon to Fri, 06:00 to 18:00, the dates and the advertiser", Number(saved.daily_target) === 12 && Number(saved.days) === 31 && saved.window_start === "06:00" && saved.window_end === "18:00"
    && saved.start_date === "2027-01-01" && saved.end_date === "2027-12-31" && saved.advertiser === "Acme Ltd" && saved.notes === "Weekday mornings");
  await A.go(`${base}/manage/ads?edit=${info.id}`);
  await A.evaluate(`(() => { const f = document.getElementById("adEdit"); f.noValidate = true; f.daily_target.value = "999"; f.requestSubmit(); })()`);
  check("a wrong number is said in words and the form is still there", await A.until(`/Plays per day: choose a whole number from 0 to 500/.test(document.body.textContent) && !!document.getElementById("adEdit")`) && Number(helper("ads-info", adTitle).daily_target) === 12);

  console.log("The ad-break settings");
  const before = await A.evaluate(`({ every: document.getElementById("adsEvery").value, per: document.getElementById("adsPer").value, gap: document.getElementById("adsGap").value, on: document.getElementById("adsOn").checked })`);
  await A.go(`${base}/manage/ads`);
  await A.evaluate(`(() => { document.getElementById("adsEvery").value = "4"; document.getElementById("adsPer").value = "2"; document.getElementById("adsGap").value = "15"; document.getElementById("adsEvery").form.requestSubmit(); })()`);
  check("they are saved and said back", await A.until(`document.getElementById("adsEvery").value === "4" && /After every 4 songs the AutoDJ plays up to 2/.test(document.body.textContent)`));
  await A.evaluate(`(() => { document.getElementById("adsEvery").value = ${JSON.stringify(before.every)}; document.getElementById("adsPer").value = ${JSON.stringify(before.per)}; document.getElementById("adsGap").value = ${JSON.stringify(before.gap)}; document.getElementById("adsOn").checked = ${before.on}; document.getElementById("adsEvery").form.requestSubmit(); })()`);
  check("  (put back as they were)", await A.until(`document.getElementById("adsEvery").value === ${JSON.stringify(before.every)} && document.getElementById("adsOn").checked === ${before.on}`));

  console.log("Apart from the songs");
  await A.go(`${base}/manage/library?q=${encodeURIComponent(adTitle)}`);
  check("the Music library doesn't list the ad", await A.evaluate(`!document.getElementById("t${info.id}") && /No songs match/.test(document.body.textContent)`));

  console.log("On a phone");
  await A.call("Emulation.setDeviceMetricsOverride", { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  await A.go(`${base}/manage/ads?edit=${info.id}`);
  check("the Ads screen, with its form open, has no sideways scroll", await A.evaluate(`document.documentElement.scrollWidth <= innerWidth + 1`));
  await A.call("Emulation.clearDeviceMetricsOverride");

  console.log("Removing it");
  await A.go(`${base}/manage/ads?confirm=${info.id}`);
  check("Remove asks first", await A.evaluate(`/Remove this ad\\?/.test(document.getElementById("a${info.id}").textContent)`));
  await A.evaluate(`[...document.querySelectorAll('#a${info.id} button')].find((b) => /Yes/.test(b.textContent)).click(); 1`);
  check("  and Yes takes it away", await A.until(`!document.getElementById("a${info.id}")`) && helper("ads-info", adTitle) === null);
} catch (e) {
  failed++;
  console.log("  [FAIL] " + (e.stack || e.message));
} finally {
  try { helper("ads-clean"); } catch { /* nothing left */ }
  try { if (manager) helper("cleanup", String(manager.id)); } catch { /* already gone */ }
  try { proc.kill(); } catch { /* gone */ }
  try { rmSync(dir, { recursive: true, force: true }); } catch { /* still locked */ }
  console.log(failed ? `${failed} check(s) failed, ${passed} passed` : `All checks passed (${passed})`);
}
process.exit(failed ? 1 : 0);
