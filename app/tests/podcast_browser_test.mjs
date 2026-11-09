// End-to-end test of the podcast editor in a real browser (headless Edge or Chrome). A throwaway
// DJ's ended show gets a two-track recording sent the way the studio sends it (made in the page:
// a tone on the music track with a gap, beeps on the mic track, encoded with lamejs and cut into
// 10 s chunks on whole frames). The editor shows both tracks split into clips; zooming; a clip is
// cut with a click and the Delete key, Undo brings it back; the cuts are kept; Mix down flattens it
// in the browser (checked by ear, in numbers: the cut clips are silent, long gaps shrink, and the
// chunk joins are seamless); publishing puts it on the podcast page and in the feed; Hide. Then a
// show with no studio recording (BUTT, or a closed browser): the editor finds the clips in the audio
// as it aired (one track), keeps them for next time, and mixes that down too. Last, a studio
// recording that stopped 30 s before the show did (the page reloaded): the rest is filled in from
// the show as it aired, and the mixdown runs through the switch without a seam.
//   node app/tests/podcast_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH; on the test server set SW_HELPER_SSH as for the studio test.
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/podcast_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const remote = process.env.SW_HELPER_SSH ? process.env.SW_HELPER_SSH.split(":") : null;
// helper(...args) runs browser_helper.php; helperIn(input, ...args) also feeds it `input` on stdin
const helperIn = (input, ...args) => JSON.parse(remote
  ? execFileSync("ssh", [remote[0], `cd ${remote[1]} && php tests/browser_helper.php ${args.map((a) => "'" + String(a).replace(/'/g, "'\''") + "'").join(" ")}`], { encoding: "utf8", input, stdio: ["pipe", "pipe", "ignore"], maxBuffer: 1 << 26 })
  : execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8", input, maxBuffer: 1 << 26 }));
const helper = (...args) => helperIn("", ...args);
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const near = (a, b, tol) => Math.abs(a - b) <= tol;

const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
const profile = mkdtempSync(join(tmpdir(), "sw-podcast-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "--window-size=1280,900", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const ed = () => evaluate("window.SwEditor && SwEditor.state()");
async function until(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await ed().catch(() => null); if (s && fn(s)) return s; await sleep(150); }
  return null;
}
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);
const click = (sel) => evaluate(`(() => { const b = document.querySelector(${JSON.stringify(sel)}); if (!b) return false; b.click(); return true; })()`);
const mouse = async (x, y, extra = {}) => {
  await call("Input.dispatchMouseEvent", { type: "mousePressed", x, y, button: "left", clickCount: 1, ...extra });
  await call("Input.dispatchMouseEvent", { type: "mouseReleased", x, y, button: "left", clickCount: 1, ...extra });
};
const key = (k, code, vk, modifiers = 0) => call("Input.dispatchKeyEvent", { type: "keyDown", key: k, code, windowsVirtualKeyCode: vk, modifiers })
  .then(() => call("Input.dispatchKeyEvent", { type: "keyUp", key: k, code, windowsVirtualKeyCode: vk, modifiers }));
const clickClip = async (cid) => {
  for (let i = 0; i < 2; i++) {
    // the canvas on screen, the clip in view, then a real click on it
    await evaluate(`(() => { document.getElementById('edCv').scrollIntoView({ block: 'center' }); const c = SwEditor.state().clips.find((x) => x.id === ${JSON.stringify(cid)}); SwEditor.scrollTo(c.from); })()`);
    await sleep(100);
    const box = await evaluate(`SwEditor.clipBox(${JSON.stringify(cid)})`);
    if (!box) return false;
    await mouse(box.x, box.y);
    if ((await ed()).selected === cid) return true;
  }
  return false;
};

// Made in the page: the recording the studio would have sent, through the same API
const RECORD = `(async () => {
  const load = (src) => new Promise((ok, bad) => { const s = document.createElement("script"); s.src = src; s.onload = ok; s.onerror = bad; document.head.append(s); });
  await load(${JSON.stringify(base + "/assets/vendor/lame.min.js")});
  await load(${JSON.stringify(base + "/assets/podcast-core.js")});
  const P = SwPodcast, R = 44100, N = 150 * R, csrf = document.querySelector("input[name=csrf]").value;
  const music = new Float32Array(N), mic = new Float32Array(N);
  for (let i = 0; i < N; i++) {
    const t = i / R;
    if (t < 40 || t >= 45) music[i] = 0.5 * Math.sin(2 * Math.PI * 330 * t);
    if ((t >= 10 && t < 20) || (t >= 60 && t < 70)) mic[i] = 0.3 * Math.sin(2 * Math.PI * 1000 * t);
  }
  const i16 = (f) => { const o = new Int16Array(f.length); for (let i = 0; i < f.length; i++) { const s = Math.max(-1, Math.min(1, f[i])); o[i] = s < 0 ? s * 0x8000 : s * 0x7fff; } return o; };
  const encode = (channels, kbps, x) => {
    const enc = new lamejs.Mp3Encoder(channels, R, kbps), parts = [];
    for (let i = 0; i < x.length; i += 1152 * 20) { const b = i16(x.subarray(i, i + 1152 * 20)); parts.push(channels === 2 ? enc.encodeBuffer(b, b) : enc.encodeBuffer(b)); }
    parts.push(enc.flush());
    const all = new Uint8Array(parts.reduce((n, p) => n + p.length, 0)); let o = 0; for (const p of parts) { all.set(p, o); o += p.length; }
    return all;
  };
  const chunks = (bytes) => { // 10 s pieces on whole frames, as the recorder cuts them
    const st = P.frameStarts(bytes), per = Math.ceil(10 * R / 1152), out = [];
    for (let k = 0; k < st.length; k += per) out.push({ bytes: bytes.subarray(st[k], st[k + per] ?? bytes.length), samples: Math.min(per, st.length - k) * 1152 });
    return out;
  };
  const an = { music: new P.Analyzer(R), mic: new P.Analyzer(R) };
  for (let i = 0; i < N; i += 4096) { an.music.push(music.subarray(i, i + 4096)); an.mic.push(mic.subarray(i, i + 4096)); }
  const analysis = { rate: P.PEAK_RATE, tracks: Object.fromEntries(Object.entries(an).map(([k, a]) => { const r = a.finish(); return [k, { peaks: P.toBase64(r.peaks), clips: r.clips }]; })) };
  const tracks = { music: chunks(encode(2, 128, music)), mic: chunks(encode(1, 96, mic)) };
  const t0 = performance.now();
  const post = async (path, body, raw, tries = 3) => {
    try {
      const r = await fetch(${JSON.stringify(base + "/podcast/api/")} + path, { method: "POST", body, headers: { "X-CSRF": csrf, "Content-Type": raw ? "audio/mpeg" : "application/json" } });
      return { status: r.status, json: await r.json() };
    } catch (e) {
      if (tries > 1) { await new Promise((ok) => setTimeout(ok, 500)); return post(path, body, raw, tries - 1); }
      return { status: 0, json: { error: e.message + " (" + path + ", " + (body.length || 0) + " bytes, " + Math.round(performance.now() - t0) + " ms in)" } };
    }
  };
  for (const [track, list] of Object.entries(tracks)) for (const [n, c] of list.entries()) {
    const r = await post("rec-chunk?show=" + encodeURIComponent(SHOW) + "&track=" + track + "&n=" + n + "&samples=" + c.samples, c.bytes, true);
    if (r.status !== 200) return { error: r.json.error, track, n };
  }
  const done = await post("rec-done", JSON.stringify({ show: SHOW, counts: { music: tracks.music.length, mic: tracks.mic.length }, analysis }), false);
  return { done: done.json, status: done.status, chunks: { music: tracks.music.length, mic: tracks.mic.length }, clips: { music: analysis.tracks.music.clips, mic: analysis.tracks.mic.clips } };
})()`;

// Decode the mixdown in the page and measure it: tone levels by Goertzel, and the biggest jump
const MEASURE = `(async () => {
  const buf = await new OfflineAudioContext(2, 44100, 44100).decodeAudioData(await (await fetch(${JSON.stringify(base + "/podcast/api/audio?id=")} + EP, { cache: "no-store" })).arrayBuffer());
  const x = buf.getChannelData(0), R = 44100;
  const tone = (f, a, b) => { const k = 2 * Math.cos(2 * Math.PI * f / R); let s1 = 0, s2 = 0; const i0 = Math.round(a * R), i1 = Math.round(b * R);
    for (let i = i0; i < i1; i++) { const s = x[i] + k * s1 - s2; s2 = s1; s1 = s; } return Math.sqrt(s1 * s1 + s2 * s2 - k * s1 * s2) / ((i1 - i0) / 2); };
  const jump = (a, b) => { let m = 0; for (let i = Math.round(a * R) + 1; i < Math.round(b * R); i++) m = Math.max(m, Math.abs(x[i] - x[i - 1])); return m; };
  return { seconds: buf.duration, beepKept: tone(1000, 2, 9), musicCut: tone(330, 2, 9), musicOn: tone(330, 13, 25), beepCut: tone(1000, 28, 35), musicThere: tone(330, 28, 35),
    jumps: jump(12.5, 115.5), edge: tone(1000, 36.45, 36.75) };
})()`;

let dj = null, show = null, airedShow = null, tailShow = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.enable");

  dj = helper("dj");
  show = helper("podcast-show", String(dj.id), "15");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(dj.email)}; f.password.value = ${JSON.stringify(dj.password)}; f.submit(); })()`);
  for (let i = 0; i < 50 && !(await evaluate("location.pathname").catch(() => "")).endsWith("/manage"); i++) await sleep(100);

  await go(`${base}/manage/podcast`);
  console.log("The studio’s recording arrives");
  const rec = await evaluate(RECORD.replaceAll("SHOW", JSON.stringify(show.public_id)));
  check("both tracks upload in 10 s chunks, and the take is complete", rec.status === 200 && rec.done.ok && rec.chunks.music === 15 && rec.chunks.mic === 15, JSON.stringify(rec.chunks || rec));
  const r1 = (c) => c.map(([a, b]) => [Math.round(a), Math.round(b)]);
  check("  the clips split at the silences", JSON.stringify(r1(rec.clips.music)) === "[[0,40],[45,150]]" && JSON.stringify(r1(rec.clips.mic)) === "[[10,20],[60,70]]", JSON.stringify(rec.clips));
  let info = helper("episode-info", show.public_id);
  check("the show is a draft episode with the device take", info && info.status === "draft" && info.takes.some((t) => t.kind === "device" && t.complete && t.chunks.music === 15 && t.chunks.mic === 15),
    info && JSON.stringify(info.takes.map((t) => [t.kind, t.complete, t.chunks])));
  const EP = info.id;

  console.log("The podcast screen");
  await go(`${base}/manage/podcast`);
  check("the DJ sees the episode in their list, recorded in the studio", (await text(`#episodes tr[data-episode="${EP}"]`)).includes("[test] Podcast test show")
    && (await text(`#episodes tr[data-episode="${EP}"]`)).includes("music and mic") && (await text(`#episodes tr[data-episode="${EP}"] .pill`)) === "Draft", await text(`#episodes tr[data-episode="${EP}"]`));
  check("  but not the podcast's details (managers only)", !(await evaluate("!!document.querySelector('.podform')")));
  await click(`#episodes tr[data-episode="${EP}"] a`);
  for (let i = 0; i < 50 && !(await evaluate("location.pathname").catch(() => "")).endsWith(`/manage/podcast/${EP}`); i++) await sleep(100);

  console.log("The editor");
  let s = await until((x) => x.loaded && x.pps > 0, 15);
  check("Edit opens it with two time-synced tracks: four clips over 2:30", !!s && s.clips.length === 4 && near(s.total, 150, 0.2) && s.takes.length >= 1, s && `${s.clips.length} clips, ${s.total.toFixed(2)} s`);
  const take = s.takes.find((t) => t.kind === "device");
  const pps0 = s.pps;
  check("  the whole show fits the width", near(s.total * s.pps, await evaluate("document.getElementById('edScroll').clientWidth"), 4));
  await click("#edZoomIn"); await click("#edZoomIn");
  s = await ed();
  check("zoom in: 4 × closer, and the timeline scrolls", near(s.pps, pps0 * 4, 1e-6) && (await evaluate("document.getElementById('edScroll').scrollWidth > document.getElementById('edScroll').clientWidth * 3")), `${s.pps.toFixed(2)} px/s`);
  const cvBox = await evaluate("(() => { const r = document.getElementById('edCv').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()");
  // as the page gets it (a real Ctrl + wheel from the debugger can zoom the whole page in headless mode)
  await evaluate(`document.getElementById('edCv').dispatchEvent(new WheelEvent('wheel', { ctrlKey: true, deltaY: -120, clientX: ${cvBox.x}, clientY: ${cvBox.y}, bubbles: true, cancelable: true }))`);
  await sleep(200);
  s = await ed();
  check("  Ctrl + wheel zooms too", s.pps > pps0 * 4.5, `${s.pps.toFixed(2)} px/s`);
  await click("#edZoomOut");
  check("zoom out", (await ed()).pps < s.pps);
  const micB = `${take.id}:mic:1`, musicA = `${take.id}:music:0`;
  check("clicking a clip selects it", (await clickClip(micB)) && (await ed()).selected === micB, (await ed()).selected);
  check("  and Delete is ready", !(await evaluate("document.getElementById('edDelete').disabled")));
  const before = (await ed()).length;
  await key("Delete", "Delete", 46);
  s = await ed();
  check("the Delete key cuts it (the music under it stays, so the length doesn't change)", s.deleted.includes(micB) && near(s.length, before, 0.01) && near(before, 146.05, 0.2), `${s.deleted} · ${s.length.toFixed(2)} s`);
  await key("z", "KeyZ", 90, 2);
  check("Ctrl + Z brings it back", !(await ed()).deleted.includes(micB));
  await click("#edDelete");
  check("  the Delete clip button cuts it again", (await ed()).deleted.includes(micB));
  await clickClip(musicA);
  await key("Delete", "Delete", 46);
  s = await ed();
  // left: mic 10–20 and music 45–150; the 25 s between shrinks to 1 s, half a second before each clip
  check("cutting the first music clip leaves 9.5–20.5 and 44.5–150: the episode is 1:57", s.deleted.length === 2 && near(s.length, 116.5, 0.2), `${s.length.toFixed(2)} s · ${await text("#edInfo")}`);
  check("  the info line says so", (await text("#edInfo")).startsWith("2 clips cut · episode 1:57 of 2:30"), await text("#edInfo"));
  await evaluate("SwEditor.flushEdits()");
  info = helper("episode-info", show.public_id);
  check("the cuts are saved", info.edits && info.edits.deleted.length === 2 && info.edits.deleted.includes(micB) && info.edits.deleted.includes(musicA), JSON.stringify(info.edits));
  await go(`${base}/manage/podcast/${EP}`);
  s = await until((x) => x.loaded, 15);
  check("  and are still there after a reload", s && s.deleted.length === 2 && near(s.length, 116.5, 0.2));
  check("Publish waits for a mixdown", await evaluate("document.getElementById('edPublish').disabled"));
  check("  which says how long it takes", (await text("#edMixNote")).endsWith("It takes under a minute; keep this page open."), await text("#edMixNote"));

  console.log("Mix down");
  const mixFrom = Date.now();
  await click("#edMix");
  s = await until((x) => x.audio && !x.busy, 120);
  const mixTook = (Date.now() - mixFrom) / 1000;
  check("Mix down makes the episode in the browser and uploads it", !!s && near(s.audio.seconds, 116.5, 0.2) && s.audio.bytes > 1.5e6,
    s ? `${s.audio.seconds} s, ${s.audio.bytes} bytes in ${mixTook.toFixed(1)} s (${(s.audio.seconds / mixTook).toFixed(0)}× real time) · ${s.mixInfo}` : (await ed()).mixInfo);
  info = helper("episode-info", show.public_id);
  check("  kept privately on the server until it's published", info.audio_file === "episode.chunk" && near(info.audio_seconds, 116.5, 0.2) && !info.files.some((f) => f.startsWith("mix-")), JSON.stringify(info.files));
  const m = await evaluate(MEASURE.replace("EP", String(EP)));
  check("  the mixdown decodes to 1:57", near(m.seconds, 116.5, 0.2), m.seconds.toFixed(3) + " s");
  check("  the mic clip that stayed is there and the cut music under it is gone", m.beepKept > 0.25 && m.musicCut < 0.01, `beep ${m.beepKept.toFixed(3)}, music ${m.musicCut.toFixed(4)}`);
  check("  after the shrunk gap the music plays; the cut mic clip is silent and the music under it stays", m.musicOn > 0.4 && m.beepCut < 0.01 && m.musicThere > 0.4,
    `music ${m.musicOn.toFixed(3)}, beep ${m.beepCut.toFixed(4)}, music ${m.musicThere.toFixed(3)}`);
  check("  the cut ends exactly where the clip ends (the codec's delay allowed for)", m.edge < 0.003, `beep right after it ${m.edge.toFixed(4)}`);
  check("  the chunk joins are seamless: no jump bigger than the tone's own over 1:43", m.jumps < 0.06, `biggest step ${m.jumps.toFixed(4)} (a 330 Hz tone at 0.5 steps 0.024)`);
  check("the player plays the mixdown", (await evaluate("(() => { const a = document.getElementById('edAudio'); return !a.hidden && a.src.includes('podcast/api/audio'); })()")));
  check("Publish is ready", !(await evaluate("document.getElementById('edPublish').disabled")));
  await clickClip(`${take.id}:mic:0`);
  await key("Delete", "Delete", 46);
  check("changing the cuts after a mixdown asks for a new one before publishing", (await ed()).stale && (await evaluate("document.getElementById('edPublish').disabled")) && (await text("#edMixNote")).startsWith("You changed the cuts"), await text("#edMixNote"));
  await key("z", "KeyZ", 90, 2);
  check("  undoing it makes the mixdown current again", !(await ed()).stale && !(await evaluate("document.getElementById('edPublish').disabled")));

  console.log("Publishing");
  const title = "[test] Grace for today " + Math.random().toString(36).slice(2, 7);
  await evaluate(`(() => { document.getElementById('edName').value = ${JSON.stringify(title)}; document.getElementById('edDesc').value = 'A word for the morning.'; })()`);
  await click("#edPublish");
  s = await until((x) => x.state === "published", 10);
  check("Publish publishes it", !!s && (await text("#edPubInfo")).startsWith("Published"), await text("#edPubInfo"));
  info = helper("episode-info", show.public_id);
  check("  as a public MP3 named after the title", /^test-grace-for-today-[a-z0-9]+\.mp3$/.test(info.audio_file || ""), info.audio_file);
  const url = `${base}/storage/podcasts/${info.public_id}/${info.audio_file}`;
  const res = await fetch(url, { method: "HEAD" });
  check("  served to anyone as audio", res.status === 200 && (res.headers.get("content-type") || "").startsWith("audio/mpeg"), `${res.status} ${res.headers.get("content-type")}`);
  const rawName = info.files.find((f) => f.endsWith(".chunk"));
  const raw = await fetch(`${base}/storage/podcasts/${info.public_id}/raw/${rawName}`, { method: "HEAD" });
  check("  while the raw tracks stay private", raw.status === 403, `${rawName}: ${raw.status}`);
  const feed = await (await fetch(`${base}/podcast/feed`, { cache: "no-store" })).text();
  check("the feed lists it with its MP3", feed.includes(`<title>${title}</title>`) && feed.includes(`/storage/podcasts/${info.public_id}/${info.audio_file}" length="${info.audio_bytes}" type="audio/mpeg"`), feed.length + " bytes");
  const page = await (await fetch(`${base}/podcast`, { cache: "no-store" })).text();
  check("  and the podcast page, with a player", page.includes(title) && page.includes(`data-episode="${info.public_id}"`) && page.includes(`storage/podcasts/${info.public_id}/${info.audio_file}`));
  await click("#edHide");
  s = await until((x) => x.state === "hidden", 8);
  const feed2 = await (await fetch(`${base}/podcast/feed`, { cache: "no-store" })).text();
  check("Hide takes it off the feed and the page", !!s && !feed2.includes(title) && !(await (await fetch(`${base}/podcast`, { cache: "no-store" })).text()).includes(title));
  await click("#edHide");
  s = await until((x) => x.state === "published", 8);
  check("  Show again brings it back", !!s && (await (await fetch(`${base}/podcast/feed`, { cache: "no-store" })).text()).includes(title));

  console.log("A show with no studio recording");
  // 2:10 as it went out: a tone to 1:00, 6 s of silence, a higher tone to the end, in 6 s pieces
  const mp3 = await evaluate(`(async () => {
    if (!window.lamejs) await new Promise((ok, bad) => { const s = document.createElement("script"); s.src = ${JSON.stringify(base + "/assets/vendor/lame.min.js")}; s.onload = ok; s.onerror = bad; document.head.append(s); });
    const R = 44100, N = 130 * R, x = new Int16Array(N);
    for (let i = 0; i < N; i++) { const t = i / R; x[i] = t < 60 ? 0.5 * 32767 * Math.sin(2 * Math.PI * 330 * t) : t >= 66 ? 0.4 * 32767 * Math.sin(2 * Math.PI * 1000 * t) : 0; }
    const enc = new lamejs.Mp3Encoder(2, R, 128), parts = [];
    for (let i = 0; i < N; i += 1152 * 20) { const b = x.subarray(i, i + 1152 * 20); parts.push(enc.encodeBuffer(b, b)); }
    parts.push(enc.flush());
    let s = ""; for (const q of parts) { const p = new Uint8Array(q.buffer, q.byteOffset, q.length); for (let i = 0; i < p.length; i += 0x8000) s += String.fromCharCode.apply(null, p.subarray(i, i + 0x8000)); }
    return btoa(s);
  })()`);
  airedShow = helperIn(mp3, "podcast-aired", String(dj.id));
  check("the show went out in 6 s pieces", airedShow.pieces >= 21 && near(airedShow.seconds, 130, 0.2), `${airedShow.pieces} pieces, ${airedShow.seconds} s`);
  info = helper("episode-info", airedShow.public_id);
  check("  and is a draft episode, as aired, with no clips yet", info && info.status === "draft" && info.takes.length === 1 && info.takes[0].kind === "aired" && info.takes[0].clips === null,
    info && JSON.stringify(info.takes));
  const EP2 = info.id;
  await go(`${base}/manage/podcast/${EP2}`);
  s = await until((x) => x.loaded, 60);
  check("the editor finds the clips in the audio as it aired: one track, split at the silence", !!s && s.clips.length === 2 && near(s.clips[0].from, 0, 0.1) && near(s.clips[0].to, 60, 0.1) && near(s.clips[1].from, 66, 0.1) && near(s.clips[1].to, 130, 0.15),
    s && JSON.stringify(s.clips.map((c) => [c.from, c.to])));
  for (let i = 0; i < 40 && !(await text("#edMsg")).includes("As it aired"); i++) await sleep(100);
  check("  on one row, “As aired”", (await text("#edMsg")).includes("As it aired: music and voice on one track."), await text("#edMsg"));
  info = helper("episode-info", airedShow.public_id);
  check("  and keeps them on the server for next time", info.takes[0].clips && info.takes[0].clips.aired.length === 2, JSON.stringify(info.takes[0].clips));
  const t2 = s.takes[0].id;
  check("cutting the second tone leaves the first and half a second: 1:00.5", (await clickClip(`${t2}:aired:1`)) && (await key("Delete", "Delete", 46), near((await ed()).length, 60.5, 0.1)), (await ed()).length.toFixed(2) + " s");
  await click("#edMix");
  s = await until((x) => x.audio && !x.busy, 120);
  check("  and it mixes down", !!s && near(s.audio.seconds, 60.5, 0.1), s ? `${s.audio.seconds} s` : JSON.stringify({ info: (await ed()).mixInfo, busy: (await ed()).busy, audio: (await ed()).audio, button: await evaluate("document.getElementById('edMix').disabled") }));
  const m2 = await evaluate(MEASURE.replace("EP", String(EP2)).replace("return { seconds: buf.duration,", "return { seconds: buf.duration, low: tone(330, 5, 55), high: tone(1000, 5, 55), seam: jump(1, 59.5), x: 0,"));
  check("  the first tone throughout, the cut one gone, seamless over its ten piece joins", m2.low > 0.4 && m2.high < 0.01 && m2.seam < 0.06, `330 Hz ${m2.low.toFixed(3)}, 1000 Hz ${m2.high.toFixed(4)}, biggest step ${m2.seam.toFixed(4)}`);
  await go(`${base}/manage/podcast/${EP2}`);
  s = await until((x) => x.loaded, 15);
  check("opened again, it doesn’t look for the clips a second time", !!s && !(await text("#edMsg")).includes("Finding") && s.deleted.length === 1, await text("#edMsg"));

  console.log("A studio recording that stopped early");
  tailShow = helperIn(mp3, "podcast-aired", String(dj.id));
  // the same sound as the studio's two tracks, but only its first 100 s (music = what aired, no mic)
  const dev = await evaluate(`(async () => {
    if (!window.lamejs) await new Promise((ok, bad) => { const s = document.createElement("script"); s.src = ${JSON.stringify(base + "/assets/vendor/lame.min.js")}; s.onload = ok; s.onerror = bad; document.head.append(s); });
    const P = SwPodcast, R = 44100, N = 100 * R, csrf = document.querySelector("input[name=csrf]").value, SHOW = ${JSON.stringify(tailShow.public_id)};
    const music = new Float32Array(N), mic = new Float32Array(N);
    for (let i = 0; i < N; i++) { const t = i / R; music[i] = t < 60 ? 0.5 * Math.sin(2 * Math.PI * 330 * t) : t >= 66 ? 0.4 * Math.sin(2 * Math.PI * 1000 * t) : 0; }
    const i16 = (f) => { const o = new Int16Array(f.length); for (let i = 0; i < f.length; i++) { const s = Math.max(-1, Math.min(1, f[i])); o[i] = s < 0 ? s * 0x8000 : s * 0x7fff; } return o; };
    const encode = (channels, kbps, x) => { const enc = new lamejs.Mp3Encoder(channels, R, kbps), parts = [];
      for (let i = 0; i < x.length; i += 1152 * 20) { const b = i16(x.subarray(i, i + 1152 * 20)); parts.push(channels === 2 ? enc.encodeBuffer(b, b) : enc.encodeBuffer(b)); }
      parts.push(enc.flush()); const all = new Uint8Array(parts.reduce((n, p) => n + p.length, 0)); let o = 0; for (const p of parts) { all.set(p, o); o += p.length; } return all; };
    const chunks = (bytes) => { const st = P.frameStarts(bytes), per = Math.ceil(10 * R / 1152), out = [];
      for (let k = 0; k < st.length; k += per) out.push({ bytes: bytes.subarray(st[k], st[k + per] ?? bytes.length), samples: Math.min(per, st.length - k) * 1152 }); return out; };
    const an = { music: new P.Analyzer(R), mic: new P.Analyzer(R) };
    for (let i = 0; i < N; i += 4096) { an.music.push(music.subarray(i, i + 4096)); an.mic.push(mic.subarray(i, i + 4096)); }
    const analysis = { rate: P.PEAK_RATE, tracks: Object.fromEntries(Object.entries(an).map(([k, a]) => { const r = a.finish(); return [k, { peaks: P.toBase64(r.peaks), clips: r.clips }]; })) };
    const tracks = { music: chunks(encode(2, 128, music)), mic: chunks(encode(1, 96, mic)) };
    const post = async (path, body, raw) => { for (let i = 0; ; i++) { try { const r = await fetch(${JSON.stringify(base + "/podcast/api/")} + path, { method: "POST", body, headers: { "X-CSRF": csrf, "Content-Type": raw ? "audio/mpeg" : "application/json" } }); return { status: r.status, json: await r.json() }; } catch (e) { if (i >= 2) return { status: 0, json: { error: e.message } }; } } };
    for (const [track, list] of Object.entries(tracks)) for (const [n, c] of list.entries()) {
      const r = await post("rec-chunk?show=" + encodeURIComponent(SHOW) + "&track=" + track + "&n=" + n + "&samples=" + c.samples, c.bytes, true);
      if (r.status !== 200) return { error: r.json.error };
    }
    return (await post("rec-done", JSON.stringify({ show: SHOW, counts: { music: tracks.music.length, mic: tracks.mic.length }, analysis }), false)).json;
  })()`);
  info = helper("episode-info", tailShow.public_id);
  check("the studio's 1:40 arrives for a show that aired 2:10", dev.ok && info.takes.some((x) => x.kind === "device" && x.complete && near(x.duration, 100, 0.1)), JSON.stringify(dev));
  const EP3 = info.id;
  await go(`${base}/manage/podcast/${EP3}`);
  s = await until((x) => x.loaded, 60);
  for (let i = 0; i < 40 && !(await text("#edMsg")).includes("clips."); i++) await sleep(100);
  check("the editor fills in the last 30 s as aired, right after the recording", !!s && s.takes.length === 2 && s.takes[0].kind === "device" && s.takes[1].kind === "aired"
    && near(s.takes[1].offset, 100, 0.1) && near(s.total, 130, 0.2), s && JSON.stringify(s.takes));
  check("  and says why", (await text("#edMsg")).includes("stopped early") && (await text("#edMsg")).includes("last 30 s of that part are as aired"), await text("#edMsg"));
  const tailClips = s.clips.filter((c) => c.id.startsWith(s.takes[1].id + ":"));
  check("  its clips start where the recording ends (found from there on only)", tailClips.length === 1 && near(tailClips[0].from, 100, 0.1) && near(tailClips[0].to, 130, 0.15), JSON.stringify(tailClips));
  info = helper("episode-info", tailShow.public_id);
  const airedTake = info.takes.find((x) => x.kind === "aired");
  check("  kept on the server in the show's own time", airedTake.clips && airedTake.clips.aired.length === 1 && near(airedTake.clips.aired[0][0], 100, 0.1), JSON.stringify(airedTake.clips));
  await click("#edMix");
  s = await until((x) => x.audio && !x.busy, 120);
  // left: 0–60.5 and 65.5–100 of the recording (the 6 s gap shrinks to 1 s), then 100–130 as aired
  check("the mixdown runs through: 2:05", !!s && near(s.audio.seconds, 125.0, 0.2), s ? `${s.audio.seconds} s` : (await ed()).mixInfo);
  const m3 = await evaluate(MEASURE.replace("EP", String(EP3)).replace("return { seconds: buf.duration,", "return { seconds: buf.duration, before: tone(1000, 70, 94), after: tone(1000, 96, 124), seam3: jump(62, 124), x: 0,"));
  check("  the higher tone on both sides of the switch, with no seam (within the tone's own step, 0.057)", m3.before > 0.35 && m3.after > 0.35 && m3.seam3 < 0.075,
    `before ${m3.before.toFixed(3)}, after ${m3.after.toFixed(3)}, biggest step ${m3.seam3.toFixed(4)}`);
} catch (e) {
  failed++;
  console.log("  FAIL the test stopped: " + e.message);
} finally {
  try { if (show) helper("podcast-cleanup", show.public_id); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (airedShow) helper("podcast-cleanup", airedShow.public_id); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (tailShow) helper("podcast-cleanup", tailShow.public_id); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (dj) helper("studio-cleanup", String(dj.id)); } catch (e) { console.log("cleanup: " + e.message); }
  try { ws && ws.close(); } catch { /* closed */ }
  try {
    const b = new WebSocket((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    await new Promise((ok, bad) => { b.onopen = ok; b.onerror = bad; });
    b.send(JSON.stringify({ id: 1, method: "Browser.close" }));
    await new Promise((r) => setTimeout(r, 1000));
  } catch { /* already closed */ }
  proc.kill();
  spawnSync("powershell", ["-NoProfile", "-Command", `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${profile}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: "ignore" });
  await sleep(500);
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still locked */ }
  console.log("Cleanup: the test DJ, their show and its episode (emptied and skipped) removed");
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
