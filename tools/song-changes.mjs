// Hear the station's song changes for a folder of songs, before they go on air: each song is
// prepared as on upload (assets/prep.js in headless Edge: levelled to −16 LUFS, silence trimmed),
// cut into pieces by the app's own Mp3::scan, and each change from one song to the next (in the
// folder's order) is planned by the planner's rule and mixed as the listener player mixes it. The
// result is a page of 15-second clips, each with its chart (the output strip's colours and level
// lines) and what was measured: silence, quiet stretches, the loudness step and the mix's peak.
//   node tools/song-changes.mjs <music folder> <out dir> [--crossfade 6] [--piece 6]
//   node tools/song-changes.mjs "D:\Music\…\Johannesburg Recordings" C:\laragon\www\song-changes
// Needs SW_PHP (the PHP path). Prepared songs are kept in <out dir>/prepared and reused. The out
// dir holds copies of the music: keep it off public servers unless the music may be shared.
import { spawn, spawnSync } from "node:child_process";
import { createServer } from "node:http";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync, createReadStream } from "node:fs";
import { tmpdir } from "node:os";
import { basename, dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const args = process.argv.slice(2);
const opt = (name, d) => { const i = args.indexOf("--" + name); if (i < 0) return d; const v = +args[i + 1]; args.splice(i, 2); return v; };
const CROSSFADE = opt("crossfade", 6), PIECE = opt("piece", 6);
const [dir, out] = args;
const PHP = process.env.SW_PHP;
if (!dir || !out || !PHP) {
  console.error("Usage: SW_PHP=<php> node tools/song-changes.mjs <music folder> <out dir> [--crossfade 6] [--piece 6]");
  process.exit(1);
}
const app = join(dirname(fileURLToPath(import.meta.url)), "..", "app");
const files = readdirSync(dir).filter((f) => /\.mp3$/i.test(f)).sort();
if (files.length < 2) { console.error("Need at least two MP3s in " + dir); process.exit(1); }
for (const d of ["prepared", "clips"]) mkdirSync(join(out, d), { recursive: true });
const num = (i) => String(i + 1).padStart(2, "0");

// ---- A small web server for the headless page: the app's prep scripts, the songs, and saving ----
const types = { ".js": "text/javascript", ".mp3": "audio/mpeg", ".html": "text/html" };
const server = createServer((q, r) => {
  const u = decodeURIComponent(new URL(q.url, "http://x").pathname);
  const send = (path) => { r.setHeader("content-type", types[path.slice(path.lastIndexOf("."))] || "application/octet-stream"); r.setHeader("content-length", statSync(path).size); createReadStream(path).pipe(r); };
  if (u === "/") { r.setHeader("content-type", "text/html"); return r.end('<!doctype html><meta charset="utf-8"><script src="/assets/prep-core.js"></script><script src="/assets/vendor/lame.min.js"></script><script src="/assets/prep.js"></script>'); }
  if (q.method === "POST" && u.startsWith("/save/")) {
    const rel = u.slice(6);
    if (!/^(prepared|clips)\/[\w.-]+$/.test(rel)) { r.statusCode = 403; return r.end(); }
    const parts = []; q.on("data", (c) => parts.push(c)); q.on("end", () => { writeFileSync(join(out, rel), Buffer.concat(parts)); r.end("ok"); });
    return;
  }
  if (/^\/assets\/(prep-core|prep|prep-worker)\.js$|^\/assets\/vendor\/lame\.min\.js$/.test(u)) return send(join(app, u));
  if (u.startsWith("/song/") && files.includes(u.slice(6))) return send(join(dir, u.slice(6)));
  if (/^\/prepared\/\d+\.mp3$/.test(u) && existsSync(join(out, u))) return send(join(out, u));
  r.statusCode = 404; r.end();
});
await new Promise((ok) => server.listen(0, "127.0.0.1", ok));
const web = `http://127.0.0.1:${server.address().port}`;

// ---- Headless Edge, driven over its debug port ----
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const exe = "C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe";
const profile = mkdtempSync(join(tmpdir(), "sw-changes-"));
const port = 9500 + Math.floor(Math.random() * 90);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "--mute-audio", "about:blank"], { stdio: "ignore" });
const endEdge = () => spawnSync("powershell", ["-NoProfile", "-Command", `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${profile}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: "ignore" });
for (const sig of ["SIGINT", "SIGTERM"]) process.on(sig, () => { console.error("stopped: closing the browser"); endEdge(); process.exit(1); }); // a long run stopped early
let ws = null, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const run = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text);
  return r.result?.result?.value;
};

// In the page: prepare one song exactly as the upload page does, and save the result.
const PREPARE = `async (name, n) => {
  const blob = await (await fetch("/song/" + encodeURIComponent(name))).blob();
  const p = await SwPrep.prepare(new File([blob], name, { type: "audio/mpeg" }));
  if (!p.prepared) throw new Error(name + ": " + p.note);
  await fetch("/save/prepared/" + n + ".mp3", { method: "POST", body: p.file });
  return p.info;
}`;

// In the page: one song change, mixed as the player mixes it. The outgoing song plays on until
// its fade ends (cos curve from the boundary); the next starts at the boundary and fades in (sin).
// t is seconds from the boundary; the mix covers ±30 s, the clip ±7.5 s.
const MIX = `async (c) => {
  const W = 30, CLIP = 7.5, rate = 44100, STEP = 441;
  window.cache = window.cache || new Map();
  const decode = async (url) => {
    if (!cache.has(url)) {
      if (cache.size > 2) cache.delete(cache.keys().next().value);
      const b = await new OfflineAudioContext(2, 1, rate).decodeAudioData(await (await fetch(url)).arrayBuffer());
      cache.set(url, [b.getChannelData(0), b.numberOfChannels > 1 ? b.getChannelData(1) : b.getChannelData(0)]);
    }
    return cache.get(url);
  };
  const A = await decode(c.prev), B = await decode(c.next), N = 2 * W * rate, f = c.fade;
  const mix = [new Float32Array(N), new Float32Array(N)];
  const cols = Math.floor(N / STEP), pa = new Float32Array(cols), pb = new Float32Array(cols), po = new Float32Array(cols);
  let over = 0, peak = 0;
  for (let s = 0; s < N; s++) {
    const t = s / rate - W, x = t / f;
    const ga = t < 0 ? 1 : x < 1 ? Math.cos(x * Math.PI / 2) : 0, gb = t < 0 ? 0 : x < 1 ? Math.sin(x * Math.PI / 2) : 1;
    const ia = Math.round((c.play + t) * rate), ib = Math.round(t * rate), col = Math.floor(s / STEP);
    for (let ch = 0; ch < 2; ch++) {
      const a = ia >= 0 && ia < A[ch].length ? A[ch][ia] * ga : 0, b = ib >= 0 && ib < B[ch].length ? B[ch][ib] * gb : 0, o = a + b;
      mix[ch][s] = o;
      const va = Math.abs(a), vb = Math.abs(b), vo = Math.abs(o);
      if (va > pa[col]) pa[col] = va; if (vb > pb[col]) pb[col] = vb; if (vo > po[col]) po[col] = vo;
      if (vo > peak) peak = vo; if (vo > 1) over++;
    }
  }
  const C = SwPrepCore, at = (t) => Math.round((t + W) * rate), dB = (v) => (v > 1e-6 ? 20 * Math.log10(v) : -120);
  const lufs = (t0, t1) => { const v = C.loudness(mix.map((x) => x.subarray(at(t0), at(t1))), rate); return v === null ? null : Math.round(v * 10) / 10; };
  // runs of 100 ms steps where the momentary loudness (400 ms) is below a level, as [from, to] in s
  const momentary = [];
  for (let t = -W; t + 0.4 <= W; t += 0.1) momentary.push(C.loudness(mix.map((x) => x.subarray(at(t), at(t + 0.4))), rate) ?? -99);
  const runs = (below) => { const r = []; let s = -1; momentary.forEach((v, i) => { if (v < below) { if (s < 0) s = i; } else if (s >= 0) { r.push([s, i]); s = -1; } }); if (s >= 0) r.push([s, momentary.length]); return r.map(([a, b]) => [Math.round((-W + a * 0.1 + 0.2) * 10) / 10, Math.round((-W + b * 0.1 + 0.2) * 10) / 10]); };
  // the longest run that touches the change (from 20 s before the boundary to 10 s after the fade)
  const near = (rs) => rs.filter(([a, b]) => b > -20 && a < f + 10).sort((x, y) => (y[1] - y[0]) - (x[1] - x[0]))[0] || null;
  const silence = near(runs(-60)), quiet = near(runs(-36));
  // the clip, as a 128 kbps MP3 with lamejs (as the upload page encodes)
  const clip = mix.map((x) => x.subarray(at(-CLIP), at(CLIP)));
  const chunks = C.encode(lamejs, clip, rate, 128);
  await fetch("/save/clips/" + c.n + ".mp3", { method: "POST", body: new Blob(chunks, { type: "audio/mpeg" }) });
  const q = (p) => Array.from(p, (v) => Math.min(1000, Math.round(v * 1000)));
  return {
    peak_db: Math.round(dB(peak) * 10) / 10, over, silence, quiet,
    before: lufs(-6, 0), after: lufs(f, f + 6), overlap: lufs(0, f),
    cols: { a: q(pa), b: q(pb), out: q(po) },
  };
}`;

let result = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.navigate", { url: web + "/" });
  for (let i = 0; i < 50 && (await run("typeof SwPrep").catch(() => "")) !== "object"; i++) await sleep(100);

  // 1. Prepare each song (kept for next time, keyed by name and size)
  const infoFile = join(out, "prepared", "info.json");
  const known = existsSync(infoFile) ? JSON.parse(readFileSync(infoFile, "utf8")) : {};
  const songs = [];
  for (const [i, name] of files.entries()) {
    const key = name + ":" + statSync(join(dir, name)).size, n = num(i);
    if (!known[key] || !existsSync(join(out, "prepared", n + ".mp3"))) {
      process.stdout.write(`preparing ${name}… `);
      known[key] = await run(`(${PREPARE})(${JSON.stringify(name)}, ${JSON.stringify(n)})`);
      writeFileSync(infoFile, JSON.stringify(known, null, 1));
      console.log(`${known[key].lufs_before} → ${known[key].lufs} LUFS`);
    }
    songs.push({ n, name, title: name.replace(/\.mp3$/i, ""), prep: known[key] });
  }

  // 2. Pieces, as the library cuts them on upload (Mp3::scan)
  const scan = spawnSync(PHP, ["-r", `require ${JSON.stringify(join(app, "src", "Mp3.php"))}; $o = []; foreach (array_slice($argv, 1) as $f) { $s = Stationwagon\\Mp3::scan($f, ${PIECE}); $o[] = ['duration' => $s['duration'], 'pieces' => array_map(fn($p) => $p[2], $s['pieces'])]; } echo json_encode($o);`,
    ...songs.map((s) => join(out, "prepared", s.n + ".mp3"))], { encoding: "utf8", maxBuffer: 1 << 26 });
  if (scan.status !== 0) throw new Error("Mp3::scan: " + scan.stderr + scan.stdout);
  JSON.parse(scan.stdout).forEach((s, i) => Object.assign(songs[i], s));

  // 3. Each change, planned as the planner plans it: move on at the last piece boundary that still
  // leaves CROSSFADE seconds of the song; the player fades over min(CROSSFADE, what's left)
  const changes = [];
  for (let i = 0; i + 1 < songs.length; i++) {
    const s = songs[i], target = Math.max(0, s.duration - CROSSFADE);
    let play = 0, count = 0;
    for (const sec of s.pieces) { if (count > 0 && play + sec > target + 1e-6) break; count++; play += sec; }
    const fade = Math.max(0.3, Math.min(CROSSFADE, s.duration - play + 0.001));
    process.stdout.write(`mixing ${s.n} → ${songs[i + 1].n}… `);
    const m = await run(`(${MIX})(${JSON.stringify({ n: s.n, prev: `/prepared/${s.n}.mp3`, next: `/prepared/${songs[i + 1].n}.mp3`, play, fade })})`);
    changes.push({ from: i, to: i + 1, clip: `clips/${s.n}.mp3`, play: Math.round(play * 1000) / 1000, fade: Math.round(fade * 1000) / 1000, slot: i % 2, ...m });
    console.log(`peak ${m.peak_db} dB, quiet ${m.quiet ? (m.quiet[1] - m.quiet[0]).toFixed(1) + " s" : "none"}`);
  }
  result = { made: new Date().toISOString(), folder: basename(dir), crossfade: CROSSFADE, piece: PIECE, songs: songs.map(({ pieces, ...s }) => s), changes };
} catch (e) {
  console.error("ERROR " + e.message);
  process.exitCode = 1;
} finally {
  try {
    const b = new WebSocket((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    await new Promise((ok, bad) => { b.onopen = ok; b.onerror = bad; });
    b.send(JSON.stringify({ id: 1, method: "Browser.close" })); await sleep(1000);
  } catch { /* closed */ }
  proc.kill();
  endEdge();
  server.close();
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* locked */ }
}
if (result) {
  writeFileSync(join(out, "data.js"), "window.SONG_CHANGES = " + JSON.stringify(result) + ";\n");
  writeFileSync(join(out, "index.html"), readFileSync(join(dirname(fileURLToPath(import.meta.url)), "song-changes.html"), "utf8"));
  console.log(`${result.changes.length} song changes written to ${out}`);
}
