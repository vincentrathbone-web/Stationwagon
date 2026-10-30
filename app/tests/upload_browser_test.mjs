// End-to-end test of song prep in a real browser (headless Edge or Chrome) on Laragon: a
// throwaway manager uploads generated MP3s on the Music library page, and the stored songs are
// checked (mastered, levelled, trimmed, tags kept, duplicates caught, fallbacks for silent and fake
// files), and the two switches (pre-processing, loudness normalization) are saved and obeyed.
//   node app/tests/upload_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH. Signs in a throwaway account: not for a public server.
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import vm from "node:vm";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/upload_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const near = (a, b, tol) => a !== null && a !== undefined && Math.abs(Number(a) - b) <= tol;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- Test files ----------------------------------------------------------------------------
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const RATE = 44100;
function mp3(parts, title, kbps = 128) {
  const n = parts.reduce((s, [, sec]) => s + Math.round(sec * RATE), 0);
  const l = new Int16Array(n), r = new Int16Array(n);
  let i = 0;
  for (const [amp, sec] of parts) for (let k = 0, m = Math.round(sec * RATE); k < m; k++, i++) {
    l[i] = Math.round(amp * 32767 * Math.sin(2 * Math.PI * 440 * i / RATE)); r[i] = l[i];
  }
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, kbps), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), r.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const text = Buffer.from("\u0003" + title, "utf8");
  const frame = Buffer.concat([Buffer.from("TIT2"), Buffer.from([0, 0, 0, text.length]), Buffer.from([0, 0]), text]);
  const head = Buffer.concat([Buffer.from("ID3"), Buffer.from([4, 0, 0, 0, 0, 0, frame.length]), frame]);
  return Buffer.concat([head, ...out]);
}
const tag = Math.random().toString(16).slice(2, 8);
const dir = mkdtempSync(join(tmpdir(), "sw-prep-browser-"));
const songTitle = `Prep Browser Test ${tag}`;
const silentTitle = `Prep Silent Test ${tag}`;
const plainTitle = `Prep Plain Test ${tag}`;
const song = join(dir, "prep song.mp3"), silent = join(dir, "silent.mp3"), fake = join(dir, "fake.mp3"), plain = join(dir, "plain.mp3");
writeFileSync(song, mp3([[0, 2], [0.4, 20], [0, 1.5]], songTitle));   // about -8 LUFS, silence both ends
writeFileSync(silent, mp3([[0, 6]], silentTitle));
writeFileSync(plain, mp3([[0, 1], [0.1, 15], [0, 1]], plainTitle));   // about -21 LUFS
writeFileSync(fake, Buffer.from("not audio at all ".repeat(400)));
const songSha1 = createHash("sha1").update(readFileSync(song)).digest("hex");

// ---- Browser -------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
const profile = mkdtempSync(join(tmpdir(), "sw-prep-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const loaded = async () => { for (let i = 0; i < 100; i++) { if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; await sleep(100); } };
const go = async (url) => { await call("Page.navigate", { url }); await sleep(300); await loaded(); };

/** Choose files in the drop zone and wait for every row's final status (before the page reloads). */
async function upload(files) {
  await go(`${base}/manage/library`);
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files });
  for (let i = 0; i < 600; i++) {
    const rows = await evaluate(`[...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, text: li.querySelector('.s').textContent }))`).catch(() => null);
    if (rows && rows.length === files.length && rows.every((r) => (r.cls === "ok" || r.cls === "bad") && !r.text.includes("keeping the original"))) {
      await sleep(1500); await loaded(); // the library reloads itself 0.9 s after an upload
      return rows;
    }
    await sleep(100);
  }
  return null;
}

let user = null, prepBefore = null;
try {
  prepBefore = helper("prep");
  helper("prep", "1", "1"); // both on (a new station has mastering off; this tries it on)
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.enable");
  await call("DOM.enable");

  user = helper("manager");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('form'); f.email.value = ${JSON.stringify(user.email)}; f.password.value = ${JSON.stringify(user.password)}; f.submit(); })()`);
  await sleep(600); await loaded();
  check("a throwaway manager signs in", (await evaluate("location.pathname")).endsWith("/manage"));

  console.log("Upload with song prep");
  await go(`${base}/manage/library`);
  const sw = await evaluate(`[document.getElementById('prepMaster')?.checked, document.getElementById('prepLevel')?.checked]`);
  check("the library shows both switches, on", sw[0] === true && sw[1] === true, JSON.stringify(sw));
  let rows = await upload([song]);
  check("the song is mastered, levelled and added", rows && rows[0].cls === "ok" && rows[0].text.startsWith("Added, mastered, levelled"), rows && rows[0].text);
  const t = helper("track", songTitle);
  check("it's marked as mastered", t && Number(t.mastered) === 1);
  check("  with the built-in preset, and the file the manager chose is kept as its original (round 40)", t && t.preset === "Audition chain" && rows[0].text.includes("original kept")
    && existsSync(join(here, "..", "storage", "originals", `${songSha1}.mp3`)), t && `${t.preset}; ${rows[0].text}`);
  check("the original title tag is kept", t !== null && t.title === songTitle);
  check("it's stored at -16 LUFS", t && near(t.loudness_lufs, -16, 0.05), t && t.loudness_lufs);
  check("as a new 192 kbps file", t && Number(t.bitrate_kbps) === 192 && Number(t.sample_rate) === 44100 && t.file !== `${songSha1}.mp3`, t && `${t.bitrate_kbps} kbps`);
  check("with the silence trimmed (23.5 s → about 20.1 s)", t && near(t.duration_s, 20.1, 0.15), t && `${t.duration_s} s`);
  check("known by the original file's fingerprint", t && t.sha1 === songSha1);
  const outro = t && t.outro ? JSON.parse(t.outro) : null;
  check("with its last seconds' loudness for silence detection", outro && outro.length === Math.floor(t.duration_s) && outro.every((v) => v >= -99 && v <= 0), outro && `${outro.length} values`);
  if (t) {
    const measured = await evaluate(`(async () => {
      const buf = await (await fetch(${JSON.stringify(`${base}/storage/media/${t.file}`)})).arrayBuffer();
      const a = await new OfflineAudioContext(2, 1, 44100).decodeAudioData(buf);
      return SwPrepCore.loudness([a.getChannelData(0), a.getChannelData(1)], 44100);
    })()`);
    check("the stored file really measures -16 LUFS", near(measured, -16, 0.3), measured && measured.toFixed(2));
  }
  await go(`${base}/manage/library`);
  check("the library shows it Mastered and Levelled", await evaluate(`(() => { const row = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent.includes(${JSON.stringify(songTitle)})); return !!row && row.textContent.includes('Mastered') && row.textContent.includes('Levelled'); })()`));
  rows = await upload([song]);
  check("the same song again is caught as a duplicate", rows && rows[0].cls === "ok" && rows[0].text === "Already there", rows && rows[0].text);

  console.log("Both switches off");
  await go(`${base}/manage/library`);
  await evaluate(`(() => { for (const id of ['prepMaster', 'prepLevel']) document.getElementById(id).click(); })()`);
  let saved = "";
  for (let i = 0; i < 50 && !saved.startsWith("Saved"); i++) { await sleep(100); saved = await evaluate(`document.getElementById('prepSaved').textContent`); }
  check("turning them off is saved straight away", saved.startsWith("Saved") && JSON.stringify(helper("prep")) === '{"master":false,"level":false}', `${saved}; ${JSON.stringify(helper("prep"))}`);
  await go(`${base}/manage/library`);
  check("  and they stay off on the next visit", JSON.stringify(await evaluate(`[document.getElementById('prepMaster').checked, document.getElementById('prepLevel').checked]`)) === "[false,false]");
  rows = await upload([plain]);
  check("a song goes up kept at its own level", rows && rows[0].cls === "ok" && rows[0].text.startsWith("Added, kept at"), rows && rows[0].text);
  const pl = helper("track", plainTitle);
  check("  not mastered and without a levelled loudness", pl && Number(pl.mastered) === 0 && pl.loudness_lufs === null, pl && JSON.stringify({ mastered: pl.mastered, lufs: pl.loudness_lufs }));
  check("  but still trimmed (17 s → about 15.1 s)", pl && near(pl.duration_s, 15.1, 0.15), pl && `${pl.duration_s} s`);
  if (pl) {
    const m = await evaluate(`(async () => {
      const buf = await (await fetch(${JSON.stringify(`${base}/storage/media/${pl.file}`)})).arrayBuffer();
      const a = await new OfflineAudioContext(2, 1, 44100).decodeAudioData(buf);
      return SwPrepCore.loudness([a.getChannelData(0), a.getChannelData(1)], 44100);
    })()`);
    check("  the stored file keeps its loudness (about −21.1 LUFS, as measured before upload)", near(m, -21.1, 0.4), m && m.toFixed(2));
  }
  helper("prep", "1", "1");

  console.log("Fallbacks");
  rows = await upload([silent, fake]);
  check("a silent file goes up as it is", rows && rows[0].cls === "ok" && rows[0].text.includes("as it is: it is silent"), rows && rows[0].text);
  const s = helper("track", silentTitle);
  check("  without a loudness", s !== null && s.loudness_lufs === null);
  check("a file that isn't audio is still refused by the server", rows && rows[1].cls === "bad" && rows[1].text.includes("not an MP3"), rows && rows[1].text);
} finally {
  if (prepBefore) helper("prep", prepBefore.master ? "1" : "0", prepBefore.level ? "1" : "0");
  if (user) {
    const r = helper("cleanup", String(user.id), songTitle, silentTitle, plainTitle);
    console.log(`Cleanup: throwaway manager and test songs removed${r.hidden ? ` (${r.hidden} on air, hidden)` : ""}`);
  }
  try { ws && ws.close(); } catch { /* closed */ }
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
  await sleep(500);
  for (const d of [dir, profile]) { try { rmSync(d, { recursive: true, force: true }); } catch { /* still locked */ } }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
