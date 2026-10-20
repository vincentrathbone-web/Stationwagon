// Test of the song prep maths in assets/prep-core.js: loudness against the EBU Tech 3341 test
// cases, silence trim, levelling and its caps, fades, and a real MP3 from lamejs that the PHP
// scanner reads back (length, bit rate, copied tags).
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

console.log(failed ? `\n${failed} of ${passed + failed} checks failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
