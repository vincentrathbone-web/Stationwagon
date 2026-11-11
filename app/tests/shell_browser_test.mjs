// End-to-end test of the bar under the staff's screens and the small Now playing card (round 61), in a real
// browser (headless Edge or Chrome) on Laragon:
//   - a manager screen opened in the browser comes inside the station's frame page (views/shell.php), the
//     screen in one frame and the bar (`bar`) under it; the bar runs by itself, muted, with no play button;
//   - moving between screens (a link in the screen, Back) changes only the screen's frame: the same bar plays
//     on with no gap, and the address bar and the title follow the screen;
//   - the studio and Song prep mute the bar by themselves; the speaker's volume slider comes up out of it
//     (the bar's frame grows over the screen's foot) and goes down again; the volume is remembered, and a
//     new visit starts muted again;
//   - links to pages that aren't screens (the station page) and signing out take the whole window;
//   - an older studio app (window.__TAURI__ without its own bar) asks for the screens without the frame page;
//   - the Now playing card (`now`): the song on air, its link (`?link=`, `?target=new`, a bad link refused).
// Takes about a minute.
//   node app/tests/shell_browser_test.mjs http://localhost:8080/stationwagon-app
// Needs SW_PHP (php.exe) unless php is on PATH; SW_HELPER_SSH="<ssh host>:<app folder>" for the test server.
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/shell_browser_test.mjs <base url>"); process.exit(1); }
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
const profile = mkdtempSync(join(tmpdir(), "sw-shell-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
  "--autoplay-policy=no-user-gesture-required", "--window-size=1280,800", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
const ready = async () => { for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; } };
const go = async (url) => { await call("Page.navigate", { url }); await ready(); };
async function waitFor(fn, seconds) {
  const end = Date.now() + seconds * 1000;
  while (Date.now() < end) { const v = await fn().catch(() => null); if (v) return v; await sleep(150); }
  return null;
}
/** The bar, as the frame page sees it (same site, so its frames can be read). */
const BAR = `(() => { const w = swShell.bar.contentWindow; if (!w.swEmbed || !w.SwBar) return null; const d = w.document;
  return { playing: w.swEmbed.playing, heard: w.swEmbed.heard, muted: w.SwBar.muted, vol: w.SwBar.volume(), open: w.SwBar.open, h: swShell.bar.getBoundingClientRect().height,
    play: !!d.getElementById("emPlay"), t: w.performance.timeOrigin, title: d.getElementById("emTitle").textContent, ping: w.swEmbed.ping }; })()`;
const bar = () => evaluate(BAR).catch(() => null);
/** The screen in the frame: its route and title. */
const screen = () => evaluate(`({ route: swShell.route, href: swShell.screen.contentWindow.location.href, title: swShell.screen.contentDocument.title, top: location.href, topTitle: document.title })`).catch(() => null);
/** Clicks a link in the screen's frame (as a person does: a click event). */
const clickInScreen = (sel) => evaluate(`(() => { const a = swShell.screen.contentDocument.querySelector(${JSON.stringify(sel)}); if (!a) return false; a.click(); return true; })()`);
const routeIs = (r) => waitFor(async () => { const s = await screen(); return s && s.route === r && s.top.endsWith("/" + r) && s; }, 10);

let manager = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const page = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  await call("Page.enable"); await call("Runtime.enable");

  console.log("The Now playing card (now)");
  const np = await (await fetch(`${base}/api/nowplaying.php`)).json();
  await go(`${base}/now?link=${encodeURIComponent("https://radio.example.org/listen/")}`);
  const card = await waitFor(async () => { const x = await evaluate(`({ title: nwTitle.textContent, artist: nwArtist.textContent, href: nwLink.href, target: nwLink.target, mode: nwMode.textContent, fill: nwFill.style.width, art: nwArt.classList.contains("has-art"), img: nwArtImg.getAttribute("src") })`); return x && x.title !== "Loading…" && x; }, 10);
  const air = np.on_air;
  check("it shows what's on air (the song and artist, or the live show)", card && air && card.title === air.title, card && `${card.title} · ${card.artist} (${card.mode})`);
  check("  its link leads to ?link, in the whole page", card && card.href === "https://radio.example.org/listen/" && card.target === "_top", card && `${card.href} ${card.target}`);
  check("  the progress line moves", !!(await waitFor(async () => parseFloat(await evaluate("nwFill.style.width")) > 0 && true, 5)), await evaluate("nwFill.style.width"));
  check("  the album art from the station, or the CD", card && (card.art ? card.img.startsWith(base + "/") : !card.img), card && String(card.img));
  check("  no sound, no Listen button that plays", (await evaluate("document.querySelectorAll('audio, video').length === 0 && !window.SwPlayer")) === true);
  await go(`${base}/now?link=${encodeURIComponent("javascript:alert(1)")}&target=new`);
  const bad = await evaluate(`({ href: nwLink.href, target: nwLink.target, rel: nwLink.rel })`);
  check("  a link that isn't http(s) is refused (the station page instead); ?target=new opens a new tab", bad.href === base + "/" && bad.target === "_blank" && bad.rel.includes("noopener"), JSON.stringify(bad));
  const head = await fetch(`${base}/now`).then((r) => r.headers.get("content-security-policy") || "");
  check("  any website may frame it", head.includes("frame-ancestors *"), head);

  console.log("The frame page and the bar");
  manager = helper("manager");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(manager.email)}; f.password.value = ${JSON.stringify(manager.password)}; f.submit(); })()`);
  await waitFor(async () => (await evaluate("location.pathname")).endsWith("/manage"), 10);
  await ready();
  let s = await waitFor(async () => { const x = await screen(); return x && x.route === "manage" && x; }, 10);
  check("after signing in, the manager opens inside the frame page (the screen in a frame, the bar under it)", !!s && (await evaluate("!!document.getElementById('swBar') && document.body.classList.contains('shell')")), s && JSON.stringify(s));
  let b = await waitFor(async () => { const x = await bar(); return x && x.playing && x; }, 15);
  check("the bar runs by itself, muted, with no play button, 84 px tall in a wide window", b && b.muted && !b.play && b.h === 84, JSON.stringify(b));
  check("  not counted as a listener", b && b.ping === null);
  check("  and shows what's on air", b && air && b.title.startsWith(air.title), b && b.title);
  const first = b;
  await evaluate("swShell.bar.contentWindow.SwBar.mute(false)");
  check("the bar may be unmuted (the speaker)", !!(await waitFor(async () => { const x = await bar(); return x && !x.muted && x.heard; }, 10)));

  await clickInScreen('a[href$="/manage/library"]');
  s = await routeIs("manage/library");
  check("a link in the screen: the screen's frame moves, the address bar and title follow", s && /library/i.test(s.topTitle), s && `${s.top} · ${s.topTitle}`);
  b = await bar();
  check("  the same bar plays on with no gap (not loaded again), still heard", b && b.t === first.t && b.playing && !b.muted, JSON.stringify(b));
  await clickInScreen('a[href$="/manage/playlists"]');
  s = await routeIs("manage/playlists");
  await call("Runtime.evaluate", { expression: "history.back()" });
  s = await routeIs("manage/library");
  check("Back goes to the screen before, still in the frame page", !!s && (await bar()).t === first.t, s && s.top);
  await clickInScreen('a[href$="/account"]');
  s = await routeIs("account");
  check("your account page opens in the frame too", !!s && (await bar()).t === first.t, s && s.topTitle);

  await clickInScreen('a[href$="/manage/songprep"]');
  if (!(await routeIs("manage/songprep"))) { await evaluate(`swShell.screen.contentWindow.location.href = ${JSON.stringify(base + "/manage/songprep")}`); await routeIs("manage/songprep"); }
  b = await waitFor(async () => { const x = await bar(); return x && x.muted && x; }, 5);
  check("Song prep mutes the bar by itself", b && b.t === first.t, JSON.stringify(b));
  await evaluate("swShell.bar.contentWindow.SwBar.mute(false)");
  await clickInScreen('a[href$="/studio"]');
  s = await routeIs("studio");
  b = await waitFor(async () => { const x = await bar(); return x && x.muted && x; }, 5);
  check("the studio mutes it too, and opens in the frame", !!s && b && b.t === first.t, s && s.topTitle);
  check("  the studio's frame may use the microphone", ((await evaluate("swShell.screen.getAttribute('allow')")) || "").includes("microphone"));

  console.log("The volume slider");
  await evaluate(`swShell.bar.contentDocument.getElementById("emSound").dispatchEvent(new PointerEvent("pointerenter", { pointerType: "mouse" }))`);
  b = await waitFor(async () => { const x = await bar(); return x && x.open && x.h > 200 && x; }, 3);
  check("pointing at the speaker brings the slider up; the bar's frame grows over the screen's foot", !!b, JSON.stringify(await bar()));
  const screenH = await evaluate("swShell.screen.getBoundingClientRect().height");
  check("  the screen keeps its size under it", screenH === (await evaluate("innerHeight")) - 84, String(screenH));
  await evaluate(`(() => { const v = swShell.bar.contentDocument.getElementById("emVol"); v.value = 35; v.dispatchEvent(new Event("input", { bubbles: true })); v.dispatchEvent(new Event("change", { bubbles: true })); })()`);
  b = await bar();
  check("  moving it sets the volume and turns the sound on", b && Math.abs(b.vol - 0.35) < 0.001 && !b.muted, JSON.stringify(b));
  // round 66: muting drops the slider to the bottom, and raising it from there unmutes quietly
  const slider = () => evaluate(`swShell.bar.contentDocument.getElementById("emVol").value`);
  const press = () => evaluate(`swShell.bar.contentDocument.getElementById("emMute").click()`);
  const drag = (v) => evaluate(`(() => { const e = swShell.bar.contentDocument.getElementById("emVol"); e.value = ${v}; e.dispatchEvent(new Event("input", { bubbles: true })); })()`);
  await press();
  b = await bar();
  check("  pressing the speaker mutes it and drops the slider to the bottom, keeping the volume for later", b && b.muted && (await slider()) === "0" && Math.abs(b.vol - 0.35) < 0.001, JSON.stringify(b) + " slider " + await slider());
  await press();
  b = await bar();
  check("  pressing it again brings the sound back at the same volume, the slider up where it was", b && !b.muted && (await slider()) === "35" && Math.abs(b.vol - 0.35) < 0.001, JSON.stringify(b) + " slider " + await slider());
  await press();
  await drag(4);
  b = await bar();
  check("  from the bottom, raising it a little unmutes at that low level", b && !b.muted && Math.abs(b.vol - 0.04) < 0.001 && (await slider()) === "4", JSON.stringify(b) + " slider " + await slider());
  await press();
  await drag(0);
  b = await bar();
  check("  moving it to the bottom while muted changes nothing (still muted, the volume kept)", b && b.muted && (await slider()) === "0" && Math.abs(b.vol - 0.04) < 0.001, JSON.stringify(b));
  await press();
  b = await bar();
  check("  a volume left at the bottom never comes back silent: the speaker brings it to half", b && !b.muted && Math.abs(b.vol - 0.5) < 0.001 && (await slider()) === "50", JSON.stringify(b) + " slider " + await slider());
  await evaluate(`swShell.bar.contentWindow.SwBar.volume(0.35)`);
  await evaluate(`swShell.bar.contentDocument.getElementById("emSound").dispatchEvent(new PointerEvent("pointerleave", { pointerType: "mouse" }))`);
  b = await waitFor(async () => { const x = await bar(); return x && !x.open && x.h === 84 && x; }, 6);
  check("  the pointer leaving: it goes down again", !!b, JSON.stringify(await bar()));
  await go(`${base}/manage`);
  b = await waitFor(async () => { const x = await bar(); return x && x.playing && x; }, 15);
  check("opening the manager again (a new visit): muted again, the volume kept", b && b.muted && Math.abs(b.vol - 0.35) < 0.001, JSON.stringify(b));

  console.log("Leaving the screens");
  await clickInScreen(`header a[href="${new URL(base).pathname}/"]`);
  const out = await waitFor(async () => { const x = await evaluate("({ href: location.href, shell: !!window.swShell })"); return x && !x.shell && x.href === base + "/" && x; }, 10);
  check("the station page link opens in the whole window (no frame page, no bar)", !!out, JSON.stringify(await evaluate("({ href: location.href, shell: !!window.swShell })")));
  await go(`${base}/manage/library?bare=1`);
  check("?bare gives the screen on its own", (await evaluate("!window.swShell && !!document.querySelector('main')")) === true);
  await go(`${base}/manage`);
  await waitFor(async () => (await screen())?.route === "manage", 10);
  await evaluate(`(() => { const b = [...swShell.screen.contentDocument.querySelectorAll("button")].find((x) => x.textContent.trim() === "Sign out"); b.click(); })()`);
  const signedOut = await waitFor(async () => { const x = await evaluate("({ path: location.pathname, shell: !!window.swShell })"); return x && x.path.endsWith("/signin") && !x.shell && x; }, 10);
  check("signing out from the screen: the sign-in page in the whole window", !!signedOut, JSON.stringify(await evaluate("({ href: location.href, shell: !!window.swShell })")));
  await go(`${base}/manage`);
  check("  signed out, a screen isn't framed: the sign-in page", (await evaluate("location.pathname")).endsWith("/signin") && !(await evaluate("!!window.swShell")));

  console.log("An older studio app");
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(manager.email)}; f.password.value = ${JSON.stringify(manager.password)}; f.submit(); })()`);
  await waitFor(async () => (await evaluate("location.pathname")).endsWith("/manage"), 10);
  const inject = await call("Page.addScriptToEvaluateOnNewDocument", { source: "window.__TAURI__ = { core: {} };" });
  await go(`${base}/studio`);
  const bare = await waitFor(async () => { const x = await evaluate("({ shell: !!window.swShell, studio: !!document.getElementById('stBanner') || !!window.SwStudio, cookie: document.cookie })"); return x && !x.shell && x.studio && x; }, 10);
  check("an app without its own bar (window.__TAURI__) gets the studio itself, and the sw_bare mark", bare && bare.cookie.includes("sw_bare=1"), JSON.stringify(bare));
  await call("Page.removeScriptToEvaluateOnNewDocument", { identifier: inject.result.identifier });
  await call("Network.deleteCookies", { name: "sw_bare", url: base + "/" });
  await go(`${base}/studio`);
  check("  (the mark gone, the browser gets the frame page again)", !!(await waitFor(async () => (await screen())?.route === "studio", 10)));
} catch (e) {
  failed++;
  console.log("  FAIL " + (e.stack || e.message));
} finally {
  try { if (manager) helper("cleanup", manager.id); } catch (e) { console.log("cleanup: " + e.message); }
  try { ws && ws.close(); } catch { /* closed */ }
  try {
    const b = new WebSocket((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
    await new Promise((ok, bad) => { b.onopen = ok; b.onerror = bad; });
    b.send(JSON.stringify({ id: 1, method: "Browser.close" }));
    await sleep(1000);
  } catch { /* already closed */ }
  proc.kill();
  spawnSync("powershell", ["-NoProfile", "-Command", `Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" | Where-Object { $_.CommandLine -like '*${profile}*' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }`], { stdio: "ignore" });
  await sleep(500);
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still locked */ }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : `\nAll ${passed} checks passed`);
process.exit(failed ? 1 : 0);
