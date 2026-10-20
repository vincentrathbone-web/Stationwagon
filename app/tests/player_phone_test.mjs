// The listener player on a real Android phone (Chrome over USB), at 1 % volume: it joins, crossfades,
// then the test locks the screen for two minutes and checks the sound carries on (songs change on
// time, the sound engine's clock keeps moving), then wakes the screen. About three and a half minutes.
//   node app/tests/player_phone_test.mjs https://wordwave.controll.co.za/stationwagon-app
// Needs the phone unlocked with Chrome open, and
//   adb forward tcp:9222 localabstract:chrome_devtools_remote
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

try {
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
  await sleep(4000);

  console.log("Screen locked (2 minutes)");
  adb("input", "keyevent", "26");
  const t0 = Date.now(), samples = [];
  let songs = new Set(), media = "";
  for (let i = 0; i < 40; i++) {
    await sleep(3000);
    const x = await state();
    if (x) { samples.push({ at: Date.now(), x }); if (x.current) songs.add(x.current.key); }
    if (i === 10) media = adb("dumpsys", "media_session");
  }
  const firstS = samples[0], lastS = samples[samples.length - 1];
  check("the page keeps answering behind the lock screen", samples.length >= 36, `${samples.length} of 40`);
  check("  and says it's hidden", samples.every((p) => p.x.hidden));
  check("  Android shows Chrome's media session as playing", /package=com\.android\.chrome[\s\S]*?state=PlaybackState \{state=PLAYING/.test(media));
  check("  songs change while locked", songs.size >= 3, `${songs.size} songs`);
  const ran = lastS && firstS ? (lastS.x.engine.time - firstS.x.engine.time) / ((lastS.at - firstS.at) / 1000) : 0;
  check("  the sound engine's clock keeps up (at least 98 % of real time)", ran >= 0.98, (ran * 100).toFixed(1) + " %");
  const late = samples.filter((p) => onCurrent(p.x) && Math.abs(drift(p.x, onCurrent(p.x))) > 0.3).length;
  check("  and stays on time (at most 3 of 40 looks off by over 0.3 s)", late <= 3, `${late} off`);
  const stuck = lastS ? lastS.x.events.filter((e) => e.includes("sound engine stuck")) : [];
  console.log(`  (the watchdog nudged the sound engine ${stuck.length} time(s)${stuck.length ? ": " + stuck.join("; ") : ""})`);
  console.log(`  locked for ${Math.round((Date.now() - t0) / 1000)} s`);
} finally {
  adb("input", "keyevent", "KEYCODE_WAKEUP");
  await sleep(2000);
  const end = await state(3000);
  if (failed && end) console.log("Player events:\n    " + end.events.join("\n    "));
  await evaluate("SwPlayer.stop()", 3000);
  try { ws.close(); } catch { /* closed */ }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
