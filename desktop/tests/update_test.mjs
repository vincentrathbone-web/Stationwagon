// End-to-end test of the Windows app's updates, through the Stationwagon Updates WordPress plugin
// (wordpress/stationwagon-updates, run under PHP's own web server with wordpress/tests/wp-stub.php) and
// a stand-in for GitHub's API that answers like a private repository: the release's files need the
// token, and the file host refuses it. Installs the older build on this PC, then:
//   - the plugin: 204 when nothing is newer, the updater's manifest when something is, no token or a
//     wrong one gives 204, the installer link, a missing file 404, the token never leaving the server;
//   - opening the app with a newer version shows the update dialog (round 58): the release's notes under
//     their headings, in the look picked in the studio; "Remind me later" then "Open App settings" or OK;
//   - "Update now" with a signature that doesn't match is refused (the app stays as it is, the dialog says why);
//   - "Update now" with a properly signed newer version installs it, and the app restarts new.
// Uninstalls it afterwards and puts your app settings back. Takes about 3 minutes.
//   bash tools/build-desktop.sh 0.6.0 test && bash tools/build-desktop.sh 0.6.1 test   (two test builds:
//   the same app, but its updater may use the stand-in's plain http; real builds only take https)
//   node desktop/tests/update_test.mjs [old version] [new version] [base url]
// Needs SW_PHP (php.exe) unless php is on PATH.
import { spawn, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, existsSync, mkdirSync, rmSync, mkdtempSync } from "node:fs";
import { createServer } from "node:http";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const OLD = process.argv[2] || "0.6.0", NEW = process.argv[3] || "0.6.1";
const base = (process.argv[4] || "http://localhost:8080/stationwagon-app").replace(/\/$/, "");
const php = process.env.SW_PHP || "php";
const dist = join(here, "../dist/test");
const file = (v) => join(dist, `Stationwagon-Studio_${v}_x64-setup.exe`);
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const ps = (cmd) => execFileSync("powershell", ["-NoProfile", "-Command", cmd], { encoding: "utf8" }).trim();
const installDir = join(process.env.LOCALAPPDATA, "Stationwagon Studio");
const installed = join(installDir, "stationwagon-studio.exe");
const versionOf = (exe) => (existsSync(exe) ? ps(`(Get-Item '${exe}').VersionInfo.ProductVersion`) : null);
const pids = () => ps("@(Get-Process stationwagon-studio -ErrorAction SilentlyContinue | ForEach-Object { $_.Id }) -join ','").split(",").filter(Boolean).map(Number);
const killAll = () => { for (const p of pids()) { try { execFileSync("taskkill", ["/PID", String(p), "/T", "/F"], { stdio: "ignore" }); } catch { /* gone */ } } };

for (const v of [OLD, NEW]) if (!existsSync(file(v)) || !existsSync(file(v) + ".sig")) { console.error(`Missing ${file(v)}(.sig): bash tools/build-desktop.sh ${v} test`); process.exit(1); }
const TOKEN = "test-token-" + Math.random().toString(36).slice(2);
// the release's description as written on GitHub (Windows line ends), with a long tail the plugin trims at 4000
const NOTES = ["## New features", "- A **dialog** before each update, with [these notes](https://example.com)", "", "Improvements:",
  "- Clearer wording", "**Fixes**", "* The start page follows the studio's look", "### Warning", "- The app closes while it updates",
  "", "- " + "Long notes. ".repeat(400)].join("\r\n");
const newExe = readFileSync(file(NEW)), newSig = readFileSync(file(NEW) + ".sig", "utf8"), oldSig = readFileSync(file(OLD) + ".sig", "utf8");

// ---- a stand-in for GitHub: a private repository's latest release ----------------------------------
const gh = { mode: "good", latest: 0, assets: 0, files: 0, tokenAtFileHost: 0 };
const github = createServer((req, res) => {
  const u = new URL(req.url, "http://x"), auth = req.headers.authorization || "";
  const send = (code, body, headers = {}) => { res.writeHead(code, headers); res.end(body); };
  if (u.pathname === "/repos/test/stationwagon/releases") {
    gh.latest++;
    if (auth !== `Bearer ${TOKEN}`) return send(auth ? 401 : 404, "{}");
    // the Windows release, with a newer Android one (released apart) and a draft above it
    const at = (min) => new Date(Date.now() - min * 60000).toISOString();
    return send(200, JSON.stringify([
      { tag_name: "v9.9.9", draft: true, published_at: null, assets: [{ id: 91, name: "Stationwagon-Studio_9.9.9_x64-setup.exe" }] },
      { tag_name: "android-v0.7.0", body: "Android", published_at: at(1), assets: [{ id: 21, name: "Stationwagon-Studio_0.7.0.apk" }] },
      { tag_name: "v" + NEW, body: NOTES, published_at: at(5),
        assets: [{ id: 11, name: `Stationwagon-Studio_${NEW}_x64-setup.exe` }, { id: 12, name: `Stationwagon-Studio_${NEW}_x64-setup.exe.sig` }] },
    ]), { "Content-Type": "application/json" });
  }
  const a = u.pathname.match(/^\/repos\/test\/stationwagon\/releases\/assets\/(\d+)$/);
  if (a) {
    gh.assets++;
    if (auth !== `Bearer ${TOKEN}` || req.headers.accept !== "application/octet-stream") return send(404, "{}");
    return send(302, "", { Location: `http://127.0.0.1:${github.address().port}/files/${a[1]}?X-Amz-Signature=short-lived` });
  }
  const f = u.pathname.match(/^\/files\/(\d+)$/);
  if (f) {
    gh.files++;
    if (auth) { gh.tokenAtFileHost++; return send(400, "Only one auth mechanism allowed"); } // as GitHub's file host does
    if (f[1] === "11") return send(200, newExe, { "Content-Type": "application/octet-stream", "Content-Length": newExe.length });
    return send(200, gh.mode === "tampered" ? oldSig : newSig);
  }
  send(404, "");
});
await new Promise((ok) => github.listen(0, "127.0.0.1", ok));

// ---- the plugin, in PHP's own web server --------------------------------------------------------------
const tmp = mkdtempSync(join(tmpdir(), "sw-wp-"));
const store = join(tmp, "options.json");
const setOptions = (o) => writeFileSync(store, JSON.stringify({ options: o }));
setOptions({ stationwagon_updates_token: TOKEN, stationwagon_updates_repo: "test/stationwagon" });
const wpPort = 18600 + Math.floor(Math.random() * 300);
const wp = spawn(php, ["-S", `127.0.0.1:${wpPort}`, join(here, "../../wordpress/tests/wp-stub.php")],
  { env: { ...process.env, SW_WP_STORE: store, SW_TEST_GITHUB: `http://127.0.0.1:${github.address().port}` }, stdio: "ignore" });
const WP = `http://127.0.0.1:${wpPort}/wp-json/stationwagon/v1/desktop`;
for (let i = 0; i < 50; i++) { try { await fetch(WP + "/latest-release"); break; } catch { await sleep(100); } }

// ---- the app's settings: the station for the test, yours back afterwards --------------------------------
const cfgDir = join(process.env.APPDATA, "za.co.controll.stationwagon.studio"), cfgFile = join(cfgDir, "settings.json");
const saved = existsSync(cfgFile) ? readFileSync(cfgFile) : null;
mkdirSync(cfgDir, { recursive: true });
// with the Hi-fi look, as the studio page passes it (set_look)
const LOOK = { skin: "hifi", vars: { "--panel": "#1f1d1a", "--accent": "#ffb24a", "--on-accent": "#1a1206", "--r-card": "3px", "--card-shadow": "inset 1px 1px 0 #4b463e, inset -1px -1px 0 #0a0908" } };
writeFileSync(cfgFile, JSON.stringify({ station: base + "/", port: 18000 + Math.floor(Math.random() * 1000), password: "update-test", look: LOOK }));
const wasInstalled = existsSync(installed);

const cdpPort = 9800 + Math.floor(Math.random() * 100);
const env = { ...process.env, WEBVIEW2_ADDITIONAL_BROWSER_ARGUMENTS: `--remote-debugging-port=${cdpPort}`, SW_UPDATE_ENDPOINT: `${WP}/latest-release?current_version={{current_version}}` };
const pages = async () => (await (await fetch(`http://127.0.0.1:${cdpPort}/json/list`)).json()).filter((t) => t.type === "page");
async function waitPage(match, seconds) {
  for (let i = 0; i < seconds * 5; i++) { const p = (await pages().catch(() => [])).find(match); if (p) return p; await sleep(200); }
  return null;
}
async function evalIn(target, expression) {
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  const r = await new Promise((ok) => { ws.onmessage = (m) => ok(JSON.parse(m.data)); ws.send(JSON.stringify({ id: 1, method: "Runtime.evaluate", params: { expression, returnByValue: true } })); });
  ws.close();
  return r.result?.result?.value;
}
const pageText = async (target) => (await evalIn(target, "document.body.innerText")) || "";
/** Waits (up to `seconds`) for the expression to give something truthy on the page; null if it never does. */
async function waitOn(target, expression, seconds = 20) {
  for (let i = 0; i < seconds * 4; i++) { const v = await evalIn(target, expression).catch(() => null); if (v) return v; await sleep(250); }
  return null;
}
const DIALOG = `(() => { const d = document.getElementById("swuDialog"); if (!d) return null;
  const css = getComputedStyle(d.querySelector(".swu-head") || d), card = getComputedStyle(d.querySelector(".swu-card") || d);
  return { title: d.querySelector("h3") && d.querySelector("h3").textContent, you: d.querySelector(".swu-head p") && d.querySelector(".swu-head p").textContent,
    heads: [...d.querySelectorAll(".swu-h")].map((x) => x.textContent), warn: [...d.querySelectorAll(".swu-h.warn")].map((x) => x.textContent),
    bullets: [...d.querySelectorAll(".swu-b span:last-child")].map((x) => x.textContent), box: d.querySelector(".swu-box") && d.querySelector(".swu-box").textContent,
    error: d.querySelector("#swuError") && d.querySelector("#swuError").textContent, buttons: [...d.querySelectorAll("button")].map((x) => x.textContent),
    head: css.backgroundColor, radius: card.borderTopLeftRadius, text: d.innerText }; })()`;

try {
  if (wasInstalled) throw new Error(`Stationwagon Studio is already installed in ${installDir}; this test would replace it. Uninstall it first.`);
  console.log("The plugin");
  let r = await fetch(`${WP}/latest-release?current_version=${NEW}`);
  check("nothing newer: 204", r.status === 204, r.status);
  r = await fetch(`${WP}/latest-release?current_version=${OLD}`);
  const m = r.status === 200 ? await r.json() : null;
  check("an older app gets the updater's answer for the new version", m && m.version === NEW && m.platforms["windows-x86_64"].signature === newSig.trim(), r.status);
  check("  its download goes through this server", m && m.platforms["windows-x86_64"].url === `${WP}/latest-release/download?asset=Stationwagon-Studio_${NEW}_x64-setup.exe`, m && m.platforms["windows-x86_64"].url);
  check("  and nothing in it carries the token", m && !JSON.stringify(m).includes(TOKEN));
  check("  its notes are the release's description, plain line ends, trimmed to 4000 characters", m && m.notes.startsWith("## New features\n- A **dialog**") && !m.notes.includes("\r") && m.notes.length <= 4000 && m.notes.length > 3900, m && m.notes.length);
  const before = gh.latest;
  await fetch(`${WP}/latest-release?current_version=${OLD}`);
  check("the release is asked of GitHub once, then kept for 10 minutes", gh.latest === before, `${gh.latest - before} more`);
  r = await fetch(`${WP}/installer`, { redirect: "manual" });
  const loc = r.headers.get("location") || "";
  check("the installer link sends people on to the file's short-lived link", r.status === 302 && loc.includes("/files/11") && !loc.includes(TOKEN), `${r.status} ${loc}`);
  const got = Buffer.from(await (await fetch(`${WP}/installer`)).arrayBuffer());
  check("  which is the installer, byte for byte", got.equals(newExe), `${got.length} bytes`);
  check("  and the token never reached the file host", gh.tokenAtFileHost === 0);
  r = await fetch(`${WP}/latest-release/download?asset=nothing.exe`);
  check("a file that isn't in the release: 404", r.status === 404, r.status);
  setOptions({ stationwagon_updates_repo: "test/stationwagon" });
  r = await fetch(`${WP}/latest-release?current_version=${OLD}`);
  check("with no token saved: 204 (no update), and the settings page knows why", r.status === 204 && JSON.parse(readFileSync(store, "utf8")).options.stationwagon_updates_last_error === "No GitHub token is saved yet.");
  setOptions({ stationwagon_updates_token: "wrong", stationwagon_updates_repo: "test/stationwagon" });
  r = await fetch(`${WP}/latest-release?current_version=${OLD}`);
  check("with a wrong token: 204, 'GitHub refused the token'", r.status === 204 && JSON.parse(readFileSync(store, "utf8")).options.stationwagon_updates_last_error === "GitHub refused the token (401).");
  setOptions({ stationwagon_updates_token: TOKEN, stationwagon_updates_repo: "test/stationwagon" });

  console.log(`Installing ${OLD}`);
  killAll();
  execFileSync(file(OLD), ["/S"], { stdio: "ignore" });
  check(`the installer puts ${OLD} in your own app folder (no admin needed)`, versionOf(installed) === OLD, versionOf(installed));
  check("  with an uninstaller", existsSync(join(installDir, "uninstall.exe")));
  killAll(); // the installer may start it

  console.log("Opening the app with a newer version: the update dialog");
  gh.mode = "tampered";
  const files0 = gh.files;
  spawn(installed, [], { env, stdio: "ignore", detached: true }).unref();
  let first = await waitPage(() => true, 30);
  let d = first && await waitOn(first, DIALOG, 20);
  check(`it asks first: "Version ${NEW} is now available", "You have version ${OLD}."`, d && d.title === `Version ${NEW} is now available` && d.you === `You have version ${OLD}.`, d && `${d.title} / ${d.you}`);
  check("  the notes under their headings (markdown headings, a colon line, a bold line), links and bold made plain",
    d && d.heads.slice(0, 4).join("|") === "New features|Improvements|Fixes|Warning" && d.bullets[0] === "A dialog before each update, with these notes" && d.bullets.includes("The start page follows the studio's look"), d && `${d.heads.slice(0, 4).join("|")} · ${d.bullets[0]}`);
  check("  Warning stands out (its own colour and a sign)", d && d.warn.length === 1 && d.warn[0] === "Warning");
  check("  it says the app will close while it updates", d && /will close while it updates/.test(d.box), d && d.box);
  check("  with Remind me later and Update now", d && d.buttons.join("|") === "Remind me later|Update now", d && d.buttons.join("|"));
  check("  in the look picked in the studio (Hi-fi: amber header, square corners)", d && d.head === "rgb(255, 178, 74)" && d.radius === "3px", d && `${d.head} ${d.radius}`);
  await evalIn(first, `document.getElementById("swuUpdate").click()`);
  d = await waitOn(first, `(() => { const e = document.getElementById("swuError"); return e && e.textContent; })()`, 60);
  check("Update now with a download whose signature doesn't match: refused, the dialog says so, the app stays as it is", !!d && /didn’t install/.test(d) && gh.files > files0 && versionOf(installed) === OLD, d);
  d = await evalIn(first, DIALOG);
  check("  and offers Try again", d && d.buttons.includes("Try again"), d && d.buttons.join("|"));
  await evalIn(first, `document.getElementById("swuLater").click()`);
  d = await evalIn(first, DIALOG);
  check("Remind me later: a notice that it can be installed from App settings at any time", d && /you can update later/.test(d.text) && /App settings/.test(d.text) && d.text.includes(`install version ${NEW}`) && d.buttons.join("|") === "Open App settings|OK", d && d.text.replace(/\s+/g, " "));
  await evalIn(first, `document.getElementById("swuOpenSettings").click()`);
  const settings = await waitOn(first, `!document.getElementById("swuDialog") && !document.getElementById("page").hidden && document.getElementById("updBtn") && location.href`, 10);
  check("  Open App settings: the dialog closes and the settings page shows, with Check for updates", !!settings, settings);
  await evalIn(first, `document.getElementById("updBtn").click()`);
  d = await waitOn(first, DIALOG, 20);
  check("  Check for updates there shows the dialog again", d && d.title === `Version ${NEW} is now available`);
  await evalIn(first, `document.getElementById("swuLater").click()`);
  const said = await waitOn(first, `!document.getElementById("swuDialog") && document.getElementById("msg").textContent`, 5);
  check("  where Remind me later just closes it (no notice: this is App settings)", !!said && said.includes(`install version ${NEW} here`), said);
  killAll();
  await sleep(1000);
  spawn(installed, [], { env, stdio: "ignore", detached: true }).unref();
  first = await waitPage(() => true, 30);
  d = first && await waitOn(first, DIALOG, 20);
  if (d) { await evalIn(first, `document.getElementById("swuLater").click()`); await evalIn(first, `document.getElementById("swuOk").click()`); }
  let p = await waitPage((t) => t.url.startsWith(base), 30);
  check("opening again: the dialog again (Remind me later lasts until the app next opens); Remind me later, OK: the studio opens", !!d && !!p, p ? p.url : "no studio page");
  killAll();
  await sleep(1000);

  console.log("A properly signed newer version");
  gh.mode = "good";
  spawn(installed, [], { env, stdio: "ignore", detached: true }).unref();
  first = await waitPage(() => true, 30);
  d = first && await waitOn(first, DIALOG, 20);
  check(`opening the app finds ${NEW} and asks first`, d && d.title === `Version ${NEW} is now available`);
  if (d) await evalIn(first, `document.getElementById("swuUpdate").click()`);
  const busy = d && await waitOn(first, `(() => { const b = document.getElementById("swuUpdate"); return b && b.disabled && /^Downloading/.test(b.textContent) && b.textContent; })()`, 10);
  check("  Update now: the button shows the download", !!busy, busy);
  let v = null;
  for (let i = 0; i < 120 && v !== NEW; i++) { await sleep(1000); v = versionOf(installed); }
  check(`  it installs`, v === NEW, v);
  p = null;
  for (let i = 0; i < 60 && !p; i++) { p = await waitPage((t) => t.url.startsWith(base), 1).catch(() => null); }
  check(`  and the app restarts as ${NEW}, back at the studio`, !!p && pids().length > 0 && versionOf(installed) === NEW, p ? `${versionOf(installed)}: ${p.url}` : "not restarted");
} catch (e) {
  failed++;
  console.log("  FAIL " + (e.stack || e.message));
} finally {
  killAll();
  if (!wasInstalled && existsSync(join(installDir, "uninstall.exe"))) {
    try { execFileSync(join(installDir, "uninstall.exe"), ["/S"], { stdio: "ignore" }); } catch { /* gone */ }
    for (let i = 0; i < 40 && existsSync(installed); i++) await sleep(250);
    console.log(existsSync(installed) ? "  (couldn't uninstall the test app)" : "  (the test app is uninstalled)");
  }
  if (saved) writeFileSync(cfgFile, saved); else rmSync(cfgFile, { force: true });
  wp.kill(); github.close();
  rmSync(tmp, { recursive: true, force: true });
}
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
