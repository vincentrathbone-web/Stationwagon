// End-to-end test of the station's loudness target (round 41) in headless Edge or Chrome on
// Laragon: a throwaway manager uploads songs (each keeps its normalization in the database and in its
// MP3's ID3 tag), moves the Music library's loudness slider (it snaps to the marked levels while
// dragged, steps by half a dB with the keys), is asked whether to re-level the library or only new
// uploads, and re-levels: each song made again from its original with the mastering it was made
// with, at the new target. Song prep follows the target; with normalization off nothing is offered.
//   node app/tests/loudness_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH. Signs in a throwaway account: not for a public server.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/loudness_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- songs with some dynamics (chords that hit twice a second) ----------------------------------
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const RATE = 44100;
function music(title, seconds, level) {
  const n = seconds * RATE, l = new Int16Array(n), r = new Int16Array(n);
  for (let i = 0; i < n; i++) {
    const t = i / RATE, hit = Math.exp(-((t % 0.5) * 6));
    const v = level * (0.3 * hit * Math.sin(2 * Math.PI * 110 * t) + 0.12 * Math.sin(2 * Math.PI * 440 * t) + 0.06 * hit * Math.sin(2 * Math.PI * 3300 * t));
    l[i] = Math.round(32767 * v); r[i] = Math.round(32767 * v * 0.9);
  }
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, 192), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), r.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const text = Buffer.from("\u0003" + title, "utf8");
  const frame = Buffer.concat([Buffer.from("TIT2"), Buffer.from([0, 0, 0, text.length]), Buffer.from([0, 0]), text]);
  return Buffer.concat([Buffer.concat([Buffer.from("ID3"), Buffer.from([4, 0, 0, 0, 0, 0, frame.length]), frame]), ...out]);
}
const tag = Math.random().toString(16).slice(2, 8);
const dir = mkdtempSync(join(tmpdir(), "sw-loud-"));
const titleA = `Loudness Test ${tag}`, titleB = `Loudness Mastered ${tag}`;
const fileA = join(dir, "a.mp3"), fileB = join(dir, "b.mp3");
writeFileSync(fileA, music(titleA, 12, 0.5)); writeFileSync(fileB, music(titleB, 12, 0.3));
const media = join(here, "..", "storage", "media");
/** The STATIONWAGON_* TXXX values in a stored song's ID3 tag. */
const levelTag = (file) => {
  const head = readFileSync(join(media, file)).subarray(0, 4096).toString("latin1"), out = {};
  for (const m of head.matchAll(/STATIONWAGON_([A-Z_]+)\0([^\0]*?)(?=\0|TXXX|$)/g)) out[m[1]] = m[2];
  return out;
};

// ---- browser -----------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((b) => existsSync(b));
const profile = mkdtempSync(join(tmpdir(), "sw-loud-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--mute-audio",
  "--window-size=1280,1600", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map(), errors = [];
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const loaded = async () => { for (let i = 0; i < 100; i++) { if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; await sleep(100); } };
const go = async (url) => {
  await call("Page.navigate", { url });
  for (let i = 0; i < 100 && (await evaluate("location.href").catch(() => "")) !== url; i++) await sleep(100);
  await sleep(200); await loaded();
};
async function upload(files) {
  await go(`${base}/manage/library`);
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files });
  for (let i = 0; i < 900; i++) {
    const rows = await evaluate(`[...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, text: li.querySelector('.s').textContent }))`).catch(() => null);
    if (rows && rows.length === files.length && rows.every((r) => (r.cls === "ok" || r.cls === "bad") && !r.text.includes("keeping the original"))) {
      await sleep(1500); await loaded(); // the library reloads itself 0.9 s after an upload
      return rows;
    }
    await sleep(100);
  }
  return null;
}
const rl = () => evaluate("window.SwRelevel && SwRelevel.state()");
/** Set the slider the way a person does: a drag (pointer events, snapping) or the keyboard (no snap). */
const slide = (value, drag) => evaluate(`(() => { const e = document.getElementById('prepTarget');
  if (${drag}) e.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));
  e.value = ${JSON.stringify(String(value))}; e.dispatchEvent(new Event('input', { bubbles: true }));
  if (${drag}) e.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
  e.dispatchEvent(new Event('change', { bubbles: true })); return e.value; })()`);
async function relevel() {
  // the panel's button; the page reloads when every song is done (a song on air waits and is tried again)
  await evaluate("document.getElementById('relevelGo').click()");
  for (let i = 0; i < 2400; i++) {
    const s = await evaluate("({ href: location.href, running: !!(window.SwRelevel && SwRelevel.state().running), text: (document.getElementById('relevelText') || {}).textContent })").catch(() => null);
    if (s && !s.running && /Re-levelled/.test(s.text || "")) { await sleep(2500); await loaded(); return s.text; }
    await sleep(250);
  }
  return null;
}

let user = null, prepBefore = null;
try {
  prepBefore = helper("prep");
  helper("prep", "0", "1", "-16");
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    if (d.method === "Runtime.exceptionThrown") errors.push(d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text);
    if (d.method === "Runtime.consoleAPICalled" && d.params.type === "error") errors.push(d.params.args.map((a) => a.value || a.description).join(" "));
  };
  await call("Page.enable"); await call("DOM.enable"); await call("Runtime.enable");
  user = helper("manager");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(user.email)}; f.password.value = ${JSON.stringify(user.password)}; f.submit(); })()`);
  await sleep(600); await loaded();
  check("a throwaway manager signs in", (await evaluate("location.pathname")).endsWith("/manage"));

  console.log("Each song keeps its normalization");
  let rows = await upload([fileA]);
  let tA = helper("track", titleA);
  check("an upload is levelled to the station's target (-16 LUFS)", rows && rows[0].cls === "ok" && Number(tA.level_target) === -16 && Math.abs(Number(tA.loudness_lufs) + 16) < 0.1, rows && rows[0].text);
  check("  and keeps its loudness before levelling and the gain", tA.source_lufs !== null && Math.abs(Number(tA.source_lufs) + Number(tA.level_gain_db) - Number(tA.loudness_lufs)) < 0.05,
    `${tA.source_lufs} ${tA.level_gain_db >= 0 ? "+" : ""}${tA.level_gain_db} → ${tA.loudness_lufs}`);
  let tg = levelTag(tA.file);
  check("  in its MP3's tag too", tg.LEVEL_TARGET === "-16.0 LUFS" && tg.SOURCE_LUFS === Number(tA.source_lufs).toFixed(2) + " LUFS" && /^[+-]\d+\.\d\d dB$/.test(tg.LEVEL_GAIN || ""), JSON.stringify(tg));
  check("  the Levelled pill says it", (await evaluate(`[...document.querySelectorAll('.pill')].map((p) => p.title).find((t) => t.includes('target −16.0'))`) || "").includes("dB from"));

  console.log("The loudness slider");
  check("the slider shows the target, with four marked levels and a legend",
    (await evaluate("document.getElementById('prepTarget').value")) === "-16" && (await evaluate("document.querySelectorAll('.loudmark').length")) === 4
    && (await evaluate("document.getElementById('loudLegend').textContent")).includes("Spotify"));
  check("  nothing is offered while every song is at the target", !(await rl()).ask && !(await rl()).later);
  await slide(-13.5, true);
  check("a drag near a marked level snaps to it", (await evaluate("document.getElementById('prepTarget').value")) === "-14" && (await evaluate("document.getElementById('prepTargetOut').textContent")) === "−14.0");
  let s = null;
  for (let i = 0; i < 40 && !(s && s.ask); i++) { await sleep(150); s = await rl(); }
  check("  saved, it asks: re-level the library, or only new uploads", !!s && s.ask && s.target === -14 && helper("prep").target === -14 && s.songs.some((x) => x.title === titleA),
    await evaluate("document.getElementById('relevelText').textContent"));
  await evaluate("document.getElementById('relevelSkip').click()");
  s = await rl();
  check("Only new uploads: it leaves the songs, and a quiet line stays", !s.ask && s.later && (await evaluate("document.querySelector('#relevelLater span').textContent")).includes("another loudness than −14.0"));
  await slide(-14.5, false);
  await sleep(1200);
  check("the keys step past a mark by half a dB (no snap)", (await evaluate("document.getElementById('prepTarget').value")) === "-14.5" && helper("prep").target === -14.5);
  await slide(-14, false);
  await sleep(1200);
  await go(`${base}/manage/library`);
  s = await rl();
  check("after a reload the quiet line is still there", s.later && !s.ask && s.target === -14);

  console.log("Re-levelling");
  await evaluate("document.getElementById('relevelOpen').click()");
  check("Re-level opens the panel", (await rl()).ask && (await evaluate("document.getElementById('relevelGo').disabled")) === false);
  const beforeA = helper("track", titleA);
  const done = await relevel();
  tA = helper("track", titleA);
  check("the song is made again from its original at the new target", !!done && Number(tA.level_target) === -14 && Math.abs(Number(tA.loudness_lufs) + 14) < 0.1 && tA.file !== beforeA.file && tA.id === beforeA.id,
    `${done}; ${tA.loudness_lufs} LUFS`);
  check("  the same loudness before levelling, a gain 2 dB higher", Math.abs(Number(tA.source_lufs) - Number(beforeA.source_lufs)) < 0.05 && Math.abs(Number(tA.level_gain_db) - Number(beforeA.level_gain_db) - 2) < 0.05,
    `${beforeA.level_gain_db} → ${tA.level_gain_db} dB`);
  tg = levelTag(tA.file);
  check("  its tag says so", tg.LEVEL_TARGET === "-14.0 LUFS", JSON.stringify(tg));
  s = await rl();
  check("  and nothing is left to offer", !s.ask && !s.later && !s.songs.some((x) => x.title === titleA));

  console.log("A mastered song keeps its sound");
  helper("prep", "1", "1");
  rows = await upload([fileB]);
  let tB = helper("track", titleB);
  check("a song mastered with the built-in chain keeps its mastering ({})", rows && rows[0].cls === "ok" && Number(tB.mastered) === 1 && tB.preset === "Audition chain" && tB.preset_settings === "{}" && Number(tB.level_target) === -14,
    tB && `${tB.preset} ${tB.preset_settings} ${tB.level_target}`);
  await slide(-12, false);
  for (let i = 0; i < 40 && !(s && s.ask && s.target === -12); i++) { await sleep(150); s = await rl(); }
  const item = s.songs.find((x) => x.title === titleB);
  check("  re-levelling makes it again with that mastering", !!item && typeof item.master === "object" && Object.keys(item.master).length === 0 && item.preset === "Audition chain", JSON.stringify(item));
  const beforeB = helper("track", titleB);
  await relevel();
  tB = helper("track", titleB);
  check("  at the new target, still mastered the same way", Number(tB.level_target) === -12 && Math.abs(Number(tB.loudness_lufs) + 12) < 0.15 && Number(tB.mastered) === 1 && tB.preset_settings === "{}"
    && Math.abs(Number(tB.source_lufs) - Number(beforeB.source_lufs)) < 0.05, `${tB.loudness_lufs} LUFS, source ${beforeB.source_lufs} → ${tB.source_lufs}`);

  console.log("Elsewhere");
  await go(`${base}/manage/songprep?song=${tB.id}`);
  check("Song prep levels to the station's target", (await evaluate("JSON.parse(document.getElementById('songprepData').textContent).target")) === -12
    && (await evaluate("document.querySelector('.songprep .vhead p').textContent")).includes("−12.0 LUFS"));
  await go(`${base}/manage/library`);
  await evaluate("document.getElementById('prepLevel').click()");
  await slide(-15, false);
  for (let i = 0; i < 40 && helper("prep").target !== -15; i++) await sleep(150);
  s = await rl();
  check("with normalization off nothing is offered, and the slider is dimmed", helper("prep").level === false && !s.ask && !s.later && (await evaluate("document.getElementById('loud').hasAttribute('data-off')")));
  check("no errors in the page", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (ex) {
  const where = await evaluate("location.href").catch(() => "?");
  check("the test ran to the end", false, (ex.stack || String(ex)) + "\n  on: " + where + (errors.length ? "\n  page errors: " + errors.slice(0, 5).join(" | ") : ""));
} finally {
  if (prepBefore) try { helper("prep", prepBefore.master ? "1" : "0", prepBefore.level ? "1" : "0", String(prepBefore.target)); } catch { /* as it was */ }
  if (user) try { helper("cleanup", String(user.id), titleA, titleB); } catch { /* left */ }
  try { ws && ws.close(); } catch { /* closed */ }
  proc.kill();
  await sleep(300);
  for (const d of [dir, profile]) try { rmSync(d, { recursive: true, force: true }); } catch { /* still in use */ }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : `\nAll checks passed (${passed})`);
process.exit(failed ? 1 : 0);
