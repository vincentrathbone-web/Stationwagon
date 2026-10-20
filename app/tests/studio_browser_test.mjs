// End-to-end test of the live studio in a real browser (headless Edge or Chrome, with Chromium's
// fake microphone) on Laragon. A throwaway DJ without a block can't go live; one with a live block
// switches the studio on, plans a running order, goes live (Deck A picks up the AutoDJ song in
// sync), sends real MP3 pieces that reach the listener stream, crossfades to Deck B, posts and
// removes a presenter note from the drawer, and ends the show from the page. Takes about 1.5 minutes.
//   node app/tests/studio_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH. It goes live on the station (Laragon only).
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/studio_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
const profile = mkdtempSync(join(tmpdir(), "sw-studio-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run",
  "--autoplay-policy=no-user-gesture-required", "--use-fake-ui-for-media-stream", "--use-fake-device-for-media-stream", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
/** Run in the page as if the DJ had clicked (sound and the mic need a user gesture). */
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const state = () => evaluate("window.SwStudio && SwStudio.state()");
async function until(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await state().catch(() => null); if (s && fn(s)) return s; await sleep(150); }
  return null;
}
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);
const click = (sel) => evaluate(`(() => { const b = document.querySelector(${JSON.stringify(sel)}); if (!b) return false; b.click(); return true; })()`);
const clickText = (sel, label) => evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(sel)})].find((x) => x.textContent.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);
async function signIn(user) {
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(user.email)}; f.password.value = ${JSON.stringify(user.password)}; f.submit(); })()`);
  for (let i = 0; i < 50 && !(await evaluate("location.pathname").catch(() => "")).endsWith("/manage"); i++) await sleep(100);
  await go(`${base}/studio`);
  await until((s) => s.slot, 10);
}

let lone = null, dj = null, liveId = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.enable");

  console.log("A DJ without a show");
  lone = helper("dj");
  await signIn(lone);
  let s = await state();
  check("the studio opens for a signed-in DJ", !!s && s.slot !== null);
  check("  Go live stays off outside their block", s && !s.slot.may && (await evaluate("document.getElementById('stGo').disabled")), s && s.slot.reason);
  check("  and the banner says why", (await text("#stBannerText")).includes("Ask a manager"), await text("#stBannerText"));
  await evaluate("document.querySelector('header form[action$=\"signout\"]').submit()");
  await sleep(800);

  console.log("A DJ in their own block");
  dj = helper("dj", "block");
  await signIn(dj);
  s = await state();
  check("the studio knows the show", s && s.slot.may && s.slot.title === "[test] Studio test show", s && JSON.stringify(s.slot.title));
  check("  and shows it in the top bar", (await text("#stShow")).startsWith("[test] Studio test show"), await text("#stShow"));
  check("  Go live waits until the studio is on", await evaluate("document.getElementById('stGo').disabled"));
  await clickText("#stBannerActs button", "Switch on the studio");
  s = await until((x) => x.on, 10);
  check("switching on starts sound and the (fake) microphone", !!s, s ? "" : JSON.stringify((await state()).events.slice(-3)));
  check("  lists the microphone", (await evaluate("document.getElementById('dev').options.length")) >= 1);
  s = await until((x) => x.mic !== null && x.mic > -80, 5);
  check("  and the mic meter moves", !!s, s && s.mic.toFixed(1) + " dB");
  check("Go live is ready", !(await evaluate("document.getElementById('stGo').disabled")));

  console.log("Running order");
  await evaluate("(() => { const q = document.getElementById('libQ'); q.value = 'a'; q.dispatchEvent(new Event('input')); })()");
  await sleep(1200);
  const results = await evaluate("document.querySelectorAll('#libResults li:not(.none)').length");
  check("the library search finds songs", results > 0, `${results} found`);
  await clickText("#libResults li:first-child button", "+ Song");
  await evaluate("(() => { document.getElementById('talkTitle').value = 'Welcome and prayer'; document.getElementById('talkMin').value = '4'; document.getElementById('talkForm').requestSubmit(); })()");
  for (let i = 0; i < 30 && (await text("#roSaved")) !== "Saved"; i++) await sleep(200);
  check("a song and a talk item are added and saved", (await text("#roSaved")) === "Saved" && (await state()).order.length === 2, await text("#roSaved"));
  await go(`${base}/studio`);
  s = await until((x) => x.order.length === 2, 8);
  check("  still there after a reload", !!s && s.order[0].startsWith("song:") && s.order[1] === "talk:Welcome and prayer", s && s.order.join(", "));
  check("  with planned times and the fill bar", (await evaluate("document.querySelectorAll('#ro li .at').length")) === 2 && (await text("#fillTotal")).includes("planned of 24:00:00"), await text("#fillTotal"));
  await clickText("#stBannerActs button", "Switch on the studio");
  await until((x) => x.on, 10);
  await clickText("#ro li:first-child .ld button", "B");
  for (let i = 0; i < 40 && (await text("#stB")) !== "Ready"; i++) await sleep(150);
  check("a running-order song loads on Deck B", (await text("#stB")) === "Ready" && (await text("#sB")).startsWith("Running order · item 1"), `${await text("#stB")} · ${await text("#sB")}`);

  console.log("Going live");
  await click("#stGo");
  s = await until((x) => x.live, 8);
  liveId = s && s.live.public_id;
  check("Go live starts a show", !!liveId, s ? "" : await text("#stBannerText"));
  check("  the button says Joining", (await text("#stGoLabel")) === "Joining…" || (s && s.live.sending), await text("#stGoLabel"));
  s = await until((x) => x.decks.A.playing && x.decks.A.drift !== null, 8);
  check("Deck A picks up the AutoDJ song", !!s && !!s.decks.A.title, s && s.decks.A.title);
  check("  exactly where the AutoDJ is (within 30 ms)", s && Math.abs(s.decks.A.drift) < 0.03, s && (s.decks.A.drift * 1000).toFixed(1) + " ms");
  s = await until((x) => x.live && x.live.sending, 12);
  check("the studio goes on air at the handover time", !!s && Math.abs(s.live.firstAt - s.live.start) < 0.05, s && `first sample ${((s.live.firstAt - s.live.start) * 1000).toFixed(1)} ms from the start`);
  check("  the button shows the time on air", (await text("#stGoLabel")).startsWith("On air"), await text("#stGoLabel"));
  await click("#talk");
  s = await until((x) => x.talk, 2);
  check("Mic on air latches", !!s && (await text("#talk")).startsWith("Mic live"));
  s = await until((x) => x.sent >= 3, 30);
  check("pieces go out every 6 s", !!s, s ? `${s.sent} sent` : JSON.stringify((await state()).events.slice(-4)));
  check("  with nothing waiting and no errors", s && s.queue <= 1 && s.errors === 0, s && `queue ${s.queue}, errors ${s.errors}`);
  let info = helper("live-info", liveId);
  check("the server has them, in order, as whole MP3s of about 6 s", info.pieces.length >= 3 && info.pieces.every((p, i) => p.seq === i && Math.abs(p.duration - 6) < 0.1 && Math.abs(p.scanned - p.duration) < 0.05),
    info.pieces.map((p) => `${p.seq}:${p.duration}/${p.scanned}`).join(" "));
  check("  under the DJ's name and their block's title", info.dj_user_id === dj.id && info.title === "[test] Studio test show");
  const level = await evaluate(`(async () => {
    const ctx = new OfflineAudioContext(2, 44100, 44100);
    const buf = await ctx.decodeAudioData(await (await fetch(${JSON.stringify(`${base}/storage/live/${liveId}/00001.mp3`)})).arrayBuffer());
    let sum = 0; const d = buf.getChannelData(0); for (const v of d) sum += v * v;
    return { secs: buf.duration, rms: Math.sqrt(sum / d.length) };
  })()`);
  check("  holding the music (not silence)", level.rms > 0.005 && level.secs > 5.5, `rms ${level.rms.toFixed(4)}, ${level.secs.toFixed(2)} s`);
  const playlist = await (await fetch(`${base}/api/stream.php`, { cache: "no-store" })).text();
  check("listeners' stream carries the live pieces", playlist.includes(`storage/live/${liveId}/`), playlist.split("\n").filter((l) => l.includes("storage/")).slice(-1)[0]);

  console.log("Output strip");
  // the mic off (its ducking follows speech no one can plan), and Deck A's short test tone
  // from the start, so there is music all through this part
  const micWasOn = (await state()).talk;
  if (micWasOn) await click("#talk");
  await click("#xA"); await click("#pA");
  await until((x) => x.decks.A.playing, 3); await sleep(2500);
  const dB = (v) => (v > 1e-5 ? 20 * Math.log10(v) : -99), dBs = (a) => (a ? a.map((v) => dB(v).toFixed(1)).join(" / ") : "none");
  s = await until((x) => x.wave.meter && x.wave.ahead !== null, 5);
  check("the output strip measures what goes out, in the sound engine", s && s.wave.meter === "sample-exact", s && s.wave.meter);
  let w = await evaluate("(() => { const n = SwStudio.wave.now(); return SwStudio.wave.measured(n - 2, n - 0.2); })()");
  check("  Deck A shows in its own colour, and the output with it", w && w[0] > 0.01 && w[1] === 0 && w[3] > 0.01, dBs(w) + " (A / B / mic / out, dB)");
  check("  the coming seconds are mixed ahead", s && s.wave.ahead > 10, s && s.wave.ahead + " s");
  // what the strip mixes ahead must be what goes out when that moment comes
  const ahead = async (wait) => {
    const p = await evaluate("(() => { const n = SwStudio.wave.now(); return { t0: n + 2, t1: n + 3, p: SwStudio.wave.planned(n + 2, n + 3) }; })()");
    await sleep(wait);
    return { plan: p.p, got: await evaluate(`SwStudio.wave.measured(${p.t0}, ${p.t1})`) };
  };
  const same = (c) => c.plan && c.got && [0, 1, 3].every((j) => Math.abs(dB(c.plan[j]) - dB(c.got[j])) < 1);
  let cmp = await ahead(3800);
  check("  what it mixed ahead is what went out, each deck and the output (within 1 dB)", same(cmp), `planned ${dBs(cmp.plan)}, measured ${dBs(cmp.got)}`);
  await click("#stOutTg");
  check("the toggle on the left hides the strip", (await evaluate("document.getElementById('stOutBox').hidden")) && !(await state()).wave.open);
  await click("#stOutTg");
  check("  and shows it again", (await state()).wave.open);
  const keyOnGrip = (k) => evaluate(`document.getElementById('stOutGrip').dispatchEvent(new KeyboardEvent('keydown', { key: '${k}', bubbles: true }))`);
  check("  it starts 50 px high", (await state()).wave.height === 50, (await state()).wave.height + " px");
  await keyOnGrip("ArrowDown"); await keyOnGrip("ArrowDown");
  check("  its handle makes it taller (arrow keys too)", (await state()).wave.height === 70, (await state()).wave.height + " px");
  check("  and the height is remembered", (await evaluate("JSON.parse(localStorage.getItem('sw.studio.wave')).height")) === 70);
  await keyOnGrip("ArrowUp"); await keyOnGrip("ArrowUp");

  console.log("Mixing");
  await click("#fadeBtn");
  cmp = await ahead(3800);
  check("during Fade to B the strip mixes both decks ahead", cmp.plan && cmp.plan[0] > 0.001 && cmp.plan[1] > 0.001, dBs(cmp.plan));
  check("  and that is what went out (within 1 dB)", same(cmp), `planned ${dBs(cmp.plan)}, measured ${dBs(cmp.got)}`);
  s = await until((x) => x.xf === 100 && x.decks.B.playing && !x.decks.A.playing, 10);
  if (micWasOn) await click("#talk");
  check("Fade to B crossfades to Deck B and stops Deck A", !!s, s ? "" : JSON.stringify((await state()).decks));
  check("  the running order marks that song as on now", s && s.nowItem === 0 && (await evaluate("document.querySelector('#ro li').classList.contains('now')")));

  console.log("Presenter notes");
  await click("#stNotesBtn");
  check("the Notes button opens the drawer", !(await evaluate("document.getElementById('stDrawer').hidden")));
  check("  posting is open while on air", !(await evaluate("document.getElementById('notePost').disabled")));
  await evaluate(`(() => { const f = document.querySelector('.st-nf[data-for="verse"]'); f.querySelector('[name=ref]').value = 'Psalm 23:1'; f.querySelector('[name=text]').value = 'The LORD is my shepherd; I shall not want.'; document.getElementById('noteForm').requestSubmit(); })()`);
  s = await until((x) => x.notes.length === 1, 6);
  check("a verse is posted from the drawer", !!s && helper("live-info", liveId).notes === 1);
  check("  the drawer says when listeners will see it", (await text("#noteList li .st-wait")).startsWith("Listeners see it at"), await text("#noteList li .st-nhead"));
  check("  the Notes button counts it", (await text("#stNotesCount")) === "1");
  await clickText("#noteList li button", "Remove");
  check("Remove asks first, in the page", (await text("#noteList li .st-nrm")).includes("Remove this note?"));
  await clickText("#noteList li .st-nrm button", "Remove");
  s = await until((x) => x.notes.length === 0, 6);
  check("  then removes it", !!s && helper("live-info", liveId).notes === 0);
  await clickText(".st-kinds button", "Note");
  await evaluate(`(() => { document.querySelector('.st-nf[data-for="note"] [name=text]').value = 'Thank you for listening.'; document.getElementById('noteForm').requestSubmit(); })()`);
  s = await until((x) => x.notes.length === 1, 6);
  check("a plain note is posted too", !!s);
  await evaluate(`(() => { document.querySelector('.st-nf[data-for="note"] [name=text]').value = ''; document.getElementById('noteForm').requestSubmit(); })()`);
  await sleep(1200);
  check("  an empty note says why and isn't posted", !(await evaluate("document.getElementById('noteErr').hidden")) && (await state()).notes.length === 1, await text("#noteErr"));
  await click("#stDrawerClose");

  console.log("Ending the show");
  await click("#stGo");
  check("pressing On air asks before ending, in the page", (await text("#stBannerText")).startsWith("End the show?"));
  await clickText("#stBannerActs button", "End show");
  s = await until((x) => !x.live, 20);
  info = helper("live-info", liveId);
  check("End show sends the last audio and ends the show", !!s && !info.open && info.closed_reason === "ended by DJ", `${info.closed_reason}, ${info.pieces.length} pieces`);
  check("  the banner says the AutoDJ is back", (await text("#stBannerText")).startsWith("Handed back to the AutoDJ"), await text("#stBannerText"));
  const tl = await (await fetch(`${base}/api/timeline.php`, { cache: "no-store" })).json();
  const after = tl.entries.filter((e) => e.type === "song" && e.start >= tl.entries.find((x) => x.public_id === liveId).end - 0.01);
  check("  and the timeline carries on with songs after it", after.length > 0, after[0] && after[0].title);
  liveId = null;
  if (failed) console.log("Studio events:\n    " + ((await state()).events || []).map((e) => e.m).join("\n    "));
} finally {
  try { if (dj) helper("studio-cleanup", String(dj.id), String(dj.block || 0)); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (lone) helper("studio-cleanup", String(lone.id)); } catch { /* gone */ }
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
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still locked */ }
  console.log("Cleanup: test DJs, their block, running orders, notes and any open show removed");
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
