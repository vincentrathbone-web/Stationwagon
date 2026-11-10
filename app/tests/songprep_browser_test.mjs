// End-to-end test of the Song prep screen (round 40) in headless Edge or Chrome on Laragon: a
// throwaway manager uploads a generated song (its original is kept, never served), opens it on
// the Song prep screen, and the test checks the preview (processed in pieces exactly as the whole
// song in one go, everything off is the original untouched, the meters and loudness readouts,
// playing, the A/B switch, seeking), the EQ curve and the controls, presets (saved, kept in range,
// refused when wrong, used for new uploads, removed) and "Apply to this song".
//   node app/tests/songprep_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH. Signs in a throwaway account: not for a public server.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync, unlinkSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import vm from "node:vm";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/songprep_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- a song with dynamics: chords that hit every half second, louder in the middle --------------
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const RATE = 44100;
function music(title, seconds = 24) {
  const n = seconds * RATE, l = new Int16Array(n), r = new Int16Array(n);
  for (let i = 0; i < n; i++) {
    const t = i / RATE, hit = Math.exp(-((t % 0.5) * 6)), swell = 0.35 + 0.65 * Math.sin(Math.PI * t / seconds);
    const v = swell * (0.3 * hit * Math.sin(2 * Math.PI * 110 * t) + 0.12 * Math.sin(2 * Math.PI * 440 * t) + 0.06 * hit * Math.sin(2 * Math.PI * 3300 * t));
    l[i] = Math.round(32767 * Math.max(-1, Math.min(1, v)));
    r[i] = Math.round(32767 * Math.max(-1, Math.min(1, v * 0.9 + 0.04 * Math.sin(2 * Math.PI * 660 * t))));
  }
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, 192), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), r.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const text = Buffer.from("\u0003" + title, "utf8");
  const frame = Buffer.concat([Buffer.from("TIT2"), Buffer.from([0, 0, 0, text.length]), Buffer.from([0, 0]), text]);
  return Buffer.concat([Buffer.concat([Buffer.from("ID3"), Buffer.from([4, 0, 0, 0, 0, 0, frame.length]), frame]), ...out]);
}
const tag = Math.random().toString(16).slice(2, 8), presetPrefix = `Test ${tag}`;
const dir = mkdtempSync(join(tmpdir(), "sw-songprep-"));
const titleA = `Song Prep Test ${tag}`, titleB = `Song Prep Second ${tag}`;
const fileA = join(dir, "a.mp3"), fileB = join(dir, "b.mp3");
writeFileSync(fileA, music(titleA)); writeFileSync(fileB, music(titleB, 12));
const shaA = createHash("sha1").update(readFileSync(fileA)).digest("hex");
const originals = join(here, "..", "storage", "originals");

// ---- browser -----------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((b) => existsSync(b));
const profile = mkdtempSync(join(tmpdir(), "sw-songprep-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "--mute-audio",
  "--autoplay-policy=no-user-gesture-required", "--window-size=1280,1800", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map(), errors = [];
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const loaded = async () => { for (let i = 0; i < 100; i++) { if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; await sleep(100); } };
// the new page, not the one before it: wait for its address, then for it to load
const go = async (url) => {
  await call("Page.navigate", { url });
  for (let i = 0; i < 100 && (await evaluate("location.href").catch(() => "")) !== url; i++) await sleep(100);
  await sleep(200); await loaded();
};
const st = () => evaluate("window.SwSongPrep && SwSongPrep.state()");
async function until(fn, seconds) { const end = Date.now() + seconds * 1000; while (Date.now() < end) { const s = await st().catch(() => null); if (s && fn(s)) return s; await sleep(150); } return null; }
async function upload(files) {
  await go(`${base}/manage/library`);
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await evaluate("window.swUploadNoWindow = true"); // the upload window (round 56) has its own test
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
// a range input or checkbox set the way a person does it (input / change events)
const setRange = (sel, v) => evaluate(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.value = ${JSON.stringify(String(v))}; e.dispatchEvent(new Event("input", { bubbles: true })); return e.value; })()`);
const setBox = (sel, on) => evaluate(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (e.checked !== ${on}) e.click(); return e.checked; })()`);
const maxDiff = (a, b) => { let m = 0; for (let i = 0; i < a.length; i++) { const d = Math.abs(a[i] - b[i]); if (d > m) m = d; } return m; };

let user = null, prepBefore = null, presetsBefore = null;
try {
  prepBefore = helper("prep");
  presetsBefore = helper("presets");
  helper("prep", "1", "1");
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
  // the screens as they are, not inside the station's frame page with the bar (round 61; the shell test has that)
  await call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(user.email)}; f.password.value = ${JSON.stringify(user.password)}; f.submit(); })()`);
  await sleep(600); await loaded();
  check("a throwaway manager signs in", (await evaluate("location.pathname")).endsWith("/manage"));

  console.log("Upload: the original is kept");
  let rows = await upload([fileA]);
  check("the song is added with the built-in preset", rows && rows[0].cls === "ok" && rows[0].text.includes("original kept"), rows && rows[0].text);
  const tA = helper("track", titleA);
  check("  marked mastered with “Audition chain”", tA && Number(tA.mastered) === 1 && tA.preset === "Audition chain", tA && `${tA.mastered} ${tA.preset}`);
  check("  its original is in storage/originals, the same bytes", existsSync(join(originals, `${shaA}.mp3`)) && createHash("sha1").update(readFileSync(join(originals, `${shaA}.mp3`))).digest("hex") === shaA);
  const leak = await fetch(`${base}/storage/originals/${shaA}.mp3`);
  check("  and the web can’t fetch it", leak.status === 403, String(leak.status));
  const anon = await fetch(`${base}/manage/songprep/original?id=${tA.id}`, { redirect: "manual" });
  check("  nor anyone not signed in through Song prep", anon.status !== 200, String(anon.status));

  console.log("A song without its original (uploaded before round 40)");
  unlinkSync(join(originals, `${shaA}.mp3`));
  await go(`${base}/manage/songprep?song=${tA.id}`);
  await until((x) => x.song && x.song.id === tA.id, 15);
  check("Song prep previews the station’s copy, says why, and can’t Apply", (await evaluate("document.getElementById('spSource').textContent")).includes("wasn’t kept")
    && await evaluate("document.getElementById('spApply').disabled"));
  rows = await upload([fileA]);
  check("uploading it again keeps its original", rows && rows[0].text.includes("Already there") && rows[0].text.includes("original kept") && existsSync(join(originals, `${shaA}.mp3`)), rows && rows[0].text);
  check("  and adds no second copy", helper("track", titleA).id === tA.id);

  console.log("The Song prep screen");
  await go(`${base}/manage/songprep?song=${tA.id}`);
  check("the rail has Song prep, and the page opens on the chosen song", (await evaluate(`!!document.querySelector('.rail a[aria-current="page"][href$="manage/songprep"]') && document.getElementById('spSong').value`)) === String(tA.id));
  check("  it says it previews the original", (await evaluate("document.getElementById('spSource').textContent")).includes("from the original"));
  let s = await until((x) => x.song && x.song.done === x.song.pieces && x.lufsO !== null && x.lufsP !== null, 30);
  check("the whole song is processed in pieces", !!s && s.song.pieces === 3, s && `${s.song.done} of ${s.song.pieces} pieces`);
  // pieces against the whole song in one go, in the page's own chain
  const cmp = await evaluate(`(() => { const [L, R] = SwSongPrep.original(0, 1e9), [PL, PR] = SwSongPrep.processed(0, 1e9), whole = SwMaster.master([L, R], 44100, null, SwSongPrep.state().settings);
    let m = 0, at = 0; for (let i = 0; i < L.length; i++) { const d = Math.max(Math.abs(whole[0][i] - PL[i]), Math.abs(whole[1][i] - PR[i])); if (d > m) { m = d; at = i; } } return { m, at, n: L.length }; })()`);
  check("  processed in pieces is the whole song processed in one go (within −60 dB)", cmp.m < 0.001, `largest difference ${(20 * Math.log10(cmp.m || 1e-12)).toFixed(1)} dB at ${(cmp.at / RATE).toFixed(2)} s`);
  check("the loudness readouts: original and processed, and the level import gives them", s && s.lufsP > s.lufsO && Math.abs(s.gainP) < 15 && (await evaluate("document.getElementById('spLufsP').textContent")) !== "–", s && `${s.lufsO.toFixed(1)} → ${s.lufsP.toFixed(1)} LUFS, levels ${s.gainO.toFixed(1)} / ${s.gainP.toFixed(1)} dB`);
  check("  the meters show the compressor working", s && s.reduction.bands.some((v) => v > 1), s && s.reduction.bands.map((v) => v.toFixed(1)).join(" / ") + " dB");
  const lra = await evaluate("[document.getElementById('spLraO').textContent, document.getElementById('spLraP').textContent]");
  check("  and the processed song has less loudness range than the original", Number(lra[1]) < Number(lra[0]), lra.join(" → ") + " LU");

  console.log("Playing");
  await evaluate("document.getElementById('spPlay').click()");
  s = await until((x) => x.playing && x.at > 1, 8);
  check("Play plays it", !!s, s ? s.at.toFixed(2) + " s" : JSON.stringify(await st()));
  check("  the processed version by default", s && s.hear === "P");
  await evaluate("document.getElementById('spHearO').click()");
  check("  Original switches to the original", (await st()).hear === "O" && (await evaluate("document.getElementById('spHearO').getAttribute('aria-pressed')")) === "true");
  await evaluate("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'a' }))");
  check("  and the A key switches back", (await st()).hear === "P");
  await evaluate(`(() => { const c = document.getElementById('spOverview'), r = c.getBoundingClientRect(); c.dispatchEvent(new MouseEvent('click', { clientX: r.left + r.width * 0.75, clientY: r.top + 10, bubbles: true })); })()`);
  s = await until((x) => x.playing && x.at > 17.5 && x.at < 20, 4);
  check("  a click on the whole-song view plays from there", !!s, s ? s.at.toFixed(2) + " s" : String((await st()).at));
  const px = await evaluate(`(() => { const c = document.getElementById('spStrip'), d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 0) n++; return { n, w: c.width, h: c.height }; })()`);
  check("  the enlarged wave is drawn", px.n > px.w * 10, `${px.n} pixels on ${px.w}×${px.h}`);
  await evaluate("document.getElementById('spPlay').click()");
  check("  Pause stops", !(await st()).playing);

  console.log("Settings");
  await setBox("#spCompOn", false); await setBox("#spFinishOn", false); await setBox("#spEqOn", false);
  s = await until((x) => x.song.done === x.song.pieces && !x.settings.compOn && !x.settings.finishOn, 15);
  const same = await evaluate(`(() => { const [L] = SwSongPrep.original(0, 1e9), [PL] = SwSongPrep.processed(0, 1e9); let m = 0; for (let i = 0; i < L.length; i++) m = Math.max(m, Math.abs(L[i] - PL[i])); return m; })()`);
  check("with everything switched off the processed song is the original, sample for sample", !!s && same === 0, String(same));
  check("  and it shows the changes as not saved", s && s.dirty && !(await evaluate("document.getElementById('spDirty').hidden")));
  await setBox("#spEqOn", true);
  await evaluate(`(() => { const r = document.querySelector('.speqf[data-key="b1"] input[data-k="db"]'); r.value = "9"; r.dispatchEvent(new Event("change", { bubbles: true })); })()`);
  s = await until((x) => x.song.done === x.song.pieces && x.settings.eq.bells[1].db === 9, 15);
  const gain440 = await evaluate(`(() => { const [L] = SwSongPrep.original(0, 1e9), [PL] = SwSongPrep.processed(0, 1e9); let a = 0, b = 0; for (let i = 44100; i < L.length - 44100; i++) { a += L[i] * L[i]; b += PL[i] * PL[i]; } return 10 * Math.log10(b / a); })()`);
  check("EQ: a +9 dB bell at 1 kHz makes the song louder (its 440 Hz and 3.3 kHz parts sit on the bell's sides)", !!s && gain440 > 1, gain440.toFixed(2) + " dB");
  const curve = await evaluate(`(() => { const c = document.getElementById('spEq'), g = c.getContext('2d'), d = g.getImageData(0, 0, c.width, c.height).data; let n = 0; for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 0) n++; return n; })()`);
  check("  the curve is drawn", curve > 1000, String(curve));
  // drag the low shelf's point up: its gain follows
  const dragged = await evaluate(`(async () => { const c = document.getElementById('spEq'), r = c.getBoundingClientRect(), s = SwSongPrep.state().settings.eq.low;
    const x = r.left + Math.log(s.hz / 20) / Math.log(1000) * r.width, y = r.top + r.height / 2, ev = (t, yy) => c.dispatchEvent(new PointerEvent(t, { clientX: x, clientY: yy, pointerId: 1, bubbles: true }));
    c.setPointerCapture = () => {}; ev('pointerdown', y); ev('pointermove', y - r.height / 4); ev('pointerup', y - r.height / 4); return SwSongPrep.state().settings.eq.low.db; })()`);
  check("  dragging the low shelf's point up raises its gain", dragged > 4, dragged + " dB");
  await setBox("#spCompOn", true); await setBox("#spFinishOn", true);
  await setRange("#sps-amount", 0.9);
  s = await st();
  check("the simple view's Amount sets the full view's ratios", s.settings.ratio.every((v, i) => v > [3, 2, 2, 3][i]) && Number(await evaluate("document.getElementById('spa-rat0').value")) === s.settings.ratio[0], s.settings.ratio.join(" / "));
  await setRange("#spa-rat2", 7.5);
  check("  and a full-view setting changes only itself", (await st()).settings.ratio[2] === 7.5 && (await st()).settings.ratio[0] === s.settings.ratio[0]);

  console.log("Presets");
  await evaluate(`document.getElementById('spName').value = ${JSON.stringify(presetPrefix + " warm")}`);
  await evaluate("document.getElementById('spSaveAs').click()");
  s = await until((x) => x.presetId > 0 && !x.dirty, 5);
  const saved = helper("presets").presets.find((p) => p.name === presetPrefix + " warm");
  check("Save as new keeps the settings on the server", !!s && !!saved && saved.settings.ratio[2] === 7.5 && saved.settings.eq.bells[1].db === 9 && saved.settings.simple.amount === 0.9, saved && JSON.stringify(saved.settings.ratio));
  await evaluate(`document.getElementById('spName').value = ${JSON.stringify(presetPrefix + " warm")}`);
  await evaluate("document.getElementById('spSaveAs').click()");
  await sleep(800);
  check("  the same name twice is refused", (await evaluate("document.getElementById('spMsg').textContent")).includes("already a preset"), await evaluate("document.getElementById('spMsg').textContent"));
  const csrf = await evaluate("JSON.parse(document.getElementById('songprepData').textContent).csrf");
  const bad = await evaluate(`(async () => { const f = new FormData(); f.append('csrf', ${JSON.stringify(csrf)}); f.append('action', 'save'); f.append('id', '0'); f.append('name', ${JSON.stringify(presetPrefix + " bad")});
    f.append('settings', JSON.stringify({ ratio: [3, 2], hack: '<script>' })); const r = await fetch(location.pathname, { method: 'POST', body: f, headers: { Accept: 'application/json' } }); const t = await r.text(); try { return JSON.parse(t); } catch { return { ok: null, error: t.replace(/<[^>]+>/g, '').slice(0, 400) }; } })()`);
  check("  a preset with an unknown setting is refused", bad.ok === false && bad.error.includes("hack"), JSON.stringify(bad));
  await evaluate("document.getElementById('spDefault').click()");
  await sleep(800);
  check("Use for new uploads makes it the import default", helper("presets").default === saved.id && (await evaluate("document.getElementById('spDefault').textContent")).includes("★"));
  await go(`${base}/manage/library`);
  check("  the library says so", (await evaluate("document.getElementById('drop').dataset.presetName")) === presetPrefix + " warm" && (await evaluate("document.getElementById('prepOpts').textContent")).includes(presetPrefix + " warm"));
  rows = await upload([fileB]);
  const tB = helper("track", titleB);
  check("  and a new upload is mastered with it", rows && rows[0].cls === "ok" && tB && tB.preset === presetPrefix + " warm", tB && tB.preset);

  console.log("Apply to this song");
  await go(`${base}/manage/songprep?song=${tA.id}`);
  s = await until((x) => x.song && x.song.id === tA.id && x.presetId === saved.id, 10);
  check("the page opens with the import default", !!s);
  const before = helper("track", titleA);
  // the station plays its songs, the test's too: while this one is on air (or just was) Apply is
  // refused, so then the test checks that and tries again until it has gone off air
  let msg = "", refused = false;
  for (let tries = 0; tries < 40; tries++) {
    await evaluate("window.confirm = () => true; document.getElementById('spMsg').textContent = ''; document.getElementById('spApply').click()");
    msg = "";
    for (let i = 0; i < 300 && !/is now prepared|Not applied/.test(msg); i++) { await sleep(200); msg = await evaluate("document.getElementById('spMsg').textContent"); }
    if (!msg.includes("is on air")) break;
    if (!refused) check("  (while it’s on air, Apply is refused and says so)", (await evaluate("document.getElementById('spMsg').className")).includes("bad") && helper("track", titleA).file === before.file, msg);
    refused = true;
    await sleep(6000);
  }
  const after = helper("track", titleA);
  check("the song is prepared again from its original and put in place", msg.includes("is now prepared") && after.file !== before.file && after.preset === presetPrefix + " warm" && after.sha1 === before.sha1, msg);
  check("  the old copy is gone, the original kept", !existsSync(join(here, "..", "storage", "media", before.file)) && existsSync(join(originals, `${shaA}.mp3`)));

  console.log("Removing a preset");
  await evaluate("window.confirm = () => true; document.getElementById('spDelete').click()");
  await sleep(1000);
  const left = helper("presets");
  check("Remove takes it away, and new uploads go back to the built-in preset", !left.presets.some((p) => p.name === presetPrefix + " warm") && left.default === 0 && (await st()).presetId === 0, JSON.stringify(left.default));
  check("no errors in the page", errors.length === 0, errors.slice(0, 3).join(" | "));
} catch (ex) {
  const where = await evaluate("location.href + ' | ' + document.title + ' | ' + (document.querySelector('main') || document.body).innerText.slice(0, 400)").catch(() => "?");
  check("the test ran to the end", false, (ex.stack || String(ex)) + "\n  on: " + where + (errors.length ? "\n  page errors: " + errors.slice(0, 5).join(" | ") : ""));
} finally {
  try { helper("presets", "clear", presetPrefix); } catch { /* nothing to clear */ }
  if (presetsBefore) try { helper("presets", "default", String(presetsBefore.default)); } catch { /* gone */ }
  if (prepBefore) try { helper("prep", prepBefore.master ? "1" : "0", prepBefore.level ? "1" : "0"); } catch { /* as it was */ }
  let hidden = 0;
  if (user) try { hidden = helper("cleanup", String(user.id), titleA, titleB).hidden; } catch { /* left */ }
  // a song on air is hidden instead, and removed (with its original) by the next run
  check("the test's original copies are removed with their songs", hidden > 0 || !existsSync(join(originals, `${shaA}.mp3`)), hidden ? `${hidden} on air, hidden till the next run` : "");
  try { ws && ws.close(); } catch { /* closed */ }
  proc.kill();
  await sleep(300);
  for (const d of [dir, profile]) try { rmSync(d, { recursive: true, force: true }); } catch { /* still in use */ }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : `\nAll checks passed (${passed})`);
process.exit(failed ? 1 : 0);
