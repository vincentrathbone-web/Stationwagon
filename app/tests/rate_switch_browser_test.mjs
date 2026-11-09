// Songs at 44.1 and 48 kHz one after another (round 51: a station may keep each song's own rate), in
// headless Edge (or Firefox, with --firefox) on Laragon: three songs (44.1, 48, 44.1 kHz) play in order through the listener player's
// decks, the stream-only player (?mode=stream, hls.js) and the embed, all three at once. Each song is a
// 600 Hz tone that turns to 900 Hz at exactly 20 s. A song played at the wrong rate would sound at
// 551 or 653 Hz, and a timing slip would move the turn. On each page an analyser on the page's own
// output reads the pitch every 50 ms beside the moment the player says is being heard:
//   - the tone's pitch in every song (within 3 Hz);
//   - the turn at 20 s heard on time (within 0.35 s), in the 48 kHz song and in the songs around it;
//   - no dropouts, stalls or waits in any of them.
// In Firefox the stream-only page and the embed (hls.js) go quiet for about 5 s where the rate changes and then
// play that much late (Firefox's media pipeline; the same stream is exact in Edge). That limit was agreed with
// the user (2026-11-09) and is why stations default to 44.1 kHz for every song: there, songs after the change
// are checked for pitch only and their lateness is reported as a note.
//   node app/tests/rate_switch_browser_test.mjs http://localhost:8080/stationwagon-app [--firefox | --phone]
// Edge is driven over the DevTools protocol; Firefox (C:/Program Files/Mozilla Firefox) over WebDriver BiDi;
// --phone uses the Android phone's browser over USB (Brave, or SW_PHONE_BROWSER; adb at SW_ADB; the phone
// unlocked, and `adb reverse tcp:8080 tcp:8080` for Laragon): its one tab has the station page, with the
// stream-only page and the embed in frames on it (DevTools can't open tabs on Android).
// Needs SW_PHP (php.exe) unless php is on PATH. Changes the station's schedule for a few minutes
// (a block and a playlist named "[ww-test] Rate switch", removed at the end): Laragon only.
import { spawn, execFileSync } from "node:child_process";
import { writeFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const base = (process.argv.slice(2).find((a) => !a.startsWith("--")) || "").replace(/\/$/, "");
const FIREFOX = process.argv.includes("--firefox"), PHONE = process.argv.includes("--phone");
if (!base) { console.error("Usage: node app/tests/rate_switch_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
const require = createRequire(import.meta.url);
const core = require("../assets/prep-core.js");
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const LOW = 600, HIGH = 900, TURN = 20, SECONDS = 30;

// ---- the songs: 600 Hz, then 900 Hz from 20 s, made with the station's own LAME 3.100 ----------
const lame = await require("../assets/vendor/lame-3.100.js")();
const tag = Math.random().toString(16).slice(2, 8);
const dir = mkdtempSync(join(tmpdir(), "sw-rate-"));
const songs = [["A", 44100], ["B", 48000], ["C", 44100]].map(([name, rate], k) => {
  const n = SECONDS * rate, x = new Float32Array(n);
  let ph = 0;
  for (let i = 0; i < n; i++) { ph += 2 * Math.PI * (i < TURN * rate ? LOW : HIGH) / rate; x[i] = 0.25 * Math.sin(ph); }
  const enc = core.encodeLame(lame, core.toInt16([x, x.slice()], true, core.rng(parseInt(tag, 16) + k)), rate, 160, 2); // a dither of its own: every song of every run differs
  const file = join(dir, `${name}.mp3`), title = `[ww-test] Rate ${name} ${rate / 1000} kHz ${tag}`;
  writeFileSync(file, Buffer.concat(enc.chunks.map((c) => Buffer.from(c))));
  return { name, rate, file, title };
});

// ---- the pages: an analyser on each page's output, read every 50 ms beside the moment heard ------
const TAP = `(() => {
  const taps = window.__taps = [], els = window.__els = new Set(), log = window.__rateLog = [];
  const connect = AudioNode.prototype.connect;
  const tapOf = (node) => { const an = node.context.createAnalyser(); an.fftSize = 8192; an.smoothingTimeConstant = 0; connect.call(node, an); taps.push(an); };
  AudioNode.prototype.connect = function (dest, ...rest) {
    const r = connect.call(this, dest, ...rest);
    try { if (dest instanceof AudioDestinationNode && !this.__tapped) { this.__tapped = true; tapOf(this); } } catch (_) {}
    return r;
  };
  const play = HTMLMediaElement.prototype.play;
  HTMLMediaElement.prototype.play = function () { els.add(this); return play.apply(this, arguments); };
  const heard = () => {
    if (window.SwPlayer && SwPlayer.state) { const s = SwPlayer.state(); return s && s.heard; }
    if (window.swEmbed && swEmbed.heard && swEmbed.lag !== null) return Date.now() / 1000 + swEmbed.offset - swEmbed.lag;
    return null;
  };
  const peak = (list) => {
    // the strongest peak between 100 Hz and 5 kHz of any of them
    let best = null;
    for (const an of list) {
      const d = new Float32Array(an.frequencyBinCount), w = an.context.sampleRate / an.fftSize;
      an.getFloatFrequencyData(d);
      const lo = Math.ceil(100 / w), hi = Math.floor(5000 / w);
      let k = lo; for (let i = lo + 1; i <= hi; i++) if (d[i] > d[k]) k = i;
      const a = d[k - 1], b = d[k], c = d[k + 1], den = a - 2 * b + c, p = den ? 0.5 * (a - c) / den : 0;
      if (isFinite(b) && (!best || b > best.db)) best = { hz: (k + p) * w, db: b };
    }
    return best;
  };
  // without a Web Audio path to the speakers (the stream-only player, the embed, whose visuals end in a
  // silent gain): the playing element's own sound, copied with captureStream (the element plays on as it was)
  const elTaps = new Map();
  let own = null;
  const elementTap = () => {
    const el = [...els].find((e) => !e.paused);
    if (!el) return [];
    if (!elTaps.has(el)) {
      const stream = (el.captureStream || el.mozCaptureStream).call(el), tracks = stream.getAudioTracks(); // Firefox: mozCaptureStream
      el.__stream = stream;
      if (!tracks.length) return [];
      own = own || new AudioContext();
      if (own.state === "suspended") own.resume().catch(() => {});
      // every track: a stream source takes only the first by id, and an element can hand over two (Android:
      // hls.js's media source gives one that stays silent)
      const an = own.createAnalyser(), mute = own.createGain();
      an.fftSize = 8192; an.smoothingTimeConstant = 0; mute.gain.value = 0;
      for (const t of tracks) own.createMediaStreamSource(new MediaStream([t])).connect(an);
      connect.call(an, mute); connect.call(mute, own.destination);
      elTaps.set(el, an);
    }
    return [elTaps.get(el)];
  };
  // for the test's own diagnosis when a page gives no readings
  window.__tapInfo = () => ({ taps: taps.length, own: own && own.state, els: [...els].map((e) => [e.paused, +e.currentTime.toFixed(1), e.readyState]), elTaps: elTaps.size,
    tracks: [...elTaps.keys()].map((e) => e.__stream ? e.__stream.getAudioTracks().map((t) => t.readyState + (t.muted ? ' muted' : '')) : null), peak: (r => r && [Math.round(r.hz), Math.round(r.db)])(read()) });
  // every node that reaches the speakers is tapped (a page may also have a silent keep-alive there)
  const read = () => peak(taps) || peak(elementTap());
  setInterval(() => { try { if (window.swEmbed && swEmbed.heard && !window.__heardAt) window.__heardAt = performance.now() / 1000;
    const h = heard(), r = h === null ? null : read(); if (r) log.push([h, r.hz, r.db]); } catch (_) {} }, 50);
})();`;

// ---- the browser: Edge over the DevTools protocol, or Firefox over WebDriver BiDi --------------------
// open(path) opens the page in a window of its own (a tab behind another is hidden and gets no sound) with
// TAP in it before the page's scripts; evaluate(page, expression) gives the (awaited) value as JSON.
const { existsSync } = require("node:fs");
const profile = mkdtempSync(join(tmpdir(), "sw-rate-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
let proc, ws, id = 0;
const pending = new Map();
const send = (msg) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, ...msg })); });
const connect = async (url) => {
  ws = new WebSocket(url);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    const c = d.method === "Runtime.executionContextCreated" && d.params.context;
    if (c && c.auxData && c.auxData.isDefault) contexts.set(c.auxData.frameId, c.id);
  };
};
const contexts = new Map(); // DevTools: a frame's id -> its page's own context
const waitFor = async (url) => { for (let i = 0; i < 100; i++) { try { const r = await fetch(url); if (r.status < 500) return; } catch { /* starting */ } await sleep(200); } };
let browser;
if (PHONE) {
  const adbPath = process.env.SW_ADB || "D:/Android/platform-tools/adb.exe";
  const APP = process.env.SW_PHONE_BROWSER || "com.brave.browser";
  const adb = (...args) => execFileSync(adbPath, ["shell", ...args], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] });
  const call = (method, params = {}) => send({ method, params });
  const frames = { "/": null }, frameIds = {};
  browser = {
    name: `the phone's ${APP}`,
    async start() {
      if (adb("dumpsys", "window").includes("isKeyguardShowing=true")) throw new Error("the phone is locked: unlock it and run this again");
      execFileSync(adbPath, ["reverse", "tcp:8080", "tcp:8080"], { stdio: "ignore" });
      adb("monkey", "-p", APP, "-c", "android.intent.category.LAUNCHER", "1"); // the browser in front
      await sleep(2000);
      // port 9222 to that browser (Chrome has its own socket next to the plain one, which Brave may hold)
      const pid = adb("pidof", APP).trim().split(/\s+/)[0];
      const own = pid && adb("cat", "/proc/net/unix").includes(`@chrome_devtools_remote_${pid}`) ? `chrome_devtools_remote_${pid}` : "chrome_devtools_remote";
      execFileSync(adbPath, ["forward", "tcp:9222", `localabstract:${own}`], { stdio: "ignore" });
      const held = await fetch("http://localhost:9222/json/version").then((r) => r.json()).catch(() => ({}));
      if (held["Android-Package"] !== APP) throw new Error(`port 9222 leads to ${held["Android-Package"] || "nothing"}, not ${APP}: open it on the phone`);
      const find = async () => (await (await fetch("http://localhost:9222/json")).json()).find((t) => t.type === "page");
      let pg = await find();
      if (!pg) { adb("am", "start", "-a", "android.intent.action.VIEW", "-d", `${base}/privacy`, "-p", APP); for (let i = 0; i < 20 && !pg; i++) { await sleep(500); pg = await find(); } }
      await connect(pg.webSocketDebuggerUrl);
      await call("Page.enable"); await call("Runtime.enable");
      const ua = (await call("Runtime.evaluate", { expression: "navigator.userAgent", returnByValue: true })).result?.result?.value || "";
      await call("Network.setUserAgentOverride", { userAgent: ua + " StationwagonTest" }); // kept out of the station's stats
      await call("Page.addScriptToEvaluateOnNewDocument", { source: TAP }); // every frame gets it
    },
    async open(url) {
      const path = url.slice(base.length) || "/";
      if (path === "/") {
        await call("Page.navigate", { url });
        for (let i = 0; i < 30; i++) { if ((await this.evaluate(null, "typeof SwPlayer").catch(() => "")) === "object") break; await sleep(1000); }
        return null;
      }
      const fid = "rt" + Object.keys(frames).length;
      await this.evaluate(null, `(() => { const f = document.createElement('iframe'); f.id = ${JSON.stringify(fid)}; f.src = ${JSON.stringify(url)};
        f.allow = 'autoplay'; f.style.cssText = 'width:100%;height:240px;border:0;display:block'; document.body.prepend(f); })()`);
      for (let i = 0; i < 30; i++) {
        if (await this.evaluate(fid, path.startsWith("/embed") ? "!!window.swEmbed" : "typeof SwPlayer === 'object'").catch(() => false)) break;
        await sleep(1000);
      }
      frames[path] = fid;
      // its frame id: then it is asked in its own context, so a tap there is its own gesture (Android
      // keeps an AudioContext a frame makes without one suspended, and the test's analyser with it)
      const tree = (await call("Page.getFrameTree")).result.frameTree;
      const child = (tree.childFrames || []).find((f) => f.frame.url === url || f.frame.url.endsWith(path));
      if (child) frameIds[fid] = child.frame.id;
      return fid;
    },
    async evaluate(fid, expression) {
      const ctx = fid !== null && frameIds[fid] ? contexts.get(frameIds[fid]) : undefined;
      const expr = fid === null || ctx ? expression : `document.getElementById(${JSON.stringify(fid)}).contentWindow.eval(${JSON.stringify(expression)})`;
      const r = await call("Runtime.evaluate", { expression: expr, returnByValue: true, awaitPromise: true, userGesture: true, ...(ctx ? { contextId: ctx } : {}) });
      if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
      return r.result?.result?.value;
    },
    async close() {
      // stop the sound and leave the tab on a quiet page
      try { await call("Page.navigate", { url: `${base}/privacy` }); } catch { /* gone */ }
    },
  };
} else if (!FIREFOX) {
  const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((b) => existsSync(b));
  proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
    "--autoplay-policy=no-user-gesture-required", "--disable-background-media-suspend", "--disable-renderer-backgrounding",
    "--disable-background-timer-throttling", "--disable-backgrounding-occluded-windows", "about:blank"], { stdio: "ignore" });
  const call = (method, params = {}, sessionId = undefined) => send({ method, params, sessionId });
  browser = {
    name: "Edge",
    async start() { await waitFor(`http://127.0.0.1:${port}/json/version`); await connect((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl); },
    async open(url) {
      const t = await call("Target.createTarget", { url: "about:blank", newWindow: true });
      const sid = (await call("Target.attachToTarget", { targetId: t.result.targetId, flatten: true })).result.sessionId;
      await call("Page.enable", {}, sid); await call("Runtime.enable", {}, sid);
      await call("Page.addScriptToEvaluateOnNewDocument", { source: TAP }, sid);
      await call("Page.navigate", { url }, sid);
      return sid;
    },
    async evaluate(sid, expression) {
      const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true }, sid);
      if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
      return r.result?.result?.value;
    },
    async close() { ws.send(JSON.stringify({ id: ++id, method: "Browser.close" })); },
  };
} else {
  const exe = "C:/Program Files/Mozilla Firefox/firefox.exe";
  // autoplay allowed, no first-run pages or updates, nothing throttled in windows behind others
  writeFileSync(join(profile, "user.js"), [
    ["media.autoplay.default", 0], ["media.autoplay.blocking_policy", 0], ["media.block-autoplay-until-in-foreground", false],
    ["browser.shell.checkDefaultBrowser", false], ["browser.aboutwelcome.enabled", false], ["datareporting.policy.dataSubmissionEnabled", false],
    ["app.update.disabledForTesting", true], ["dom.timeout.enable_budget_timer_throttling", false], ["dom.min_background_timeout_value", 4],
    ["media.suspend-background-video.enabled", false], ["browser.startup.homepage_override.mstone", "ignore"],
  ].map(([k, v]) => `user_pref(${JSON.stringify(k)}, ${JSON.stringify(v)});`).join("\n"));
  proc = spawn(exe, ["-headless", "-no-remote", "-profile", profile, `--remote-debugging-port=${port}`, "about:blank"], { stdio: "ignore" });
  const call = async (method, params = {}) => {
    const r = await send({ method, params });
    if (r.type === "error" || r.error) throw new Error(`${method}: ${r.error} ${r.message || ""}`);
    return r.result;
  };
  browser = {
    name: "Firefox",
    async start() {
      await waitFor(`http://127.0.0.1:${port}/`);
      await connect(`ws://127.0.0.1:${port}/session`);
      await call("session.new", { capabilities: { alwaysMatch: { acceptInsecureCerts: true } } });
    },
    async open(url) {
      const { context } = await call("browsingContext.create", { type: "window" });
      await call("script.addPreloadScript", { functionDeclaration: `() => ${TAP.trim().replace(/;$/, "")}`, contexts: [context] });
      await call("browsingContext.navigate", { context, url, wait: "complete" });
      return context;
    },
    async evaluate(context, expression) {
      const r = await call("script.evaluate", { expression: `(async () => JSON.stringify(await (${expression})))()`, target: { context }, awaitPromise: true, userActivation: true });
      if (r.type === "exception") throw new Error(r.exceptionDetails?.text || "page error");
      return r.result?.value === undefined ? undefined : JSON.parse(r.result.value);
    },
    async close() { await call("browser.close").catch(() => {}); },
  };
}
const evaluate = (page, expression) => browser.evaluate(page, expression);

let fixture = null;
try {
  helper("rate-fixture-clean");
  console.log("Three songs: 44.1, 48 and 44.1 kHz, in order");
  fixture = helper("rate-fixture", ...songs.map((s) => `${s.file}|${s.title}`));
  const byTitle = Object.fromEntries(fixture.tracks.map((t) => [t.title, t]));
  const durs = songs.map((s) => Number(byTitle[s.title]?.duration_s));
  check("the station reads each song's length right, 48 kHz too (30 s, frames of 1152 samples at its own rate)", durs.every((d) => Math.abs(d - SECONDS) < 0.1), durs.join(" / "));
  check("a block plays them from now", !!fixture.block && !Object.keys(fixture.error || {}).length, JSON.stringify(fixture.error));

  console.log(`In ${browser.name}`);
  await browser.start();
  const pages = {};
  for (const [name, path] of [["decks", "/"], ["stream", "/?mode=stream"], ["embed", "/embed"]]) pages[name] = await browser.open(base + path);
  await sleep(3000);
  await evaluate(pages.decks, "SwPlayer.start()");
  await evaluate(pages.stream, "SwPlayer.start()");
  await evaluate(pages.embed, "document.getElementById('emPlay').click()");

  // the songs' places on the timeline
  const tl = await (await fetch(`${base}/api/timeline.php`)).json();
  const at = songs.map((s) => tl.entries.find((e) => e.type === "song" && e.title === s.title));
  check("the timeline has them one after another", at.every(Boolean) && at[0].start < at[1].start && at[1].start < at[2].start,
    at.map((e) => (e ? `${e.title.split(" ")[3]} ${(e.start - tl.server_now).toFixed(0)} s` : "missing")).join(", "));
  if (!at.every(Boolean)) throw new Error("the songs aren't on the timeline");
  const lastTurn = at[2].start + TURN + 2 + (FIREFOX ? 8 : 0); // Firefox: long enough to hear the late turn on its hls.js pages
  console.log(`  (listening until the third song's turn, about ${Math.round(lastTurn + 20 - tl.server_now)} s from now)`);
  const deadline = Date.now() + (lastTurn + 20 - tl.server_now + 90) * 1000;
  for (;;) {
    const h = await evaluate(pages.decks, "SwPlayer.state().heard").catch(() => null);
    if (h !== null && h > lastTurn) break;
    if (Date.now() > deadline) throw new Error("the songs never came");
    await sleep(1000);
  }
  await sleep(1500);

  for (const [name, sid] of Object.entries(pages)) {
    console.log(`The ${name === "decks" ? "player's decks" : name === "stream" ? "stream-only player (hls.js)" : "embed (hls.js)"}`);
    const log = await evaluate(sid, "window.__rateLog");
    const st = name === "embed" ? await evaluate(sid, "({ events: swEmbed.events, heardAt: window.__heardAt })") : await evaluate(sid, "SwPlayer.state()");
    for (const [i, s] of songs.entries()) {
      const e = at[i], rel = log.filter(([h]) => h !== null).map(([h, hz, db]) => [h - e.start, hz, db]);
      const loud = (from, to) => rel.filter(([t, , db]) => t >= from && t <= to && db > -80);
      const median = (xs) => { const v = xs.map((x) => x[1]).sort((a, b) => a - b); return v.length ? v[Math.floor(v.length / 2)] : null; };
      // away from the crossfades: the 600 Hz part after the song before has faded out, the 900 Hz part before the next fades in
      const fadeOut = e.fade_out || 6, low = loud(fadeOut + 0.8, TURN - 1.5), high = loud(TURN + 0.8, SECONDS - fadeOut - 0.4);
      const lowHz = median(low), highHz = median(high);
      if (FIREFOX && name !== "decks" && i > 0) {
        // the agreed Firefox limit: after a rate change these pages run late, so only the pitch is checked
        const all = loud(fadeOut + 0.8, SECONDS + 8), near = (hz) => all.filter(([, h]) => Math.abs(h - hz) < 10).length;
        check(`song ${s.name} (${s.rate / 1000} kHz) sounds at ${LOW} Hz (and no other pitch)`, lowHz !== null && Math.abs(lowHz - LOW) < 3 && near(LOW) + near(HIGH) >= all.length * 0.97,
          `${lowHz && lowHz.toFixed(1)} Hz from ${low.length} readings; ${near(LOW) + near(HIGH)} of ${all.length} at ${LOW} or ${HIGH} Hz`);
        const late = rel.find(([t, hz, db]) => t > TURN - 3 && t < SECONDS + 8 && db > -80 && Math.abs(hz - HIGH) < 10);
        console.log(`  note the turn at ${TURN} s is heard ${late ? (late[0] - TURN).toFixed(2) + " s late" : "after the test stopped listening"} (the agreed Firefox limit)`);
        continue;
      }
      check(`song ${s.name} (${s.rate / 1000} kHz) sounds at ${LOW} Hz, then ${HIGH} Hz`, lowHz !== null && highHz !== null && Math.abs(lowHz - LOW) < 3 && Math.abs(highHz - HIGH) < 3,
        `${lowHz && lowHz.toFixed(1)} Hz from ${low.length} readings, ${highHz && highHz.toFixed(1)} Hz from ${high.length}`);
      const turn = rel.find(([t, hz, db]) => t > TURN - 3 && t < TURN + 3 && db > -80 && Math.abs(hz - HIGH) < 10);
      check(`  the turn at ${TURN} s is heard on time`, !!turn && turn[0] - TURN > -0.15 && turn[0] - TURN < 0.35, turn ? `${(turn[0] - TURN).toFixed(2)} s` : "never heard");
    }
    if (!log.some(([h, , db]) => h !== null && db > -80)) { // nothing heard at all: what the page itself says
      const why = name === "embed" ? st.events : { status: st.status, stream: st.stream, events: st.events, diag: st.diag && { waits: st.diag.waits, dropLog: st.diag.dropLog } };
      console.log(`  (nothing was heard on this page: ${log.length} readings, all quiet; ${JSON.stringify(why).slice(0, 1500)})`);
      console.log(`  (the tap: ${JSON.stringify(await evaluate(sid, "window.__tapInfo && __tapInfo()").catch((e) => String(e)))})`);
    }
    if (name === "embed") {
      // before it is heard it is silent and seeking to the delay (waiting is expected there)
      const bad = (st.events || []).filter((x) => /stall|waiting|error/i.test(x) && parseFloat(x) > (st.heardAt || 0) + 0.5);
      check("no stalls, waits or errors once it is heard", bad.length === 0, bad.slice(0, 3).join(" | "));
    } else {
      const d = st.diag;
      const wallLessHeard = await evaluate(sid, "Date.now() / 1000 - SwPlayer.state().heard"); // the page's clock against the moment heard
      check("no dropouts, stalls or waits", st.drops === 0 && d.stalls === 0 && d.waits === 0 && (d.dropLog || []).length === 0,
        `${st.drops} drops, ${d.stalls} stalls, ${d.waits} waits${(d.dropLog || []).length ? ": " + d.dropLog.slice(0, 4).map((x) => {
          // where it fell: the moment heard then (20 s behind the wall clock), against the songs
          const h = x.at / 1000 - wallLessHeard, k = at.findLastIndex((e) => e.start <= h);
          return `${x.cause} (${x.detail}) at ${k < 0 ? `${(h - at[0].start).toFixed(1)} s before song A` : `song ${songs[k].name} + ${(h - at[k].start).toFixed(1)} s`}`;
        }).join("; ") : ""}`);
      if (name === "stream") check("  and the stream never changed speed", st.stream && st.stream.rate === 1, st.stream && String(st.stream.rate));
    }
  }
} catch (ex) {
  check("the test ran to the end", false, ex.message);
} finally {
  const c = helper("rate-fixture-clean");
  console.log(`Cleanup: the block, playlist and ${c.cleaned} test songs removed`);
  try {
    if (ws) { await Promise.race([browser.close(), sleep(3000)]); await sleep(1000); ws.close(); }
  } catch { /* closed */ }
  if (proc) proc.kill();
  if (FIREFOX) try { execFileSync("taskkill", ["/F", "/T", "/PID", String(proc.pid)], { stdio: "ignore" }); } catch { /* gone */ }
  await sleep(500);
  if (PHONE) try { execFileSync(process.env.SW_ADB || "D:/Android/platform-tools/adb.exe", ["forward", "--remove", "tcp:9222"], { stdio: "ignore" }); } catch { /* none */ }
  for (const d of [dir, profile]) try { rmSync(d, { recursive: true, force: true }); } catch { /* still held */ }
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
