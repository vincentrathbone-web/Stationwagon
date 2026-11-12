// End-to-end test of a further stream (round 73) in a real browser (headless Edge or Chrome, with Chromium's fake
// microphone) on Laragon: a throwaway stream with a DJ who may go live only there. The DJ opens the studio on the stream,
// goes live, sends pieces that reach that stream's listeners (and not the main stream's), posts a presenter note that only
// that stream's listeners get, and ends the show; the studio's recording uploads and becomes an episode of the station's
// one podcast. Then a listener opens the stream's own player page, which plays that stream and counts itself there.
// Takes about a minute and a half.
//   node app/tests/streams_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH. It goes live on the test stream: Laragon, or the test server with
// SW_HELPER_SSH set (as studio_browser_test.mjs).
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/streams_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
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
const profile = mkdtempSync(join(tmpdir(), "sw-streams-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
  "--autoplay-policy=no-user-gesture-required", "--use-fake-ui-for-media-stream", "--use-fake-device-for-media-stream", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const requests = [];
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const go = async (url) => { await call("Page.navigate", { url }); for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const studio = () => evaluate("window.SwStudio && SwStudio.state()");
const player = () => evaluate("window.SwPlayer && SwPlayer.state()");
async function until(get, fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const s = await get().catch(() => null); if (s && fn(s)) return s; await sleep(150); }
  return null;
}
const text = (sel) => evaluate(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ""`);
const click = (sel) => evaluate(`(() => { const b = document.querySelector(${JSON.stringify(sel)}); if (!b) return false; b.click(); return true; })()`);
const clickText = (sel, label) => evaluate(`(() => { const b = [...document.querySelectorAll(${JSON.stringify(sel)})].find((x) => x.textContent.trim() === ${JSON.stringify(label)}); if (!b) return false; b.click(); return true; })()`);
const getJson = async (path) => (await fetch(`${base}/${path}`, { cache: "no-store" })).json();
const getText = async (path) => (await fetch(`${base}/${path}`, { cache: "no-store" })).text();

let fx = null, liveId = null, minBefore = null;
try {
  minBefore = helper("podcast-min", "20").before; // the test show is short
  helper("stream-clean", "0"); // anything a killed run left
  fx = helper("stream-fixture");
  check("(a test stream, its DJ and their live block on it)", !!fx.stream && !!fx.block, JSON.stringify(fx.errors || fx.error || ""));
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    if (d.method === "Page.javascriptDialogOpening") call("Page.handleJavaScriptDialog", { accept: true });
    if (d.method === "Network.requestWillBeSent") requests.push(d.params.request.url);
  };
  await call("Page.enable");
  await call("Network.enable");

  console.log("The DJ's studio on the stream");
  await call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(fx.email)}; f.password.value = ${JSON.stringify(fx.password)}; f.submit(); })()`);
  for (let i = 0; i < 50 && !(await evaluate("location.pathname").catch(() => "")).endsWith("/manage"); i++) await sleep(100);
  await go(`${base}/studio`);
  let s = await until(studio, (x) => x.slot, 10);
  check("the studio opens on the one stream they have, without asking", (await evaluate("document.getElementById('studio') && document.getElementById('studio').dataset.stream || document.querySelector('[data-stream]') && document.querySelector('[data-stream]').dataset.stream")) === fx.stream,
    String(await evaluate("(document.querySelector('[data-stream]') || {}).dataset && document.querySelector('[data-stream]').dataset.stream")));
  check("  it knows their show on that stream", s && s.slot.may && s.slot.title === "[test] Stream test show", s && JSON.stringify(s.slot.title));
  check("  and its api calls carry the stream", requests.some((u) => u.includes("studio/api/") && u.includes("stream=" + fx.stream)));
  await clickText("#stBannerActs button", "Switch on the studio");
  s = await until(studio, (x) => x.on, 10);
  check("switching on works", !!s);
  let ready = false;
  for (let i = 0; i < 50 && !ready; i++) { await sleep(200); ready = !(await evaluate("document.getElementById('stGo').disabled")); }
  check("  Go live is ready", ready, await text("#stBannerText"));

  console.log("Live on the stream");
  await click("#stGo");
  s = await until(studio, (x) => x.live, 8);
  liveId = s && s.live.public_id;
  check("Go live starts a show", !!liveId, s ? "" : (await text("#stBannerText")) + " " + requests.filter((u) => u.includes("golive")).join(" "));
  if (!liveId) throw new Error("not live, the rest can't run");
  s = await until(studio, (x) => x.live && x.live.sending, 15);
  check("  Deck A picked up the stream's AutoDJ song and the studio is on air", !!s && Math.abs(s.live.firstAt - s.live.start) < 0.05, s ? "" : await text("#stBannerText"));
  s = await until(studio, (x) => x.sent >= 2, 30);
  check("pieces go out, with no errors", !!s && s.errors === 0, s ? `${s.sent} sent` : JSON.stringify(((await studio()) || {}).events?.slice(-4)));
  let info = helper("live-info", liveId);
  check("  the show is the stream's, with its pieces", info && info.station_id === fx.stream_id && info.pieces.length >= 2 && info.dj_user_id === fx.id, info && `station ${info.station_id}, ${info.pieces.length} pieces`);
  const hlsStream = await getText(`api/stream.php?stream=${fx.stream}`);
  const hlsMain = await getText("api/stream.php");
  check("the stream's listeners get the live pieces", hlsStream.includes(`storage/live/${liveId}/`), hlsStream.split("\n").filter((l) => l.includes("storage/")).slice(-1)[0]);
  check("  the main stream's listeners don't", !hlsMain.includes(`storage/live/${liveId}/`));
  const tlS = await getJson(`api/timeline.php?stream=${fx.stream}`);
  const tlM = await getJson("api/timeline.php");
  check("  the stream's timeline has the show, the main one doesn't", tlS.entries.some((e) => e.public_id === liveId) && !tlM.entries.some((e) => e.public_id === liveId));

  console.log("Presenter notes on the stream");
  await click("#stNotesBtn");
  await clickText(".st-kinds button", "Note");
  await evaluate(`(() => { document.querySelector('.st-nf[data-for="note"] [name=text]').value = 'Hello from the test stream.'; document.getElementById('noteForm').requestSubmit(); })()`);
  s = await until(studio, (x) => x.notes.length === 1, 6);
  check("a note is posted from the drawer", !!s && helper("live-info", liveId).notes === 1);
  const feedS = await getJson(`api/notes.php?stream=${fx.stream}`);
  const feedM = await getJson("api/notes.php");
  check("  the stream's notes feed has it", (feedS.items || []).some((n) => n.text === "Hello from the test stream."), JSON.stringify((feedS.items || []).map((n) => n.text)));
  check("  the main stream's doesn't", !(feedM.items || []).some((n) => n.text === "Hello from the test stream."));
  await click("#stDrawerClose");

  console.log("Ending the show");
  await until(studio, (x) => x.sent >= 5 && x.rec && x.rec.seconds > 25, 40); // over the 20 s the podcast is set to need here
  await click("#stGo");
  await clickText("#stBannerActs button", "End show");
  s = await until(studio, (x) => !x.live, 20);
  info = helper("live-info", liveId);
  check("End show ends it on the stream", !!s && !info.open && info.closed_reason === "ended by DJ", info && info.closed_reason);
  let up = "";
  for (let i = 0; i < 120 && !up.startsWith("Your show’s recording is uploaded"); i++) { await sleep(250); up = await text("#stBannerText"); }
  check("the studio's recording uploads", up.startsWith("Your show’s recording is uploaded"), up);
  const ep = helper("episode-info", liveId);
  check("  and the show is an episode of the station's podcast, a draft with the studio's recording", ep && ep.status === "draft" && ep.takes.some((t) => t.kind === "device" && t.complete) && ep.takes.some((t) => t.kind === "aired"),
    ep ? `${ep.status}: ` + JSON.stringify(ep.takes.map((t) => [t.kind, t.complete])) : "no episode");
  await clickText("#stBannerActs button", "Edit the episode");
  for (let i = 0; i < 50 && !(await evaluate("location.pathname").catch(() => "")).endsWith(`/manage/podcast/${ep && ep.id}`); i++) await sleep(100);
  let ed = null;
  for (let i = 0; i < 60 && !(ed && ed.loaded); i++) { await sleep(250); ed = await evaluate("window.SwEditor && SwEditor.state()").catch(() => null); }
  check("  its editor opens for the DJ, with the recording", !!ed && ed.loaded && ed.clips.length >= 1, ed ? `${ed.clips.length} clips` : "no editor");
  const tlAfter = await getJson(`api/timeline.php?stream=${fx.stream}`);
  const showEnd = (tlAfter.entries.find((e) => e.public_id === liveId) || {}).end;
  check("  and the stream's AutoDJ carries on after it", tlAfter.entries.some((e) => e.type === "song" && showEnd && e.start >= showEnd - 0.01));
  liveId = null;

  console.log("The bar's stream picker (round 75)");
  await go(`${base}/bar`);
  await evaluate("localStorage.removeItem('sw-bar-stream')");
  await go(`${base}/bar`);
  await until(player, (x) => x.on, 10);
  const opts = await evaluate("[...document.querySelectorAll('#emStream option')].map((o) => o.value)");
  check("with two streams the bar has a stream picker, on the main stream at first", opts.length >= 2 && opts[0] === "" && opts.includes(fx.stream) && (await evaluate("document.getElementById('emStream').value")) === "", JSON.stringify(opts));
  const mutedBefore = await evaluate("SwPlayer.muted()");
  requests.length = 0;
  await evaluate(`(() => { const s = document.getElementById('emStream'); s.value = ${JSON.stringify(fx.stream)}; s.dispatchEvent(new Event('change')); })()`);
  s = await until(player, (x) => x.on && x.status === "Playing", 10);
  const tlName = await evaluate("SwPlayer.timeline() && SwPlayer.timeline().station.name");
  check("picking a stream plays it in the bar, in place (no reload)", !!s && (await evaluate("SwPlayer.stream()")) === fx.stream && tlName === fx.name && (await evaluate("!!window.SwBar")), `${tlName}`);
  check("  from that stream's timeline and now playing", requests.some((u) => u.includes("api/timeline.php") && u.includes("stream=" + fx.stream)) && requests.some((u) => u.includes("api/nowplaying.php") && u.includes("stream=" + fx.stream))
    && !requests.some((u) => u.includes("/bar")), requests.filter((u) => u.includes("api/")).slice(0, 3).join(" "));
  await until(() => evaluate("swEmbed.np"), (x) => x && x.station && x.station.name === fx.name, 6);
  check("  the bar shows that stream's now playing, and Open the player goes to its page", (await evaluate("swEmbed.np && swEmbed.np.station.name")) === fx.name && (await evaluate("document.getElementById('emOpen').href")).endsWith("/" + fx.stream));
  check("  muted as it was", (await evaluate("SwPlayer.muted()")) === mutedBefore);
  check("  remembered on this device", (await evaluate("localStorage.getItem('sw-bar-stream')")) === fx.stream);
  await go(`${base}/bar`);
  await until(player, (x) => x.on, 10);
  check("the bar opens on the remembered stream next time", (await evaluate("document.getElementById('emStream').value")) === fx.stream && (await evaluate("SwPlayer.stream()")) === fx.stream);
  await evaluate("localStorage.setItem('sw-bar-stream', 'a-stream-that-was-removed')");
  await go(`${base}/bar`);
  await until(player, (x) => x.on, 10);
  check("  a remembered stream that was removed falls back to the main one, and is forgotten", (await evaluate("document.getElementById('emStream').value")) === "" && (await evaluate("SwPlayer.stream()")) === "" && (await evaluate("localStorage.getItem('sw-bar-stream')")) === null);
  await evaluate(`(() => { const s = document.getElementById('emStream'); s.value = ${JSON.stringify(fx.stream)}; s.dispatchEvent(new Event('change')); })()`);
  await sleep(500);
  await evaluate("(() => { const s = document.getElementById('emStream'); s.value = ''; s.dispatchEvent(new Event('change')); })()");
  s = await until(player, (x) => x.on && x.status === "Playing", 10);
  check("  and back to the main stream", !!s && (await evaluate("SwPlayer.stream()")) === "" && (await evaluate("SwPlayer.timeline() && SwPlayer.timeline().station.name")) !== fx.name && (await evaluate("localStorage.getItem('sw-bar-stream')")) === "");
  await call("Network.setCookie", { name: "sw_bare", value: "0", url: base + "/" });
  await call("Network.deleteCookies", { name: "sw_bare", url: base + "/" });
  await evaluate(`localStorage.setItem('sw-bar-stream', ${JSON.stringify(fx.stream)})`);
  await go(`${base}/studio`);
  let framed = null;
  for (let i = 0; i < 40 && !(framed && framed.bar === fx.stream); i++) { await sleep(250); framed = await evaluate("(() => { const b = document.getElementById('swBar'); const w = b && b.contentWindow; return w && w.SwPlayer ? { bar: w.SwPlayer.stream(), screen: (document.getElementById('swScreen') || {}).src || '' } : null; })()").catch(() => null); }
  check("under the screens, the bar plays the picked stream and the screen keeps its own", !!framed && framed.bar === fx.stream && !framed.screen.includes("stream="), JSON.stringify(framed));
  await call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });

  console.log("A listener on the stream's page");
  requests.length = 0;
  await go(`${base}/${fx.stream}`);
  check("the stream's page has the player, for the stream", await evaluate(`typeof SwPlayer === 'object' && document.getElementById('player').dataset.stream === ${JSON.stringify(fx.stream)}`));
  const mainBefore = helper("stream-listeners").now;
  await evaluate("SwPlayer.start()");
  // 20 s behind: what they hear first may still be the end of the show just ended
  s = await until(player, (x) => x.status === "Playing" && x.current && (x.current.type === "song" || x.current.type === "live"), 15);
  check("Listen plays the stream", !!s, s ? `${s.current.type}: ${s.current.title}` : JSON.stringify(((await player()) || {}).status));
  check("  from the stream's own timeline and playlist", requests.some((u) => u.includes("api/timeline.php") && u.includes("stream=" + fx.stream)) && !requests.some((u) => u.includes("api/timeline.php") && !u.includes("stream=")),
    requests.filter((u) => u.includes("api/")).slice(0, 4).join(" "));
  s = await until(player, (x) => x.ping && x.listeners >= 1, 8);
  const onStream = helper("stream-listeners", fx.stream).now;
  check("  it counts itself on the stream, not on the main one", !!s && onStream >= 1 && helper("stream-listeners").now === mainBefore, `stream ${onStream}, main ${mainBefore} → ${helper("stream-listeners").now}`);
  check("  and the page fetches the stream's notes", requests.some((u) => u.includes("api/notes.php?stream=" + fx.stream)));
  await evaluate("SwPlayer.stop()");
  await sleep(1500);
  check("Stop takes it off the stream's count", helper("stream-listeners", fx.stream).now === 0);
} finally {
  try { if (minBefore !== null) helper("podcast-min", String(minBefore)); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (fx) helper("stream-clean", String(fx.id)); } catch (e) { console.log("cleanup: " + e.message); }
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
  console.log("Cleanup: the test stream, its DJ, shows, notes and the show's episode removed");
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
