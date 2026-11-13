// Builds the Gansbaai Community Radio brand package (v1.9): SVGs with the lettering as outlines, then
// transparent PNGs and favicon.ico rendered by headless Microsoft Edge.
//   cd tools/brandkit && npm install && node gen-gbcr-brand.mjs
// The font (Sora Light and SemiBold, SIL Open Font Licence) is read from fonts/ (gitignored; see the
// README for where it comes from). Output: stations/gansbaai/brand/.
import { readFileSync, writeFileSync, mkdirSync, existsSync, rmSync } from "fs";
import { join, dirname } from "path";
import { fileURLToPath } from "url";
import { spawn } from "child_process";
import opentype from "opentype.js";

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, "../../stations/gansbaai/brand");
const SVG = join(out, "svg"), PNG = join(out, "png");
mkdirSync(SVG, { recursive: true }); mkdirSync(PNG, { recursive: true });

// the palette: the sea from the deep to the surf, foam, and coral for "on air"
const C = {
  midnight: "#03182B", deep: "#0A6FA8", boat: "#0A8FD8", turquoise: "#0FB5BA", neon: "#3EF0D8",
  foam: "#E6FFFB", coral: "#FF5A4E", white: "#FFFFFF", mint: "#7FE7E0", navy: "#0A3D78",
};

// ---- lettering as outlines ------------------------------------------------------------------------
const semi = opentype.loadSync(join(here, "fonts/Sora-600.ttf"));
const light = opentype.loadSync(join(here, "fonts/Sora-300.ttf"));
/** The text as one path, set at the given size with extra spacing (in em) between letters, its left baseline at 0,0. */
function lettering(font, text, size, spacing) {
  const glyphs = font.stringToGlyphs(text), scale = size / font.unitsPerEm;
  let x = 0, d = "";
  const box = { x1: Infinity, y1: Infinity, x2: -Infinity, y2: -Infinity };
  glyphs.forEach((g, i) => {
    const p = g.getPath(x, 0, size);
    const b = p.getBoundingBox();
    if (b.x1 < b.x2) { box.x1 = Math.min(box.x1, b.x1); box.y1 = Math.min(box.y1, b.y1); box.x2 = Math.max(box.x2, b.x2); box.y2 = Math.max(box.y2, b.y2); }
    d += p.toPathData(2);
    x += g.advanceWidth * scale + (i < glyphs.length - 1 ? spacing * size + (font.getKerningValue(g, glyphs[i + 1]) || 0) * scale : 0);
  });
  return { d, box };
}
const NAME = lettering(semi, "gansbaai", 100, -0.01);
const SUB = lettering(light, "COMMUNITY RADIO", 100, 0.22);
/** The two lines, the second as wide as the first, the first's top-left at x,y (or its top centre with "center"); h: the name's height. */
function words(x, y, h, nameFill, subFill, align = "left") {
  const s1 = h / (NAME.box.y2 - NAME.box.y1), w1 = (NAME.box.x2 - NAME.box.x1) * s1;
  const s2 = w1 / (SUB.box.x2 - SUB.box.x1), h2 = (SUB.box.y2 - SUB.box.y1) * s2;
  const gap = h * 0.2, left = align === "center" ? x - w1 / 2 : x;
  const t1 = `translate(${f(left - NAME.box.x1 * s1)} ${f(y - NAME.box.y1 * s1)}) scale(${f(s1, 4)})`;
  const t2 = `translate(${f(left - SUB.box.x1 * s2)} ${f(y + h + gap - SUB.box.y1 * s2)}) scale(${f(s2, 4)})`;
  return { svg: `<path fill="${nameFill}" transform="${t1}" d="${NAME.d}"/><path fill="${subFill}" transform="${t2}" d="${SUB.d}"/>`, w: w1, h: h + gap + h2 };
}
const f = (n, p = 2) => String(+n.toFixed(p));

// ---- the mark, "Swell": sound bars like a level meter, a line of foam running through them like a wave (200 x 200) ----
const HEIGHTS = [60, 80, 108, 138, 152, 128, 100, 72, 48];
const BAR = 13, GAP = 5.5, X0 = 100 - (HEIGHTS.length * BAR + (HEIGHTS.length - 1) * GAP) / 2;
const bars = () => HEIGHTS.map((h, i) => `<rect x="${f(X0 + i * (BAR + GAP))}" y="${f(100 - h / 2)}" width="${BAR}" height="${h}" rx="${BAR / 2}"/>`).join("");
const FOAM = "M10 118 C44 92 70 138 104 112 S160 84 190 104";
/** Full colour: bars in the sea's gradient, cut by the foam line. dark: for dark backgrounds. */
function mark(dark, id, opts = {}) {
  const top = opts.top || (dark ? C.neon : C.turquoise), bottom = opts.bottom || (dark ? C.boat : C.deep);
  return `<defs><linearGradient id="${id}g" x1="0" y1="0" x2="0.35" y2="1"><stop offset="0" stop-color="${top}"/><stop offset="1" stop-color="${bottom}"/></linearGradient>` +
    `<mask id="${id}m" maskUnits="userSpaceOnUse" x="0" y="0" width="200" height="200"><rect width="200" height="200" fill="#fff"/><path d="${FOAM}" fill="none" stroke="#000" stroke-width="13" stroke-linecap="round"/></mask></defs>` +
    `<g mask="url(#${id}m)" fill="${opts.solid || `url(#${id}g)`}">${bars()}</g>` +
    `<path d="${FOAM}" fill="none" stroke="${opts.foam || (dark ? C.foam : C.midnight)}" stroke-width="5" stroke-linecap="round"/>`;
}
/** One colour: the bars and the foam line, with a gap between them. */
const markMono = (col, id) => mark(true, id, { solid: col, foam: col });
/** The small version (favicon): five heavy bars and the foam line on a midnight tile. 64 x 64. */
function markSmall() {
  const hs = [18, 30, 40, 30, 20], bw = 8, gp = 3.5, x0 = 32 - (5 * bw + 4 * gp) / 2;
  return `<defs><linearGradient id="sg" x1="0" y1="0" x2="0.3" y2="1"><stop offset="0" stop-color="${C.neon}"/><stop offset="1" stop-color="${C.boat}"/></linearGradient>` +
    `<mask id="sm" maskUnits="userSpaceOnUse" x="0" y="0" width="64" height="64"><rect width="64" height="64" fill="#fff"/><path d="M4 38 C16 28 24 44 34 36 S52 28 60 33" fill="none" stroke="#000" stroke-width="7" stroke-linecap="round"/></mask></defs>` +
    `<rect width="64" height="64" rx="14" fill="${C.midnight}"/>` +
    `<g mask="url(#sm)" fill="url(#sg)">${hs.map((h, i) => `<rect x="${f(x0 + i * (bw + gp))}" y="${f(32 - h / 2)}" width="${bw}" height="${h}" rx="4"/>`).join("")}</g>` +
    `<path d="M4 38 C16 28 24 44 34 36 S52 28 60 33" fill="none" stroke="${C.foam}" stroke-width="3" stroke-linecap="round"/>`;
}
/** A rounded tile in the night-sea gradient (or turquoise), the mark on it. */
function tile(id, rx, teal) {
  const [a, b] = teal ? [C.turquoise, C.boat] : [C.midnight, C.navy];
  return `<defs><linearGradient id="${id}t" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${a}"/><stop offset="1" stop-color="${b}"/></linearGradient></defs>` +
    `<rect width="200" height="200" rx="${rx}" fill="url(#${id}t)"/><g transform="translate(18 18) scale(0.82)">${teal ? mark(true, id, { top: C.white, bottom: C.foam, foam: C.midnight }) : mark(true, id)}</g>`;
}

// ---- the files ------------------------------------------------------------------------------------------
const svg = (w, h, body, title) => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${f(w)} ${f(h)}" width="${f(w)}" height="${f(h)}"><title>${title}</title>${body}</svg>\n`;
const files = {};
const put = (name, w, h, body, title, px) => { files[name] = { w, h, px }; writeFileSync(join(SVG, name + ".svg"), svg(w, h, body, title)); };
const T = "Gansbaai Community Radio";

put("gbcr-mark", 200, 200, mark(true, "t1"), T, 2000);
put("gbcr-mark-light-bg", 200, 200, mark(false, "t2"), T, 2000);
put("gbcr-mark-white", 200, 200, markMono(C.white, "m1"), T, 2000);
put("gbcr-mark-black", 200, 200, markMono("#000000", "m2"), T, 2000);

for (const [suffix, m, nameC, subC] of [["", mark(true, "h1"), C.white, C.mint], ["-light-bg", mark(false, "h2"), C.midnight, C.deep],
  ["-white", markMono(C.white, "h3"), C.white, C.white], ["-black", markMono("#000000", "h4"), "#000000", "#000000"]]) {
  const t = words(212, 0, 62, nameC, subC);
  put("gbcr-logo-horizontal" + suffix, 212 + t.w + 12, 200, `<g>${m}</g><g transform="translate(0 ${f((200 - t.h) / 2)})">${t.svg}</g>`, T, 2400);
}
for (const [suffix, m, nameC, subC] of [["", mark(true, "s1"), C.white, C.mint], ["-light-bg", mark(false, "s2"), C.midnight, C.deep]]) {
  const W = Math.max(240, words(0, 0, 50, nameC, subC).w + 36);
  const t = words(W / 2, 196, 50, nameC, subC, "center");
  put("gbcr-logo-stacked" + suffix, W, 196 + t.h + 18, `<g transform="translate(${f((W - 200) / 2)} 0)">${m}</g>${t.svg}`, T, 1600);
}
for (const [suffix, nameC, subC] of [["", C.white, C.mint], ["-light-bg", C.midnight, C.deep]]) {
  const t = words(10, 10, 62, nameC, subC);
  put("gbcr-wordmark" + suffix, t.w + 20, t.h + 20, t.svg, T, 2400);
}
put("gbcr-app-icon", 200, 200, tile("a1", 44, false), T, 1024);
put("gbcr-app-icon-teal", 200, 200, tile("a2", 44, true), T, 1024);
put("gbcr-favicon", 64, 64, markSmall(), T, 512);
put("gbcr-apple-touch-icon", 200, 200, tile("p1", 0, false), T, 180);

// ---- PNGs with Edge (transparent background) ------------------------------------------------------------
const EDGE = ["C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe", "C:/Program Files/Microsoft/Edge/Application/msedge.exe"].find(existsSync);
const profile = join(here, ".edge-profile");
const edge = spawn(EDGE, ["--headless=new", "--disable-gpu", "--hide-scrollbars", "--remote-debugging-port=9339", `--user-data-dir=${profile}`, "about:blank"], { stdio: "ignore" });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let target;
for (let i = 0; i < 40 && !target; i++) { await sleep(250); target = await fetch("http://127.0.0.1:9339/json").then((r) => r.json()).then((l) => l.find((t) => t.type === "page")).catch(() => null); }
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((ok) => (ws.onopen = ok));
let seq = 0; const waiting = new Map();
ws.onmessage = (m) => { const d = JSON.parse(m.data); if (waiting.has(d.id)) { waiting.get(d.id)(d.result); waiting.delete(d.id); } };
const cdp = (method, params = {}) => new Promise((ok) => { const id = ++seq; waiting.set(id, ok); ws.send(JSON.stringify({ id, method, params })); });
await cdp("Emulation.setDefaultBackgroundColorOverride", { color: { r: 0, g: 0, b: 0, a: 0 } });
async function shoot(name, px, file = name + ".png") {
  const { w, h } = files[name], ph = Math.round((px * h) / w);
  const src = "data:image/svg+xml;base64," + Buffer.from(readFileSync(join(SVG, name + ".svg"))).toString("base64");
  await cdp("Emulation.setDeviceMetricsOverride", { width: px, height: ph, deviceScaleFactor: 1, mobile: false });
  await cdp("Page.navigate", { url: "data:text/html;base64," + Buffer.from(`<!doctype html><style>html,body{margin:0;background:transparent}img{display:block;width:${px}px;height:${ph}px}</style><img src="${src}">`).toString("base64") });
  await sleep(400);
  const r = await cdp("Page.captureScreenshot", { format: "png", clip: { x: 0, y: 0, width: px, height: ph, scale: 1 } });
  const buf = Buffer.from(r.data, "base64");
  writeFileSync(join(PNG, file), buf);
  return buf;
}
for (const [name, { px }] of Object.entries(files)) if (!/favicon|apple-touch/.test(name)) await shoot(name, px);
await shoot("gbcr-app-icon", 512, "gbcr-app-icon-512.png");
const ico = [];
for (const s of [16, 32, 48, 192, 512]) { const b = await shoot("gbcr-favicon", s, `favicon-${s}.png`); if (s <= 48) ico.push([s, b]); }
await shoot("gbcr-apple-touch-icon", 180, "apple-touch-icon-180.png");
ws.close(); edge.kill(); await sleep(500); try { rmSync(profile, { recursive: true, force: true }); } catch { /* still in use */ }

// favicon.ico: 16, 32 and 48 px, each a PNG inside the icon file
const head = Buffer.alloc(6 + 16 * ico.length); head.writeUInt16LE(0, 0); head.writeUInt16LE(1, 2); head.writeUInt16LE(ico.length, 4);
let offset = head.length;
ico.forEach(([s, b], i) => { const e = 6 + 16 * i; head.writeUInt8(s, e); head.writeUInt8(s, e + 1); head.writeUInt16LE(1, e + 4); head.writeUInt16LE(32, e + 6); head.writeUInt32LE(b.length, e + 8); head.writeUInt32LE(offset, e + 12); offset += b.length; });
writeFileSync(join(out, "favicon.ico"), Buffer.concat([head, ...ico.map(([, b]) => b)]));
console.log(`${Object.keys(files).length} SVGs, PNGs and favicon.ico in ${out}`);
