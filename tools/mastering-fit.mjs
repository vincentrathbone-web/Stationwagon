// Run the mastering model (app/assets/master-core.js) over the dry export from Audition and
// write what it makes, to compare with Audition's own exports (mastering-ref/, gitignored).
//   node tools/mastering-fit.mjs <stage: multiband|mastering|both> <out.f32> [P overrides as JSON]
// (mastering runs on Audition's own multiband export, to fit that step on its own)
// The output is raw interleaved stereo float32 at 44.1 kHz, the same length as dry.wav.
import { readFileSync, writeFileSync } from "node:fs";
import { createRequire } from "node:module";
const require = createRequire(import.meta.url);
const M = require("../app/assets/master-core.js");

const [stage = "both", out = "mastering-ref/model.f32", over = "{}"] = process.argv.slice(2);
Object.assign(M.P, JSON.parse(over));

function readWav(path) {
  const b = readFileSync(path);
  let o = 12, fmt = null;
  while (o < b.length) {
    const id = b.toString("ascii", o, o + 4), size = b.readUInt32LE(o + 4);
    if (id === "fmt ") fmt = { ch: b.readUInt16LE(o + 10), rate: b.readUInt32LE(o + 12), bits: b.readUInt16LE(o + 22), tag: b.readUInt16LE(o + 8) };
    if (id === "data") {
      const bytes = fmt.bits / 8, n = Math.floor(size / (bytes * fmt.ch)), ch = [];
      for (let c = 0; c < fmt.ch; c++) ch.push(new Float32Array(n));
      for (let i = 0; i < n; i++) for (let c = 0; c < fmt.ch; c++) {
        const at = o + 8 + (i * fmt.ch + c) * bytes;
        ch[c][i] = fmt.tag === 3 ? b.readFloatLE(at) : bytes === 3 ? b.readIntLE(at, 3) / 8388608 : b.readInt16LE(at) / 32768;
      }
      return { rate: fmt.rate, ch };
    }
    o += 8 + size + (size & 1);
  }
  throw new Error("no data in " + path);
}

const t0 = Date.now();
const { rate, ch } = readWav(stage === "mastering" ? "mastering-ref/mutiband.wav" : "mastering-ref/dry.wav");
const t1 = Date.now();
const [l, r] = stage === "multiband" ? M.multiband(ch, rate) : stage === "mastering" ? M.mastering(ch, rate) : M.master(ch, rate);
const t2 = Date.now();
const o = new Float32Array(l.length * 2);
for (let i = 0; i < l.length; i++) { o[2 * i] = l[i]; o[2 * i + 1] = r[i]; }
writeFileSync(out, Buffer.from(o.buffer));
console.log(`${stage}: ${(l.length / rate).toFixed(1)} s of audio in ${((t2 - t1) / 1000).toFixed(1)} s (read ${((t1 - t0) / 1000).toFixed(1)} s)`);
