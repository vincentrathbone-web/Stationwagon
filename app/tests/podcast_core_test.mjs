// Node test of podcast-core.js: the analyser (peaks, and clips split at every 2 s silence), the
// mixdown plan (cut clips muted on their own track, long empty stretches shortened), the mute
// ramps, MP3 frame finding and the chunk reader's overlap (with a stand-in decoder).
//   node app/tests/podcast_core_test.mjs
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const src = readFileSync(fileURLToPath(new URL("../assets/podcast-core.js", import.meta.url)), "utf8");
const ctx = { globalThis: {}, btoa, atob, Uint8Array, Float32Array, Math, Map, Set, Array, Object, String };
ctx.self = ctx.globalThis;
vm.runInNewContext(src, ctx);
const P = ctx.globalThis.SwPodcast;
let failed = 0;
const check = (what, ok, detail = "") => { console.log(`  ${ok ? "ok  " : "FAIL"} ${what}${detail ? "  (" + detail + ")" : ""}`); if (!ok) failed++; };
const R = 44100;

// a signal: [from, to, level] stretches of a 440 Hz tone, silence elsewhere
const signal = (seconds, parts) => {
  const x = new Float32Array(Math.round(seconds * R));
  for (const [a, b, lv] of parts) for (let i = Math.round(a * R); i < Math.round(b * R); i++) x[i] = lv * Math.sin(2 * Math.PI * 440 * i / R);
  return x;
};
const feed = (an, x, block = 4096) => { for (let i = 0; i < x.length; i += block) an.push(x.subarray(i, i + block)); };

console.log("The analyser");
{
  const an = new P.Analyzer(R);
  // talk 1–4 s, a 1 s pause, 5–8 s, then 3 s of silence, 11–12 s, a whisper at −60 dB, and loud 14–15 s
  feed(an, signal(16, [[1, 4, 0.5], [5, 8, 0.5], [11, 12, 0.3], [12.5, 13.5, 0.001], [14, 15, 0.9]]));
  const r = an.finish();
  check("one peak per 0.1 s", r.peaks.length === 160, String(r.peaks.length));
  check("  silence is 0, a loud tone near the top", r.peaks[5] === 0 && r.peaks[145] > 240 && r.peaks[25] > 200, `${r.peaks[5]} ${r.peaks[145]} ${r.peaks[25]}`);
  const c = r.clips.map(([a, b]) => [Math.round(a * 10) / 10, Math.round(b * 10) / 10]);
  check("a pause under 2 s keeps one clip; 2 s or more splits", JSON.stringify(c) === JSON.stringify([[1, 8], [11, 12], [14, 15]]), JSON.stringify(c));
  check("  quieter than −50 dB counts as silence", !r.clips.some(([a]) => a > 12.2 && a < 13.9));
  const half = new P.Analyzer(R);
  feed(half, signal(16, [[1, 4, 0.5], [5, 8, 0.5], [11, 12, 0.3]]));
  const snap = half.result(Math.round(6 * R));
  check("a snapshot cut at 6 s ends the open clip there", snap.peaks.length === 60 && snap.clips.length === 1 && snap.clips[0][1] === 6, JSON.stringify(snap.clips));
  const none = new P.Analyzer(R); feed(none, new Float32Array(R * 3));
  check("silence alone has no clips", none.finish().clips.length === 0);
}

console.log("The mixdown plan");
{
  const take = { id: 7, duration: 60, tracks: { music: [], mic: [] }, analysis: { tracks: {
    music: { clips: [[0, 10], [20, 30], [45, 58]] },
    mic: { clips: [[9, 21], [31, 32], [40, 44]] },
  } } };
  const all = P.planTake(take, new Set());
  check("nothing cut: short pauses stay, the 8 s gap at 32–40 s shrinks", JSON.stringify(all.segments) === JSON.stringify([[0, 32.5], [39.5, 58.5]]), JSON.stringify(all.segments));
  const cut = P.planTake(take, new Set(["7:music:1", "7:mic:1"]));
  // kept: music 0–10, 45–58; mic 9–21, 40–44 → union 0–21, 40–44, 45–58
  check("cut clips leave gaps; over 2 s they shrink to 1 s", JSON.stringify(cut.segments) === JSON.stringify([[0, 21.5], [39.5, 58.5]]), JSON.stringify(cut.segments));
  check("  and are muted on their own track only", JSON.stringify(cut.muted) === JSON.stringify({ music: [[20, 30]], mic: [[31, 32]] }));
  check("  the episode's length", Math.abs(P.planLength([take], new Set(["7:music:1", "7:mic:1"])) - 40.5) < 1e-9);
  const ends = P.planTake({ ...take, duration: 58.2 }, new Set());
  check("a stretch never runs past the take's end", ends.segments[1][1] === 58.2);
  check("everything cut: nothing left", P.planTake(take, new Set(["7:music:0", "7:music:1", "7:music:2", "7:mic:0", "7:mic:1", "7:mic:2"])).segments.length === 0);
  check("a take with no clips adds nothing", P.planTake({ id: 1, duration: 9, tracks: { aired: [] }, analysis: { tracks: { aired: { clips: [] } } } }, new Set()).segments.length === 0);
  check("cut ids are per take", P.planTake(take, new Set(["8:music:1"])).muted.music.length === 0);
}

console.log("Mute ramps");
{
  const m = [[10, 20]];
  check("full outside, silent inside", P.maskAt(m, 5) === 1 && P.maskAt(m, 15) === 0 && P.maskAt(m, 25) === 1);
  check("  10 ms ramps at the edges", Math.abs(P.maskAt(m, 9.995) - 0.5) < 1e-6 && Math.abs(P.maskAt(m, 20.005) - 0.5) < 1e-6 && P.maskAt(m, 9.98) === 1);
}

console.log("MP3 frames");
{
  const frame = new Uint8Array(417); frame.set([0xff, 0xfb, 0x90, 0x00]); // 128 kbps, 44.1 kHz
  const pad = new Uint8Array(418); pad.set([0xff, 0xfb, 0x92, 0x00]);       // the same with padding
  const bytes = new Uint8Array(3 + 417 + 418 + 417 + 100);
  bytes.set([1, 2, 3]); bytes.set(frame, 3); bytes.set(pad, 420); bytes.set(frame, 838);
  check("whole frames found after junk, padding counted, a cut-off one left out", JSON.stringify(P.frameStarts(bytes)) === JSON.stringify([3, 420, 838]), JSON.stringify(P.frameStarts(bytes)));
}

console.log("The chunk reader");
{
  // a stand-in for MP3: each "frame" is 4 bytes [0xff, 0xfb, 0x90, k]... decoding gives 1152
  // samples of value k (so we can see which frame each output sample came from)
  const F = 1152, frameLen = 417;
  const mk = (from, n) => { const b = new Uint8Array(n * frameLen); for (let i = 0; i < n; i++) b.set([0xff, 0xfb, 0x90, 0x00, from + i], i * frameLen); return b; };
  const chunks = [{ n: 0, samples: 20 * F }, { n: 1, samples: 20 * F }, { n: 2, samples: 7 * F }];
  const data = [mk(0, 20), mk(20, 20), mk(40, 7)];
  let fetched = 0;
  const decode = async (u8) => {
    const starts = P.frameStarts(u8), out = new Float32Array(starts.length * F);
    starts.forEach((s, i) => out.fill(u8[s + 4], i * F, (i + 1) * F));
    return { numberOfChannels: 1, length: out.length, getChannelData: () => out };
  };
  const r = new P.ChunkReader(chunks, async (n) => { fetched++; return data[n]; }, decode, 2, 0);
  check("its length is the chunks' samples", r.length === 47 * F);
  const [l, rr] = await r.read(19 * F + 5, 2 * F);
  check("a read across a join is seamless (frame 19 then 20)", l[0] === 19 && l[F - 6] === 19 && l[F - 5] === 20 && l[2 * F - 1] === 21 && rr[0] === 19, `${l[0]} ${l[F - 5]} ${l[2 * F - 1]}`);
  const [tail] = await r.read(46 * F, 3 * F);
  check("  the last chunk too; past the end reads silence", tail[0] === 46 && tail[F - 1] === 46 && tail[F] === 0);
  check("  each chunk fetched once, going forward", fetched === 3, String(fetched));
  const d = new P.ChunkReader(chunks, async (n) => data[n], decode, 1, F + 7);
  const [dl] = await d.read(0, F);
  check("the codec's delay is skipped: read(0) is sample F + 7 of the stream", d.length === 46 * F - 7 && dl[0] === 1 && dl[F - 8] === 1 && dl[F - 7] === 2, `${dl[0]} ${dl[F - 7]}`);
  check("  lamejs plus a decoder: 1105 samples", P.CODEC_DELAY === 1105);
}

console.log(failed ? `\n${failed} check(s) failed` : "\nAll checks passed");
process.exit(failed ? 1 : 0);
