// "Look up details" in a real browser (headless Edge or Chrome), round 65: a throwaway manager opens a song's details
// window, looks it up (MusicBrainz's answers are saved ones put in the lookup's cache, so nothing reaches the internet),
// sees the suggestions as a table with the empty fields ticked, unticks one, fills the rest, saves, and the song keeps
// the album, label, ISRC, writers, publishers, ISWC and a link to its source. A second look-up shows what is now the same.
//   node app/tests/lookup_browser_test.mjs http://localhost:8080/stationwagon-app
// SW_PHP is the PHP for the helper (Laragon only: it makes and removes the throwaway manager and songs).
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, existsSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("usage: node lookup_browser_test.mjs <app url>"); process.exit(2); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args.map(String)], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  if (ok) passed++; else failed++;
  console.log(`  [${ok ? "PASS" : "FAIL"}] ${label}${detail ? " · " + detail : ""}`);
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"].find((p) => existsSync(p));
const profile = mkdtempSync(join(tmpdir(), "sw-lookup-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "--window-size=1280,1000", "about:blank"], { stdio: "ignore" });

async function tab(wsUrl) {
  const ws = new WebSocket(wsUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  let id = 0; const pending = new Map();
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); } };
  const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
  const evaluate = async (expression) => {
    const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true, userGesture: true });
    if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
    return r.result?.result?.value;
  };
  const go = async (url) => {
    await call("Page.navigate", { url });
    for (let i = 0; i < 100; i++) { await sleep(100); if ((await evaluate("document.readyState").catch(() => "")) === "complete") break; }
    await sleep(300);
  };
  const until = async (expr, ms = 15000) => { for (let t = 0; t < ms; t += 100) { if (await evaluate(expr).catch(() => false)) return true; await sleep(100); } return false; };
  await call("Page.enable"); await call("Runtime.enable");
  return { call, evaluate, go, until };
}

let manager = null, songs = null, songId = 0, seeded = false;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const first = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  const A = await tab(first.webSocketDebuggerUrl);
  manager = helper("manager");
  songs = helper("lib-songs", 2);
  songId = songs.ids[0];
  helper("lib-set", songId, "Reckless Love", "Cory Asbury", 333.4); // the song MusicBrainz's saved answers are about
  helper("mb-seed");
  seeded = true;
  await A.call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  await A.go(`${base}/signin`);
  await A.evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(manager.email)}; f.password.value = ${JSON.stringify(manager.password)}; f.submit(); })()`);
  await sleep(1500);

  console.log("Opening the window");
  await A.go(`${base}/manage/library`);
  await A.evaluate(`window.SwLibrary.openEditor(${songId}); document.querySelector("#t${songId} + tr [data-role=rights]").click(); 1`);
  check("a song's details window has a Look up box and the album field", await A.until(`document.getElementById("rightsDlg").open`) && await A.evaluate(`!document.getElementById("lkBox").hidden && !document.getElementById("lkAlbumWrap").hidden
    && document.getElementById("lkRun").textContent.includes("MusicBrainz") && /only this song’s title, artist, ISRC and length/.test(document.getElementById("lkBox").textContent)`));
  check("  it says what is sent, and that a licence still has to be kept", await A.evaluate(`/says who is registered, not what you may play/.test(document.getElementById("lkBox").textContent)`));
  check("  nothing is shown before a look-up", await A.evaluate(`document.getElementById("lkResult").hidden && document.getElementById("lkCands").hidden`));

  console.log("Looking it up");
  await A.evaluate(`document.getElementById("lkRun").click(); 1`);
  check("the suggestions arrive as a table, the best match chosen", await A.until(`!document.getElementById("lkResult").hidden && document.querySelectorAll("#lkRows tr").length > 5`));
  const cands = await A.evaluate(`[...document.querySelectorAll("#lkCands li")].map((li) => li.textContent)`);
  check("  the other possible matches are listed, the first “Likely” with its length agreeing", cands.length === 4 && /Reckless Love — Cory Asbury/.test(cands[0]) && /Likely/.test(cands[0]) && /length agrees/.test(cands[0]) && /Check/.test(cands[1]) && /length differs by 93 s/.test(cands[1]), JSON.stringify(cands));
  const rows = await A.evaluate(`Object.fromEntries([...document.querySelectorAll("#lkRows tr")].map((tr) => [tr.children[1].textContent, { found: tr.children[3].textContent, ticked: tr.querySelector("input").checked, disabled: tr.querySelector("input").disabled }]))`);
  check("  album, year, label, ISRC, writers, publishers and ISWC are found, and ticked because they are empty now",
    rows["Album"].found === "Reckless Love" && rows["Year"].found === "2017" && rows["Record label"].found === "Bethel Music" && rows["ISRC"].found === "USWFE1790445"
    && rows["Songwriter(s)"].found === "Cory Asbury; Caleb Culver; Ran Jackson" && /Bethel Music Publishing/.test(rows["Publisher(s)"].found) && rows["ISWC"].found === "T-921.709.275-6"
    && ["Album", "Year", "Record label", "ISRC", "Songwriter(s)", "Publisher(s)", "ISWC"].every((k) => rows[k].ticked), JSON.stringify(rows));
  check("  what MusicBrainz doesn't have (lyricists) is said, and can't be ticked", /not in MusicBrainz/.test(rows["Lyricist(s)"].found) && rows["Lyricist(s)"].disabled && !rows["Lyricist(s)"].ticked);
  check("  the writers come from a work that isn't linked to this recording, and the window says to check them", await A.evaluate(`!document.getElementById("lkWarn").hidden && /doesn’t link to this recording/.test(document.getElementById("lkWarn").textContent)`));
  check("  the album it is on can be chosen, and the cover is offered", await A.evaluate(`!document.getElementById("lkRelWrap").hidden && document.querySelectorAll("#lkRel option").length >= 2 && !document.getElementById("lkCover").hidden && !document.getElementById("lkCoverShow").hidden`));
  check("  nothing has been changed yet", await A.evaluate(`document.getElementById("rightsForm").label.value === "" && document.getElementById("lkAlbumIn").value === ""`));

  console.log("Keeping what is ticked");
  await A.evaluate(`[...document.querySelectorAll("#lkRows tr")].find((tr) => tr.children[1].textContent === "Year").querySelector("input").click(); document.getElementById("lkApply").click(); 1`);
  const filled = await A.evaluate(`(() => { const f = document.getElementById("rightsForm"); return { label: f.label.value, year: f.year.value, isrc: f.isrc.value, writers: f.writers.value, publisher: f.publisher.value, iswc: f.iswc.value, track: f.track.value,
    album: document.getElementById("lkAlbumIn").value, notes: document.getElementById("rtNotes").textContent }; })()`);
  check("Fill puts the ticked fields in the window, and leaves the unticked one (the year) alone", filled.label === "Bethel Music" && filled.year === "" && filled.isrc === "USWFE1790445" && filled.writers === "Cory Asbury; Caleb Culver; Ran Jackson"
    && /Bethel Music Publishing/.test(filled.publisher) && filled.iswc === "T-921.709.275-6" && filled.album === "Reckless Love", JSON.stringify(filled));
  check("  with a link to MusicBrainz in the notes, and it says to save", /Details from MusicBrainz: https:\/\/musicbrainz\.org\/recording\/0df9f5b3-a8c0-4be3-8cbb-6aae9a84c322/.test(filled.notes) && /Save the window to keep them/.test(await A.evaluate(`document.getElementById("lkMsg").textContent`)));
  await A.evaluate(`document.getElementById("rightsSave").click(); 1`);
  check("Save keeps them with the song: the rights details and the album", await A.until(`!document.getElementById("rightsDlg").open`));
  const info = helper("lib-info", songId);
  check("  on the server: label, ISRC, writers, publishers, ISWC, the link in the notes, and the album", info.album === "Reckless Love" && info.rights && info.rights.label === "Bethel Music" && info.rights.isrc === "US-WFE-17-90445" && info.rights.writers === "Cory Asbury; Caleb Culver; Ran Jackson"
    && /Bethel Music Publishing/.test(info.rights.publisher) && info.rights.iswc === "T-921.709.275-6" && /musicbrainz\.org\/recording/.test(info.rights.notes) && !info.rights.year, JSON.stringify(info));
  check("  and the row in the list shows the album", await A.evaluate(`document.querySelector("#t${songId} .alb").textContent === "Reckless Love"`));

  console.log("Looking it up again");
  await A.go(`${base}/manage/library`);
  await A.evaluate(`window.SwLibrary.openEditor(${songId}); document.querySelector("#t${songId} + tr [data-role=rights]").click(); 1`);
  await A.until(`document.getElementById("rightsDlg").open`);
  await A.evaluate(`document.getElementById("lkRun").click(); 1`);
  await A.until(`!document.getElementById("lkResult").hidden && document.querySelectorAll("#lkRows tr").length > 5`);
  const again = await A.evaluate(`Object.fromEntries([...document.querySelectorAll("#lkRows tr")].map((tr) => [tr.children[1].textContent, { found: tr.children[3].textContent, ticked: tr.querySelector("input").checked, disabled: tr.querySelector("input").disabled }]))`);
  check("what is the same is marked and left alone; the year, still empty, is ticked", /\(same\)/.test(again["Record label"].found) && again["Record label"].disabled && !again["Record label"].ticked && /\(same\)/.test(again["Album"].found) && again["Year"].ticked && !again["Year"].disabled, JSON.stringify(again["Year"]));
  check("  this time the song has no album art, so the cover could be used (and one with art would be left alone)", await A.evaluate(`!document.getElementById("lkCover").hidden`));

  console.log("On a phone");
  await A.call("Emulation.setDeviceMetricsOverride", { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  await A.go(`${base}/manage/library`);
  await A.evaluate(`window.SwLibrary.openEditor(${songId}); document.querySelector("#t${songId} + tr [data-role=rights]").click(); 1`);
  await A.until(`document.getElementById("rightsDlg").open`);
  await A.evaluate(`document.getElementById("lkRun").click(); 1`);
  await A.until(`!document.getElementById("lkResult").hidden && document.querySelectorAll("#lkRows tr").length > 5`);
  check("the window with the suggestions is as wide as the screen, no wider", await A.evaluate(`document.documentElement.scrollWidth <= innerWidth + 1 && document.getElementById("rightsDlg").getBoundingClientRect().width <= innerWidth && document.getElementById("rightsDlg").scrollWidth <= document.getElementById("rightsDlg").clientWidth + 1`));
  await A.call("Emulation.clearDeviceMetricsOverride");
} catch (e) {
  failed++;
  console.log("  [FAIL] " + (e.stack || e.message));
} finally {
  try { if (songId) helper("lib-set", songId, "Web Test Lib restored", "Web Tester"); } catch { /* gone */ }
  try { if (seeded) helper("mb-unseed"); } catch { /* nothing seeded */ }
  try { helper("lib-clean"); } catch { /* nothing left */ }
  try { if (manager) helper("cleanup", String(manager.id)); } catch { /* already gone */ }
  try { proc.kill(); } catch { /* gone */ }
  try { rmSync(profile, { recursive: true, force: true }); } catch { /* still locked */ }
  console.log(failed ? `${failed} check(s) failed, ${passed} passed` : `All checks passed (${passed})`);
}
process.exit(failed ? 1 : 0);
