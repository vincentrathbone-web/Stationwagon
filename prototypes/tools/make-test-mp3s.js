// Generates test MP3s: a steady tone per track, a tick every second and a beep every 10 s,
// so sync between devices and song changes are easy to hear. Usage: node make-test-mp3s.js <lame.min.js> <outDir>
const fs = require("fs"), vm = require("vm"), path = require("path");
const [lamePath, outDir] = process.argv.slice(2);
const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(lamePath, "utf8") + "\nthis.lamejs = lamejs;", sandbox);
const lamejs = sandbox.lamejs;

const RATE = 44100;
const tracks = [
  { file: "Test_Tone_A_440Hz.mp3", freq: 440, seconds: 45, id3: "Test Tone A (440 Hz)" },
  { file: "Test_Tone_C_523Hz.mp3", freq: 523.25, seconds: 50 },
  { file: "Test_Tone_E_659Hz.mp3", freq: 659.25, seconds: 40 },
  { file: "Test_Tone_G_784Hz.mp3", freq: 783.99, seconds: 35 },
];

function id3(title) {
  const text = Buffer.from("\u0003" + title, "utf8");           // encoding byte + text
  const frame = Buffer.concat([Buffer.from("TIT2"), u32(text.length), Buffer.from([0, 0]), text]);
  const size = frame.length;
  const syncsafe = Buffer.from([(size >> 21) & 0x7f, (size >> 14) & 0x7f, (size >> 7) & 0x7f, size & 0x7f]);
  return Buffer.concat([Buffer.from("ID3"), Buffer.from([4, 0, 0]), syncsafe, frame]);
}
function u32(n) { const b = Buffer.alloc(4); b.writeUInt32BE(n); return b; }

fs.mkdirSync(outDir, { recursive: true });
for (const t of tracks) {
  const n = t.seconds * RATE;
  const L = new Int16Array(n), R = new Int16Array(n);
  for (let i = 0; i < n; i++) {
    const s = i / RATE;
    const fade = Math.min(1, s / 1, (t.seconds - s) / 1);
    let v = 0.25 * Math.sin(2 * Math.PI * t.freq * s) * fade;
    const inSec = s % 1, inTen = s % 10;
    if (inSec < 0.03) v += 0.5 * Math.sin(2 * Math.PI * 1800 * s) * (1 - inSec / 0.03);            // tick
    if (inTen < 0.25) v += 0.35 * Math.sin(2 * Math.PI * 1200 * s) * Math.sin(Math.PI * inTen / 0.25); // beep
    const c = Math.max(-1, Math.min(1, v));
    L[i] = c * 32767; R[i] = c * 32767 * 0.9;
  }
  const enc = new lamejs.Mp3Encoder(2, RATE, 128);
  const parts = [];
  for (let i = 0; i < n; i += 1152 * 10) {
    const b = enc.encodeBuffer(L.subarray(i, i + 1152 * 10), R.subarray(i, i + 1152 * 10));
    if (b.length) parts.push(Buffer.from(b.buffer, b.byteOffset, b.length));
  }
  const tail = enc.flush();
  if (tail.length) parts.push(Buffer.from(tail.buffer, tail.byteOffset, tail.length));
  const out = Buffer.concat([t.id3 ? id3(t.id3) : Buffer.alloc(0), ...parts]);
  fs.writeFileSync(path.join(outDir, t.file), out);
  console.log(`${t.file}: ${t.seconds}s, ${out.length} bytes`);
}
