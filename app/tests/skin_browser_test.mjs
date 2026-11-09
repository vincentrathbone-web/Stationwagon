// Skins in a real browser (headless Edge or Chrome): the Look picker on every screen, the choice kept
// on this device so every screen follows it (open tabs too), the tokens and the canvases switching,
// and the embed's own picker (in another website's frame its storage is that website's).
//   node app/tests/skin_browser_test.mjs http://localhost:8080/stationwagon-app
// SW_PHP is the PHP for the helper; SW_HELPER_SSH="<ssh host>:<app folder>" runs it on a server.
// The outer page for the framed embed is another site: 127.0.0.1 for a localhost base, else SW_FRAME_HOST
// (default http://localhost:8080/stationwagon-app).
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("usage: node skin_browser_test.mjs <app url>"); process.exit(2); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const remote = process.env.SW_HELPER_SSH ? process.env.SW_HELPER_SSH.split(":") : null;
const helper = (...args) => JSON.parse(remote
  ? execFileSync("ssh", [remote[0], `cd ${remote[1]} && php tests/browser_helper.php ${args.map((a) => "'" + String(a).replace(/'/g, "'\''") + "'").join(" ")}`], { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] })
  : execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
const outer = base.includes("//localhost") ? base.replace("//localhost", "//127.0.0.1") : (process.env.SW_FRAME_HOST || "http://localhost:8080/stationwagon-app").replace(/\/$/, "");

let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  if (ok) passed++; else failed++;
  console.log(`  [${ok ? "PASS" : "FAIL"}] ${label}${detail ? " · " + detail : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((p) => existsSync(p));
const profile = mkdtempSync(join(tmpdir(), "sw-skin-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
// frames stay in the page's process, so the test can reach into the framed embed
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
  "--autoplay-policy=no-user-gesture-required", "--disable-site-isolation-trials", "--disable-features=IsolateOrigins,site-per-process,CalculateNativeWinOcclusion",
  "--window-size=1280,900", "about:blank"], { stdio: "ignore" });

/** One page (tab) over the DevTools protocol. */
async function tab(wsUrl) {
  const ws = new WebSocket(wsUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  let id = 0; const pending = new Map(), contexts = new Map();
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    if (d.method === "Runtime.executionContextCreated") contexts.set(d.params.context.id, d.params.context);
  };
  const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
  const evaluate = async (expression, contextId) => {
    const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true, ...(contextId ? { contextId } : {}) });
    if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
    return r.result?.result?.value;
  };
  const go = async (url) => {
    await call("Page.navigate", { url });
    for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") break; }
    await sleep(200);
  };
  await call("Page.enable"); await call("Runtime.enable");
  return { ws, call, evaluate, go, contexts };
}

// What a page looks like now: its skin, a few tokens, the picker, and computed styles that show it
const LOOK = `(() => {
  const r = document.documentElement, cs = getComputedStyle(r), t = (n) => cs.getPropertyValue(n).trim();
  const pick = document.querySelector("[data-skin-pick]"), win = document.querySelector(".lp-win, .st-win, .card, .tile, .mhdr, main form, .em");
  return { skin: r.getAttribute("data-skin") || "", amber: t("--amber"), ground: t("--ground"), rcard: t("--r-card"),
    font: getComputedStyle(document.body).fontFamily.split(",")[0].replace(/"/g, ""),
    radius: win ? getComputedStyle(win).borderTopLeftRadius : "", bg: getComputedStyle(document.body).backgroundColor,
    pick: pick ? (pick.tagName === "SELECT" ? pick.value : pick.textContent) : null, api: !!window.SwSkin };
})()`;
const HIFI = "#ffb24a", GREEN = "#1ed760";
const rgb = (hex) => `rgb(${parseInt(hex.slice(1, 3), 16)}, ${parseInt(hex.slice(3, 5), 16)}, ${parseInt(hex.slice(5, 7), 16)})`;
const pickSkin = (t, id) => t.evaluate(`(() => { const s = document.querySelector("select[data-skin-pick]"); s.value = ${JSON.stringify(id)}; s.dispatchEvent(new Event("change")); return 1; })()`);

let manager = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const first = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  const A = await tab(first.webSocketDebuggerUrl);

  console.log("A new device");
  await A.go(`${base}/`);
  let l = await A.evaluate(LOOK);
  check("the player starts in the default look (green), with the Look picker in the top bar", l.skin === "" && l.amber === GREEN && l.pick === "green" && l.api, JSON.stringify(l));
  check("  green: rounded cards, Figtree", l.radius === "16px" && l.font === "Figtree", `${l.radius}, ${l.font}`);

  console.log("Picking Hi-fi on the player");
  await pickSkin(A, "hifi");
  await sleep(300);
  l = await A.evaluate(LOOK);
  check("the page switches at once: hi-fi tokens, square bevelled windows, Barlow", l.skin === "hifi" && l.amber === HIFI && l.radius === "3px" && l.font === "Barlow" && l.bg === rgb("#141311"), JSON.stringify(l));
  check("  it is kept on this device", (await A.evaluate("localStorage.getItem('sw.skin')")) === "hifi");
  // the spectrum and the output strip read their colours from the skin
  await A.evaluate(`(() => { const v = document.getElementById("lpVol"); v.value = 0; v.dispatchEvent(new Event("input")); SwPlayer.start(); return 1; })()`);
  let spec = null;
  for (let i = 0; i < 80 && !(spec && spec.n > 50); i++) {
    await sleep(250);
    spec = await A.evaluate(`(() => { const c = document.getElementById("lpSpec"); if (!c.width) return null; const d = c.getContext("2d").getImageData(0, 0, c.width, c.height).data;
      let n = 0, r = 0, g = 0, b = 0; for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 200) { n++; r += d[i]; g += d[i + 1]; b += d[i + 2]; }
      return n ? { n, r: Math.round(r / n), g: Math.round(g / n), b: Math.round(b / n) } : { n }; })()`);
  }
  check("  the spectrum draws in the hi-fi amber (red over green over blue)", !!spec && spec.n > 50 && spec.r > spec.g && spec.g > spec.b, JSON.stringify(spec));
  const strip = await A.evaluate(`(() => { const c = document.getElementById("lpWave"); return !!c && c.width > 0; })()`);
  check("  the output strip keeps drawing", strip);

  console.log("Every screen follows the device's choice");
  await A.go(`${base}/`);
  l = await A.evaluate(LOOK);
  check("after a reload the player is still hi-fi, the picker says so", l.skin === "hifi" && l.pick === "hifi" && l.amber === HIFI, JSON.stringify(l));
  const order = await A.evaluate(`(() => { const h = [...document.head.children]; const s = h.findIndex((e) => e.tagName === "SCRIPT" && !e.src && e.textContent.includes("sw.skin")), c = h.findIndex((e) => e.tagName === "LINK" && /app\\.css/.test(e.href)); return s >= 0 && s < c; })()`);
  check("  the look is set in <head> before the stylesheet (no flash of green)", order);
  for (const p of ["podcast", "privacy", "signin", "embed"]) {
    await A.go(`${base}/${p}`);
    l = await A.evaluate(LOOK);
    check(`/${p} is hi-fi too`, l.skin === "hifi" && l.amber === HIFI && l.bg !== "" && (p === "embed" ? l.pick === "Look: Hi-fi" : l.pick === "hifi"), JSON.stringify(l));
  }
  manager = helper("manager");
  await A.go(`${base}/signin`);
  await A.evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(manager.email)}; f.password.value = ${JSON.stringify(manager.password)}; f.submit(); })()`);
  for (let i = 0; i < 50 && !(await A.evaluate("location.pathname").catch(() => "")).endsWith("/manage"); i++) await sleep(100);
  await sleep(500);
  for (const p of ["account", "manage", "manage/listeners", "manage/sound", "manage/podcast", "studio"]) {
    await A.go(`${base}/${p}`);
    l = await A.evaluate(LOOK);
    check(`/${p} (signed in) is hi-fi`, l.skin === "hifi" && l.amber === HIFI && l.pick === "hifi" && l.font === "Barlow", JSON.stringify(l));
  }
  // round 55: at phone width the top bar (logo, version, Look, the signed-in links) wraps instead of pushing
  // the Look picker and Sign out off the side (found in Safari's engine; Edge did the same)
  await A.call("Emulation.setDeviceMetricsOverride", { width: 360, height: 740, deviceScaleFactor: 1, mobile: false });
  for (const p of ["", "manage", "manage/library", "podcast", "privacy"]) {
    await A.go(`${base}/${p}`);
    const w = await A.evaluate(`(() => { const out = [...document.querySelectorAll(".top *")].filter((el) => { const r = el.getBoundingClientRect(); return r.width > 0 && (r.right > innerWidth + 1 || r.left < -1); }).map((el) => el.tagName.toLowerCase() + "." + el.className);
      return { page: document.documentElement.scrollWidth, inner: innerWidth, out }; })()`);
    check(`  /${p} at 360 px: the top bar fits, nothing scrolls sideways`, w.inner === 360 && w.page <= 360 && !w.out.length, JSON.stringify(w));
  }
  await A.call("Emulation.clearDeviceMetricsOverride");
  await A.go(`${base}/studio`); // the checks below read the studio's deck
  // the studio's deck waves are drawn on a canvas: their background is the skin's display colour
  const deckBg = async () => { await sleep(700); return A.evaluate(`(() => { const c = document.getElementById("wA"); if (!c || !c.width) return null; const d = c.getContext("2d").getImageData(2, 2, 1, 1).data; return [d[0], d[1], d[2]]; })()`); };
  let px = await deckBg();
  check("  the studio's deck wave uses the hi-fi display colour", !!px && px.join() === "13,12,9", JSON.stringify(px));

  console.log("Open tabs switch at once");
  const t2 = await (await fetch(`http://127.0.0.1:${port}/json/new?${base}/manage`, { method: "PUT" })).json();
  const B = await tab(t2.webSocketDebuggerUrl);
  for (let i = 0; i < 50 && (await B.evaluate("document.readyState").catch(() => "")) !== "complete"; i++) await sleep(100);
  await sleep(300);
  await pickSkin(B, "green");
  await sleep(500);
  l = await A.evaluate(LOOK);
  check("picking Green in the manager (second tab) turns the open studio green", l.skin === "" && l.amber === GREEN && l.pick === "green" && l.radius === "16px", JSON.stringify(l));
  px = await deckBg();
  check("  and its deck wave redraws in the green display colour", !!px && px.join() === "31,31,31", JSON.stringify(px));
  await pickSkin(B, "hifi");
  await sleep(500);
  check("  and back to Hi-fi", (await A.evaluate(LOOK)).skin === "hifi");
  await B.call("Page.close").catch(() => {});

  console.log("The embed");
  await A.go(`${base}/embed?look=light`);
  l = await A.evaluate(LOOK);
  check("?look=light keeps the light colours for light websites, with the hi-fi shapes and type", l.skin === "hifi" && l.ground === "#ffffff" && l.rcard === "3px" && l.font === "Barlow", JSON.stringify(l));
  await A.go(`${base}/embed`);
  await A.evaluate(`document.querySelector("[data-skin-pick]").click()`);
  await sleep(300);
  l = await A.evaluate(LOOK);
  check("the embed's Look button steps to the next look (Green), and the station's screens follow on this site", l.skin === "" && l.pick === "Look: Green" && (await A.evaluate("localStorage.getItem('sw.skin')")) === "green", JSON.stringify(l));
  await A.evaluate(`document.querySelector("[data-skin-pick]").click()`);
  await sleep(200);
  check("  pressed again: Hi-fi", (await A.evaluate(LOOK)).pick === "Look: Hi-fi");

  // On another website the frame's storage is that website's: its own choice, kept for that website
  const framed = async () => {
    await A.go(`${outer}/privacy`);
    await A.evaluate(`(() => { const f = document.createElement("iframe"); f.src = ${JSON.stringify(base + "/embed")}; f.width = 520; f.height = 124; f.id = "swf"; document.body.prepend(f); return 1; })()`);
    for (let i = 0; i < 60; i++) {
      await sleep(150);
      const tree = (await A.call("Page.getFrameTree")).result.frameTree;
      const child = (tree.childFrames || []).find((c) => c.frame.url.startsWith(base + "/embed"));
      if (child) {
        const w = await A.call("Page.createIsolatedWorld", { frameId: child.frame.id, worldName: "skintest" });
        const ctx = w.result.executionContextId;
        if ((await A.evaluate("document.readyState", ctx).catch(() => "")) === "complete" && (await A.evaluate("!!document.querySelector('[data-skin-pick]')", ctx))) { await sleep(200); return ctx; }
      }
    }
    return null;
  };
  let ctx = await framed();
  l = ctx ? await A.evaluate(LOOK, ctx) : null;
  check("framed on another website, the embed starts in its own default look (the station site's Hi-fi isn't seen there)", !!l && l.skin === "" && l.pick === "Look: Green", JSON.stringify(l));
  if (ctx) await A.evaluate(`document.querySelector("[data-skin-pick]").click()`, ctx);
  await sleep(300);
  ctx = await framed();
  l = ctx ? await A.evaluate(LOOK, ctx) : null;
  check("  its own Look button's choice is kept for that website after a reload", !!l && l.skin === "hifi" && l.pick === "Look: Hi-fi", JSON.stringify(l));
  await A.go(`${outer}/`);
  l = await A.evaluate(LOOK);
  check("  and that website's own pages aren't changed by it", l.skin === "" || !base.includes("//localhost"), JSON.stringify(l));

  console.log("A private window (no storage)");
  await A.call("Page.addScriptToEvaluateOnNewDocument", { source: "Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } });" });
  await A.go(`${base}/`);
  l = await A.evaluate(LOOK);
  check("with storage blocked the page shows the default look without errors", l.skin === "" && l.amber === GREEN && l.api, JSON.stringify(l));
  await pickSkin(A, "hifi");
  await sleep(200);
  check("  and picking still changes this page", (await A.evaluate(LOOK)).skin === "hifi");
} catch (e) {
  failed++;
  console.log("  [FAIL] the test stopped: " + (e.stack || e.message));
} finally {
  try { if (manager) helper("cleanup", String(manager.id)); } catch (e) { console.log("cleanup: " + e.message); }
  proc.kill();
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
