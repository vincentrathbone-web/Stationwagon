// Test of the song prep maths in assets/prep-core.js: loudness against the EBU Tech 3341 test
// cases, silence trim, levelling and its caps, fades, and a real MP3 from lamejs that the PHP
// scanner reads back (length, bit rate, copied tags). Then mastering (assets/master-core.js)
// against the response Audition measured with the station's chain, and levelling switched off.
//   node app/tests/prep_test.mjs        (SW_PHP = path to php.exe if php isn't on PATH)
import { createRequire } from "node:module";
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { tmpdir } from "node:os";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const here = dirname(fileURLToPath(import.meta.url));
const core = createRequire(import.meta.url)("../assets/prep-core.js");
const php = process.env.SW_PHP || "php";
let failed = 0, passed = 0;
const check = (label, ok, detail = "") => {
  ok ? passed++ : failed++;
  console.log(`  ${ok ? "ok  " : "FAIL"} ${label}${detail !== "" ? `  (${detail})` : ""}`);
};
const near = (a, b, tol) => a !== null && Math.abs(a - b) <= tol;

// Stereo (or mono) 1 kHz-ish sine pieces: [[dBFS or null for silence, seconds], ...]
function tone(parts, rate, { freq = 997, mono = false } = {}) {
  const n = parts.reduce((s, [, sec]) => s + Math.round(sec * rate), 0);
  const x = new Float32Array(n);
  let i = 0;
  for (const [db, sec] of parts) {
    const amp = db === null ? 0 : Math.pow(10, db / 20);
    for (let k = 0, m = Math.round(sec * rate); k < m; k++, i++) x[i] = amp * Math.sin(2 * Math.PI * freq * i / rate);
  }
  return mono ? [x] : [x, x.slice()];
}

console.log("Loudness (EBU Tech 3341)");
const [shelf, hp] = core.kFilters(48000);
const pub = [1.53512485958697, -2.69169618940638, 1.19839281085285, -1.69065929318241, 0.73248077421585, -1.99004745483398, 0.99007225036621];
const got = [...shelf.b, ...shelf.a, ...hp.a];
check("K-weighting at 48 kHz matches BS.1770's published filters", got.every((v, i) => Math.abs(v - pub[i]) < 1e-8));
check("case 1: -23 dBFS sine reads -23.0 LUFS", near(core.loudness(tone([[-23, 20]], 48000), 48000), -23, 0.1), core.loudness(tone([[-23, 20]], 48000), 48000)?.toFixed(2));
check("case 2: -33 dBFS sine reads -33.0 LUFS", near(core.loudness(tone([[-33, 20]], 48000), 48000), -33, 0.1));
const c3 = core.loudness(tone([[-36, 10], [-23, 60], [-36, 10]], 48000), 48000);
check("case 3: the relative gate ignores quiet parts", near(c3, -23, 0.1), c3?.toFixed(2));
const c4 = core.loudness(tone([[-72, 10], [-36, 10], [-23, 60], [-36, 10], [-72, 10]], 48000), 48000);
check("case 4: the absolute gate too", near(c4, -23, 0.1), c4?.toFixed(2));
check("the same at 44.1 kHz", near(core.loudness(tone([[-23, 20]], 44100), 44100), -23, 0.1));
check("mono counts as both speakers", near(core.loudness(tone([[-23, 20]], 44100, { mono: true }), 44100), -23, 0.1));
check("silence and very short clips have no loudness", core.loudness(tone([[null, 5]], 44100), 44100) === null && core.loudness(tone([[-20, 0.2]], 44100), 44100) === null);

console.log("Trim, level, fade");
const R = 44100;
const span = core.soundSpan(tone([[null, 1], [-20, 2], [null, 1]], R), R);
check("silence at both ends is found, 50 ms kept", span && near(span[0] / R, 0.95, 0.002) && near(span[1] / R, 3.05, 0.002), span && `${(span[0] / R).toFixed(3)}–${(span[1] / R).toFixed(3)} s`);
let r = core.prepare(tone([[null, 1.5], [-26, 8], [null, 2]], R), R);
check("a quiet song comes up to -16 LUFS", near(r.info.lufs, -16, 0.05) && near(core.loudness(r.channels, R), -16, 0.15), JSON.stringify(r.info));
check("  with the silence trimmed", near(r.info.trimmed_start_s, 1.45, 0.01) && near(r.info.trimmed_end_s, 1.95, 0.01) && near(r.info.seconds, 8.1, 0.01));
check("  starting and ending at zero (no click)", r.channels[0][0] === 0 && r.channels[1][r.channels[1].length - 1] === 0);
r = core.prepare(tone([[-6, 8]], R), R);
check("a loud song comes down to -16 LUFS", near(core.loudness(r.channels, R), -16, 0.15) && r.info.gain_db < -9 && !r.info.capped, JSON.stringify(r.info));
const spiky = tone([[-30, 8]], R);
spiky[0][R * 4] = 0.9; spiky[1][R * 4] = 0.9;
r = core.prepare(spiky, R);
check("a loud click stops the gain at -1 dBFS peak", r.info.capped && near(r.info.peak_db, -1, 0.01) && r.info.lufs < -16, JSON.stringify(r.info));
r = core.prepare(tone([[-45, 8]], R), R);
check("a very quiet recording gets at most +12 dB", r.info.capped && near(r.info.gain_db, 12, 0.001), JSON.stringify(r.info));
check("a silent file is left alone", core.prepare(tone([[null, 5]], R), R).skip !== undefined);
r = core.prepare(tone([[-20, 4]], R, { mono: true }), R);
check("mono comes out as stereo", r.channels.length === 2 && near(core.loudness(r.channels, R), -16, 0.15));
const tail = core.outro(tone([[-23, 10], [null, 5]], 48000), 48000);
check("the last minute is measured second by second, silence as -99", tail.length === 15 && tail.slice(0, 10).every((v) => near(v, -23, 0.2)) && tail.slice(10).every((v) => v === -99), JSON.stringify(tail));
check("  a long song gives 60 values", core.outro(tone([[-23, 70]], 44100), 44100).length === core.P.OUTRO_S);
r = core.prepare(tone([[-26, 8]], R), R);
check("  and song prep keeps them, levelled like the song", r.info.outro.length === 8 && r.info.outro.every((v) => near(v, -16, 0.3)), JSON.stringify(r.info.outro));

console.log("Tags and MP3");
const enc = new TextEncoder();
const frame = (id, text) => { const t = enc.encode("\u0003" + text); const h = new Uint8Array(10 + t.length); h.set(enc.encode(id)); new DataView(h.buffer).setUint32(4, t.length); h.set(t, 10); return h; };
const body = [frame("TIT2", "Prep Test Hymn"), frame("TPE1", "Test Choir")];
const size = body.reduce((s, b) => s + b.length, 0);
const id3 = new Uint8Array(10 + size);
id3.set(enc.encode("ID3")); id3[3] = 4; id3[6] = (size >> 21) & 0x7f; id3[7] = (size >> 14) & 0x7f; id3[8] = (size >> 7) & 0x7f; id3[9] = size & 0x7f;
let o = 10; for (const b of body) { id3.set(b, o); o += b.length; }
const v1 = new Uint8Array(128); v1.set(enc.encode("TAGOld title"));
const original = new Uint8Array(id3.length + 500 + 128);
original.set(id3); original.set(v1, id3.length + 500);
const t = core.tags(original);
check("the ID3v2 tag at the start is found whole", t.head.length === id3.length && t.head.every((v, i) => v === id3[i]));
check("and the ID3v1 tag at the end", t.tail.length === 128 && t.tail[0] === 0x54);
check("a file without tags has none", core.tags(new Uint8Array(1000)).head.length === 0 && core.tags(new Uint8Array(1000)).tail.length === 0);

const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(readFileSync(join(here, "../assets/vendor/lame.min.js"), "utf8") + "\nthis.lamejs = lamejs;", sandbox);
r = core.prepare(tone([[null, 1], [-20, 12], [null, 1]], R, { freq: 440 }), R);
let progress = 0;
const chunks = core.encode(sandbox.lamejs, r.channels, R, 192, (p) => { progress = p; });
const dir = mkdtempSync(join(tmpdir(), "sw-prep-"));
try {
  const file = join(dir, "prepared.mp3");
  writeFileSync(file, Buffer.concat([t.head, ...chunks, t.tail].map((c) => Buffer.from(c))));
  const script = `require ${JSON.stringify(join(here, "../src/bootstrap.php"))}; $s = Stationwagon\\Mp3::scan($argv[1], 6.0); echo json_encode(['frames' => $s['frames'], 'duration' => $s['duration'], 'bitrate' => $s['bitrate'], 'rate' => $s['sample_rate'], 'tags' => Stationwagon\\Mp3::tags($argv[1])]);`;
  const scan = JSON.parse(execFileSync(php, ["-r", script, file], { encoding: "utf8" }));
  check("lamejs makes an MP3 the station can play", scan.frames > 0 && scan.bitrate === 192 && scan.rate === 44100, JSON.stringify({ ...scan, tags: undefined }));
  check("  as long as the trimmed song", near(scan.duration, r.info.seconds, 0.08), `${scan.duration} s vs ${r.info.seconds} s`);
  check("  with the original title and artist", scan.tags.title === "Prep Test Hymn" && scan.tags.artist === "Test Choir", JSON.stringify(scan.tags));
  check("encoding reports progress up to 100 %", progress === 1);
} finally {
  rmSync(dir, { recursive: true, force: true });
}

// ---- Mastering (master-core.js) against what Audition did with the station's chain ----------
// Audition's own numbers, measured from its exports of the test signals (tools/mastering-signals.mjs):
// the gain (dB) the multiband step gave a steady tone at each level (dBFS RMS), and the gain of
// the whole chain on quiet tones.
console.log("Mastering, against Audition's measured response");
const master = createRequire(import.meta.url)("../assets/master-core.js");
const MR = 44100;
const rmsDb = (x, from, to) => { let s = 0; for (let i = from; i < to; i++) s += x[i] * x[i]; return 10 * Math.log10(s / (to - from)); };
function sine(hz, db, sec = 1.5, side = false) {
  const n = Math.round(sec * MR), l = new Float32Array(n), r = new Float32Array(n), a = Math.pow(10, db / 20) * Math.SQRT2;
  for (let i = 0; i < n; i++) { l[i] = a * Math.sin(2 * Math.PI * hz * i / MR); r[i] = side ? -l[i] : l[i]; }
  return [l, r];
}
const gainOf = (fn, x) => { const y = fn(x, MR); return rmsDb(y[0], MR / 2, x[0].length) - rmsDb(x[0], MR / 2, x[0].length); };
const audition = {
  60: [[-36, 5.9], [-21, 4.8], [-18, 3.0], [-15, 1.2], [-12, -0.6]],
  400: [[-36, 4.9], [-18, 3.7], [-15, 2.2], [-12, 0.8], [-9, -0.7]],
  3000: [[-36, 5.0], [-24, 3.3], [-21, 1.8], [-18, 0.4], [-15, -1.0], [-12, -2.3]],
  12000: [[-36, 7.8], [-24, 5.5], [-21, 3.6], [-18, 1.8], [-15, -0.1], [-12, -1.9]],
};
for (const [hz, points] of Object.entries(audition)) {
  const diffs = points.map(([db, want]) => gainOf(master.multiband, sine(Number(hz), db)) - want);
  check(`multiband at ${hz} Hz: within 1 dB of Audition at every level`, diffs.every((d) => Math.abs(d) <= 1), diffs.map((d) => (d >= 0 ? "+" : "") + d.toFixed(2)).join(" "));
}
const whole = { 60: 9.8, 400: 8.8, 3000: 9.1, 12000: 13.0 };
const wd = Object.entries(whole).map(([hz, want]) => gainOf(master.master, sine(Number(hz), -36)) - want);
check("the whole chain on quiet tones: within 0.3 dB of Audition", wd.every((d) => Math.abs(d) <= 0.3), wd.map((d) => (d >= 0 ? "+" : "") + d.toFixed(2)).join(" "));
// the widener brightens the sides only: Audition gave the sides +4.3 dB over the middle above 3 kHz, none below 1 kHz
const sideLow = gainOf(master.mastering, sine(200, -30, 1.5, true)) - gainOf(master.mastering, sine(200, -30));
const sideHigh = gainOf(master.mastering, sine(8000, -30, 1.5, true)) - gainOf(master.mastering, sine(8000, -30));
check("the widener lifts the sides by about 4.3 dB in the highs and leaves the lows", near(sideHigh, 4.3, 0.3) && Math.abs(sideLow) < 0.3, `8 kHz ${sideHigh.toFixed(2)} dB, 200 Hz ${sideLow.toFixed(2)} dB`);
{
  // the band split adds back up exactly, with no delay or phase shift
  const n = MR * 3, l = new Float32Array(n), r = new Float32Array(n);
  let seed = 7; const rnd = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 2 ** 32) * 2 - 1;
  for (let i = 0; i < n; i++) { l[i] = rnd() * 0.3; r[i] = rnd() * 0.3; }
  const [L, R2] = master.split(l, r, MR);
  let err = 0;
  for (let i = 0; i < n; i++) err = Math.max(err, Math.abs(L[0][i] + L[1][i] + L[2][i] + L[3][i] - l[i]), Math.abs(R2[0][i] + R2[1][i] + R2[2][i] + R2[3][i] - r[i]));
  check("the four bands add back up to the input exactly (linear phase, as Audition)", err < 1e-5, err.toExponential(1));
  let lowOnly = 0; const lowTone = sine(60, -20, 3);
  const [LL] = master.split(lowTone[0], lowTone[1], MR);
  const share = 1 / (1 + Math.pow(60 / 137, 4)); // the crossover's slope: 96.4 % of a 60 Hz tone is in the low band
  for (let i = MR; i < 2 * MR; i++) lowOnly = Math.max(lowOnly, Math.abs(LL[0][i] - share * lowTone[0][i]));
  check("  a 60 Hz tone's low-band part is in phase with it, at the crossover's share", lowOnly < 1e-3, lowOnly.toExponential(1));
  // loud noise: no peak above the maximizer's ceiling (−0.87 dBFS)
  for (let i = 0; i < n; i++) { l[i] = rnd() * 0.9; r[i] = rnd() * 0.9; }
  let pk = 0, last = 0;
  const y = master.master([l, r], MR, (p) => { last = p; });
  for (const c of y) for (let i = 0; i < n; i++) pk = Math.max(pk, Math.abs(c[i]));
  check("loud music never peaks above −0.87 dBFS", 20 * Math.log10(pk) <= -0.869, (20 * Math.log10(pk)).toFixed(3) + " dBFS");
  check("  and mastering reports progress up to 100 %", last === 1);
  const silent = master.master([new Float32Array(MR), new Float32Array(MR)], MR);
  check("silence stays silent", silent.every((c) => c.every((v) => Math.abs(v) < 1e-9)));
  const mono = master.master([sine(440, -20)[0]], MR);
  check("a mono song comes out as stereo with both sides the same", mono.length === 2 && mono[0].every((v, i) => v === mono[1][i]));
}
r = core.prepare(tone([[null, 1], [-20, 12], [null, 1]], R, { freq: 440 }), R, { level: false });
check("loudness normalization off: the song keeps its level", r.info.gain_db === 0 && r.info.levelled === false && near(r.info.lufs, r.info.lufs_before, 0.001), `${r.info.lufs} LUFS`);
check("  and is still trimmed", near(r.info.seconds, 12.1, 0.02), `${r.info.seconds} s`);

console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
