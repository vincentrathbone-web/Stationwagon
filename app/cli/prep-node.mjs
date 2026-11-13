// Song prep on the server (round 85, v1.13): one uploaded file prepared by Node with the very chain the browser uses
// (assets/prep-run.js with prep-core.js, master-core.js, LAME 3.100 and the station's FFmpeg decoder, all WebAssembly),
// so a song comes out the same whichever did it. The PHP queue (src/PrepQueue.php) runs it once per upload:
//   node prep-node.mjs <job.json>
// job.json: { in: the raw upload, name: its file name, out: a folder for the results, progress: a file for progress,
//             opts: { kbps, master: false | {} | preset settings, level, target, quality: { rate, precision, dither, q } } }
// Writes in `out`: song.out (the MP3 the station plays; absent when the upload is used as it came), original.out (for an
// upload that isn't an MP3: the 320 kbps MP3 kept as its original) and result.json:
//   { prepared, kept?, refused?, note?, notice?, info?, sourceSha1, name, kind, song: bool, original: bool }
// What it does mirrors prep.js (the browser's side): the same limits, the same notes, the same tags. With "--check" it
// only says it can run: { ok, node, lame, decoder } (Sound & safety's setup test).
import { createRequire } from "node:module";
import { readFileSync, writeFileSync, renameSync } from "node:fs";
import { createHash } from "node:crypto";
import { basename, join, dirname } from "node:path";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const assets = join(dirname(fileURLToPath(import.meta.url)), "..", "assets");
const core = require(join(assets, "prep-core.js"));
const SwMaster = require(join(assets, "master-core.js"));
const SwPrepRun = require(join(assets, "prep-run.js"));
const SwLame = require(join(assets, "vendor", "lame-3.100.js"));
const SwDecode = require(join(assets, "vendor", "ffmpeg-decode-7.1.js"));

const MAX_BYTES = 60 * 1024 * 1024;   // as prep.js: an MP3 over this goes in as it came
const MAX_OTHER = 400 * 1024 * 1024;  // as prep.js: another format over this is refused
const NAMES = { wav: "WAV", flac: "FLAC", m4a: "M4A", ogg: "Ogg", wma: "WMA", aac: "AAC" };

let lameReady = null, decoderReady = null;
const lame = () => (lameReady = lameReady || SwLame());
const decoder = () => (decoderReady = decoderReady || SwDecode());
const sha1 = (bytes) => createHash("sha1").update(bytes).digest("hex");
const concat = (parts) => Buffer.concat(parts.map((p) => (Buffer.isBuffer(p) ? p : Buffer.from(p.buffer, p.byteOffset, p.byteLength))));
const writeJson = (path, value) => { writeFileSync(path + ".tmp", JSON.stringify(value)); renameSync(path + ".tmp", path); };

async function check() {
  const l = await lame(), d = await decoder();
  return { ok: true, node: process.versions.node, lame: l.UTF8ToString(l._sw_version()), decoder: d.UTF8ToString(d._sw_dec_version()) };
}

async function prepare(job) {
  const bytes = readFileSync(job.in);
  const name = job.name || basename(job.in);
  const opts = job.opts || {};
  let lastWrite = 0, lastStage = "";
  const post = (m) => {
    if (m.notice) { notice = m.notice; return; }
    if (!m.stage || !job.progress) return;
    const now = Date.now();
    if (m.stage === lastStage && now - lastWrite < 400 && m.progress !== 1) return;
    lastStage = m.stage; lastWrite = now;
    try { writeJson(job.progress, { stage: m.stage, progress: m.progress === undefined ? null : m.progress }); } catch (_) { /* progress is a nicety */ }
  };
  let notice = null;
  const env = { core, master: SwMaster, lame, decoder, post };
  const base = { kbps: opts.kbps || 224, master: opts.master && typeof opts.master === "object" ? opts.master : !!opts.master,
    level: opts.level !== false, target: opts.target, quality: opts.quality || {} };
  const kind = core.sniff(bytes.subarray(0, 65536), name);
  const out = (r) => Object.assign({ name, kind, song: false, original: false }, r, notice ? { notice } : {});
  if (!kind) return out({ prepared: false, refused: true, note: "not an audio file the station can read (MP3, WAV, FLAC, M4A, AAC, Ogg, Opus or WMA)" });

  if (kind !== "mp3") {
    // converted to MP3: the song, and the 320 kbps MP3 kept as its original, from one decode
    if (bytes.length > MAX_OTHER) return out({ prepared: false, refused: true, note: "too long to convert (over " + Math.round(MAX_OTHER / 1048576) + " MB)" });
    let r;
    try {
      r = await SwPrepRun.run(Object.assign({}, base, { file: bytes, convert: true }), env);
    } catch (ex) {
      return out({ prepared: false, refused: true, note: String((ex && ex.message) || ex) });
    }
    if (r.skip) return out({ prepared: false, refused: true, note: r.skip });
    const head = core.id3(r.tags, r.picture);
    const original = concat([head, ...r.original.chunks]);
    writeFileSync(join(job.out, "original.out"), original);
    writeFileSync(join(job.out, "song.out"), concat([core.levelTags(head, r.info), ...r.chunks]));
    r.info.converted.kind = kind === "ogg" && r.source.codec === "opus" ? "Opus" : NAMES[kind] || kind;
    return out({ prepared: true, info: r.info, sourceSha1: sha1(original), name: name.replace(/\.[^.\/]*$/, "") + ".mp3", song: true, original: true, tags: r.tags || {} });
  }

  // an MP3: prepared from its own sound, its tags carried over (as prep.js)
  const sourceSha1 = sha1(bytes);
  const asIs = (note) => out({ prepared: false, note, sourceSha1 });
  if (bytes.length > MAX_BYTES) return asIs("too long to prepare");
  const note = core.rateNote(core.mp3Rate(bytes) || 44100, (base.quality.rate ?? 44100));
  if (note) notice = note.charAt(0).toUpperCase() + note.slice(1);
  post({ stage: "Decoding" });
  let d;
  try {
    d = SwPrepRun.decodeFile(await decoder(), bytes);
  } catch (_) {
    return asIs("the station couldn’t read it as audio");
  }
  let r;
  try {
    r = await SwPrepRun.run(Object.assign({}, base, { channels: d.channels.slice(0, 2), rate: d.rate }), env);
  } catch (ex) {
    return asIs("prep failed: " + String((ex && ex.message) || ex));
  }
  if (r.skip) return asIs(r.skip);
  if (r.keep) return out({ prepared: true, kept: true, info: r.info, sourceSha1 });
  const tags = core.tags(bytes);
  writeFileSync(join(job.out, "song.out"), concat([core.levelTags(tags.head, r.info), ...r.chunks, tags.tail]));
  return out({ prepared: true, info: r.info, sourceSha1, song: true });
}

const arg = process.argv[2];
try {
  if (arg === "--check") {
    process.stdout.write(JSON.stringify(await check()) + "\n");
  } else {
    const job = JSON.parse(readFileSync(arg, "utf8"));
    const result = await prepare(job);
    writeJson(join(job.out, "result.json"), result);
    if (job.progress) try { writeJson(job.progress, { stage: "Done", progress: 1 }); } catch (_) { /* */ }
  }
  process.exit(0);
} catch (ex) {
  process.stderr.write(String((ex && ex.stack) || ex) + "\n");
  process.exit(1);
}
