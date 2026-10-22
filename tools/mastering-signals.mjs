// Test signals for matching the Audition mastering chain: a WAV to put at the start of the
// Audition session, before the songs, and a JSON of where each part sits (for the analysis).
// node tools/mastering-signals.mjs [folder]   (default: mastering-ref/)
import { writeFileSync, mkdirSync } from "node:fs";
import { join } from "node:path";

const RATE = 44100;
const PK = 0.1948; // the pink noise's own RMS, so its level is its RMS in dBFS
const out = process.argv[2] || "mastering-ref";
const L = [], R = [], parts = [];
let seed = 1;
const rnd = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 2 ** 32) * 2 - 1;
const db = (d) => 10 ** (d / 20);
const now = () => L.length / RATE;

function silence(sec) {
  for (let i = 0; i < Math.round(sec * RATE); i++) { L.push(0); R.push(0); }
}
// fade 10 ms in and out so the tones don't click
function add(name, sec, fn, info = {}) {
  const n = Math.round(sec * RATE), f = Math.round(0.01 * RATE), at = now();
  for (let i = 0; i < n; i++) {
    const g = Math.min(1, i / f, (n - 1 - i) / f);
    const [l, r] = fn(i, i / RATE);
    L.push(l * g); R.push(r * g);
  }
  parts.push({ name, at: +at.toFixed(4), sec, ...info });
}
// pink noise (Paul Kellet's filter), scaled to about the given RMS
function pink() {
  let b0 = 0, b1 = 0, b2 = 0, b3 = 0, b4 = 0, b5 = 0, b6 = 0;
  return () => {
    const w = rnd();
    b0 = 0.99886 * b0 + w * 0.0555179; b1 = 0.99332 * b1 + w * 0.0750759; b2 = 0.969 * b2 + w * 0.153852;
    b3 = 0.8665 * b3 + w * 0.3104856; b4 = 0.55 * b4 + w * 0.5329522; b5 = -0.7616 * b5 - w * 0.016898;
    const p = b0 + b1 + b2 + b3 + b4 + b5 + b6 + w * 0.5362; b6 = w * 0.115926;
    return p * 0.11;
  };
}
const sine = (hz, d) => (i, t) => { const v = Math.sin(2 * Math.PI * hz * t) * db(d) * Math.SQRT2; return [v, v]; };

silence(1);
// a one-sample click on both sides: lines the files up to the sample
parts.push({ name: "click", at: +now().toFixed(4), sec: 0 }); L.push(0.5); R.push(0.5);
silence(1);

// 1. a quiet sweep, 20 Hz to 20 kHz: below every threshold, so it shows the fixed tone changes
add("sweep -30", 12, (i, t) => {
  const f0 = 20, f1 = 20000, T = 12, k = Math.log(f1 / f0);
  const v = Math.sin(2 * Math.PI * f0 * T / k * (Math.exp(t / T * k) - 1)) * db(-30) * Math.SQRT2;
  return [v, v];
}, { from: 20, to: 20000, level: -30 });
silence(1);

// 2. a tone in each band, in 3 dB steps (levels are RMS in dBFS): how hard each band squeezes at each level
for (const hz of [60, 400, 3000, 12000]) {
  for (let d = -45; d <= -6; d += 3) add(`tone ${hz} ${d}`, 1, sine(hz, d), { hz, level: d });
  silence(1);
}

// 3. pink noise in 6 dB steps: the whole chain on a full-range sound
for (let d = -42; d <= -18; d += 6) { const p = pink(); add(`pink ${d}`, 2, () => { const v = p() * db(d) / PK; return [v, v]; }, { level: d }); }
silence(1);

// 4. a jump from quiet to loud and back in each band: how fast it squeezes and lets go
for (const hz of [60, 400, 3000, 12000]) {
  add(`jump ${hz}`, 4, (i, t) => { const d = t >= 1 && t < 2 ? -6 : -36; const v = Math.sin(2 * Math.PI * hz * t) * db(d) * Math.SQRT2; return [v, v]; }, { hz, quiet: -36, loud: -6, loudFrom: 1, loudTo: 2 });
  silence(1);
}

// 5. stereo: the same noise in the middle only, then on the sides only (left = minus right)
{ const p = pink(); add("mid only -24", 3, () => { const v = p() * db(-24) / PK; return [v, v]; }, { level: -24 }); }
silence(0.5);
{ const p = pink(); add("side only -24", 3, () => { const v = p() * db(-24) / PK; return [v, -v]; }, { level: -24 }); }
silence(0.5);
{ const p = pink(), q = pink(); add("left only -24", 3, () => [p() * db(-24) / PK, 0], { level: -24 }); q(); }
silence(2);

// 24-bit stereo WAV
const n = L.length, data = Buffer.alloc(n * 6);
for (let i = 0; i < n; i++) for (const [c, s] of [[0, L[i]], [1, R[i]]]) {
  const v = Math.max(-8388608, Math.min(8388607, Math.round(s * 8388607)));
  data.writeIntLE(v, i * 6 + c * 3, 3);
}
const head = Buffer.alloc(44);
head.write("RIFF", 0); head.writeUInt32LE(36 + data.length, 4); head.write("WAVEfmt ", 8);
head.writeUInt32LE(16, 16); head.writeUInt16LE(1, 20); head.writeUInt16LE(2, 22); head.writeUInt32LE(RATE, 24);
head.writeUInt32LE(RATE * 6, 28); head.writeUInt16LE(6, 32); head.writeUInt16LE(24, 34);
head.write("data", 36); head.writeUInt32LE(data.length, 40);
mkdirSync(out, { recursive: true });
writeFileSync(join(out, "00 Test signals.wav"), Buffer.concat([head, data]));
writeFileSync(join(out, "00 Test signals.json"), JSON.stringify({ rate: RATE, seconds: +(n / RATE).toFixed(3), parts }, null, 1));
console.log(`${join(out, "00 Test signals.wav")}: ${(n / RATE).toFixed(1)} s, ${parts.length} parts`);
