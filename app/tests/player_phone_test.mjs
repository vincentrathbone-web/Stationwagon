// The listener player on a real Android phone (Chrome over USB), at 1 % volume: it joins, crossfades,
// then starts the embed in a frame on the same page (it plays at the same 20 s delay), then locks the
// screen for two minutes and checks the sound carries on in both (songs change on time, the sound
// engine's clock keeps moving, the embed stays 20 s behind), then wakes the screen. About four minutes.
//   node app/tests/player_phone_test.mjs https://wordwave.controll.co.za/stationwagon-app
// Needs the phone unlocked with Chrome open, and
//   (the test points port 9222 at Chrome itself)
// SW_ADB is adb's path (default D:/Android/platform-tools/adb.exe). Chrome can't start sound behind
// the lock screen, so a locked phone stops the test before it starts. Changes nothing on the server.
import { execFileSync } from "node:child_process";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/player_phone_test.mjs <base url>"); process.exit(1); }
const adbPath = process.env.SW_ADB || "D:/Android/platform-tools/adb.exe";
const adb = (...args) => execFileSync(adbPath, ["shell", ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] });
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

if (adb("dumpsys", "window").includes("isKeyguardShowing=true")) {
  console.log("The phone is locked: unlock it, open Chrome, and run this again.");
  process.exit(2);
}
adb("monkey", "-p", "com.android.chrome", "-c", "android.intent.category.LAUNCHER", "1"); // Chrome in front
await new Promise((r) => setTimeout(r, 2000));
// Port 9222 to Chrome itself: other Chromium browsers on the phone (Brave) may hold the plain
// chrome_devtools_remote socket, so use Chrome's own (chrome_devtools_remote_<pid>) when it has one
{
  const pid = adb("pidof", "com.android.chrome").trim().split(/\s+/)[0];
  const own = pid && adb("cat", "/proc/net/unix").includes(`@chrome_devtools_remote_${pid}`) ? `chrome_devtools_remote_${pid}` : "chrome_devtools_remote";
  execFileSync(adbPath, ["forward", "tcp:9222", `localabstract:${own}`], { stdio: "ignore" });
}
await sleep(2000);

const page = (await (await fetch("http://localhost:9222/json")).json()).find((p) => p.type === "page");
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
let id = 0;
const pending = new Map();
ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
// every question has a time limit: a page behind the lock screen may not answer
const call = (method, params = {}, ms = 4000) => new Promise((ok) => {
  const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params }));
  setTimeout(() => { if (pending.has(i)) { pending.delete(i); ok({ timeout: true }); } }, ms);
});
const evaluate = async (expression, ms) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true }, ms);
  return r.timeout || r.result?.exceptionDetails ? null : r.result?.result?.value;
};
const state = (ms = 2500) => evaluate("window.SwPlayer && SwPlayer.state()", ms);
const audible = (s) => s.decks.filter((d) => d.playing && d.gain > 0.5);
const onCurrent = (s) => s.current && s.current.type === "song" && audible(s).find((d) => d.key === s.current.key);
const drift = (s, d) => d.pos - (s.heard - s.current.start);
const blending = (s) => s.decks.filter((d) => d.playing && d.gain > 0.05 && d.gain < 0.95).length === 2;
// the embed in its frame on the station page (same site, so its test hooks can be read)
const em = (x) => `(() => { const w = document.getElementById('swEm'); return w && w.contentWindow.swEmbed ? (${x})(w.contentWindow.swEmbed, w.contentWindow.document) : null; })()`;

try {
  // the test mark keeps this run's listening out of the station's stats (src/Agent.php)
  const ua = (await evaluate("navigator.userAgent")) || "";
  await call("Network.setUserAgentOverride", { userAgent: ua + " StationwagonTest" });
  console.log("Joining");
  let loaded = false;
  for (let i = 0; i < 4 && !loaded; i++) { // the phone's Wi-Fi drops now and then: try the page again
    await call("Page.navigate", { url: `${base}/` });
    for (let w = 0; w < 20 && !(loaded = (await evaluate("typeof SwPlayer", 2000)) === "object"); w++) await sleep(1000);
  }
  check("the station page loads on the phone", loaded);
  if (!loaded) throw new Error("no player");
  await evaluate("(() => { const v = document.getElementById('lpVol'); v.value = 1; v.dispatchEvent(new Event('input')); return SwPlayer.start(); })()", 15000);
  let s = null;
  for (let i = 0; i < 100 && !(s && onCurrent(s)); i++) { s = await state(); await sleep(100); }
  check("Listen joins the song the delayed timeline is on", !!(s && onCurrent(s)), s && s.status);
  check("  at the right moment (within 0.3 s)", !!(s && onCurrent(s)) && Math.abs(drift(s, onCurrent(s))) < 0.3, s && onCurrent(s) && drift(s, onCurrent(s)).toFixed(3) + " s");
  let counted = null;
  for (let i = 0; i < 20 && !(counted && counted.listeners >= 1); i++) { counted = await state(); await sleep(500); }
  check("  and counts itself as listening", !!(counted && /^[0-9a-f]{16}$/.test(counted.ping || "") && counted.listeners >= 1), counted && `${counted.ping}, ${counted.listeners} listening`);

  console.log("Crossfade (screen on)");
  const first = s && s.current && s.current.key;
  let blend = null;
  for (let i = 0; i < 700 && !(blend && s.current.key !== first); i++) {
    s = await state();
    if (s && !blend && blending(s)) blend = s;
    if (i % 80 === 0) adb("input", "keyevent", "KEYCODE_WAKEUP"); // keeps the screen from timing out
    await sleep(100);
  }
  check("the next song crossfades in on both decks", !!blend, blend && blend.decks.map((d) => d.gain).join(" / "));

  console.log("The embed (in a frame on the same page)");
  await evaluate("(() => { const f = document.createElement('iframe'); f.id = 'swEm'; f.src = 'embed'; f.width = 360; f.height = 124; document.body.prepend(f); })()");
  let np = null;
  for (let i = 0; i < 20 && !np; i++) { await sleep(1000); np = await evaluate(em("(e) => e.np")); }
  const shown = (await evaluate(em("(e, d) => d.getElementById('emTitle').textContent"))) || "";
  check("the embed shows what's on air", !!(np && np.on_air) && shown.startsWith(np.on_air.title), shown);
  await evaluate(em("(e, d) => { d.getElementById('emPlay').click(); return true; }"));
  let lag = null;
  for (let i = 0; i < 60 && !lag; i++) { await sleep(500); lag = await evaluate(em("(e) => e.heard && Math.abs(e.lag - 20) < 0.6 && e.lag")); }
  check("  Listen plays the stream at the same 20 s delay", !!lag, lag ? lag.toFixed(2) + " s" : String(await evaluate(em("(e) => e.lag"))));
  check("  and counts itself as listening", /^[0-9a-f]{16}$/.test((await evaluate(em("(e) => e.ping"))) || ""));

  console.log("Screen locked (2 minutes)");
  // the sleep key only turns the screen off (the power key toggles, and a tap that crosses a wake-up turns it back on)
  const screen = () => (/mWakefulness=(\w+)/.exec(adb("dumpsys", "power")) || [])[1];
  for (let i = 0; i < 3 && screen() !== "Asleep"; i++) { adb("input", "keyevent", "KEYCODE_SLEEP"); await sleep(1500); }
  check("the screen goes off", screen() === "Asleep", screen());
  const t0 = Date.now(), samples = [];
  let songs = new Set(), media = "";
  const emLags = [];
  for (let i = 0; i < 40; i++) {
    await sleep(3000);
    const x = await state();
    if (x) { samples.push({ at: Date.now(), x, screen: (/mWakefulness=(\w+)/.exec(adb("dumpsys", "power")) || [])[1] }); if (x.current) songs.add(x.current.key); }
    const l = await evaluate(em("(e) => e.playing && e.lag"), 2500);
    if (typeof l === "number") emLags.push(l);
    if (i === 10) media = adb("dumpsys", "media_session");
  }
  const firstS = samples[0], lastS = samples[samples.length - 1];
  check("the page keeps answering behind the lock screen", samples.length >= 36, `${samples.length} of 40`);
  const shownAt = samples.filter((p) => !p.x.hidden);
  check("  and says it's hidden", !shownAt.length, shownAt.map((p) => `${Math.round((p.at - t0) / 1000)} s: screen ${p.screen}`).join(", "));
  check("  Android shows Chrome's media session as playing", /package=com\.android\.chrome[\s\S]*?state=PlaybackState \{state=PLAYING/.test(media));
  check("  songs change while locked", songs.size >= 3, `${songs.size} songs`);
  const ran = lastS && firstS ? (lastS.x.engine.time - firstS.x.engine.time) / ((lastS.at - firstS.at) / 1000) : 0;
  check("  the sound engine's clock keeps up (at least 98 % of real time)", ran >= 0.98, (ran * 100).toFixed(1) + " %");
  const late = samples.filter((p) => onCurrent(p.x) && Math.abs(drift(p.x, onCurrent(p.x))) > 0.3).length;
  check("  and stays on time (at most 3 of 40 looks off by over 0.3 s)", late <= 3, `${late} off`);
  const stuck = lastS ? lastS.x.events.filter((e) => e.includes("sound engine stuck")) : [];
  console.log(`  (the watchdog nudged the sound engine ${stuck.length} time(s)${stuck.length ? ": " + stuck.join("; ") : ""})`);
  check("  the embed keeps playing, still about 20 s behind", emLags.length >= 36 && emLags.every((l) => Math.abs(l - 20) < 1.5), `${emLags.length} of 40, ${Math.min(...emLags).toFixed(1)} to ${Math.max(...emLags).toFixed(1)} s`);
  console.log(`  locked for ${Math.round((Date.now() - t0) / 1000)} s`);
} finally {
  adb("input", "keyevent", "KEYCODE_WAKEUP");
  await sleep(2000);
  const end = await state(3000);
  if (failed && end) console.log("Player events:\n    " + end.events.join("\n    "));
  await evaluate(em("(e, d) => { if (e.playing) d.getElementById('emPlay').click(); return true; }"), 3000);
  await evaluate("SwPlayer.stop()", 3000);
  try { ws.close(); } catch { /* closed */ }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
