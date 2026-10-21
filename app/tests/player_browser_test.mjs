// End-to-end test of the listener player in a real browser (headless Edge or Chrome) on Laragon:
// it joins mid-song at the listener delay, crossfades at a song boundary, hands over to a live
// show (made here, with generated 6 s pieces) and back to the AutoDJ, with a presenter note that
// appears when listeners reach it, and the stream-only mode
// holds the same delay; the embed (framed by another page) shows what's on and plays at that
// delay too. Takes about four minutes.
//   node app/tests/player_browser_test.mjs http://localhost:8080/stationwagon-app
//   node app/tests/player_browser_test.mjs https://wordwave.controll.co.za/stationwagon-app --listen-only
// Needs SW_PHP (php.exe) unless php is on PATH. The live part goes live on the station (through the
// local database), so a public server gets --listen-only: joining, crossfade and stream only.
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const base = (process.argv[2] || "").replace(/\/$/, "");
const listenOnly = process.argv.includes("--listen-only");
if (!base) { console.error("Usage: node app/tests/player_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- Live pieces: 6 s of tone each, as the studio would send them ---------------------------
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const dir = mkdtempSync(join(tmpdir(), "sw-player-test-"));
function piece(seq) {
  const RATE = 44100, n = 230 * 1152, l = new Int16Array(n);
  for (let i = 0; i < n; i++) l[i] = Math.round(0.3 * 32767 * Math.sin(2 * Math.PI * 330 * (seq * n + i) / RATE));
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, 192), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), l.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const file = join(dir, `piece-${seq}.mp3`);
  writeFileSync(file, Buffer.concat(out));
  return file;
}

// ---- Browser ----------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
const profile = mkdtempSync(join(tmpdir(), "sw-player-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run",
  "--autoplay-policy=no-user-gesture-required",
  // the test mark keeps this run's listening out of the station's stats (src/Agent.php)
  "--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0 StationwagonTest",
  // the embed part frames this server from a made-up church website, which Edge would otherwise
  // block from reaching a localhost address (a real website framing a public server is fine)
  "--disable-features=LocalNetworkAccessChecks", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}, sessionId = undefined) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params, sessionId })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const state = () => evaluate("window.SwPlayer && SwPlayer.state()");
/** Poll state() every 100 ms until fn(state) is true (returns it) or the time runs out (null). */
async function until(fn, seconds, log = null) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state(); if (log) log.push(s); if (s && fn(s)) return s; await sleep(100); }
  return null;
}
const audible = (s) => s.decks.filter((d) => d.playing && d.gain > 0.5);
const drift = (s, d) => d.pos - (s.heard - s.current.start);

// Song file traffic, from the browser's own network log: bytes that came over the network per file
// (the decks and the strip's look-ahead together), and each file's size.
const files = new Map(), reqs = new Map();
function media({ method, params: p }) {
  if (method === "Network.responseReceived" && p.response.url.includes("storage/media")) {
    const url = p.response.url.split("?")[0], h = Object.fromEntries(Object.entries(p.response.headers).map(([k, v]) => [k.toLowerCase(), v]));
    const size = +((h["content-range"] || "").split("/")[1] || (p.response.status === 200 ? h["content-length"] : 0) || 0);
    const f = files.get(url) || { size: 0, sent: 0 };
    if (size) f.size = size;
    files.set(url, f); reqs.set(p.requestId, url);
  } else if (method === "Network.loadingFinished" && reqs.has(p.requestId)) {
    files.get(reqs.get(p.requestId)).sent += p.encodedDataLength;
  } else if (method === "Network.requestServedFromCache") {
    reqs.delete(p.requestId);
  }
}

let show = null, feeder = null, notesShow = null, onPaused = () => {};
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    else if (d.method === "Fetch.requestPaused") onPaused(d.params);
    else if (d.method) media(d);
  };
  await call("Page.enable");
  await call("Network.enable");

  console.log("Joining");
  await go(`${base}/`);
  check("the station page has the player", await evaluate("!!document.getElementById('player') && typeof SwPlayer === 'object'"));
  const before = await evaluate("document.getElementById('lpTitle').textContent");
  check("it shows what's on before Listen is pressed", before !== "" && before !== "Loading…", before);
  await evaluate("SwPlayer.start()");
  let s = await until((x) => x.current && x.current.type === "song" && audible(x).some((d) => d.key === x.current.key), 10);
  check("Listen joins the song the delayed timeline is on", !!s, s && s.current.title);
  check("  at the right moment (within 0.3 s)", s && Math.abs(drift(s, audible(s)[0])) < 0.3, s && drift(s, audible(s)[0]).toFixed(3) + " s");
  check("  and says it's playing", s && s.status === "Playing" && s.delay === 20, s && s.status);
  // counted as listening: a ping with a random id, and the health line shows the count
  s = await until((x) => x.ping && x.listeners >= 1, 5);
  const shownCount = await evaluate("document.getElementById('lpListeners').textContent");
  check("  it counts itself as listening (a ping with a random id)", !!s && /^[0-9a-f]{16}$/.test(s.ping) && shownCount === String(s.listeners), s ? `${s.ping}, ${shownCount} listening` : "no ping");
  const listeningWhilePlaying = s ? s.listeners : 0;

  console.log("Crossfade");
  const log = [];
  const first = s && s.current.key;
  s = await until((x) => x.current && x.current.key !== first, 75, log);
  check("the next song comes at its boundary", !!s, s && s.current.title);
  const both = (await until((x) => x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2, 3, log)) || log.find((x) => x && x.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2);
  check("  with both decks blending", !!both, both && both.decks.map((d) => d.gain).join(" / "));
  s = await until((x) => audible(x).length === 1 && audible(x)[0].key === x.current.key && x.decks.filter((d) => d.playing).length === 1, 10);
  check("  then only the new song plays", !!s, s && s.current.title);
  check("  on time", s && Math.abs(drift(s, audible(s)[0])) < 0.3, s && drift(s, audible(s)[0]).toFixed(3) + " s");

  console.log("Output strip");
  const dB = (v) => (v > 1e-5 ? 20 * Math.log10(v) : -99), dBs = (a) => (a ? a.map((v) => dB(v).toFixed(1)).join(" / ") : "none");
  s = await until((x) => x.wave && x.wave.meter && x.wave.ahead !== null, 5);
  check("the output strip measures what goes out, in the sound engine", s && s.wave.meter === "sample-exact", s && s.wave.meter);
  check("  and reads the coming 20 s itself (song pieces)", s && s.wave.ahead > 15 && s.wave.pieces > 0, s && `${s.wave.ahead} s ahead, ${s.wave.pieces} pieces`);
  // what the strip mixed ahead must be what goes out when that moment comes (A / B / live / output)
  const aheadCheck = async (from) => {
    const p = await evaluate(`(() => { const n = SwPlayer.wave.now(); return { t0: n + ${from}, t1: n + ${from + 1}, p: SwPlayer.wave.planned(n + ${from}, n + ${from + 1}) }; })()`);
    await sleep((from + 1.6) * 1000);
    return { plan: p.p, got: await evaluate(`SwPlayer.wave.measured(${p.t0}, ${p.t1})`) };
  };
  const same = (c, series) => c.plan && c.got && series.every((j) => Math.abs(dB(c.plan[j]) - dB(c.got[j])) < 1);
  let cmp = await aheadCheck(4);
  check("  what it mixed ahead is what went out (within 1 dB)", same(cmp, [0, 1, 3]), `planned ${dBs(cmp.plan)}, measured ${dBs(cmp.got)} (A / B / live / out, dB)`);
  const reads = await evaluate("(() => { const r = performance.getEntriesByType('resource').filter((e) => e.initiatorType === 'fetch' && e.name.includes('storage/media')); return { n: r.length, sent: r.reduce((a, e) => a + e.transferSize, 0), body: r.reduce((a, e) => a + e.decodedBodySize, 0) }; })()");
  // The strip reads ranges of the same files the decks play, so between them each file should come
  // over the network once at most (the browser's cache serves the rest), never twice.
  const fl = [...files.values()].filter((f) => f.size), sent = fl.reduce((a, f) => a + f.sent, 0), size = fl.reduce((a, f) => a + f.size, 0);
  check("  those reads don't download the songs a second time", reads.n > 0 && fl.length > 0 && sent <= size * 1.05 + 4096 * fl.length,
    `${reads.n} reads of ${reads.body} bytes; ${fl.length} song files of ${size} bytes, ${sent} over the network: ` +
    fl.map((f) => `${f.sent}/${f.size}`).join(", "));
  // looked at a few times over 2 s: a look just as a song ends finds the strip nearly empty (quiet)
  let px = null;
  for (let i = 0; i < 6 && !(px && px.a + px.b > 100); i++) {
    if (i) await sleep(400);
    px = await evaluate(`(() => { const c = document.getElementById('lpWave'), g = c.getContext('2d'), d = g.getImageData(0, 0, c.width, c.height).data; let a = 0, b = 0;
      for (let i = 0; i < d.length; i += 4) { if (d[i + 2] > 150 && d[i] < 140 && d[i + 1] > 100) a++; if (d[i] > 150 && d[i + 2] > 100 && d[i + 1] < 140) b++; } return { a, b, w: c.width, h: c.height }; })()`);
  }
  check("  the strip draws the songs in their colours", px.w > 0 && px.a + px.b > 100, `${px.w}×${px.h}, ${px.a} blue and ${px.b} pink pixels`);

  console.log("Frozen sound engine (as a Xiaomi phone does some seconds after locking)");
  await evaluate(`(() => {
    const real = Object.getOwnPropertyDescriptor(BaseAudioContext.prototype, "currentTime"), held = new WeakMap();
    window.__unfreeze = () => Object.defineProperty(BaseAudioContext.prototype, "currentTime", real);
    Object.defineProperty(BaseAudioContext.prototype, "currentTime", { configurable: true, get() {
      if (!held.has(this)) held.set(this, real.get.call(this));
      return held.get(this);
    } });
  })()`);
  s = await until((x) => x.events.some((e) => e.endsWith("nudge 1")), 4);
  check("a stuck engine clock is noticed and nudged", !!s);
  s = await until((x) => x.events.some((e) => e.includes("starting again")), 6);
  check("  and when nudging doesn't help, the player starts again", !!s, s ? "" : (await state()).events.slice(-3).join(" | "));
  await evaluate("window.__unfreeze()");
  s = await until((x) => x.current && x.current.type === "song" && x.status === "Playing" && audible(x).some((d) => d.key === x.current.key), 10);
  check("  and plays on time again", s && Math.abs(drift(s, audible(s).find((d) => d.key === s.current.key))) < 0.3, s && drift(s, audible(s).find((d) => d.key === s.current.key)).toFixed(3) + " s");

  console.log(listenOnly ? "Live show: skipped (--listen-only)" : "Live show");
  // made beforehand and sent on a fixed beat, like the studio: piece n arrives 6 s × (n + 1) after
  // the start (the server ends a show after 8 s without a piece)
  const pieces = listenOnly ? [] : [0, 1, 2, 3, 4].map(piece);
  if (!listenOnly) show = helper("live-start", "Browser test show");
  if (!listenOnly) check("a test show goes live", !!show.public_id, JSON.stringify(show));
  if (show && show.public_id) {
    let seq = 0;
    const send = () => { if (seq < 5) { helper("live-piece", show.public_id, String(seq), pieces[seq], "auto"); seq++; } };
    feeder = setTimeout(function next() {
      send();
      if (seq < 5) feeder = setTimeout(next, Math.max(0, (show.start + 6.1 + 6 * seq) * 1000 - Date.now()));
    }, Math.max(0, (show.start + 6.1) * 1000 - Date.now()));
    // a presenter note, posted now: listeners 20 s behind must not see it before they hear this moment
    notesShow = show.public_id;
    const postedAt = Date.now();
    const posted = helper("note", show.public_id, "verse", JSON.stringify({ ref: "Psalm 23:1", text: "The LORD is my shepherd; <b>I shall not want.</b>", passage: "Psalm 23" }));
    const nid = posted.note && posted.note.id;
    check("a presenter note is posted", !!nid, JSON.stringify(posted));
    const notes = () => evaluate("window.SwNotes && SwNotes.state()");
    const untilNotes = async (fn, seconds) => { const end = Date.now() + seconds * 1000; while (Date.now() < end) { const n = await notes(); if (n && fn(n)) return n; await sleep(100); } return null; };
    let n = await untilNotes((x) => x.held.includes(nid), 6);
    check("  listeners don't see it before they hear that moment", !!n && !n.shown.includes(nid) && n.hidden, JSON.stringify(n));
    n = await untilNotes((x) => x.shown.includes(nid), 30);
    const after = (Date.now() - postedAt) / 1000;
    check("  it appears 20 s after it was posted", !!n && after > 18.5 && after < 23, after.toFixed(1) + " s");
    check("  tagged Live and New", !!n && n.show.label === "Live" && n.fresh.includes(nid), n && `${n.show.label} / fresh ${n.fresh}`);
    const dom = await evaluate(`(() => { const li = document.querySelector('#lpNotesList li'), q = li.querySelector('blockquote'), t = document.getElementById('lpToast');
      return { text: q.textContent, tags: q.children.length, read: li.querySelector('a').href, toast: t.hidden ? '' : t.textContent }; })()`);
    check("  shown as text, with its Bible Gateway link and a toast", dom.text.includes("<b>I shall") && dom.tags === 0 && dom.read.startsWith("https://www.biblegateway.com/passage/?search=Psalm%2023") && dom.toast.startsWith("New note"), JSON.stringify(dom));
    // the presenter fixes the verse: it comes again as new, and the old one stays until listeners reach the edit
    await sleep(2700); // the first toast has gone
    const editedAt = Date.now();
    const changed = helper("note-edit", show.public_id, String(nid), JSON.stringify({ ref: "Psalm 23:1-2", text: "The LORD is my shepherd; I shall not want. He maketh me to lie down in green pastures.", passage: "Psalm 23" }));
    const nid2 = changed.note && changed.note.id;
    check("the presenter changes the note", !!nid2 && changed.replaces === nid, JSON.stringify(changed));
    n = await untilNotes((x) => x.held.includes(nid2), 6);
    check("  listeners keep the old version until they reach the change", !!n && n.shown.includes(nid) && !n.shown.includes(nid2), n && `shown ${n.shown}, held ${n.held}`);
    s = await until((x) => x.current && x.current.type === "live", 45);
    check("the player reaches the show 20 s later", !!s, s && `heard ${(s.heard - show.start).toFixed(1)} s after its start`);
    s = await until((x) => x.current && x.current.type === "live" && x.stream && x.stream.gain > 0.9, 6);
    check("  and the stream deck takes over", !!s, s && JSON.stringify(s.stream));
    check("  held 20 s behind live", s && s.stream.lag !== null && Math.abs(s.stream.lag - 20) < 0.6, s && String(s.stream.lag && s.stream.lag.toFixed(2)));
    s = await state();
    check("  the output strip reads the live pieces ahead too", s.wave && s.wave.live > 0, s.wave && `${s.wave.live} live pieces`);
    const lc = await aheadCheck(3);
    check("  and what it mixed ahead of the show is what went out (within 1 dB)", same(lc, [2, 3]) && lc.got[2] > 0.01, `planned ${dBs(lc.plan)}, measured ${dBs(lc.got)}`);
    s = await until((x) => x.decks.every((d) => !d.playing), 9); // after the 6 s live cut-in fade
    check("  with the song decks quiet once the fade is done", !!s, s ? "" : JSON.stringify((await state()).decks));
    n = await untilNotes((x) => x.shown.includes(nid2), 30);
    const after2 = (Date.now() - editedAt) / 1000;
    check("the changed note replaces the old one 20 s after the change", !!n && !n.shown.includes(nid) && after2 > 18.5 && after2 < 23, n ? `${after2.toFixed(1)} s, shown ${n.shown}` : JSON.stringify(await notes()));
    const dom2 = await evaluate(`(() => { const t = document.getElementById('lpToast'); return { items: document.querySelectorAll('#lpNotesList li').length, text: document.querySelector('#lpNotesList li blockquote').textContent, toast: t.hidden ? '' : t.textContent }; })()`);
    check("  as new, with a toast", !!n && n.fresh.includes(nid2) && dom2.items === 1 && dom2.text.includes("green pastures") && dom2.toast.startsWith("New note"), JSON.stringify(dom2));
    while (seq < 5) await sleep(500);
    // the listener's tab is in the background when the show ends (another tab in front), as happened in the live test
    const other = (await call("Target.createTarget", { url: "about:blank", background: false })).result?.targetId;
    await sleep(500);
    const vis = await evaluate("document.visibilityState");
    helper("live-end", show.public_id); show = null;
    s = await until((x) => x.current && x.current.type === "song" && audible(x).length === 1, 60);
    check("after the show the AutoDJ comes back", !!s, s && s.current.title);
    const back = s && s.events.filter((e) => e.includes(s.current.title));
    check(`  with a fade from the stream, not a fresh join (tab ${vis})`, !!back && back.some((e) => e.includes("handover to " + s.current.title)) && !back.some((e) => e.includes("join " + s.current.title)), back && back.join(" | "));
    if (other) await call("Target.closeTarget", { targetId: other });
    s = await until((x) => x.stream && !x.stream.active, 5);
    check("  and the stream deck stops", !!s);
    const kept = await untilNotes((x) => x.show && !x.show.live, 10);
    check("  the show's notes stay, no longer Live", !!kept && kept.shown.includes(nid2) && !kept.shown.includes(nid) && kept.fresh.length === 0, kept && kept.show.label);
  }
  if (failed) console.log("Player events:\n    " + ((await state()).events || []).join("\n    "));
  await evaluate("SwPlayer.stop()");
  // Stop takes it off the count at once (its last ping)
  let after = null;
  for (let i = 0; i < 30 && after !== listeningWhilePlaying - 1; i++) { await sleep(200); after = (await (await fetch(`${base}/api/timeline.php`)).json()).listeners; }
  check("Stop takes it off \"Listening now\" at once", listeningWhilePlaying > 0 && after === listeningWhilePlaying - 1 && (await state()).ping === null, `${listeningWhilePlaying} → ${after}`);

  console.log("Stream only");
  await go(`${base}/?mode=stream`);
  await evaluate("SwPlayer.start()");
  s = await until((x) => x.stream && x.stream.active && x.stream.lag !== null && Math.abs(x.stream.lag - 20) < 0.6, 30);
  check("?mode=stream plays the stream at the same 20 s delay", !!s && s.mode === "stream", s && String(s.stream.lag.toFixed(2)));
  await evaluate("SwPlayer.stop()");

  console.log("Embed");
  /** Poll a page expression every 200 ms until it is truthy (returns it) or the time runs out (null). */
  const waitFor = async (expr, seconds) => {
    for (const end = Date.now() + seconds * 1000; Date.now() < end; await sleep(200)) { const v = await evaluate(expr).catch(() => null); if (v) return v; }
    return null;
  };
  // framed by a church website (a made-up address; the browser is handed its page here)
  const host = "https://church.example/";
  onPaused = (p) => call("Fetch.fulfillRequest", { requestId: p.requestId, responseCode: 200, responseHeaders: [{ name: "Content-Type", value: "text/html" }],
    body: Buffer.from(`<!doctype html><title>Church</title><iframe src="${base}/embed" width="470" height="124" allow="autoplay"></iframe>`).toString("base64") });
  await call("Fetch.enable", { patterns: [{ urlPattern: host + "*" }] });
  await call("Page.navigate", { url: host });
  let framed = null;
  for (let i = 0; i < 50 && !framed; i++) {
    await sleep(200);
    // a frame from another site runs as a target of its own
    const t = (await call("Target.getTargets")).result.targetInfos.find((x) => x.type === "iframe" && x.url.startsWith(base));
    if (!t) continue;
    const { sessionId } = (await call("Target.attachToTarget", { targetId: t.targetId, flatten: true })).result;
    for (let j = 0; j < 25 && !framed; j++) {
      framed = (await call("Runtime.evaluate", { expression: "!!document.getElementById('embed') && location.href", returnByValue: true }, sessionId)).result?.result?.value || null;
      if (!framed) await sleep(200);
    }
    if (!framed) framed = (await call("Runtime.evaluate", { expression: "'no player: ' + location.href", returnByValue: true }, sessionId)).result?.result?.value;
  }
  await call("Fetch.disable");
  check("a church website can frame the embed", typeof framed === "string" && !framed.startsWith("no player"), framed || "no frame");
  await go(`${base}/embed`);
  const np = await waitFor("window.swEmbed && swEmbed.np", 10);
  const shown = await evaluate("document.getElementById('emTitle').textContent");
  check("the embed shows what's on air, from the now-playing data", !!(np && np.on_air) && shown.startsWith(np.on_air.title), shown);
  await evaluate("document.getElementById('emPlay').click()");
  const lag = await waitFor("swEmbed.heard && Math.abs(swEmbed.lag - 20) < 0.6 && swEmbed.lag", 30);
  check("Listen plays the stream at the same 20 s delay (silent until then)", !!lag, lag ? lag.toFixed(2) + " s" : String(await evaluate("swEmbed.lag + ' s, page ' + document.visibilityState")));
  check("  and it counts itself as listening too", /^[0-9a-f]{16}$/.test((await evaluate("swEmbed.ping")) || ""), String(await evaluate("swEmbed.ping")));
  const meta = await waitFor("/^\\d+:\\d\\d \\/ \\d+:\\d\\d$|^Live for /.test(document.getElementById('emState').textContent) && document.getElementById('emState').textContent", 3);
  check("  with the time in the song", !!meta, meta || await evaluate("document.getElementById('emState').textContent"));
  await evaluate("document.getElementById('emPlay').click()");
  check("  and stops again", !(await evaluate("swEmbed.playing")) && (await evaluate("document.getElementById('emPlay').getAttribute('aria-pressed')")) === "false");
} finally {
  clearTimeout(feeder);
  if (show && show.public_id) { try { helper("live-end", show.public_id); } catch { /* ended */ } }
  if (notesShow) { try { helper("notes-clear", notesShow); } catch { /* gone */ } }
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
