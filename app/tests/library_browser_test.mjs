// The music library and the copyright line in a real browser (headless Edge or Chrome), round 45:
// editing a song, adding album art and saving the copyright details happen in place (no reload, the
// page keeps its place), the © badge, the copyright window's text-and-links-only editor (a paste is
// cleaned), Remove in the row, and the player's and embed's copyright line and album art.
//   node app/tests/library_browser_test.mjs http://localhost:8080/stationwagon-app
// SW_PHP is the PHP for the helper (Laragon only: it makes and removes throwaway songs).
import { spawn, execFileSync } from "node:child_process";
import { mkdtempSync, existsSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import { deflateSync } from "node:zlib";

const base = (process.argv[2] || "").replace(/\/$/, "");
if (!base) { console.error("usage: node library_browser_test.mjs <app url>"); process.exit(2); }
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
const profile = mkdtempSync(join(tmpdir(), "sw-lib-profile-"));
const port = 9300 + Math.floor(Math.random() * 500);
const proc = spawn(exe, ["--headless=new", `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, "--no-first-run", "--disable-sync", "--disable-extensions",
  "--window-size=1280,800", "about:blank"], { stdio: "ignore" });

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
  const until = async (expr, ms = 6000) => { for (let t = 0; t < ms; t += 100) { if (await evaluate(expr).catch(() => false)) return true; await sleep(100); } return false; };
  const files = async (selector, paths) => {
    const doc = await call("DOM.getDocument", { depth: 0 });
    const q = await call("DOM.querySelector", { nodeId: doc.result.root.nodeId, selector });
    if (selector === "#files") await evaluate("window.swUploadNoWindow = true"); // the upload window has its own test
    await call("DOM.setFileInputFiles", { nodeId: q.result.nodeId, files: paths });
  };
  await call("Page.enable"); await call("Runtime.enable"); await call("DOM.enable");
  return { ws, call, evaluate, go, until, files };
}

let manager = null, songs = null;
try {
  for (let i = 0; i < 50; i++) { await sleep(200); try { await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); break; } catch { /* starting */ } }
  const first = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find((t) => t.type === "page");
  const A = await tab(first.webSocketDebuggerUrl);

  manager = helper("manager");
  songs = helper("lib-songs", 30);
  // the screens as they are, not inside the station's frame page with the bar (round 61; the shell test has that)
  await A.call("Network.setCookie", { name: "sw_bare", value: "1", url: base + "/" });
  await A.go(`${base}/signin`);
  await A.evaluate(`(() => { const f = document.querySelector('main form'); f.email.value = ${JSON.stringify(manager.email)}; f.password.value = ${JSON.stringify(manager.password)}; f.submit(); })()`);
  await sleep(1500);

  console.log("The library shows what the file carried");
  await A.go(`${base}/manage/library`);
  const tagged = songs.tagged;
  const rowOf = (id) => `document.getElementById("t${id}")`;
  check("a song uploaded with a cover shows it, and its copyright makes the © badge", await A.evaluate(`!!${rowOf(tagged)}.querySelector(".arts img") && !!${rowOf(tagged)}.querySelector(".pill.rights")`));
  const info0 = helper("lib-info", tagged);
  check("  the badge's text comes from the tags", info0.rights.owner === "Test Owner" && info0.rights.year === "2021" && info0.rights.publisher === "Test Press" && info0.rights.writers === "T. Writer", JSON.stringify(info0.rights));
  const plain = songs.ids[28];
  check("a song without them has no badge and no art", await A.evaluate(`!${rowOf(plain)}.querySelector(".pill.rights") && !${rowOf(plain)}.querySelector(".arts img")`));

  console.log("Editing in place");
  await A.evaluate(`window.__marker = "same page"; document.getElementById("t${plain}").scrollIntoView({ block: "center" }); 1`);
  await sleep(300);
  const y0 = await A.evaluate("Math.round(window.scrollY)");
  const top0 = await A.evaluate(`Math.round(${rowOf(plain)}.getBoundingClientRect().top)`);
  check("the page is scrolled down to the song", y0 > 300, `scrollY ${y0}`);
  await A.evaluate(`${rowOf(plain)}.querySelector('a[data-act="edit"]').click(); 1`);
  await sleep(300);
  check("Edit opens the fields under the song without leaving the page", await A.evaluate(`window.__marker === "same page" && !!${rowOf(plain)}.nextElementSibling.querySelector("form.libedit") && location.search === ""`));
  const y1 = await A.evaluate("Math.round(window.scrollY)");
  const top1 = await A.evaluate(`Math.round(${rowOf(plain)}.getBoundingClientRect().top)`);
  check("  the song stays exactly where it was on the screen", Math.abs(y1 - y0) <= 1 && Math.abs(top1 - top0) <= 1, `scrollY ${y0} → ${y1}, row top ${top0} → ${top1}`);
  await A.evaluate(`(() => { const f = ${rowOf(plain)}.nextElementSibling.querySelector("form"); f.title.value = "Web Test Lib Renamed"; f.artist.value = "New Artist"; f.album.value = "New Album"; f.requestSubmit(); return 1; })()`);
  check("Save changes the row in place", await A.until(`${rowOf(plain)}.querySelector(".tt").textContent === "Web Test Lib Renamed"`));
  check("  the artist and album, the editor closed, no reload, still in place", await A.evaluate(`${rowOf(plain)}.querySelector(".c-artist").textContent === "New Artist" && ${rowOf(plain)}.querySelector(".alb").textContent === "New Album" && !${rowOf(plain)}.nextElementSibling.classList.contains("editing") && window.__marker === "same page" && Math.abs(window.scrollY - ${y0}) <= 1`));
  const i1 = helper("lib-info", plain);
  check("  and it is saved on the server", i1.title === "Web Test Lib Renamed" && i1.artist === "New Artist" && i1.album === "New Album", JSON.stringify(i1));

  console.log("Album art");
  const png = join(profile, "cover.png");
  // a small valid PNG (a 32 × 32 grey square), made here
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (b) => { let c = 0xffffffff; for (const x of b) c = crcTable[(c ^ x) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const t = Buffer.concat([Buffer.from(type), data]); const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const sum = Buffer.alloc(4); sum.writeUInt32BE(crc(t)); return Buffer.concat([len, t, sum]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(32, 0); ihdr.writeUInt32BE(32, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.concat(Array.from({ length: 32 }, () => Buffer.concat([Buffer.from([0]), Buffer.alloc(96, 128)])));
  writeFileSync(png, Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk("IHDR", ihdr), chunk("IDAT", deflateSync(raw)), chunk("IEND", Buffer.alloc(0))]));
  await A.evaluate(`${rowOf(plain)}.querySelector('a[data-act="edit"]').click(); 1`);
  await sleep(300);
  check("with no art the editor says so, and has no Remove art", await A.evaluate(`(() => { const f = ${rowOf(plain)}.nextElementSibling; return f.querySelector('[data-role="pic"]').textContent === "No album art" && f.querySelector('[data-role="unpick"]').hidden; })()`));
  await A.files(`#t${plain} + tr input[data-role="file"]`, [png]);
  await A.evaluate(`${rowOf(plain)}.nextElementSibling.querySelector('[data-role="file"]').dispatchEvent(new Event("change")); 1`);
  check("choosing a picture adds it at once, in the editor and in the row", await A.until(`!!${rowOf(plain)}.querySelector(".arts img") && !!${rowOf(plain)}.nextElementSibling.querySelector('[data-role="pic"] img')`));
  const i2 = helper("lib-info", plain);
  check("  and it is kept on the server", /^art-[0-9a-f]{12}\.(png|jpg)$/.test(i2.art || ""), String(i2.art));
  check("  and the picture is served", await A.evaluate(`fetch(${rowOf(plain)}.querySelector(".arts img").src).then((r) => r.ok && r.headers.get("content-type").startsWith("image/"))`));
  await A.evaluate(`${rowOf(plain)}.nextElementSibling.querySelector('[data-role="unpick"]').click(); 1`);
  check("Remove art takes it off", await A.until(`!${rowOf(plain)}.querySelector(".arts img")`) && helper("lib-info", plain).art === null);

  console.log("The copyright window");
  await A.evaluate(`${rowOf(plain)}.nextElementSibling.querySelector('[data-role="rights"]').click(); 1`);
  await sleep(300);
  check("the © button opens a window with the details and the notes", await A.evaluate(`document.getElementById("rightsDlg").open && !!document.querySelector('#rightsForm [name="publisher"]') && !!document.querySelector('#rightsForm [name="writers"]') && document.getElementById("rtNotes").isContentEditable`));
  await A.evaluate(`(() => { const f = document.getElementById("rightsForm"); f.owner.value = "Alruin Music"; f.year.value = "2024"; f.licence.value = "CC BY 4.0"; f.publisher.value = "Grace Press"; f.writers.value = "A. Smith, B. Jones"; return 1; })()`);
  // pasting: the browser offers rich HTML and plain text; only the plain text, with its line breaks, goes in
  const pasted = await A.evaluate(`(() => {
    const n = document.getElementById("rtNotes"); n.focus(); n.textContent = "";
    const dt = new DataTransfer();
    dt.setData("text/html", '<meta charset="utf-8"><span style="font-family:Comic Sans MS;font-size:40px;color:red"><b>Bold</b> pasted</span><script>x</script>');
    dt.setData("text/plain", "First line\\nSecond line https://youtu.be/abc123.\\n\\nFourth");
    n.dispatchEvent(new ClipboardEvent("paste", { clipboardData: dt, bubbles: true, cancelable: true }));
    return n.innerHTML;
  })()`);
  check("pasting keeps the words and line breaks and nothing of the formatting", /^First line<br>Second line <a [^>]*href="https:\/\/youtu\.be\/abc123"[^>]*>https:\/\/youtu\.be\/abc123<\/a>\.<br><br>Fourth$/.test(pasted) && !/<(b|span|font|script|meta)[\s>]|style=/.test(pasted), pasted);
  const enter = await A.evaluate(`(() => { const n = document.getElementById("rtNotes"); n.focus(); const r = document.createRange(); r.selectNodeContents(n); r.collapse(false); const s = getSelection(); s.removeAllRanges(); s.addRange(r); n.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })); const bold = new KeyboardEvent("keydown", { key: "b", ctrlKey: true, bubbles: true, cancelable: true }); n.dispatchEvent(bold); return [n.querySelectorAll("br").length, bold.defaultPrevented]; })()`);
  check("Enter makes a line break; Ctrl+B and the like do nothing", enter[0] >= 4 && enter[1] === true, JSON.stringify(enter));
  // a link: select a word, Add link, give an address
  await A.evaluate(`(() => { const n = document.getElementById("rtNotes"); n.textContent = ""; n.append("The artist page here"); const r = document.createRange(); r.setStart(n.firstChild, 4); r.setEnd(n.firstChild, 15); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.querySelector('[data-cmd="link"]').click(); return 1; })()`);
  check("Add link asks for the address", await A.evaluate(`!document.getElementById("rtLink").hidden`));
  await A.evaluate(`document.getElementById("rtUrl").value = "javascript:alert(1)"; document.getElementById("rtApply").click(); 1`);
  check("  and refuses anything that isn't a web address", await A.evaluate(`!document.getElementById("rtNotes").querySelector("a") && /web address/.test(document.getElementById("rightsMsg").textContent)`));
  await A.evaluate(`document.getElementById("rtUrl").value = "www.example.com/artist"; document.getElementById("rtApply").click(); 1`);
  const linked = await A.evaluate(`document.getElementById("rtNotes").innerHTML`);
  check("  a good one makes the selected words a link that opens in a new tab", /^The <a href="https:\/\/www\.example\.com\/artist" target="_blank" rel="noopener noreferrer">artist page<\/a> here$/.test(linked), linked);
  await A.evaluate(`(() => { const a = document.querySelector("#rtNotes a"); const r = document.createRange(); r.setStart(a.firstChild, 2); r.collapse(true); const s = getSelection(); s.removeAllRanges(); s.addRange(r); document.querySelector('[data-cmd="unlink"]').click(); return 1; })()`);
  check("Remove link turns it back into plain words", await A.evaluate(`!document.querySelector("#rtNotes a") && document.getElementById("rtNotes").textContent === "The artist page here"`));
  await A.evaluate(`(() => { const n = document.getElementById("rtNotes"); n.textContent = ""; n.focus(); window.SwLibrary.insertPlain("Watch: https://youtu.be/xyz\\nArtist: https://example.com/a"); return 1; })()`);
  const yy = await A.evaluate("Math.round(window.scrollY)");
  await A.evaluate(`document.getElementById("rightsSave").click(); 1`);
  check("Save closes the window and the row gets the © badge, with no reload", await A.until(`!document.getElementById("rightsDlg").open && !!${rowOf(plain)}.querySelector(".pill.rights") && window.__marker === "same page"`));
  check("  and the page didn't move", await A.evaluate(`Math.abs(window.scrollY - ${yy}) <= 1`));
  const i3 = helper("lib-info", plain);
  check("  the details are saved, the notes as text, breaks and links", i3.rights.owner === "Alruin Music" && i3.rights.year === "2024" && i3.rights.licence === "CC BY 4.0" && i3.rights.publisher === "Grace Press" && i3.rights.writers === "A. Smith, B. Jones"
    && /^Watch: <a href="https:\/\/youtu\.be\/xyz" target="_blank" rel="noopener noreferrer">https:\/\/youtu\.be\/xyz<\/a><br>Artist: <a /.test(i3.rights.notes), JSON.stringify(i3.rights));
  check("  the badge says what listeners will see", await A.evaluate(`${rowOf(plain)}.querySelector(".pill.rights").title === "© 2024 Alruin Music · Written by A. Smith, B. Jones · CC BY 4.0"`));
  // the server cleans too: a hand-made request with formatting and a script
  const evil = await A.evaluate(`(async () => { const d = new FormData(); d.append("csrf", document.getElementById("libWrap").dataset.csrf); d.append("action", "rights"); d.append("id", "${plain}"); d.append("owner", "Z"); d.append("notes", '<span style="color:red">hi</span><script>alert(1)</script><a href="javascript:alert(2)">x</a><img src=x onerror=alert(3)>'); const r = await fetch(document.getElementById("libWrap").dataset.action, { method: "POST", body: d, headers: { Accept: "application/json" }, credentials: "same-origin" }); return (await r.json()).track.rights.notes; })()`);
  check("the server cleans whatever it is sent", evil === "hix", evil);
  await A.evaluate(`${rowOf(plain)}.querySelector(".pill.rights").click(); 1`);
  await sleep(200);
  check("clicking the badge opens the window again, filled in", await A.evaluate(`document.getElementById("rightsDlg").open && document.getElementById("rightsForm").owner.value === "Alruin Music" && /^Watch: /.test(document.getElementById("rtNotes").textContent)`));
  await A.evaluate(`window.confirm = () => true; document.getElementById("rightsClear").click(); 1`);
  check("Clear all takes the badge away", await A.until(`!document.getElementById("rightsDlg").open && !${rowOf(plain)}.querySelector(".pill.rights")`) && helper("lib-info", plain).rights === null);

  console.log("Remove, in the row");
  const countBefore = await A.evaluate(`document.getElementById("libCount").textContent`);
  const victim = songs.ids[27];
  await A.evaluate(`${rowOf(victim)}.querySelector('a[data-act="remove"]').click(); 1`);
  check("Remove asks in the row", await A.evaluate(`/Remove from the library/.test(${rowOf(victim)}.querySelector("td.acts").textContent) && window.__marker === "same page"`));
  await A.evaluate(`[...${rowOf(victim)}.querySelectorAll("td.acts button")].find((b) => /Yes/.test(b.textContent)).click(); 1`);
  check("  Yes removes the row and updates the count, without a reload", await A.until(`!${rowOf(victim)} && document.getElementById("libCount").textContent !== ${JSON.stringify(countBefore)} && window.__marker === "same page"`));

  // ---- round 62: the whole window wide, stars, sorting, the column menu, compact rows, the phone's cards ----
  console.log("The wide table, stars, sorting and columns (round 62)");
  await A.call("Emulation.setDeviceMetricsOverride", { width: 1700, height: 900, deviceScaleFactor: 1, mobile: false });
  await A.evaluate(`try { localStorage.removeItem("sw.library.cols"); localStorage.removeItem("sw.library.dense"); } catch (e) {} 1`);
  await A.go(`${base}/manage/library`);
  const [s0, s1, s2] = songs.ids;
  const star = (id, n) => `document.querySelector('#t${id} .star[data-stars="${n}"]')`;
  const disp = (sel) => `getComputedStyle(document.querySelector(${JSON.stringify(sel)})).display`;
  check("the manager takes the whole window's width", await A.evaluate(`document.querySelector("main.page").getBoundingClientRect().width > 1640`));
  check("  the album, loudness and added columns show; bitrate, size and preset start hidden", await A.evaluate(`[${disp('#t' + s0 + ' td[data-col="album"]')}, ${disp('#t' + s0 + ' td[data-col="loudness"]')}, ${disp('#t' + s0 + ' td[data-col="added"]')}].every((d) => d !== "none")
    && [${disp('#t' + s0 + ' td[data-col="bitrate"]')}, ${disp('#t' + s0 + ' td[data-col="size"]')}, ${disp('#t' + s0 + ' td[data-col="preset"]')}].every((d) => d === "none")`));
  check("  the stars are the last column before the buttons", await A.evaluate(`(() => { const c = [...document.querySelectorAll("#t${s0} > td")]; return c[c.length - 2].classList.contains("c-rating") && c[c.length - 1].classList.contains("acts"); })()`));
  check("the sticky heading stays at the top of the table while it scrolls", await (async () => {
    await A.evaluate(`document.getElementById("libWrap").scrollTop = 500; 1`);
    await sleep(200);
    return await A.evaluate(`(() => { const w = document.getElementById("libWrap"); return w.scrollTop > 100 && Math.abs(document.querySelector("#libTable th[data-col=length]").getBoundingClientRect().top - w.getBoundingClientRect().top) < 3; })()`);
  })());
  await A.evaluate(`document.getElementById("libWrap").scrollTop = 0; 1`);

  await A.evaluate(`${star(s0, 4)}.click(); 1`);
  check("a click on the 4th star rates the song at once", await A.until(`document.querySelectorAll("#t${s0} .star.on").length === 4 && ${star(s0, 4)}.getAttribute("aria-checked") === "true"`));
  check("  and it is saved on the server, with the row's own record updated", await A.until(`JSON.parse(document.getElementById("t${s0}").dataset.track).rating === 4`) && helper("lib-info", s0).rating === 4);
  await A.evaluate(`${star(s0, 4)}.click(); 1`);
  check("  the star it already has takes the rating off", await A.until(`document.querySelectorAll("#t${s0} .star.on").length === 0`) && await A.until(`JSON.parse(document.getElementById("t${s0}").dataset.track).rating === null`) && helper("lib-info", s0).rating === null);
  await A.evaluate(`${star(s0, 1)}.focus(); ${star(s0, 1)}.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true })); 1`);
  const moved = await A.evaluate(`document.activeElement.dataset.stars === "2"`);
  await A.evaluate(`document.activeElement.dispatchEvent(new KeyboardEvent("keydown", { key: "5", bubbles: true })); 1`);
  const five = await A.until(`document.querySelectorAll("#t${s0} .star.on").length === 5 && JSON.parse(document.getElementById("t${s0}").dataset.track).rating === 5`);
  check("the arrow keys move between the stars, the number keys rate", moved && five && helper("lib-info", s0).rating === 5);

  await A.evaluate(`${star(s1, 2)}.click(); ${star(s2, 3)}.click(); 1`);
  await A.until(`JSON.parse(document.getElementById("t${s2}").dataset.track).rating === 3`);
  const order = async (key, dir) => {
    await A.evaluate(`document.querySelector('#libTable .sortb[data-key="${key}"]').click(); 1`);
    return A.evaluate(`[...document.querySelectorAll("#libBody tr[id^=t]")].map((tr) => tr.id)`);
  };
  const byRating = await order("rating");
  const ratingOf = (id) => (id === `t${s0}` ? 5 : id === `t${s1}` ? 2 : id === `t${s2}` ? 3 : 3);
  check("a click on Rating sorts by it (unrated counts as 3), a second click turns it round", byRating.length >= 28 && ratingOf(byRating[0]) === 2 && ratingOf(byRating[byRating.length - 1]) === 5
    && await A.evaluate(`document.querySelector('#libTable th[data-col=rating]').getAttribute("aria-sort")`) === "ascending"
    && (await order("rating"))[0] === `t${s0}` && await A.evaluate(`document.querySelector('#libTable th[data-col=rating]').getAttribute("aria-sort")`) === "descending");
  const lens = async () => A.evaluate(`[...document.querySelectorAll("#libBody tr[id^=t]")].map((tr) => parseFloat(tr.querySelector('[data-col=length]').dataset.sort))`);
  await order("length");
  const l1 = await lens();
  check("Length sorts as numbers, smallest first, and again largest first", l1.every((v, i) => i === 0 || v >= l1[i - 1]) && (await order("length"), (await lens()).every((v, i, a) => i === 0 || v <= a[i - 1])));
  await order("title");
  const titles = await A.evaluate(`[...document.querySelectorAll("#libBody .tt")].map((e) => e.textContent)`);
  check("Title sorts the songs by name", titles.every((v, i) => i === 0 || new Intl.Collator(undefined, { numeric: true, sensitivity: "base" }).compare(titles[i - 1], v) <= 0));

  const filterTo = (v) => A.evaluate(`(() => { const f = document.getElementById("ratingFilter"); f.value = ${JSON.stringify(v)}; f.dispatchEvent(new Event("change", { bubbles: true })); return 1; })()`);
  await filterTo("5");
  check("the rating filter shows only the songs with that many stars", await A.evaluate(`[...document.querySelectorAll("#libBody tr[id^=t]")].filter((tr) => !tr.hidden).map((tr) => tr.id).join() === "t${s0}"`)
    && /Showing 1 of/.test(await A.evaluate(`document.getElementById("libNote").textContent`)));
  await filterTo("none");
  check("  “Not rated yet” hides the rated ones", await A.evaluate(`[${s0}, ${s1}, ${s2}].every((id) => document.getElementById("t" + id).hidden) && [...document.querySelectorAll("#libBody tr[id^=t]")].some((tr) => !tr.hidden)`));
  await filterTo("");
  check("  All ratings shows everything again", await A.evaluate(`[...document.querySelectorAll("#libBody tr[id^=t]")].every((tr) => !tr.hidden)`));

  await A.evaluate(`document.querySelector("#t${s1} .sel").click(); document.querySelector("#t${s2} .sel").click(); 1`);
  check("choosing songs shows the bar with how many", await A.evaluate(`!document.getElementById("bulkBar").hidden && document.getElementById("bulkCount").textContent === "2 selected"`));
  await A.evaluate(`document.querySelector('#bulkStars .star[data-stars="4"]').click(); 1`);
  check("  a star in the bar rates them all, on screen and on the server", await A.until(`document.querySelectorAll("#t${s1} .star.on").length === 4 && document.querySelectorAll("#t${s2} .star.on").length === 4`)
    && helper("lib-info", s1).rating === 4 && helper("lib-info", s2).rating === 4);
  await A.evaluate(`document.getElementById("bulkClear").click(); 1`);
  check("  Clear rating takes them off", await A.until(`document.querySelectorAll("#t${s1} .star.on").length === 0 && document.querySelectorAll("#t${s2} .star.on").length === 0`) && helper("lib-info", s1).rating === null);
  await A.evaluate(`document.getElementById("bulkNone").click(); 1`);
  check("  Deselect puts the bar away", await A.evaluate(`document.getElementById("bulkBar").hidden`));

  await A.evaluate(`document.querySelector('#colMenu input[data-col="album"]').click(); document.querySelector('#colMenu input[data-col="bitrate"]').click(); 1`);
  check("the Columns menu hides and shows columns at once", await A.evaluate(`${disp('#t' + s0 + ' td[data-col="album"]')} === "none" && ${disp('#t' + s0 + ' td[data-col="bitrate"]')} !== "none" && ${disp('#libTable th[data-col="album"]')} === "none"`));
  await A.evaluate(`document.getElementById("denseToggle").click(); 1`);
  check("  Compact rows make them shorter", await A.evaluate(`document.getElementById("libTable").classList.contains("dense")`));
  await A.go(`${base}/manage/library`);
  check("  the choices are still there after a reload", await A.evaluate(`${disp('#t' + s0 + ' td[data-col="album"]')} === "none" && ${disp('#t' + s0 + ' td[data-col="bitrate"]')} !== "none" && document.getElementById("libTable").classList.contains("dense") && document.querySelector('#colMenu input[data-col="album"]').checked === false`));
  await A.evaluate(`try { localStorage.removeItem("sw.library.cols"); localStorage.removeItem("sw.library.dense"); } catch (e) {} 1`);

  await A.call("Emulation.setDeviceMetricsOverride", { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
  await A.go(`${base}/manage/library`);
  const phone = await A.evaluate(`(() => { const r = document.querySelector("#t${s0}"); const st = r.querySelector(".star").getBoundingClientRect(); return {
    wide: document.documentElement.scrollWidth <= innerWidth + 1, headHidden: getComputedStyle(document.querySelector("#libTable thead")).display === "none",
    card: [".c-title", ".c-artist", ".c-rating", ".acts"].every((s) => getComputedStyle(r.querySelector(s)).display !== "none"), star: st.width >= 18 && st.height >= 18,
    plain: getComputedStyle(r.querySelector("td[data-col=length]")).display === "none" }; })()`);
  check("on a phone each song is a card with its stars and buttons, and nothing scrolls sideways", phone.wide && phone.headHidden && phone.card && phone.star && phone.plain, JSON.stringify(phone));
  await A.call("Emulation.clearDeviceMetricsOverride");

  console.log("After a reload");
  await A.go(`${base}/manage/library`);
  check("the changes are still there", await A.evaluate(`document.getElementById("t${plain}").querySelector(".tt").textContent === "Web Test Lib Renamed" && !document.getElementById("t${victim}")`));
  check("a phone-width screen has no sideways scroll with the editor and window", await (async () => {
    await A.call("Emulation.setDeviceMetricsOverride", { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
    await A.go(`${base}/manage/library`);
    await A.evaluate(`window.SwLibrary.openEditor(${tagged}); document.querySelector("#t${tagged} + tr [data-role=rights]").click(); 1`);
    await sleep(300);
    const r = await A.evaluate(`[document.documentElement.scrollWidth <= innerWidth + 1, document.getElementById("rightsDlg").getBoundingClientRect().width <= innerWidth, document.getElementById("rightsDlg").open]`);
    await A.call("Emulation.clearDeviceMetricsOverride");
    return r.every(Boolean);
  })());

  console.log("The player and the embed");
  // the station's own timeline and now playing, with the song on air given this song's art and copyright
  const fake = `(() => {
    const art = ${JSON.stringify("storage/art/" + info0.art)};
    const rights = { line: "© 2021 Test Owner · Written by T. Writer · CC BY 4.0", owner: "Test Owner", year: "2021", licence: "CC BY 4.0", publisher: "Test Press", writers: "T. Writer",
      notes: 'More at <a href="https://youtu.be/abc">YouTube</a> and <a href="javascript:alert(1)">bad</a><br>Second line' };
    const orig = window.fetch.bind(window);
    window.fetch = async (url, opts) => {
      const r = await orig(url, opts);
      if (!/api\\/(timeline|nowplaying)\\.php/.test(String(url))) return r;
      const j = await r.clone().json();
      const hear = (j.heard_at !== undefined ? j.heard_at : j.server_now - 20);
      for (const e of j.entries || []) if (e.type === "song" && e.start <= hear + 1000) { if (e.start <= j.server_now - 20 && j.server_now - 20 < e.end) { e.art = art; e.rights = rights; e.album = "The Album"; } }
      if (j.on_air && j.on_air.type === "song") { j.on_air.art = art; j.on_air.rights = rights; }
      return new Response(JSON.stringify(j), { status: r.status, headers: r.headers });
    };
  })();`;
  await A.call("Page.addScriptToEvaluateOnNewDocument", { source: fake });
  await A.go(`${base}/`);
  const onAirSong = await A.until(`document.getElementById("lpTitle").textContent !== "Loading…" && !document.getElementById("lpMode").textContent.startsWith("LIVE")`, 8000);
  if (onAirSong) {
    check("the player shows the copyright line under the song's title", await A.until(`!document.getElementById("lpRights").hidden && document.getElementById("lpRights").textContent === "© 2021 Test Owner · Written by T. Writer · CC BY 4.0"`));
    await A.evaluate(`document.getElementById("lpRights").click(); 1`);
    await sleep(200);
    const d = await A.evaluate(`(() => { const dlg = document.getElementById("lpRDlg"); return { open: dlg.open, text: dlg.textContent, links: [...dlg.querySelectorAll("a")].map((a) => a.href + "|" + a.target), bad: /alert|javascript/.test(dlg.innerHTML) }; })()`);
    check("  a click opens the details: owner, licence, publisher, writers and the notes", d.open && /Test Owner/.test(d.text) && /CC BY 4.0/.test(d.text) && /Test Press/.test(d.text) && /T\. Writer/.test(d.text) && /Second line/.test(d.text), d.text.replace(/\s+/g, " ").slice(0, 160));
    check("  its link opens in a new tab, and an unsafe one is not a link", d.links.length === 1 && d.links[0] === "https://youtu.be/abc|_blank" && !d.bad, JSON.stringify(d.links));
    await A.evaluate(`document.getElementById("lpRClose").click(); 1`);
  } else check("the player has a song on air to check", false);

  await A.call("Emulation.setDeviceMetricsOverride", { width: 640, height: 340, deviceScaleFactor: 1, mobile: false });
  await A.go(`${base}/embed`);
  if (await A.until(`document.getElementById("emTitle").textContent !== "Loading…" && !document.getElementById("emMode").textContent.startsWith("Live")`, 8000)) {
    check("the embed shows the line and the song's own cover instead of the CD", await A.until(`!document.getElementById("emRights").hidden && document.getElementById("emRights").textContent.startsWith("© 2021 Test Owner") && !document.getElementById("emArtImg").hidden && /storage\\/art\\/art-/.test(document.getElementById("emArtImg").src) && getComputedStyle(document.querySelector("#emArt svg")).display === "none"`));
    await A.evaluate(`document.getElementById("emRights").click(); 1`);
    check("  a click opens the details inside the frame, and Close puts them away", await A.evaluate(`!document.getElementById("emRDlg").hidden && /Test Press/.test(document.getElementById("emRDlg").textContent) && document.querySelectorAll("#emRDlg a").length === 1`)
      && await A.evaluate(`(document.getElementById("emRClose").click(), document.getElementById("emRDlg").hidden)`));
  } else check("the embed has a song on air to check", false);
  await A.call("Emulation.clearDeviceMetricsOverride");
} catch (e) {
  failed++;
  console.log("  [FAIL] " + (e.stack || e.message));
} finally {
  try { helper("lib-clean"); } catch (e) { console.log("cleanup: " + e.message); }
  try { if (manager) helper("cleanup", manager.id); } catch (e) { console.log("cleanup: " + e.message); }
  try { proc.kill(); } catch { /* gone */ }
}
console.log(failed ? `${failed} check(s) failed, ${passed} passed` : `All checks passed (${passed})`);
process.exit(failed ? 1 : 0);
