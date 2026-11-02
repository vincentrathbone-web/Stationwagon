// End-to-end test of the live studio in a real browser (headless Edge or Chrome, with Chromium's
// fake microphone) on Laragon. A throwaway DJ without a block can't go live; one with a live block
// switches the studio on, plans a running order, goes live (Deck A picks up the AutoDJ song in
// sync), sends real MP3 pieces that reach the listener stream, crossfades to Deck B, posts and
// removes a presenter note from the drawer, and ends the show from the page. All the while it records
// the show for the podcast (music and mic apart, in this browser): what a reload left behind uploads
// when the studio opens again, and the rest after End show, which then offers the episode's editor.
// Takes about 2 minutes.
//   node app/tests/studio_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH. It goes live on the station: Laragon, or the test
// server with SW_HELPER_SSH set (see below).
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/studio_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
// SW_HELPER_SSH="<ssh host>:<app folder>" runs the helper on that server instead, for a live test there
const remote = process.env.SW_HELPER_SSH ? process.env.SW_HELPER_SSH.split(":") : null;
const helper = (...args) => JSON.parse(remote
  ? execFileSync("ssh", [remote[0], `cd ${remote[1]} && php tests/browser_helper.php ${args.map((a) => "'" + String(a).replace(/'/g, "'\''") + "'").join(" ")}`], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] })
  : execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
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
const dialogs = [];
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

let lone = null, dj = null, liveId = null, minBefore = null;
try {
  minBefore = helper("podcast-min", "30").before; // the test show runs about a minute and a half
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    // "Leave site?" while on air: leave, as a DJ reloading the page would
    if (d.method === "Page.javascriptDialogOpening") { dialogs.push(d.params.type); call("Page.handleJavaScriptDialog", { accept: true }); }
  };
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
  check("  with how many are listening now", /^\d+ listening$/.test(await text("#stListeners")), await text("#stListeners"));
  check("  Go live waits until the studio is on", await evaluate("document.getElementById('stGo').disabled"));
  await clickText("#stBannerActs button", "Switch on the studio");
  s = await until((x) => x.on, 10);
  check("switching on starts sound and the (fake) microphone", !!s, s ? "" : JSON.stringify((await state()).events.slice(-3)));
  check("  lists the microphone", (await evaluate("document.getElementById('dev').options.length")) >= 1);
  check("  and starts the MP3 encoder in a worker, off the page's main thread", s && s.encoder === "worker", s && String(s.encoder));
  check("  on a computer, no Do Not Disturb reminder (that's for phones and tablets)", s && !s.handheld && !(await text("#stBannerText")).includes("Do Not Disturb"), await text("#stBannerText"));
  s = await until((x) => x.mic !== null && x.mic > -80, 5);
  check("  and the mic meter moves", !!s, s && s.mic.toFixed(1) + " dB");

  console.log("Headphones");
  s = await state();
  const outs = s.output ? s.output.choices : [];
  check("the headphones choice starts on the system default", !!s.output && s.output.sink === "" && outs[0] === "", JSON.stringify(s.output));
  check(outs.length > 2 ? "  and lists this computer's outputs in a menu" : "  with one output here, it shows its name instead of a menu",
    outs.length > 2 ? s.output.shown : !s.output.shown && (await text("#stOut")).length > 0, `${outs.length - 1} outputs: ${await text("#stOut")}`);
  if (outs.length > 2) {
    await evaluate(`(() => { const sel = document.getElementById('stOutDev'); sel.value = ${JSON.stringify(outs[1])}; sel.dispatchEvent(new Event('change')); })()`);
    s = await until((x) => x.output.sink === outs[1], 4);
    check("  picking one moves everything the DJ hears there", !!s, JSON.stringify((await state()).output.sink));
    await evaluate(`(() => { const sel = document.getElementById('stOutDev'); sel.value = ''; sel.dispatchEvent(new Event('change')); })()`);
    s = await until((x) => x.output.sink === "", 4);
    check("  and back to the default", !!s);
  }
  // a device that has gone (unplugged between listing and picking): the studio says so and stays put
  await evaluate(`(() => { const sel = document.getElementById('stOutDev'); const o = new Option('Unplugged headset', 'no-such-device'); sel.add(o); sel.value = 'no-such-device'; sel.dispatchEvent(new Event('change')); })()`);
  for (let i = 0; i < 20 && !(await text("#stBannerText")).includes("Couldn’t switch"); i++) await sleep(150);
  s = await state();
  check("  an output that can't be used says why and keeps the one before", (await text("#stBannerText")).includes("Couldn’t switch your headphones") && s.output.sink === ""
    && (await evaluate("document.getElementById('stOutDev').value")) === "", await text("#stBannerText"));
  await evaluate("document.querySelector('#stOutDev option[value=\"no-such-device\"]').remove()");
  check("Go live is ready", !(await evaluate("document.getElementById('stGo').disabled")));

  console.log("Running order");
  await evaluate("(() => { const q = document.getElementById('libQ'); q.value = 'a'; q.dispatchEvent(new Event('input')); })()");
  let results = 0;
  for (let i = 0; i < 30 && !results; i++) { await sleep(200); results = await evaluate("document.querySelectorAll('#libResults li:not(.none)').length"); }
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
  check("the studio records the show for the podcast as it goes out", s && s.rec && s.rec.recording && s.rec.show === liveId && s.rec.seconds > 10 && !s.rec.error,
    s && JSON.stringify(s.rec));
  check("  and says so in the status strip", (await text("#stRec")).startsWith("● Recording"), await text("#stRec"));
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
  check("Fade to B crossfades to Deck B and stops Deck A", !!s, s ? "" : JSON.stringify((await state()).decks));
  check("  the running order marks that song as on now", s && s.nowItem === 0 && (await evaluate("document.querySelector('#ro li').classList.contains('now')")));

  console.log("Talk items");
  const roClass = () => evaluate("[...document.querySelectorAll('#ro li')].map((li) => li.className || '-').join(' ')");
  check("a talk item has a Now button, not deck buttons", (await evaluate("[...document.querySelectorAll('#ro li:nth-child(2) .ld button')].map((b) => b.textContent).join(',')")).startsWith("Now"),
    await evaluate("[...document.querySelectorAll('#ro li:nth-child(2) .ld button')].map((b) => b.textContent).join(',')"));
  if ((await state()).talk) await click("#talk");
  await click("#talk");
  s = await until((x) => x.talk && x.nowItem === 1, 3);
  check("opening the mic marks the next talk item as now", !!s && (await roClass()) === "done now", await roClass());
  check("  and it loses its Now button", !(await evaluate("[...document.querySelectorAll('#ro li:nth-child(2) .ld button')].some((b) => b.textContent === 'Now')")));
  await evaluate("(() => { document.getElementById('talkTitle').value = 'Closing prayer'; document.getElementById('talkMin').value = '2'; document.getElementById('talkForm').requestSubmit(); })()");
  for (let i = 0; i < 30 && ((await state()).order.length !== 3 || (await text("#roSaved")) !== "Saved"); i++) await sleep(200);
  await click("#talk"); await click("#talk"); // closing and opening the mic again: the next talk item starts
  s = await until((x) => x.talk && x.nowItem === 2, 2);
  check("  closing and opening it again starts the talk item after", !!s && (await roClass()) === "done done now", await roClass());
  await clickText("#ro li:nth-child(2) .ld button", "Now");
  s = await until((x) => x.nowItem === 1, 2);
  check("the Now button marks a talk item by hand (here going back one)", !!s && (await roClass()) === "done now -", await roClass());
  await click("#talk"); await click("#talk");
  s = await until((x) => x.talk && x.nowItem === 2, 2);
  check("  and the mic carries on from there", !!s, await roClass());
  if (!micWasOn) await click("#talk");

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
  const firstId = s.notes[0];
  await clickText("#noteList li .st-nrm button", "Edit");
  check("Edit fills the note's form to change it", (await evaluate(`document.querySelector('.st-nf[data-for="note"] [name=text]').value`)) === "Thank you for listening."
    && (await text("#notePost")) === "Save changes" && !(await evaluate("document.getElementById('noteCancel').hidden")) && (await state()).editing === firstId);
  check("  says listeners get it as new", (await text("#noteHint")).includes("as new"), await text("#noteHint"));
  check("  and keeps its kind", await evaluate(`document.querySelector('.st-kinds [data-kind="verse"]').disabled`));
  await click("#noteCancel");
  check("Cancel leaves the note as it was", (await state()).editing === null && (await text("#notePost")) === "Post note" && (await state()).notes[0] === firstId);
  await clickText("#noteList li .st-nrm button", "Edit");
  await evaluate(`(() => { document.querySelector('.st-nf[data-for="note"] [name=text]').value = 'Thank you for listening. God bless.'; document.getElementById('noteForm').requestSubmit(); })()`);
  s = await until((x) => x.notes.length === 1 && x.notes[0] !== firstId && x.editing === null, 6);
  check("Save changes posts the note again as new", !!s && (await text("#noteList li p")) === "Thank you for listening. God bless." && helper("live-info", liveId).notes === 1,
    `${await text("#noteList li p")} · ${JSON.stringify(s && s.notes)}`);
  check("  listeners get it when they reach this moment", (await text("#noteList li .st-wait")).startsWith("Listeners see it at"), await text("#noteList li .st-nhead"));
  await evaluate(`(() => { document.querySelector('.st-nf[data-for="note"] [name=text]').value = ''; document.getElementById('noteForm').requestSubmit(); })()`);
  await sleep(1200);
  check("  an empty note says why and isn't posted", !(await evaluate("document.getElementById('noteErr').hidden")) && (await state()).notes.length === 1, await text("#noteErr"));
  await click("#stDrawerClose");

  console.log("Rejoining after a reload");
  s = await until((x) => x.rec && x.rec.pieces && x.rec.pieces.music >= 1 && x.rec.pieces.mic >= 1, 40);
  check("by now the recording has whole pieces of both tracks kept in this browser", !!s, JSON.stringify((await state()).rec));
  await go(`${base}/studio`);
  let bannerOk = false;
  for (let i = 0; i < 30 && !bannerOk; i++) { await sleep(200); bannerOk = (await text("#stBannerText")).includes("Switch on the studio to rejoin it"); }
  check("reloading during a show asks first", dialogs.includes("beforeunload"), dialogs.join(", "));
  check("  after the reload the studio says the show is still on and how to rejoin", bannerOk, await text("#stBannerText"));
  await clickText("#stBannerActs button", "Switch on the studio");
  let rejoinBtn = false;
  for (let i = 0; i < 50 && !rejoinBtn; i++) { await sleep(200); rejoinBtn = await clickText("#stBannerActs button", "Rejoin your show"); }
  check("  once on, Rejoin your show carries it on", rejoinBtn);
  s = await until((x) => x.live && x.live.sending, 15);
  const oldId = liveId;
  liveId = s ? s.live.public_id : liveId;
  check("  as a new show at a fresh boundary, Deck A on the AutoDJ song", !!s && liveId !== oldId && Math.abs(s.live.firstAt - s.live.start) < 0.05 && s.decks.A.playing,
    s ? `first sample ${((s.live.firstAt - s.live.start) * 1000).toFixed(1)} ms from the start` : await text("#stBannerText"));
  const oldInfo = helper("live-info", oldId);
  check("  the recording the reload cut short waits in this browser while the show is on (no upload competing with it)",
    (await evaluate(`SwRecorder.waiting(${JSON.stringify(liveId)})`)) === 1 && !(helper("episode-info", oldId) || { takes: [] }).takes.some((x) => x.kind === "device"));
  check("  the first show is closed as rejoined, its notes moved over", oldInfo.closed_reason === "rejoined" && oldInfo.notes === 0 && helper("live-info", liveId).notes === 1,
    `${oldInfo.closed_reason}, notes ${oldInfo.notes} → ${helper("live-info", liveId).notes}`);
  s = await until((x) => x.sent >= 2, 20);
  info = helper("live-info", liveId);
  check("  and its pieces go out in order, with no errors", !!s && s.errors === 0 && info.pieces.length >= 2 && info.pieces.every((p, i) => p.seq === i),
    s ? `${s.sent} sent, ${s.errors} errors` : JSON.stringify((await state()).events.slice(-4)));

  console.log("Ending the show");
  // just after a piece goes out, so the last one is short: under 16 KB it goes as base64 in JSON (some hosts'
  // firewalls refuse a small binary upload; wordwave.co.za's under about 10 KB)
  const sentBefore = (await state()).sent;
  await until((x) => x.sent > sentBefore, 10);
  await click("#stGo");
  check("pressing On air asks before ending, in the page", (await text("#stBannerText")).startsWith("End the show?"));
  const endAt = Date.now();
  await clickText("#stBannerActs button", "End show");
  s = await until((x) => !x.live, 20);
  const endTook = (Date.now() - endAt) / 1000;
  info = helper("live-info", liveId);
  check("End show sends the last audio and ends the show", !!s && !info.open && info.closed_reason === "ended by DJ", `${info.closed_reason}, ${info.pieces.length} pieces`);
  const last = info.pieces[info.pieces.length - 1];
  check("  its short last piece (sent as base64) arrives whole, and End show doesn't wait", !!last && last.bytes < 16384 && last.bytes > 0 && last.scanned !== null && Math.abs(last.scanned - last.duration) < 0.05 && endTook < 6 && (await state()).errors === 0,
    last ? `${last.bytes} bytes, ${last.duration} s (${last.scanned} s scanned), ended in ${endTook.toFixed(1)} s` : "no pieces");
  check("  the banner says the AutoDJ is back", (await text("#stBannerText")).startsWith("Handed back to the AutoDJ") || (await text("#stBannerText")).startsWith("Uploading your show"), await text("#stBannerText"));
  let up = "";
  for (let i = 0; i < 120 && !up.startsWith("Your show’s recording is uploaded"); i++) { await sleep(250); up = await text("#stBannerText"); }
  check("then the recording uploads, and the banner offers the episode's editor", up.startsWith("Your show’s recording is uploaded. Trim it") && (await evaluate("[...document.querySelectorAll('#stBannerActs button')].some((b) => b.textContent === 'Edit the episode')")), up);
  check("  nothing is left waiting in this browser", (await evaluate("SwRecorder.waiting()")) === 0 && !(await state()).rec.recording);
  const ep = helper("episode-info", liveId);
  const oldTake = ep && ep.takes.find((x) => x.kind === "device" && x.show !== ep.takes[ep.takes.length - 1].show);
  check("  the part the reload cut short uploaded with it", !!oldTake && oldTake.complete && oldTake.chunks.music >= 1 && oldTake.chunks.mic >= 1,
    ep && JSON.stringify(ep.takes.map((x) => [x.show, x.kind, x.complete, x.chunks])));
  const dev = ep && ep.takes.find((x) => x.kind === "device" && (!oldTake || x.show !== oldTake.show)); // the rejoined part's
  const aired = ep && dev && ep.takes.find((x) => x.kind === "aired" && x.show === dev.show);
  check("one episode for both parts of the show, a draft, each part recorded in the studio", ep && ep.status === "draft" && ep.takes.filter((x) => x.kind === "device" && x.complete).length === 2,
    ep && `${ep.status}: ` + JSON.stringify(ep.takes.map((x) => [x.show, x.kind, x.complete, x.chunks])));
  check("  the rejoined part's recording is as long as what went out (within 2 s)", dev && aired && Math.abs(dev.duration - aired.duration) < 2, dev && aired && `${dev.duration} s recorded, ${aired.duration} s aired`);
  const first = ep && ep.takes.find((x) => x.kind === "device" && oldTake && x.show === oldTake.show);
  check("  the music in clips; the mic's too in the first part, where it was on air (after the reload it wasn't)", dev && dev.clips && dev.clips.music.length >= 1 && dev.clips.mic.length === 0
    && first && first.clips && first.clips.mic.length >= 1, JSON.stringify({ first: first && first.clips, rejoined: dev && dev.clips }));
  await clickText("#stBannerActs button", "Edit the episode");
  for (let i = 0; i < 50 && !(await evaluate("location.pathname").catch(() => "")).endsWith(`/manage/podcast/${ep.id}`); i++) await sleep(100);
  let edState = null;
  for (let i = 0; i < 60 && !(edState && edState.loaded); i++) { await sleep(250); edState = await evaluate("window.SwEditor && SwEditor.state()").catch(() => null); }
  // the reload cut the first part's recording short by up to 10 s: over 1 s of that is filled in as aired
  const firstAired = ep.takes.find((x) => x.kind === "aired" && oldTake && x.show === oldTake.show);
  const filled = firstAired && firstAired.duration - oldTake.duration > 1;
  const kinds = edState ? edState.takes.map((x) => x.kind).join(",") : "";
  check("  Edit the episode opens it in the editor with both parts on two tracks" + (filled ? ", the seconds the reload lost filled in as aired" : ""),
    edState && edState.loaded && kinds === (filled ? "device,aired,device" : "device,device") && edState.clips.length >= 2 && Math.abs(edState.total - ep.takes.filter((x) => x.kind === "aired").reduce((n, x) => n + x.duration, 0)) < (filled ? 2 : 12),
    edState && `${kinds}: ${edState.clips.length} clips, ${edState.total.toFixed(1)} s; first part ${oldTake.duration} s recorded of ${firstAired && firstAired.duration} s aired`);
  const tl = await (await fetch(`${base}/api/timeline.php`, { cache: "no-store" })).json();
  const after = tl.entries.filter((e) => e.type === "song" && e.start >= tl.entries.find((x) => x.public_id === liveId).end - 0.01);
  check("  and the timeline carries on with songs after it", after.length > 0, after[0] && after[0].title);
  liveId = null;
  if (failed) console.log("Studio events:\n    " + (((await state()) || {}).events || []).map((e) => e.m).join("\n    "));
} finally {
  try { if (minBefore !== null) helper("podcast-min", String(minBefore)); } catch (e) { console.log("cleanup: " + e.message); }
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
  console.log("Cleanup: test DJs, their block, running orders, notes, any open show and the show's episode removed");
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
