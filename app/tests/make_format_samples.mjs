// Makes the upload test's samples in every format the Music library takes, from the lossless test music
// (pdp8/01-amazon.flac, 30 s from 1:00) with the test-only ffmpeg (tools/build-ffmpeg-cli-wasm.sh) and a
// cover (formats/cover.jpg, any JPEG over 100 KB). Skips files already made. The Ogg ones need
// "-fflags +bitexact": without it the Ogg writer waits forever for a random seed in this build.
//   node app/tests/make_format_samples.mjs [audio folder]   (default D:/Dev/toolshed/audio, or SW_AUDIO)
// SW_FFMPEG_CLI: the tool's ffmpeg.js (default D:/Dev/toolshed/ffmpeg-cli/ffmpeg.js).
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, statSync } from "node:fs";
import { join } from "node:path";

export const SAMPLES = [
  // name, what it is, ffmpeg output options (tags and cover where the format keeps them; a WAV's tags
  // (RIFF INFO) have no songwriter field)
  { file: "wav16.wav", kind: "WAV", cover: false, writers: false, args: ["-c:a", "pcm_s16le"] },
  { file: "wav24-96k.wav", kind: "WAV", cover: false, writers: false, rate: 96000, args: ["-af", "aresample=96000", "-c:a", "pcm_s24le"] },
  { file: "flac24.flac", kind: "FLAC", cover: true, args: ["-c:a", "flac"] },
  { file: "aac.m4a", kind: "M4A", cover: true, args: ["-c:a", "aac", "-b:a", "256k"] },
  { file: "alac.m4a", kind: "M4A", cover: false, args: ["-c:a", "alac"] },
  { file: "vorbis.ogg", kind: "Ogg", cover: false, args: ["-c:a", "vorbis", "-strict", "-2", "-b:a", "192k", "-fflags", "+bitexact"] },
  { file: "opus.opus", kind: "Opus", cover: false, rate: 48000, args: ["-af", "aresample=48000", "-c:a", "opus", "-strict", "-2", "-b:a", "160k", "-fflags", "+bitexact"] },
  { file: "wma2.wma", kind: "WMA", cover: false, args: ["-c:a", "wmav2", "-b:a", "192k"] },
];
export const TAGS = { title: "Amazon (format test)", artist: "pdp8", album: "calling home", composer: "Songwriter One" };

export function makeSamples(audio = process.env.SW_AUDIO || "D:/Dev/toolshed/audio") {
  const cli = process.env.SW_FFMPEG_CLI || "D:/Dev/toolshed/ffmpeg-cli/ffmpeg.js";
  const dir = join(audio, "formats");
  mkdirSync(dir, { recursive: true });
  const src = join(audio, "pdp8", "01-amazon.flac"), cover = join(dir, "cover.jpg");
  if (!existsSync(src)) throw new Error(`No ${src}: see HANDOVER.md "Dev environment"`);
  for (const s of SAMPLES) {
    const out = join(dir, s.file);
    if (existsSync(out) && statSync(out).size > 0) continue;
    if (!existsSync(cli)) throw new Error(`No test ffmpeg at ${cli}: bash tools/build-ffmpeg-cli-wasm.sh`);
    const meta = Object.entries(TAGS).flatMap(([k, v]) => ["-metadata", `${k}=${v}`]);
    const pic = s.cover && existsSync(cover) ? ["-i", "formats/cover.jpg", "-map", "0:a", "-map", "1", "-c:v", "copy", "-disposition:v", "attached_pic"] : [];
    // relative paths: the tool reads them from its working folder
    execFileSync(process.execPath, [cli, "-hide_banner", "-loglevel", "error", "-y", "-ss", "60", "-t", "30", "-i", "pdp8/01-amazon.flac", ...pic, ...s.args, ...meta, `formats/${s.file}`],
      { cwd: audio, stdio: ["ignore", "ignore", "inherit"] });
  }
  return dir;
}

if (process.argv[1] && process.argv[1].endsWith("make_format_samples.mjs")) {
  const dir = makeSamples(process.argv[2]);
  for (const s of SAMPLES) console.log(s.file.padEnd(16), statSync(join(dir, s.file)).size);
}
