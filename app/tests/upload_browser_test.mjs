// End-to-end test of song prep in a real browser (headless Edge or Chrome) on Laragon: a
// throwaway manager uploads generated MP3s on the Music library page, and the stored songs are
// checked (mastered, levelled, trimmed, tags kept, duplicates caught, fallbacks for silent and fake
// files), and the two switches (pre-processing, loudness normalization) are saved and obeyed.
// Round 54: songs in WAV (16-bit, 24-bit 96 kHz), FLAC, M4A (AAC, ALAC), Ogg Vorbis, Opus and WMA
// are converted in the browser, each with its tags, cover and songwriter, and a 320 kbps MP3 original
// kept (samples from make_format_samples.mjs; skipped with a note when the lossless music isn't here).
// Round 84: songs turned off and on (the Status column, the bulk bar, the On/Off filter, Remove refused on air
// offering Turn off, the "Nothing to play" banner) and the upload guard (the progress line, the browser asking
// before leaving, the unfinished songs listed after a refresh, songs already added skipped when picked again).
// Round 85 (v1.13): song prep on the server: an admin's Set up in a second browser, a bulk upload whose page is closed while
// the songs are prepared (watched from the second browser until added), a transfer resumed after a refresh, the details
// window, a window closed with its page, an ad, and the same song from the server and from the browser compared.
//   node app/tests/upload_browser_test.mjs http://localhost:8080/stationwagon-app [--only=bulk | --only=server]
// (--only=bulk runs the Music library's own bulk parts: round 83's tick box and round 84's; --only=server round 85's)
// Needs SW_PHP (php.exe) unless php is on PATH. Signs in a throwaway account: not for a public server.
import { spawn, spawnSync, execFileSync } from "node:child_process";
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import vm from "node:vm";
import { createRequire } from "node:module";
import { makeSamples, SAMPLES, TAGS } from "./make_format_samples.mjs";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("Usage: node app/tests/upload_browser_test.mjs <base url>"); process.exit(1); }
const here = dirname(fileURLToPath(import.meta.url));
const php = process.env.SW_PHP || "php";
const helper = (...args) => JSON.parse(execFileSync(php, [join(here, "browser_helper.php"), ...args], { encoding: "utf8" }));
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const near = (a, b, tol) => a !== null && a !== undefined && Math.abs(Number(a) - b) <= tol;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---- Test files ----------------------------------------------------------------------------
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const core = createRequire(import.meta.url)("../assets/prep-core.js");
const media = join(here, "..", "storage", "media");
function mp3(parts, title, kbps = 128, RATE = 44100) {
  const n = parts.reduce((s, [, sec]) => s + Math.round(sec * RATE), 0);
  const l = new Int16Array(n), r = new Int16Array(n);
  let i = 0;
  for (const [amp, sec] of parts) for (let k = 0, m = Math.round(sec * RATE); k < m; k++, i++) {
    l[i] = Math.round(amp * 32767 * Math.sin(2 * Math.PI * 440 * i / RATE)); r[i] = l[i];
  }
  const enc = new sandbox.lamejs.Mp3Encoder(2, RATE, kbps), out = [];
  for (let s = 0; s < n; s += 1152) { const b = enc.encodeBuffer(l.subarray(s, s + 1152), r.subarray(s, s + 1152)); if (b.length) out.push(Buffer.from(b)); }
  out.push(Buffer.from(enc.flush()));
  const text = Buffer.from("\u0003" + title, "utf8");
  const frame = Buffer.concat([Buffer.from("TIT2"), Buffer.from([0, 0, 0, text.length]), Buffer.from([0, 0]), text]);
  const head = Buffer.concat([Buffer.from("ID3"), Buffer.from([4, 0, 0, 0, 0, 0, frame.length]), frame]);
  return Buffer.concat([head, ...out]);
}
const tag = Math.random().toString(16).slice(2, 8);
const dir = mkdtempSync(join(tmpdir(), "sw-prep-browser-"));
const songTitle = `Prep Browser Test ${tag}`;
const silentTitle = `Prep Silent Test ${tag}`;
const plainTitle = `Prep Plain Test ${tag}`;
const oddTitle = `Prep 32 kHz Test ${tag}`;
const song = join(dir, "prep song.mp3"), silent = join(dir, "silent.mp3"), fake = join(dir, "fake.mp3"), plain = join(dir, "plain.mp3");
writeFileSync(song, mp3([[0, 2], [0.4, 20], [0, 1.5]], songTitle));   // about -8 LUFS, silence both ends
writeFileSync(silent, mp3([[0, 6]], silentTitle));
writeFileSync(plain, mp3([[0, 1], [0.1, 15], [0, 1]], plainTitle));   // about -21 LUFS
const odd = join(dir, "odd rate.mp3");
writeFileSync(odd, mp3([[0.3, 12]], oddTitle, 128, 32000));   // a 32 kHz song: goes to the station's 44.1 kHz (the default), and the uploader is told
writeFileSync(fake, Buffer.from("not audio at all ".repeat(400)));
const songSha1 = createHash("sha1").update(readFileSync(song)).digest("hex");
const badWav = join(dir, "not audio.wav"), badWma = join(dir, "broken.wma");
writeFileSync(badWav, Buffer.from("not audio at all ".repeat(400)));
// a WMA header's first bytes, then nothing that decodes
writeFileSync(badWma, Buffer.concat([Buffer.from([0x30, 0x26, 0xb2, 0x75, 0x8e, 0x66, 0xcf, 0x11, 0xa6, 0xd9, 0x00, 0xaa, 0x00, 0x62, 0xce, 0x6c]), Buffer.alloc(4000, 7)]));
let formats = null;
try { formats = makeSamples(); } catch (ex) { formats = null; console.log(`(the format samples can't be made: ${ex.message})`); }
/** An MP3's bit rate and sample rate from its first frame (after any ID3 tag). */
function frameInfo(bytes) {
  let i = 0;
  if (bytes[0] === 0x49 && bytes[1] === 0x44 && bytes[2] === 0x33) i = 10 + (((bytes[6] & 0x7f) << 21) | ((bytes[7] & 0x7f) << 14) | ((bytes[8] & 0x7f) << 7) | (bytes[9] & 0x7f));
  for (; i < bytes.length - 4; i++) {
    if (bytes[i] !== 0xff || (bytes[i + 1] & 0xe0) !== 0xe0 || ((bytes[i + 1] >> 3) & 3) !== 3 || ((bytes[i + 1] >> 1) & 3) !== 1) continue;
    const kbps = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320][bytes[i + 2] >> 4];
    return { kbps, rate: [44100, 48000, 32000][(bytes[i + 2] >> 2) & 3] };
  }
  return null;
}

// ---- Browser -------------------------------------------------------------------------------
const exe = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Google/Chrome/Application/chrome.exe"]
  .find((b) => { try { readFileSync(b, { flag: "r" }); return true; } catch { return false; } });
const profile = mkdtempSync(join(tmpdir(), "sw-prep-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions", "about:blank"], { stdio: "ignore" });
let ws, id = 0;
const pending = new Map();
const call = (method, params = {}) => new Promise((ok) => { const i = ++id; pending.set(i, ok); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expression) => {
  const r = await call("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
  return r.result?.result?.value;
};
// a real click (the page counts it as the person using it: the browser asks before leaving only then)
async function realClick(sel) {
  const r = await evaluate(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.scrollIntoView({ block: "center" }); const b = e.getBoundingClientRect(); return [b.x + b.width / 2, b.y + b.height / 2]; })()`);
  for (const type of ["mousePressed", "mouseReleased"]) await call("Input.dispatchMouseEvent", { type, x: r[0], y: r[1], button: "left", clickCount: 1 });
}
const dialogs = []; // the browser's own questions (round 84: "Leave site?"), answered Leave
const loaded = async () => { for (let i = 0; i < 100; i++) { if ((await evaluate("document.readyState").catch(() => "")) === "complete") return; await sleep(100); } };
const go = async (url) => { await call("Page.navigate", { url }); await sleep(300); await loaded(); };

/** Choose files in the drop zone and wait for every row's final status (before the page reloads). */
async function upload(files) {
  await go(`${base}/manage/library`);
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await evaluate("window.swUploadNoWindow = true"); // the upload window (round 56) has its own test
  await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files });
  for (let i = 0; i < 600; i++) {
    const rows = await evaluate(`[...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, text: li.querySelector('.s').textContent, warn: li.querySelector('.w') && !li.querySelector('.w').hidden ? li.querySelector('.w').textContent : '' }))`).catch(() => null);
    if (rows && rows.length === files.length && rows.every((r) => (r.cls === "ok" || r.cls === "bad") && !r.text.includes("keeping the original"))) {
      await sleep(1500); await loaded(); // the library reloads itself 0.9 s after an upload
      return rows;
    }
    await sleep(100);
  }
  return null;
}

// ---- The upload window (round 56, goal 10) ------------------------------------------------------------
// Five songs picked at once, a window each in turn: an MP3 whose own tag (with a cover) fills the window
// at once, changed and added with its licence; a FLAC whose tags come once it's decoded, sent with a wrong
// ISRC (the server refuses it and says why); a WAV skipped with Esc; then Cancel the rest, which leaves the
// last one out too. Then the song's details in the library window (edit mode) and for listeners.
const winTitle = `Window Test ${tag}`, winEdited = `Window Edited ${tag}`;
function taggedMp3(tags, picture, secs = 12) {
  const a = mp3([[0, 0.5], [0.3, secs], [0, 0.5]], "x");
  const size = 10 + (((a[6] & 0x7f) << 21) | ((a[7] & 0x7f) << 14) | ((a[8] & 0x7f) << 7) | (a[9] & 0x7f));
  return Buffer.concat([Buffer.from(core.id3(tags, picture)), a.subarray(size)]);
}
async function windowTest() {
  const cover = formats && existsSync(join(formats, "cover.jpg")) ? new Uint8Array(readFileSync(join(formats, "cover.jpg"))) : null;
  const tagged = join(dir, "window song.mp3"), skip1 = join(dir, "skip me.mp3"), skip2 = join(dir, "never asked.mp3");
  writeFileSync(tagged, taggedMp3({ title: winTitle, artist: "Tag Artist", album: "Tag Album", composer: "Tag Writer", publisher: "Tag Publisher",
    copyright: "© 2023 Tag Owner", isrc: "ZA-ABC-23-00042", track: "4/10" }, cover ? { mime: "image/jpeg", data: cover } : null));
  writeFileSync(skip1, mp3([[0.2, 5]], `Window Skip ${tag}`));
  writeFileSync(skip2, mp3([[0.2, 5]], `Window Never ${tag}`));
  const flac = formats ? join(formats, "flac24.flac") : null, wav = formats ? join(formats, "wav16.wav") : null;
  if (!flac) { check("the format samples are here for the window test", false); return; }
  helper("cleanup-like", TAGS.title);
  await go(`${base}/manage/library`);
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files: [tagged, flac, wav, skip1, skip2] });
  const FORM = `document.getElementById('rightsForm').elements`;
  const state = () => evaluate(`(() => { const f = ${FORM}, d = document.getElementById('rightsDlg');
    return { open: d.open, song: document.getElementById('rightsSong').textContent, heading: document.getElementById('rightsTitle').textContent,
      title: f.title.value, artist: f.artist.value, album: f.album.value, writers: f.writers.value, publisher: f.publisher.value, owner: f.owner.value,
      year: f.year.value, isrc: f.isrc.value, track: f.track.value, licence: f.licence.value, art: !!document.querySelector('#sdPic img'),
      warn: document.getElementById('sdWarn').hidden ? '' : document.getElementById('sdWarn').textContent, prep: document.getElementById('sdPrep').textContent,
      stop: !document.getElementById('sdStop').hidden, top: !document.getElementById('sdTop').hidden, rows: [...document.querySelectorAll('#uplist li')].map((li) => li.className + ': ' + li.querySelector('.s').textContent) }; })()`);
  const waitFor = async (fn, sec = 30) => { for (let i = 0; i < sec * 10; i++) { const s = await state().catch(() => null); if (s && fn(s)) return s; await sleep(100); } return state().catch(() => null); };
  const type = (name, value) => evaluate(`(() => { const el = ${FORM}[${JSON.stringify(name)}]; el.value = ${JSON.stringify(value)}; el.dispatchEvent(new Event('input', { bubbles: true })); return 1; })()`);
  const click = (id) => evaluate(`document.getElementById(${JSON.stringify(id)}).click()`);

  // 1: the MP3, filled from its tag at once
  let s = await waitFor((x) => x.open && x.song.includes("window song.mp3"), 10);
  check("picking five songs opens the window for the first, before anything is uploaded", s && s.open && s.top && s.heading === "Before it’s added" && s.song.includes("song 1 of 5")
    && s.rows.every((r) => !r.startsWith("ok")), s && JSON.stringify({ song: s.song, rows: s.rows }));
  check("  filled from the MP3's own tag: title, artist, album, songwriter, publisher, © owner and year, ISRC, track", s && s.title === winTitle && s.artist === "Tag Artist"
    && s.album === "Tag Album" && s.writers === "Tag Writer" && s.publisher === "Tag Publisher" && s.owner === "Tag Owner" && s.year === "2023" && s.isrc === "ZA-ABC-23-00042" && s.track === "4/10",
    s && JSON.stringify(s));
  check("  with its cover as the album art", s && s.art === !!cover);
  check("  the missing licence type is pointed out (the songwriter and publisher are there)", s && /^No licence type yet/.test(s.warn) && !s.warn.includes("songwriter"), s && s.warn);
  s = await waitFor((x) => /ready to add/.test(x.prep), 60);
  check("  it is prepared in the background meanwhile", s && /Prepared: ready to add/.test(s.prep), s && s.prep);
  await type("title", winEdited); await type("artist", "Window Artist");
  await type("licence", "CCLI Streaming Licence"); await type("licence_ref", "CCLI 7123456"); await type("licence_url", "https://example.test/licence");
  await type("licence_until", "2027-12-31"); await type("terms", "Clause one.\nClause two."); await type("label", "Grace Records"); await type("lyricists", "Window Lyricist");
  s = await state();
  check("  the warning goes once the licence type is filled in", s && s.warn === "", s && s.warn);
  await click("sdAdd");

  // 2: the FLAC, its tags once decoded; a wrong ISRC typed in
  s = await waitFor((x) => x.open && x.song.includes("flac24.flac"), 30);
  check("then the window for the second song", s && s.open && s.song.includes("song 2 of 5"), s && s.song);
  s = await waitFor((x) => /ready to add/.test(x.prep) && x.title === TAGS.title, 90);
  check("  a FLAC's tags fill it once the browser has decoded it: title, artist, album, songwriter, cover", s && s.title === TAGS.title && s.artist === TAGS.artist
    && s.album === TAGS.album && s.writers === TAGS.composer && s.art, s && JSON.stringify({ t: s.title, a: s.artist, w: s.writers, art: s.art, prep: s.prep }));
  check("  a missing licence type and publisher are pointed out, and Add still works", s && /licence type/.test(s.warn) && /publisher/.test(s.warn) && /can still add/.test(s.warn), s && s.warn);
  await type("isrc", "not an isrc");
  await click("sdAdd");

  // 3: skipped with Esc; 4: Cancel the rest (5 is never asked about)
  s = await waitFor((x) => x.open && x.song.includes("wav16.wav"), 60);
  check("Esc on the third song's window skips it", !!s && s.open);
  await call("Input.dispatchKeyEvent", { type: "keyDown", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  await call("Input.dispatchKeyEvent", { type: "keyUp", key: "Escape", code: "Escape", windowsVirtualKeyCode: 27 });
  s = await waitFor((x) => x.open && x.song.includes("skip me.mp3"), 30);
  check("  and the fourth asks next, with Cancel the rest", s && s.stop && s.song.includes("song 4 of 5"), s && s.song);
  await click("sdStop");
  let rows = null;
  for (let i = 0; i < 300; i++) {
    rows = await evaluate(`[...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, text: li.querySelector('.s').textContent }))`).catch(() => null);
    if (rows && rows.length === 5 && rows.every((r) => (r.cls === "ok" || r.cls === "bad") && !r.text.includes("keeping the original"))) break;
    await sleep(200);
  }
  check("the first is added with what the window said", rows && rows[0].cls === "ok" && rows[0].text.startsWith("Added,"), rows && rows[0].text);
  check("the second is refused by the station for its ISRC, saying so", rows && rows[1].cls === "bad" && rows[1].text.includes("ISRC"), rows && rows[1].text);
  check("the third and fourth are skipped, the fifth cancelled; none sent", rows && rows[2].text === "Not added: skipped" && rows[3].text === "Not added: skipped" && rows[4].text === "Not added: cancelled",
    rows && rows.slice(2).map((r) => r.text).join(" | "));
  await sleep(1500);
  const t = helper("track", winEdited), r = t && JSON.parse(t.rights || "{}");
  check("  stored with the window's title and artist (over its tag), the tag's album, and its cover", t && t.artist === "Window Artist" && t.album === "Tag Album" && (!!t.art) === !!cover, t && `${t.title} / ${t.artist} / ${t.album} / ${t.art}`);
  check("  and the licensing: type, number, link, until, terms, label, lyricist, with the tag's songwriter, publisher and ISRC", r && r.licence === "CCLI Streaming Licence"
    && r.licence_ref === "CCLI 7123456" && r.licence_url === "https://example.test/licence" && r.licence_until === "2027-12-31" && r.terms === "Clause one.\nClause two."
    && r.label === "Grace Records" && r.lyricists === "Window Lyricist" && r.writers === "Tag Writer" && r.publisher === "Tag Publisher" && r.isrc === "ZA-ABC-23-00042", JSON.stringify(r));
  check("  nothing else was kept", !helper("track", TAGS.title) && !helper("track", `Window Skip ${tag}`) && !helper("track", `Window Never ${tag}`));

  // the same window for a song in the library (edit mode): the new fields, a wrong link refused
  await go(`${base}/manage/library?q=${encodeURIComponent(winEdited)}`);
  await evaluate(`document.querySelector('#libBody .pill.rights').click()`);
  s = await waitFor((x) => x.open, 5);
  const ed = await evaluate(`(() => { const f = ${FORM}; return { ref: f.licence_ref.value, until: f.licence_until.value, terms: f.terms.value, label: f.label.value }; })()`);
  check("the library's © window shows the new fields, without the upload part", s && s.open && !s.top && s.heading === "Copyright and licence" && ed.ref === "CCLI 7123456"
    && ed.until === "2027-12-31" && ed.terms === "Clause one.\nClause two." && ed.label === "Grace Records", JSON.stringify(ed));
  await type("licence_url", "ftp://nope.test/x");
  await click("rightsSave");
  await sleep(800);
  const msg = await evaluate(`document.getElementById('rightsMsg').textContent`);
  check("  a link that isn't a web address is refused there, saying why", /must be a web address/.test(msg), msg);
  await type("licence_url", "https://example.test/licence2"); await type("pline", "Window Records");
  await click("rightsSave");
  await sleep(1000);
  const r2 = JSON.parse(helper("track", winEdited).rights || "{}");
  check("  a good one is saved, with the ℗ owner", r2.licence_url === "https://example.test/licence2" && r2.pline === "Window Records", JSON.stringify(r2));

  // listeners see it all (the user's choice): the details window's rows, the terms as text
  await go(`${base}/`);
  const shown = await evaluate(`(() => { const box = document.createElement('div'); box.appendChild(SwRights.details(${JSON.stringify(r2)}));
    return { dts: [...box.querySelectorAll('dt')].map((d) => d.textContent), link: (box.querySelector('dd a') || {}).href || '', terms: (box.querySelector('.rights-terms') || {}).textContent || '' }; })()`);
  check("listeners' details show the recording, the composition and the licence, with its link and terms", ["Copyright", "Sound recording", "Record label", "Songwriter(s)", "Lyricist(s)", "Publishing house",
    "Licence", "Licence number", "Licence link", "Valid until", "ISRC", "Track"].every((k) => shown.dts.includes(k)) && shown.link === "https://example.test/licence2" && shown.terms === "Clause one.\nClause two.",
    JSON.stringify(shown));
}

// ---- Bulk upload, details later (round 83) ----------------------------------------------------------
// The Music library's tick box "Do bulk upload now and enter details later": two tagged songs picked with it
// ticked go up one after another with no details window, prepared as usual, with their files' own details.
// The Ads screen (same drop zone, no window) doesn't show it. `--only=bulk` runs just this part.
const only = (process.argv.find((a) => a.startsWith("--only=")) || "").slice(7);
const bulkTitles = [`Bulk Later A ${tag}`, `Bulk Later B ${tag}`];
async function bulkTest() {
  const files = bulkTitles.map((t, i) => {
    const f = join(dir, `bulk ${i + 1}.mp3`);
    writeFileSync(f, taggedMp3({ title: t, artist: "Bulk Artist", album: "Bulk Album" }, null));
    return f;
  });
  await go(`${base}/manage/library`);
  check("the Music library shows the tick box, not ticked", await evaluate(`!document.getElementById('bulkUpWrap').hidden && !document.getElementById('bulkUp').checked
    && /Do bulk upload now and enter details later/.test(document.getElementById('bulkUpWrap').textContent)`));
  await evaluate(`document.getElementById('bulkUp').click()`);
  // a flag that the details window ever opened (it sets the dialog's open attribute)
  await evaluate(`(() => { window.__winOpened = false; const d = document.getElementById('rightsDlg');
    new MutationObserver(() => { if (d.open) window.__winOpened = true; }).observe(d, { attributes: true }); return 1; })()`);
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files });
  let rows = null, opened = null;
  for (let i = 0; i < 600; i++) {
    const s = await evaluate(`({ opened: window.__winOpened, rows: [...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, text: li.querySelector('.s').textContent })) })`).catch(() => null);
    if (s && s.rows.length === files.length && s.rows.every((r) => (r.cls === "ok" || r.cls === "bad") && !r.text.includes("keeping the original"))) { rows = s.rows; opened = s.opened; break; }
    if (s && s.opened) { opened = true; break; }
    await sleep(100);
  }
  check("with it ticked, two songs go up without the details window", opened === false && !!rows, JSON.stringify({ opened, rows }));
  check("  each one prepared as usual (mastered, levelled) and added", rows && rows.every((r) => r.cls === "ok" && r.text.startsWith("Added, mastered, levelled")), rows && rows.map((r) => r.text).join(" | "));
  await sleep(1500); await loaded(); // the library reloads itself after an upload
  const t = bulkTitles.map((x) => helper("track", x));
  check("  stored with the title, artist and album from each file", t.every((x, i) => x && x.title === bulkTitles[i] && x.artist === "Bulk Artist" && x.album === "Bulk Album"),
    t.map((x) => x && `${x.title} / ${x.artist} / ${x.album}`).join(" | "));
  check("  and marked as mastered",t.every((x) => x && Number(x.mastered) === 1));
  check("after the page reloads the box is unticked again (the window is back for the next upload)", await evaluate(`!!document.getElementById('bulkUp') && !document.getElementById('bulkUp').checked`));
  await go(`${base}/manage/ads`);
  check("the Ads screen (no details window there) has no such box", await evaluate(`!!document.getElementById('drop') && !document.getElementById('bulkUp')`));
}

// ---- Songs on and off (round 84) ---------------------------------------------------------------------
// The two songs bulkTest added: turned off and on again with the bulk bar (in place), the On/Off filter, the
// database; the song on air: Remove refused, Turn off offered in its row; every song off: the "Nothing to play"
// banner on the library and the overview. Which songs were on is put back afterwards whatever happens.
async function stateTest() {
  const before = helper("songs-on").on;
  try {
    await go(`${base}/manage/library`);
    const [a, b] = bulkTitles.map((x) => helper("track", x));
    const rowState = (tid) => evaluate(`(() => { const tr = document.getElementById('t${tid}'); return tr ? { state: tr.dataset.state, pill: tr.querySelector('.c-status .pill').textContent, hidden: tr.hidden } : null; })()`);
    const ra = await rowState(a.id);
    check("a new song shows Status On", ra && ra.state === "on" && ra.pill === "On" && Number(a.active) === 1, JSON.stringify(ra));
    check("  the Status column and the On/Off filter are there, the banner isn't", await evaluate(`!!document.querySelector('th[data-col="status"] .sortb') && !document.getElementById('stateFilter').hidden && document.getElementById('noPlay').hidden`));
    await evaluate(`window.__stay = 1; ['t${a.id}', 't${b.id}'].forEach((x) => { const c = document.querySelector('#' + x + ' .sel'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); }); document.getElementById('bulkOff').click(); 1`);
    let note = "";
    for (let i = 0; i < 100 && !/turned off/.test(note); i++) { await sleep(100); note = await evaluate(`document.getElementById('libNote').textContent`); }
    const offA = await rowState(a.id), offB = await rowState(b.id);
    check("Turn off on two ticked songs: both show Off at once", offA.pill === "Off" && offB.pill === "Off" && offA.state === "off" && /2 songs turned off/.test(note), note);
    check("  saved: the AutoDJ and the studio leave them out", Number(helper("track", bulkTitles[0]).active) === 0 && Number(helper("track", bulkTitles[1]).active) === 0);
    check("  in place: the page didn't reload", await evaluate(`window.__stay === 1`));
    await evaluate(`(() => { const f = document.getElementById('stateFilter'); f.value = 'off'; f.dispatchEvent(new Event('change')); return 1; })()`);
    const shownOff = await evaluate(`[...document.querySelectorAll("#libBody tr[id^='t']")].filter((tr) => !tr.hidden).map((tr) => tr.dataset.state)`);
    check("the filter Off shows only the songs that are off", shownOff.length >= 2 && shownOff.every((x) => x === "off"), JSON.stringify(shownOff.slice(0, 5)));
    await evaluate(`(() => { const f = document.getElementById('stateFilter'); f.value = 'on'; f.dispatchEvent(new Event('change')); return 1; })()`);
    check("  On leaves them out", (await rowState(a.id)).hidden && (await rowState(b.id)).hidden);
    await evaluate(`(() => { const f = document.getElementById('stateFilter'); f.value = ''; f.dispatchEvent(new Event('change')); return 1; })()`);
    await evaluate(`document.getElementById('bulkOn').click(); 1`);
    for (let i = 0; i < 100 && !/turned on/.test(note); i++) { await sleep(100); note = await evaluate(`document.getElementById('libNote').textContent`); }
    check("Turn on puts them back", (await rowState(a.id)).pill === "On" && Number(helper("track", bulkTitles[0]).active) === 1 && Number(helper("track", bulkTitles[1]).active) === 1, note);
    await go(`${base}/manage/library`);
    check("  and after a reload the Status column says On", (await rowState(a.id)).pill === "On");

    // the song on air: Remove is refused and Turn off is offered right there
    let air = null;
    for (let i = 0; i < 60 && !air; i++) { air = helper("on-air"); if (!air) await sleep(1000); }
    check("a song is on air on this station", !!air);
    if (air) {
      await evaluate(`document.querySelector('#t${air.id} a[data-act="remove"]').click(); 1`);
      await evaluate(`[...document.querySelectorAll('#t${air.id} td.acts button')].find((x) => x.textContent === 'Yes, remove').click(); 1`);
      let cell = null;
      for (let i = 0; i < 50; i++) { await sleep(100); cell = await evaluate(`(() => { const c = document.querySelector('#t${air.id} td.acts'); return { text: c.querySelector('.warn2').textContent, buttons: [...c.querySelectorAll('button')].map((x) => x.textContent) }; })()`); if (/on air/.test(cell.text)) break; }
      check("Remove on the song on air is refused, saying to turn it off", /is on air \(or just was\)\. Turn it off/.test(cell.text), cell.text);
      check("  with a Turn off button in its row", cell.buttons.includes("Turn off"), JSON.stringify(cell.buttons));
      await evaluate(`[...document.querySelectorAll('#t${air.id} td.acts button')].find((x) => x.textContent === 'Turn off').click(); 1`);
      for (let i = 0; i < 50; i++) { await sleep(100); cell = await evaluate(`(() => { const c = document.querySelector('#t${air.id} td.acts'); return { text: c.querySelector('.warn2').textContent, buttons: [...c.querySelectorAll('button')].map((x) => x.textContent) }; })()`); if (/Turned off/.test(cell.text)) break; }
      check("  Turn off: said, the song shows Off, saved, and it is still in the list", /^Turned off: the AutoDJ won’t play it again/.test(cell.text) && (await rowState(air.id)).pill === "Off"
        && !helper("songs-on").on.includes(air.id) && !!(await rowState(air.id)), cell.text);
      helper("songs-restore", before.join(","));
    }

    // every song off: the station is quiet and both screens say so
    await go(`${base}/manage/library`);
    await evaluate(`(() => { const s = document.getElementById('selAll'); if (!s.checked) s.click(); document.getElementById('bulkOff').click(); return 1; })()`);
    for (let i = 0; i < 150; i++) { await sleep(100); if (await evaluate(`!document.getElementById('noPlay').hidden`)) break; }
    check("every song turned off: the “Nothing to play” banner shows at once", await evaluate(`!document.getElementById('noPlay').hidden && /Nothing to play/.test(document.getElementById('noPlay').textContent)`));
    check("  none is on in the database", helper("songs-on").on.length === 0, JSON.stringify(helper("songs-on").on.slice(0, 5)));
    await go(`${base}/manage`);
    check("  the Overview warns too", await evaluate(`(() => { const n = document.querySelector('.noplay'); return !!n && /Nothing to play/.test(n.textContent) && /Music library/.test(n.textContent); })()`));
    await go(`${base}/manage/library`);
    check("  the library shows the banner when it opens", await evaluate(`!document.getElementById('noPlay').hidden`));
    await evaluate(`(() => { const s = document.getElementById('selAll'); if (!s.checked) s.click(); document.getElementById('bulkOn').click(); return 1; })()`);
    for (let i = 0; i < 150; i++) { await sleep(100); if (await evaluate(`document.getElementById('noPlay').hidden`)) break; }
    check("  Turn on for all: the banner goes", await evaluate(`document.getElementById('noPlay').hidden`) && helper("songs-on").on.length > 0);
    await go(`${base}/manage`);
    check("  and the Overview's warning too", await evaluate(`!document.querySelector('.noplay')`));
  } finally {
    helper("songs-restore", before.join(","));
  }
}

// ---- The upload guard (round 84) -------------------------------------------------------------------
// Three songs picked with "details later", the upload slowed down: while they go, a line says how far it has
// got; the names not finished are kept in this browser; a refresh makes the browser ask first; after it the
// page lists the unfinished ones; picked again, the one already added is skipped at once and the others go up.
const guardTitles = [`Guard A ${tag}`, `Guard B ${tag}`, `Guard C ${tag}`];
async function guardTest() {
  const files = guardTitles.map((t, i) => {
    const f = join(dir, `guard ${i + 1}.mp3`);
    writeFileSync(f, taggedMp3({ title: t, artist: "Guard Artist", album: "Guard Album" }, null));
    return f;
  });
  const leftOf = `JSON.parse(localStorage.getItem('sw-upload-left:' + document.getElementById('drop').getAttribute('action')) || '[]').map((x) => x.n)`;
  const pick = async () => {
    const doc = await call("DOM.getDocument", {});
    const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
    await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files });
  };
  await go(`${base}/manage/library`);
  await evaluate(`localStorage.removeItem('sw-upload-left:' + document.getElementById('drop').getAttribute('action')); 1`);
  await realClick("#bulkUp");
  check("the tick box is ticked by a real click", await evaluate(`document.getElementById('bulkUp').checked`));
  await call("Network.enable");
  await call("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: 60 * 1024 }); // about 4 s a song
  await pick();
  let st = null;
  for (let i = 0; i < 600; i++) {
    st = await evaluate(`({ p: document.getElementById('upProgress').textContent, shown: !document.getElementById('upProgress').hidden, rows: [...document.querySelectorAll('#uplist li')].map((li) => li.className), left: ${leftOf} })`);
    if (/^1 of 3/.test(st.p)) break; // the first song added and its original kept
    await sleep(100);
  }
  check("while songs are going, a line says how far it has got and to keep the page open", st.shown && /^1 of 3 done\. Keep this page open until all are finished/.test(st.p), st.p);
  check("  the names not finished yet are kept in this browser", JSON.stringify(st.left) === JSON.stringify(["guard 2.mp3", "guard 3.mp3"]), JSON.stringify(st.left));
  dialogs.length = 0;
  await evaluate(`setTimeout(() => location.reload(), 0); 1`);
  await sleep(1500); await loaded();
  await call("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  check("refreshing while songs are going: the browser asks first", dialogs.includes("beforeunload"), JSON.stringify(dialogs));
  const box = await evaluate(`(() => { const b = document.getElementById('upLeft'); return b ? { text: b.textContent, items: [...b.querySelectorAll('li')].map((li) => li.textContent) } : null; })()`);
  check("after the refresh the page lists the songs that didn't finish", box && /2 songs from your last upload didn’t finish/.test(box.text) && JSON.stringify(box.items) === JSON.stringify(["guard 2.mp3", "guard 3.mp3"]), JSON.stringify(box));
  check("  the one that finished is in the library; the next one never arrived", !!helper("track", guardTitles[0]) && !helper("track", guardTitles[1]));
  await realClick("#bulkUp");
  await pick();
  let rows = null;
  for (let i = 0; i < 600; i++) {
    const r = await evaluate(`[...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, text: li.querySelector('.s').textContent }))`).catch(() => null);
    if (r && r.length === 3 && r.every((x) => (x.cls === "ok" || x.cls === "bad") && !x.text.includes("keeping the original"))) { rows = r; break; }
    await sleep(100);
  }
  check("picked again: the list goes at once, the song already added is skipped without preparing it", rows && rows[0].text === "Already there", rows && rows[0].text);
  check("  the other two are prepared and added", rows && rows.slice(1).every((x) => x.cls === "ok" && x.text.startsWith("Added, mastered, levelled")), rows && rows.map((x) => x.text).join(" | "));
  check("  All 3 done, nothing kept", (await evaluate(`document.getElementById('upProgress').textContent`)) === "All 3 done." && (await evaluate(leftOf)).length === 0);
  await sleep(1500); await loaded();
  check("  after the page reloads there's no list of unfinished songs", await evaluate(`!document.getElementById('upLeft')`));
  check("  all three are in the library", guardTitles.every((x) => !!helper("track", x)));
  dialogs.length = 0;
  await realClick("#bulkUp");
  await evaluate(`setTimeout(() => location.reload(), 0); 1`);
  await sleep(1500); await loaded();
  check("with nothing going, a refresh isn't asked about", dialogs.length === 0, JSON.stringify(dialogs));
}

// ---- Song prep on the server (round 85, v1.13) ------------------------------------------------------------
// A throwaway admin, in a second browser of its own (another device), sets it up on Sound & safety; the manager's
// bulk upload then goes up as files and the page is closed while they are prepared; the admin's Music library lists
// them with their stages until they are added. Then: a transfer cut off by a refresh carries on where it stopped;
// the details window (an MP3 added with new details, a FLAC's tags read in the browser, one skipped); a window closed
// with the page ("Add with the file's details"); an ad; and the same song prepared by the server and by the browser
// comes out the same.
const srvTitles = [`Server A ${tag}`, `Server B ${tag}`, `Server Long ${tag}`];
const srvResume = `Server Resume ${tag}`, srvWin = `Server Window ${tag}`, srvWinNew = `Server Window Added ${tag}`, srvHold = `Server Hold ${tag}`;
const srvAd = `Web Test Ad Server ${tag}`, srvSame = `Server Same ${tag}`, brSame = `Browser Same ${tag}`, srvSkip = `Server Skip ${tag}`;
let admin = null;

/** A second, separate browser (its own cookies): Target.createBrowserContext, then its own page connection. */
async function secondBrowser() {
  const bws = new WebSocket((await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl);
  await new Promise((ok, bad) => { bws.onopen = ok; bws.onerror = bad; });
  let bid = 0;
  const bpend = new Map();
  bws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && bpend.has(d.id)) { bpend.get(d.id)(d); bpend.delete(d.id); } };
  const bcall = (method, params = {}) => new Promise((ok) => { const i = ++bid; bpend.set(i, ok); bws.send(JSON.stringify({ id: i, method, params })); });
  const ctx = (await bcall("Target.createBrowserContext", {})).result.browserContextId;
  const tid = (await bcall("Target.createTarget", { url: "about:blank", browserContextId: ctx })).result.targetId;
  await sleep(500);
  const t = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((x) => x.id === tid);
  const pws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { pws.onopen = ok; pws.onerror = bad; });
  let pid = 0;
  const ppend = new Map();
  pws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && ppend.has(d.id)) { ppend.get(d.id)(d); ppend.delete(d.id); }
    if (d.method === "Page.javascriptDialogOpening") pws.send(JSON.stringify({ id: ++pid, method: "Page.handleJavaScriptDialog", params: { accept: true } }));
  };
  const pcall = (method, params = {}) => new Promise((ok) => { const i = ++pid; ppend.set(i, ok); pws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async (expression) => {
    const r = await pcall("Runtime.evaluate", { expression, returnByValue: true, awaitPromise: true });
    if (r.result?.exceptionDetails) throw new Error(r.result.exceptionDetails.exception?.description || "page error");
    return r.result?.result?.value;
  };
  await pcall("Page.enable");
  await pcall("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  const goto = async (url) => {
    await pcall("Page.navigate", { url }); await sleep(300);
    for (let i = 0; i < 100; i++) { if ((await ev("document.readyState").catch(() => "")) === "complete") return; await sleep(100); }
  };
  const close = async () => { try { pws.close(); await bcall("Target.closeTarget", { targetId: tid }); await bcall("Target.disposeBrowserContext", { browserContextId: ctx }); bws.close(); } catch { /* gone */ } };
  return { ev, goto, close };
}

/** Pick files in the drop zone of the page at `path` (bulk ticked when asked), with uploads slowed to `kbps` KB/s if given. */
async function pickOn(path, files, { bulk = false, kbps = 0, noWindow = false } = {}) {
  await go(`${base}/${path}`);
  if (noWindow) await evaluate("window.swUploadNoWindow = true");
  if (bulk) await realClick("#bulkUp");
  await call("Network.enable");
  await call("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: kbps ? kbps * 1024 : -1 });
  const doc = await call("DOM.getDocument", {});
  const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
  await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files });
}
const rowsNow = () => evaluate(`[...document.querySelectorAll('#uplist li')].map((li) => ({ cls: li.className, n: li.querySelector('.n').textContent, text: li.querySelector('.s').textContent }))`).catch(() => null);
const jobsOf = () => helper("prep-jobs", String(user.id));
const waitJobs = async (fn, sec = 120) => { let j = null; for (let i = 0; i < sec * 2; i++) { j = jobsOf(); if (fn(j)) return j; await sleep(500); } return j; };

async function serverTest() {
  // 1. Set up, by an admin, in a second browser; the manager sees where songs are prepared but can't change it
  await go(`${base}/manage/sound`);
  const mgr = await evaluate(`({ where: document.getElementById('spWhere').textContent, btn: !!document.getElementById('spSetup'), note: document.querySelector('.serverprep .spbtns').textContent.trim() })`);
  check("Sound & safety says songs are prepared in the browser; a manager can't change it", mgr.where === "In the browser" && !mgr.btn && /An admin can change this/.test(mgr.note), JSON.stringify(mgr));
  admin = helper("admin");
  const b2 = await secondBrowser();
  try {
    await b2.goto(`${base}/signin`);
    await b2.ev(`(() => { const f = document.querySelector('form'); f.email.value = ${JSON.stringify(admin.email)}; f.password.value = ${JSON.stringify(admin.password)}; f.submit(); })()`);
    await sleep(800);
    await b2.goto(`${base}/manage/sound`);
    await b2.ev(`document.getElementById('spSetup').click(); 1`);
    let sp = null;
    for (let i = 0; i < 240; i++) {
      sp = await b2.ev(`({ where: document.getElementById('spWhere').textContent, detail: document.getElementById('spDetail').textContent, btn: document.getElementById('spSetup').textContent, off: !document.getElementById('spRemove').hidden })`).catch(() => null);
      if (sp && sp.where === "On the server") break;
      await sleep(250);
    }
    check("an admin's Set up (in another browser) finds Node, tests it and says so", sp && sp.where === "On the server" && /Node \d+\.\d+\.\d+/.test(sp.detail) && /The test took/.test(sp.detail) && sp.btn === "Test again" && sp.off, JSON.stringify(sp));
    check("  the station now prepares on the server", !!helper("server-prep", "state").ready);
    await b2.goto(`${base}/manage`);
    const ov = await b2.ev(`document.getElementById('prepWhere') ? document.getElementById('prepWhere').textContent : ''`);
    check("  the Overview says so", /Song prep/.test(ov) && /On the server/.test(ov), ov);

    // 2. A bulk upload sent as files; the page closed while they are prepared; the other browser watches them finish
    const files = srvTitles.map((t, i) => {
      const f = join(dir, `server ${i + 1}.mp3`);
      const secs = i === 2 ? 150 : 12; // the last is long, so it is still being prepared when the page closes
      writeFileSync(f, taggedMp3({ title: t, artist: "Server Artist" }, null, secs));
      return f;
    });
    await go(`${base}/manage/library`);
    const note = await evaluate(`document.getElementById('prepNote').textContent`);
    check("the drop box says songs are prepared on the server after upload", /^Prepared on the server after upload/.test(note), note);
    await pickOn("manage/library", files, { bulk: true });
    let st = null;
    for (let i = 0; i < 600; i++) {
      st = await evaluate(`({ p: document.getElementById('upProgress').textContent, rows: [...document.querySelectorAll('#uplist li')].map((li) => li.className + ': ' + li.querySelector('.s').textContent) })`).catch(() => null);
      if (st && /^All 3 sent/.test(st.p)) break;
      await sleep(100);
    }
    check("the files go up as they are; when all are sent the page says it may be closed", st && st.p === "All 3 sent. They’re being prepared on the server: you can close this page."
      && st.rows.every((r) => r.startsWith("ok: Uploaded: being prepared on the server")), st && JSON.stringify(st));
    const atClose = jobsOf();
    await go("about:blank"); // the page is closed
    check("  the page is closed while some are still being prepared", atClose.length === 3 && atClose.some((j) => j.state !== "done"), JSON.stringify(atClose.map((j) => j.state)));
    await b2.goto(`${base}/manage/library`);
    let seen = null, stages = new Set();
    for (let i = 0; i < 480; i++) {
      seen = await b2.ev(`(() => { const q = document.getElementById('prepQueue'); return q && !q.hidden ? { h: q.querySelector('h3').textContent, rows: [...q.querySelectorAll('li')].map((li) => ({ n: li.querySelector('.n').textContent, s: li.querySelector('.s').textContent, who: li.querySelector('.who').textContent, st: li.dataset.state })) } : null; })()`).catch(() => null);
      (seen?.rows || []).forEach((r) => { if (srvTitles.some((t) => r.n.includes(t.slice(0, 6)) || r.n.startsWith("server"))) stages.add(r.st); });
      if (seen && seen.rows.filter((r) => r.st === "done").length >= 3) break;
      await sleep(250);
    }
    const mineSeen = (seen?.rows || []).filter((r) => r.who.includes("Web Test Prep"));
    check("another device's Music library lists the uploads being prepared, who sent them and their stage", mineSeen.length >= 3 && (stages.has("working") || stages.has("queued")), JSON.stringify([...stages]) + " " + JSON.stringify(mineSeen.slice(0, 3)));
    check("  until each is added, with what was done to it", mineSeen.filter((r) => r.st === "done").length >= 3 && mineSeen.every((r) => /^Added, mastered, levelled/.test(r.s)), JSON.stringify(mineSeen.map((r) => r.s)));
    const t = srvTitles.map((x) => helper("track", x));
    check("  all three are in the library, mastered and levelled to the target", t.every((x) => x && Number(x.mastered) === 1 && near(x.loudness_lufs, Number(x.level_target), 0.6)), JSON.stringify(t.map((x) => x && [x.loudness_lufs, x.level_target, x.mastered])));
    check("  the long one too, though its page was closed", t[2] && Number(t[2].duration_s) > 140, t[2] && t[2].duration_s);

    // 3. A transfer cut off by a refresh carries on from where the server has it
    const big = join(dir, "server resume.wav");
    {
      const RATE = 44100, secs = 70, n = RATE * secs, hz = 500 + Math.floor(Math.random() * 100); // about 12 MB: two pieces; a new note each run (a song left on air by the last run is only hidden)
      const pcm = Buffer.alloc(n * 4);
      for (let i = 0; i < n; i++) { const v = Math.round(0.25 * 32767 * Math.sin(2 * Math.PI * hz * i / RATE)); pcm.writeInt16LE(v, i * 4); pcm.writeInt16LE(v, i * 4 + 2); }
      const h = Buffer.alloc(44);
      h.write("RIFF", 0); h.writeUInt32LE(36 + pcm.length, 4); h.write("WAVEfmt ", 8); h.writeUInt32LE(16, 16); h.writeUInt16LE(1, 20); h.writeUInt16LE(2, 22);
      h.writeUInt32LE(RATE, 24); h.writeUInt32LE(RATE * 4, 28); h.writeUInt16LE(4, 32); h.writeUInt16LE(16, 34); h.write("data", 36); h.writeUInt32LE(pcm.length, 40);
      writeFileSync(big, Buffer.concat([h, pcm]));
    }
    await pickOn("manage/library", [big], { bulk: true, kbps: 1500 });
    let j = await waitJobs((x) => x.some((y) => y.name === "server resume.wav" && Number(y.received) >= 8388608), 60);
    const half = j.find((y) => y.name === "server resume.wav");
    dialogs.length = 0;
    await evaluate(`setTimeout(() => location.reload(), 0); 1`);
    await sleep(1500); await loaded();
    await call("Network.emulateNetworkConditions", { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    check("refreshing mid-transfer: the browser asks first, and the server keeps the pieces it has", dialogs.includes("beforeunload") && half && Number(half.received) >= 8388608 && half.state === "uploading", half && `${half.received} of ${half.bytes}`);
    const left = await evaluate(`(() => { const b = document.getElementById('upLeft'); return b ? b.textContent : ''; })()`);
    check("  the page lists it as not finished", /server resume\.wav/.test(left), left);
    await b2.goto(`${base}/manage/library`);
    await sleep(1500);
    const stale = await b2.ev(`[...document.querySelectorAll('#prepQueue li')].map((li) => li.querySelector('.s').textContent).find((s) => /^Upload/.test(s)) || ''`);
    check("  other devices see it as an upload that stopped part-way", /^Upload(ing| stopped at) \d+ %/.test(stale), stale);
    await evaluate(`(() => { window.__offsets = []; const a = FormData.prototype.append; FormData.prototype.append = function (k, v) { if (k === "offset") window.__offsets.push(Number(v)); return a.apply(this, arguments); }; return 1; })()`);
    const doc = await call("DOM.getDocument", {});
    const input = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector: "#files" });
    await realClick("#bulkUp");
    await call("DOM.setFileInputFiles", { nodeId: input.result.nodeId, files: [big] });
    let r = null;
    for (let i = 0; i < 600; i++) { r = await rowsNow(); if (r && r[0] && (r[0].cls === "ok" || r[0].cls === "bad")) break; await sleep(100); }
    const offsets = await evaluate("window.__offsets");
    check("  picked again, only the rest is sent", r && r[0].cls === "ok" && offsets.length >= 1 && offsets[0] === Number(half.received), JSON.stringify(offsets) + " " + (r && r[0].text));
    j = await waitJobs((x) => x.some((y) => y.name === "server resume.wav" && y.state === "done"), 120);
    const rj = j.find((y) => y.name === "server resume.wav");
    const rt = rj && rj.track_id ? helper("track", "server resume") : null;
    check("  and the WAV is converted and added", rj && rj.state === "done" && rt && Number(rt.duration_s) > 69, JSON.stringify(rj));

    // 4. The details window: an MP3 added with new details, a FLAC whose tags the browser reads, one skipped
    const wsong = join(dir, "server window.mp3"), wskip = join(dir, "server skip.mp3");
    writeFileSync(wsong, taggedMp3({ title: srvWin, artist: "Window Artist" }, null));
    writeFileSync(wskip, taggedMp3({ title: srvSkip }, null));
    const flac = formats ? join(formats, "flac24.flac") : null;
    helper("cleanup-like", TAGS.title);
    const pick3 = flac ? [wsong, flac, wskip] : [wsong, wskip];
    await pickOn("manage/library", pick3);
    const FORM = `document.getElementById('rightsForm').elements`;
    const win = () => evaluate(`(() => { const f = ${FORM}, d = document.getElementById('rightsDlg'); return { open: d.open, song: document.getElementById('rightsSong').textContent, title: f.title.value, artist: f.artist.value, writers: f.writers.value, prep: document.getElementById('sdPrep').textContent }; })()`).catch(() => null);
    const waitWin = async (fn, sec = 60) => { let s = null; for (let i = 0; i < sec * 10; i++) { s = await win(); if (s && fn(s)) return s; await sleep(100); } return s; };
    let w = await waitWin((x) => x.open && x.song.includes("server window.mp3") && /prepared on the server afterwards/.test(x.prep), 20);
    check("the details window opens while the file goes up to be prepared on the server", w && w.title === srvWin && /prepared on the server afterwards/.test(w.prep), w && JSON.stringify(w));
    await evaluate(`(() => { const el = ${FORM}.title; el.value = ${JSON.stringify(srvWinNew)}; el.dispatchEvent(new Event('input', { bubbles: true })); document.getElementById('sdAdd').click(); return 1; })()`);
    if (flac) {
      w = await waitWin((x) => x.open && x.song.includes("flac24.flac") && x.title === TAGS.title, 60);
      check("  a FLAC's own tags fill the window (read in the browser, not decoded there)", w && w.title === TAGS.title && w.artist === TAGS.artist && w.writers === TAGS.composer, w && JSON.stringify(w));
      await evaluate(`document.getElementById('sdAdd').click(); 1`);
    }
    w = await waitWin((x) => x.open && x.song.includes("server skip.mp3"));
    await evaluate(`document.getElementById('sdSkip').click(); 1`);
    let rows = null;
    for (let i = 0; i < 600; i++) { rows = await rowsNow(); if (rows && rows.length === pick3.length && rows.every((x) => x.cls === "ok" || x.cls === "bad")) break; await sleep(100); }
    j = await waitJobs((x) => x.filter((y) => ["server window.mp3", "flac24.flac"].includes(y.name)).every((y) => y.state === "done") && x.some((y) => y.name === "server skip.mp3" && y.state === "cancelled"), 120);
    const wt = helper("track", srvWinNew);
    check("  the song is added with the window's details", !!wt && wt.artist === "Window Artist", JSON.stringify(j.map((y) => y.name + ":" + y.state)));
    if (flac) check("  the FLAC with its own", !!helper("track", TAGS.title));
    check("  the skipped one is cancelled and never added", !helper("track", srvSkip) && j.some((y) => y.name === "server skip.mp3" && y.state === "cancelled"));
    await sleep(2500); await loaded();

    // 5. A window closed with its page: the song waits, and any device can add it with the file's details
    const hold = join(dir, "server hold.mp3");
    writeFileSync(hold, taggedMp3({ title: srvHold, artist: "Hold Artist" }, null));
    await pickOn("manage/library", [hold]);
    await waitWin((x) => x.open && x.song.includes("server hold.mp3"));
    j = await waitJobs((x) => x.some((y) => y.name === "server hold.mp3" && y.state === "prepared"), 60);
    await go("about:blank");
    check("a song whose window was closed with the page is prepared and waits for its details", j.some((y) => y.name === "server hold.mp3" && y.state === "prepared" && Number(y.hold) === 1) && !helper("track", srvHold));
    await b2.goto(`${base}/manage/library`);
    await sleep(1500);
    const holdRow = await b2.ev(`(() => { const li = [...document.querySelectorAll('#prepQueue li')].find((l) => l.querySelector('.s').textContent.includes('waiting for its details')); if (!li) return null; const b = li.querySelector('button[data-action="q_release"]'); const out = { s: li.querySelector('.s').textContent, btn: b && b.textContent }; if (b) b.click(); return out; })()`);
    check("  another device sees it waiting and adds it with the file's details", holdRow && holdRow.btn === "Add with the file’s details", JSON.stringify(holdRow));
    j = await waitJobs((x) => x.some((y) => y.name === "server hold.mp3" && y.state === "done"), 30);
    check("  then it is in the library", !!helper("track", srvHold));

    // 6. An ad, on the Ads screen
    const adFile = join(dir, "server ad.mp3");
    writeFileSync(adFile, taggedMp3({ title: srvAd }, null));
    await pickOn("manage/ads", [adFile], { noWindow: true });
    j = await waitJobs((x) => x.some((y) => y.name === "server ad.mp3" && ["done", "failed"].includes(y.state)), 90);
    const ad = helper("ads-info", srvAd);
    check("an ad goes through the server too and lands on the Ads screen", ad && ad.kind === "ad" && ad.track_id && ad.original, JSON.stringify(j.filter((y) => y.kind === "ad")));
    await sleep(2000);

    // 7. The same sound, prepared by the server and by the browser: the same song
    const same = mp3([[0, 1], [0.35, 25], [0, 1]], "x");
    const audio = same.subarray(10 + (((same[6] & 0x7f) << 21) | ((same[7] & 0x7f) << 14) | ((same[8] & 0x7f) << 7) | (same[9] & 0x7f)));
    const fs1 = join(dir, "same server.mp3"), fs2 = join(dir, "same browser.mp3");
    writeFileSync(fs1, Buffer.concat([Buffer.from(core.id3({ title: srvSame }, null)), audio]));
    writeFileSync(fs2, Buffer.concat([Buffer.from(core.id3({ title: brSame }, null)), audio]));
    await pickOn("manage/library", [fs1], { bulk: true });
    await waitJobs((x) => x.some((y) => y.name === "same server.mp3" && y.state === "done"), 90);
    await sleep(1500);
    helper("server-prep", "off");
    await upload([fs2]);
    const a = helper("track", srvSame), bb = helper("track", brSame);
    check("the same song prepared on the server and in the browser comes out the same: loudness, length, rate, quality, mastering",
      a && bb && near(a.loudness_lufs, Number(bb.loudness_lufs), 0.05) && near(a.source_lufs, Number(bb.source_lufs), 0.1) && near(a.duration_s, Number(bb.duration_s), 0.03)
      && a.sample_rate === bb.sample_rate && a.bitrate_kbps === bb.bitrate_kbps && a.mastered === bb.mastered,
      JSON.stringify([a && [a.loudness_lufs, a.source_lufs, a.duration_s, a.sample_rate, a.bitrate_kbps], bb && [bb.loudness_lufs, bb.source_lufs, bb.duration_s, bb.sample_rate, bb.bitrate_kbps]]));
    await go(`${base}/manage/library`);
    check("with Node gone, songs are prepared in the browser again", /^Prepared in your browser/.test(await evaluate(`document.getElementById('prepNote').textContent`)));
  } finally {
    await b2.close();
  }
}

let user = null, prepBefore = null;
let srvBefore = null;
const SKIP = Symbol("only");
try {
  prepBefore = helper("prep");
  srvBefore = helper("server-prep", "state").state;
  helper("server-prep", "off"); // songs are prepared in this browser, except in the server part (round 85)
  helper("prep", "1", "1", "-16"); // both on (a new station has both on since round 86; set anyway), at -16 LUFS as the checks below say
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const target = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((ok, bad) => { ws.onopen = ok; ws.onerror = bad; });
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
    if (d.method === "Page.javascriptDialogOpening") { dialogs.push(d.params.type); ws.send(JSON.stringify({ id: ++id, method: "Page.handleJavaScriptDialog", params: { accept: true } })); }
  };
  await call("Page.enable");
  await call("DOM.enable");

  user = helper("manager");
  // the screens as they are, not inside the station's frame page with the bar (round 61; the shell test has that)
  await call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  await go(`${base}/signin`);
  await evaluate(`(() => { const f = document.querySelector('form'); f.email.value = ${JSON.stringify(user.email)}; f.password.value = ${JSON.stringify(user.password)}; f.submit(); })()`);
  await sleep(600); await loaded();
  check("a throwaway manager signs in", (await evaluate("location.pathname")).endsWith("/manage"));

  if (only !== "server") {
    console.log("Bulk upload, details later (round 83)");
    await bulkTest();
    console.log("Songs on and off (round 84)");
    await stateTest();
    console.log("The upload guard (round 84)");
    await guardTest();
  }
  if (only === "bulk") throw SKIP;
  console.log("Song prep on the server (round 85)");
  await serverTest();
  if (only === "server") throw SKIP;

  console.log("Upload with song prep");
  await go(`${base}/manage/library`);
  const sw = await evaluate(`[document.getElementById('drop').dataset.master, document.getElementById('drop').dataset.level]`);
  check("the library hands the station's choices (mastering and levelling, both on) to the page", sw[0] === "1" && sw[1] === "1", JSON.stringify(sw));
  let rows = await upload([song]);
  check("the song is mastered, levelled and added", rows && rows[0].cls === "ok" && rows[0].text.startsWith("Added, mastered, levelled"), rows && rows[0].text);
  check("  a 44.1 kHz song needs no conversion, so no warning", rows && rows[0].warn === "", rows && rows[0].warn);
  const t = helper("track", songTitle);
  check("it's marked as mastered", t && Number(t.mastered) === 1);
  check("  with the built-in preset, and the file the manager chose is kept as its original (round 40)", t && t.preset === "Audition chain" && rows[0].text.includes("original kept")
    && existsSync(join(here, "..", "storage", "originals", `${songSha1}.mp3`)), t && `${t.preset}; ${rows[0].text}`);
  check("the original title tag is kept", t !== null && t.title === songTitle);
  check("it's stored at -16 LUFS", t && near(t.loudness_lufs, -16, 0.05), t && t.loudness_lufs);
  check("as a new 192 kbps file", t && Number(t.bitrate_kbps) === 192 && Number(t.sample_rate) === 44100 && t.file !== `${songSha1}.mp3`, t && `${t.bitrate_kbps} kbps`);
  check("with the silence trimmed (23.5 s → about 20.1 s)", t && near(t.duration_s, 20.1, 0.15), t && `${t.duration_s} s`);
  check("known by the original file's fingerprint", t && t.sha1 === songSha1);
  const outro = t && t.outro ? JSON.parse(t.outro) : null;
  check("with its last seconds' loudness for silence detection", outro && outro.length === Math.floor(t.duration_s) && outro.every((v) => v >= -99 && v <= 0), outro && `${outro.length} values`);
  if (t) {
    const measured = await evaluate(`(async () => {
      const buf = await (await fetch(${JSON.stringify(`${base}/storage/media/${t.file}`)})).arrayBuffer();
      const a = await new OfflineAudioContext(2, 1, 44100).decodeAudioData(buf);
      return SwPrepCore.loudness([a.getChannelData(0), a.getChannelData(1)], 44100);
    })()`);
    check("the stored file really measures -16 LUFS (within 0.1: LAME's own gain cut is undone)", near(measured, -16, 0.1), measured && measured.toFixed(2));
  }
  await go(`${base}/manage/library`);
  check("the library shows it Mastered and Levelled", await evaluate(`(() => { const row = [...document.querySelectorAll('tbody tr')].find((tr) => tr.textContent.includes(${JSON.stringify(songTitle)})); return !!row && !!row.querySelector('.sym.mastered[title^="Mastered"]') && !!row.querySelector('.sym.levelled[title^="Levelled"]'); })()`));
  rows = await upload([song]);
  check("the same song again is caught as a duplicate", rows && rows[0].cls === "ok" && rows[0].text === "Already there", rows && rows[0].text);

  console.log("A song at another sample rate (round 51)");
  rows = await upload([odd]);
  check("a 32 kHz song is prepared, and the uploader is warned it is converted to 44.1 kHz (the station's rate)", rows && rows[0].cls === "ok" && /32 kHz to 44\.1 kHz/.test(rows[0].warn) && rows[0].text.includes("at 44.1 kHz"),
    rows && `${rows[0].warn} | ${rows[0].text}`);
  const od = helper("track", oddTitle);
  const oddRate = od && core.mp3Rate(new Uint8Array(readFileSync(join(media, od.file))));
  check("  the stored song is a 44.1 kHz MP3 as long as the original", oddRate === 44100 && near(od.duration_s, 12, 0.1), od && `${oddRate} Hz, ${od.duration_s} s`);

  console.log("Both switches off");
  helper("prep", "0", "0"); // set on Sound & safety since round 64 (that screen has its own test); the page follows
  await go(`${base}/manage/library`);
  check("with mastering and levelling off the page hands that over", JSON.stringify(await evaluate(`[document.getElementById('drop').dataset.master, document.getElementById('drop').dataset.level]`)) === '["0","0"]'
    && /not mastered, kept at its own loudness/.test(await evaluate(`document.getElementById('prepNote').textContent`)));
  rows = await upload([plain]);
  // round 51: with nothing to change (no mastering, no levelling, its rate kept) the original goes up as it came
  check("a song goes up kept as uploaded, measured", rows && rows[0].cls === "ok" && rows[0].text.startsWith("Added, kept as uploaded") && rows[0].text.includes("measured at"), rows && rows[0].text);
  const pl = helper("track", plainTitle);
  check("  not mastered and without a levelled loudness", pl && Number(pl.mastered) === 0 && pl.loudness_lufs === null, pl && JSON.stringify({ mastered: pl.mastered, lufs: pl.loudness_lufs }));
  check("  the very file that was chosen, not encoded again (17 s, its own fingerprint)", pl && near(pl.duration_s, 17.0, 0.1) && pl.file === pl.sha1 + ".mp3", pl && `${pl.duration_s} s, ${pl.file} / ${pl.sha1}`);
  if (pl) {
    const m = await evaluate(`(async () => {
      const buf = await (await fetch(${JSON.stringify(`${base}/storage/media/${pl.file}`)})).arrayBuffer();
      const a = await new OfflineAudioContext(2, 1, 44100).decodeAudioData(buf);
      return SwPrepCore.loudness([a.getChannelData(0), a.getChannelData(1)], 44100);
    })()`);
    check("  the stored file keeps its loudness (about −21.1 LUFS, as measured before upload)", near(m, -21.1, 0.4), m && m.toFixed(2));
  }
  helper("prep", "1", "1", "-16");

  console.log("Fallbacks");
  rows = await upload([silent, fake]);
  check("a silent file goes up as it is", rows && rows[0].cls === "ok" && rows[0].text.includes("as it is: it is silent"), rows && rows[0].text);
  const s = helper("track", silentTitle);
  check("  without a loudness", s !== null && s.loudness_lufs === null);
  check("a file that isn't audio is still refused by the server", rows && rows[1].cls === "bad" && rows[1].text.includes("not an MP3"), rows && rows[1].text);

  console.log("Other formats, converted in the browser (round 54)");
  rows = await upload([badWav, badWma]);
  check("a .wav that isn't audio is not sent", rows && rows[0].cls === "bad" && rows[0].text.startsWith("Not added: not an audio file"), rows && rows[0].text);
  check("a broken WMA is not sent either, saying why", rows && rows[1].cls === "bad" && rows[1].text.startsWith("Not added:") && !rows[1].text.includes("undefined"), rows && rows[1].text);
  if (!formats) {
    check("the format samples are here (lossless test music and the test ffmpeg)", false, "see HANDOVER.md");
  } else {
    let first = null;
    for (const smp of SAMPLES) {
      helper("cleanup-like", TAGS.title); // the samples share their tags: one at a time
      const file = join(formats, smp.file);
      rows = await upload([file]);
      const r = rows && rows[0];
      const label = `${smp.file} (${smp.kind}${smp.rate ? ", " + smp.rate / 1000 + " kHz" : ""})`;
      check(`${label} is converted, prepared and added`, r && r.cls === "ok" && r.text.startsWith(`Added, converted from ${smp.kind} (`) && r.text.includes("320 kbps MP3 kept as its original") && r.text.includes("original kept"), r && r.text);
      if (smp.rate) check("  the uploader is told its rate is converted to 44.1 kHz (the station's rate)", r && new RegExp(`from ${smp.rate / 1000} kHz to 44\.1 kHz`).test(r.warn), r && r.warn);
      else check("  no rate warning at 44.1 kHz", r && r.warn === "", r && r.warn);
      const t = helper("track", TAGS.title);
      check("  stored with its title, artist and album from the file's own tags", t && t.artist === TAGS.artist && t.album === TAGS.album, t && `${t.title} / ${t.artist} / ${t.album}`);
      if (smp.writers !== false) check("  and its songwriter", t && String(t.rights || "").includes(TAGS.composer), t && t.rights);
      if (smp.cover) check("  and its cover as the album art", t && !!t.art, t && String(t.art));
      const media = t && readFileSync(join(here, "..", "storage", "media", t.file));
      const fi = media && frameInfo(new Uint8Array(media));
      check("  the song is an MP3 at the station's rate and quality, about 30 s long", t && fi && fi.rate === 44100 && fi.kbps === Number(t.bitrate_kbps) && near(t.duration_s, 30, 0.7), t && `${fi && fi.kbps} kbps, ${fi && fi.rate} Hz, ${t.duration_s} s`);
      const origPath = t && join(here, "..", "storage", "originals", `${t.sha1}.mp3`);
      const orig = origPath && existsSync(origPath) ? readFileSync(origPath) : null;
      const oi = orig && frameInfo(new Uint8Array(orig));
      const oRate = core.ORIGINAL.rate(smp.rate || 44100);
      check(`  its original is kept as a 320 kbps MP3 at ${oRate / 1000} kHz, known by its fingerprint`, orig && oi && oi.kbps === 320 && oi.rate === oRate && createHash("sha1").update(orig).digest("hex") === t.sha1,
        oi ? `${oi.kbps} kbps, ${oi.rate} Hz` : "no original");
      check("  the original carries the same tags (no Stationwagon levelling tags)", orig && orig.includes(Buffer.from(TAGS.title)) && !orig.includes(Buffer.from("STATIONWAGON_")));
      if (!first && t) {
        first = t;
        const measured = await evaluate(`(async () => {
          const buf = await (await fetch(${JSON.stringify(`${base}/storage/media/${t.file}`)})).arrayBuffer();
          const a = await new OfflineAudioContext(2, 1, 44100).decodeAudioData(buf);
          return SwPrepCore.loudness([a.getChannelData(0), a.getChannelData(1)], 44100);
        })()`);
        check("  the stored song measures the station's target (−16 LUFS within 0.1)", near(measured, -16, 0.1), measured && measured.toFixed(2));
      }
    }
    rows = await upload([join(formats, SAMPLES[SAMPLES.length - 1].file)]); // still in the library
    check(`the same ${SAMPLES[SAMPLES.length - 1].kind} again is caught as a duplicate (converted the same way twice)`, rows && rows[0].cls === "ok" && rows[0].text === "Already there", rows && rows[0].text);
  }

  console.log("The upload window (round 56)");
  await windowTest();
} catch (ex) {
  if (ex !== SKIP) throw ex;
} finally {
  if (prepBefore) helper("prep", prepBefore.master ? "1" : "0", prepBefore.level ? "1" : "0", String(prepBefore.target ?? -11));
  if (srvBefore !== null) helper("server-prep", "restore", JSON.stringify(srvBefore));
  if (user) helper("prep-clean", String(user.id), ...(admin ? [String(admin.id)] : []));
  if (admin) helper("cleanup", String(admin.id));
  helper("ads-clean");
  if (user) {
    const r = helper("cleanup", String(user.id), songTitle, silentTitle, plainTitle, oddTitle, TAGS.title, winTitle, winEdited, ...bulkTitles, ...guardTitles,
      ...srvTitles, srvWin, srvWinNew, srvHold, srvSame, brSame, srvSkip, "server resume");
    console.log(`Cleanup: throwaway manager and test songs removed${r.hidden ? ` (${r.hidden} on air, hidden)` : ""}`);
  }
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
  for (const d of [dir, profile]) { try { rmSync(d, { recursive: true, force: true }); } catch { /* still locked */ } }
}
console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
