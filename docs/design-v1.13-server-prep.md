# v1.13 Song prep on the server: design

Status: agreed with the user 2026-11-13 and built in round 85 (`0.9.20-dev`, schema 22; live on WordWave and Gansbaai 2026-11-13). Their choices:
- **Node is the one dependency.** LAME and the decoder ship inside the app's WebAssembly files. A station uses a
  Node the server already has; if there is none, **Sound & safety offers Set up: on an admin's click** the station
  downloads Node's official Linux build (x64 or arm64, checked against its pinned SHA-256) into its own
  `storage/runtime/`, no root needed, and test-runs it on a short song. If the host blocks it (programs may not run,
  `noexec`, memory or process limits, not Linux), it says why and the station keeps preparing in the browser.
- **vmcloud1 uses that same in-app setup** for WordWave and Gansbaai (no root changes on the shared server).
- **Music, Ads and Jingles** all go through the server when the station can.
- **Preparing in the browser stays as the automatic fallback.**
- **The queue starts at once** after an upload, with the background jobs and **a cron every minute** as the safety net.

## The problem

Songs are prepared in the browser (`prep.js`, `prep-worker.js`: mastering with the import preset,
levelling to the target LUFS, trimming, LAME 3.100; other formats converted with a 320 kbps MP3 original
kept). Closing or refreshing the Music library stops the rest of a bulk upload. Round 84 guards it
(Leave site?, the unfinished list); v1.13 moves the preparing to the server so it goes on without the page.

## What was found

- **vmcloud1** (Ubuntu 24.04, 3 cores, 24 GB, many clients): **no Node, no ffmpeg, no lame**. PHP-FPM 8.4
  per site allows `exec` / `proc_open` (only `system`, `passthru` and `pcntl_*` are off); uploads up to
  **100 MB** a request (PHP) and Cloudflare also caps a request at 100 MB; nginx 1 GB. Gansbaai has a cron
  every 5 min (`cli/plan.php`); WordWave has none (its jobs run on visits). Disk: 193 GB free.
- The prep code is already plain JS with no DOM (`prep-core.js`, `master-core.js` export for Node) and both
  WebAssembly builds (`lame-3.100.js`, `ffmpeg-decode-7.1.js`) are built with `ENVIRONMENT=web,worker,node`.
- **Measured on this PC:** the whole chain in Node (decode a 3 min 53 s FLAC, master, level, LAME) takes
  **27 s on one core, 490 MB of memory**. So about 100 songs an hour on one core.
- The only browser-only piece is MP3 decoding (`OfflineAudioContext.decodeAudioData`). Chrome and Firefox
  decode MP3 with FFmpeg's own `mp3float` decoder, so adding that decoder to our FFmpeg build gives the
  same samples on the server.

## The design

1. **Same code on the server, run by Node.** The worker's chain moves into one shared file,
   `app/assets/prep-run.js`, used by both `prep-worker.js` (browser) and `app/cli/prep-node.mjs` (server),
   so the result can't drift: same mastering, levelling, trim, fades, LAME 3.100 and the same tags.
   `prep-node.mjs` is a pure step: raw file in, the song MP3 (+ the 320 kbps original for other formats)
   and an `info.json` out, progress written to a small JSON file. The FFmpeg build gains the MP3 demuxer
   and decoder, so the server decodes MP3s too. Node runs with `nice -n 19`, one song at a time per station.
2. **The raw file goes up first, in 8 MB pieces that can resume.** The browser hashes the file (SHA-1,
   as now), asks `begin` (the server answers: already in the library / already queued / send from byte N),
   then sends pieces (`chunk`, each under the 100 MB caps; a last piece under 16 KB goes as base64 like the
   studio's, for WordWave's firewall), then `finish` (the server checks size and SHA-1). Picking the same
   file again after a refresh carries on from where it stopped. The transfer itself still needs the page
   open (a browser rule); only the preparing goes on without it.
3. **A queue in the database** (schema 22, table `prep_jobs`): who uploaded it, file name, size, SHA-1,
   bytes received, state (`uploading`, `queued`, `working`, `done`, `failed`, `cancelled`), stage and
   progress, the song details from the details window (JSON, the new album art kept with it), the prep
   settings as they were at upload (preset, mastering, levelling, target, quality), the finished song's id,
   the error. Raw files in `storage/incoming/` (never served). `Library::import` adds the finished song
   exactly as the upload action does now, with its details applied, then the station replans.
4. **Who runs the queue:** `cli/prep.php` takes the queue's own lock and works until it is empty. It is
   started at once (in the background) when an upload finishes, by the background jobs (`Jobs::run`, cron
   or visits) when something is waiting and nothing works on it, and by a cron every minute as a safety
   net. A job that dies is tried once more, then marked failed with the reason. Prep is kept apart from
   `Jobs::run`'s own lock, because a batch can take an hour.
5. **"Being prepared" on the Music library**, seen from any device: each upload with its stage and
   percentage (Uploading, Waiting, Decoding, Mastering, Levelling, Encoding), who sent it, Cancel, and for
   one that failed the reason with Try again and Remove; uploads stopped part-way say "pick the file again
   to finish". It refreshes every few seconds while anything is going, and the library list updates when a
   song is added. The Overview says how many are being prepared.
6. **Clean-up:** the raw file is deleted when its song is added (the original kept as now: the MP3 itself,
   or the 320 kbps MP3 for other formats). Unfinished transfers are dropped after 2 days, failed jobs'
   files after 7 days.
7. **The in-browser path** stays for stations whose server can't run Node (shared hosting), chosen
   automatically from the station's tested setup. The Overview and Sound & safety say which one the station uses.
8. **Node setup** (`NodeRuntime`): look for `node` (the setting, PATH, `/usr/local/bin`, `/usr/bin`), version 18 or newer;
   else, on an admin's click, fetch the pinned `node-v24.x-linux-<arch>.tar.gz` from nodejs.org, check its SHA-256, keep
   only `bin/node` in `storage/runtime/`, then prepare a 2-second test tone through `prep-node.mjs` and compare it
   with the expected loudness. The result (ready, or the reason) is kept and shown.

## Tests

- `jobs_test.php`: the queue (begin, pieces, resume, finish, duplicates), a worker run end to end with
  Node, a failed job and its retry, the jobs run starting the worker, the lock, the clean-up.
- `manage_test.php`: the schema 22 upgrade, the new actions' rules (roles, sizes, bad pieces, someone
  else's job), details applied on finish.
- `upload_browser_test.mjs`: a bulk upload through the server, closing the page while the songs are
  being prepared and seeing them finish from a second browser; resume after a refresh mid-transfer; the
  details window path; the browser fallback when Node is missing; songs from the server equal to songs from
  the browser (same loudness, length, frames).
- `prep_test.mjs`: `prep-run.js` in Node against the worker's output on the same input.
