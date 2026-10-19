# Stationwagon handover

Read this first. It records where the work stands, what was decided and why, and what changed in
each round, so that anyone (a person or a new agent session) can pick up where the last one
stopped.

**Last updated:** 2026-10-19, after round 6 (project map added).

## How to keep this file useful

After every round of code changes, before or with its commit:

1. Update **Last updated** above.
2. Update **Current status** and **Next steps** so they're true now.
3. Add any new decision to **Decisions** (date, what, why). Never rewrite old ones; if one is
   reversed, add a new entry that says so.
4. Add any change in scope to **Scope changes**.
5. Add a row to **Change log**. A commit can't contain its own hash, so write "this round's
   commit" and replace it with the real hash (`git log`) at the start of the next round.
6. Add or close items in **Open issues**.
7. If a version number changes, record it in **Versions**.
8. Update the `MAP` object at the top of the script in `docs/project-map.html` (milestones,
   parts, next step, the decision waiting on the user, today's changes) and republish it to
   https://claude.ai/artifact/VZEEnB474YasBfzULhRz9z

Keep entries short and factual. Don't put passwords, keys or other secrets in this file.

## What Stationwagon is

Radio software for small stations that runs on ordinary **shared cPanel hosting**: AutoDJ with
real crossfades, a listener stream, and a browser (later Windows) studio for live shows. No root,
no Docker, no extra ports, no processes running all day. The first station is **Radio WordWave /
Radio WoordGolf**, a Christian station.

How it started: the station runs on AzuraCast at Asura Hosting (`a8.asurahosting.com`, station
`radio_wordwave`). Its Web DJ failed with "An error occurred with the Web DJ socket": the Web DJ
websocket proxy returns 502 on Asura's side. As a stopgap, the presenters broadcast with **BUTT**
straight to the Liquidsoap harbor on port 8345, which works. The user then asked for software
designed from scratch for shared hosting, and named it Stationwagon.

## Where things are

**GitHub:** https://github.com/vincentrathbone-web/Stationwagon (private, `main` tracks `origin/main`).

| Path | What |
|---|---|
| `HANDOVER.md` | This file |
| `CLAUDE.md` | Short standing instructions for agent sessions in this repo |
| `README.md` | Project summary |
| `docs/project-map.html` | Visual project map (milestones, parts, next step, open decision). Published at https://claude.ai/artifact/VZEEnB474YasBfzULhRz9z |
| `docs/POC-PLAN.md` | Risks R1–R7, prototypes P0–P4, pass criteria, **test log**, build-out order |
| `design/DESIGN.md` | Screens, skin tokens, type, shape, copy rules |
| `design/skins/hifi/`, `design/skins/green/` | Mock-ups: `player.html`, `studio.html`, `manager.html` |
| `design/skins/make_green_skin.py` | Builds the green skin from the hi-fi files (expects the original `wordwave-*.html` names) |
| `prototypes/config.php` | All POC settings (piece length, crossfade, timeouts, control key) |
| `prototypes/shared/` | Engine: `timeline.php` (planner), `mp3.php` (frame scanner), `library.php`, `util.php`; browser code in `js/` |
| `prototypes/api/` | `stream.php` (HLS), `timeline.php` (JSON), `control.php`, `upload.php` |
| `prototypes/p0…p4-*/` | The five prototype pages |
| `prototypes/tests/engine_test.php` | Command-line engine test (42 checks) |
| `prototypes/tools/` | `make-test-mp3s.js` (test tones), `phone.mjs` (drive the phone's Chrome over adb) |
| `prototypes/media/`, `prototypes/data/` | Test MP3s and runtime state; gitignored except their README / `.htaccess` |

### Published design mock-ups (claude.ai artifacts, private)

| Screen | Hi-fi skin | Green skin |
|---|---|---|
| Listener player | https://claude.ai/artifact/9vPMTeSZVDjgU3HaGKcjGU | https://claude.ai/artifact/CHULBMqMWbg25LjTcovES8 |
| DJ studio | https://claude.ai/artifact/BLzkA52kEozDMxttoJAg2m | https://claude.ai/artifact/BntgUDVB3s69T6CMZYgzsz |
| Station manager | https://claude.ai/artifact/NeuMPpuuQxvEAmBcF3cd5h | https://claude.ai/artifact/QxYADjUAtv1qbaQVugHuCq |

The repo copies in `design/skins/` are the source: edit those and republish to the same URL.

## Dev environment (this PC)

- **Laragon** Apache 2.4.68 on **port 8080**, serving `C:\laragon\www`. PHP 8.3.33 at
  `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe` (the version has changed before, so
  check `C:\laragon\bin\php\` if that path fails).
- Junction `C:\laragon\www\stationwagon` → `D:\VS Code\Stationwagon\prototypes`, so the
  prototypes open at `http://localhost:8080/stationwagon/…` (P0: `p0-host-check/index.php`).
- Engine test: `php prototypes/tests/engine_test.php` (use the full PHP path above; `php` isn't
  on PATH in Git Bash).
- **Node** v24 (has a built-in WebSocket; `phone.mjs` relies on it).
- **Android phone over USB:** adb at `D:\Android\platform-tools\adb.exe`; phone Xiaomi
  25062RN2DA, Android 16, Chrome 155. `adb reverse tcp:8080 tcp:8080` lets the phone open
  `http://localhost:8080/…`. That counts as a secure page, so the mic works with no HTTPS.
  `adb forward tcp:9222 localabstract:chrome_devtools_remote` enables `tools/phone.mjs`.
  Removing the reverse mapping does **not** cut an open connection; use
  `node prototypes/tools/phone.mjs offline <seconds>` to simulate pulling the cable.
- Apache sends `Cache-Control: no-cache` for html/js/css under `prototypes/` (`.htaccess`), so
  pages pick up changes. Before that, browsers ran stale copies.
- Run `node prototypes/tools/phone.mjs` from a short working directory. Node fails to start from
  the very long temp/scratchpad paths on this PC.

## Current status (2026-10-19)

Proof of concept: built and mostly tested on Laragon. Nothing is tested on cPanel yet.

| Prototype | Laragon | cPanel |
|---|---|---|
| P0 Host check | ☑ | ☐ |
| P1 HLS AutoDJ | ☑ PC + phone | ☐ |
| P2 Crossfade player | ☑ PC + phone | ☐ |
| P3 Mic chain | ◐ user heard the mic and ducking ("works perfectly"); gate, record and USB headset not reported yet | n/a |
| P4 Live studio | ☑ phone studio → PC listener, including the safety net | ☐ |
| Cloudflare caching | n/a | ☐ |

Details are in the test log in `docs/POC-PLAN.md`.

## Next steps

1. Finish P3: confirm the gate (room noise gone in pauses, no clipped first words), Record 20 s,
   and a USB headset. Tune presets from the user's feedback.
2. cPanel round: change `control_key` in `config.php`, upload `prototypes/` with a few MP3s,
   make `data/` writable, use HTTPS, then run P0 → P4 there. Then Cloudflare caching (R7).
3. Then the build-out order in `docs/POC-PLAN.md` (core with MySQL and an installer, station
   manager, player and embed, studio, Windows app).

## Decisions

Newest last. Don't rewrite old entries; add a new one that overrides.

| Date | Decision | Why |
|---|---|---|
| 2026-10-19 | Target **shared cPanel hosting**: PHP + Apache only, no daemons, no Docker, no extra ports | AzuraCast/LibreTime need Docker/root; the user's only option is shared hosting |
| 2026-10-19 | The "server" is a **timeline** planned on request (lazy, under a file lock); cron is optional | Nothing can run all day on shared hosting |
| 2026-10-19 | Listener stream is **HLS with `#EXT-X-BYTERANGE`** into the original MP3s; ~6 s pieces cut on MP3 frame boundaries by a pure-PHP scanner | Apache serves static byte ranges cheaply; no transcoding, and Cloudflare can cache |
| 2026-10-19 | Continuous media sequence; `DISCONTINUITY-SEQUENCE` = entry `n`; `PROGRAM-DATE-TIME` on every piece | Players can tell song boundaries and the stream time |
| 2026-10-19 | Our own **two-deck Web Audio player** does the real crossfades (equal power); plain HLS is the fallback | HLS alone can't crossfade |
| 2026-10-19 | Cuts (skip, go live) land on the first piece boundary ≥ now + 1 s | Pieces already published can't change |
| 2026-10-19 | Live shows: a **browser studio** encodes MP3 (lamejs) and uploads 6 s pieces in order (409 for a gap, 410 once closed); an **8 s** silence ends the show and AutoDJ resumes | Plain HTTP uploads work on shared hosting; the safety net covers dropped connections |
| 2026-10-19 | Mic chain in the browser: gain → high-pass → gate (AudioWorklet) → presence EQ → compressor → make-up → limiter; a ducker drives the music bus | No server-side audio processing is possible |
| 2026-10-19 | POC uses JSON files and one shared control key instead of MySQL and accounts | Keep the POC about the risky parts |
| 2026-10-19 | Keep **both skins** (hi-fi amber/graphite and green/rounded) as skin variants | User: "both … perfect … keep as skin variants" |
| 2026-10-19 | The radio designs are **dark** themed, by the user's explicit request. Still never navy | Exception to the user's global "light theme only" artifact rule, for this project's designs |
| 2026-10-19 | Planner moves on at the last piece boundary that leaves **≥ 6 s** of the song | Two-deck crossfades were 0–6 s long. Trade-off: HLS listeners miss the last 6–12 s of each song instead of 0–6 s |
| 2026-10-19 | After a cut, `recent` is rebuilt from what actually played | A cut song could come straight back |
| 2026-10-19 | Skip carries `heard` (the presser's stream time); if that song already ended at the live edge, nothing is cut | Skip from a lagging HLS listener used to cut the next, unheard song |
| 2026-10-19 | HLS player holds a **fixed 20 s delay** against the server clock (playback rate ±3 %, seek if off by more than 2 s) | hls.js alone left two devices 1.0 s apart; now both lock at 20.03 s |
| 2026-10-19 | **Listeners never skip.** Skip is a station control only (manager/DJ) | User, 2026-10-19: "There is no reason for a listener to skip." The listener player has no Skip; the one on the P1 page is a test stand-in for the manager |

## Scope changes

| Date | Change |
|---|---|
| 2026-10-19 | Added: **presenter notes** in the listener player (verses with KJV text and a Bible Gateway link, references, video links, plain notes; "Live" with "New" tags during a show). Green player only for now. The studio needs a matching notes editor (not designed) |
| 2026-10-19 | Not now: hosting only the Web DJ part ourselves. BUTT is enough for WordWave for the moment |
| 2026-10-19 | Out of the POC on purpose (see `POC-PLAN.md`): MySQL/accounts/manager UI, upload-time audio prep (ffmpeg.wasm), the Windows app (Tauri), the Apache clock-rewrite playlist trick, recording/podcasts/stats |
| 2026-10-19 | Lowering the 20 s listener delay (to about 10 s) is low priority, since only station staff press Skip |

## Milestone plan (estimates)

Plan & design (done 19 Oct) → **Proof of concept** (now) → Core & manager `v0.1` (est. Nov) →
Player & studio `v0.4` (est. Dec) → WordWave pilot `v0.8`, running beside AzuraCast before the
switch (est. Jan) → Launch `v1.0` with the Windows app (est. Feb–Mar). Only the first date is
real.

## Versions

No version numbers yet: the POC is unversioned and the commits are the record. Proposal for when
build-out starts: begin at `0.1.0` and record every bump here.

## Change log

| Round | Date | Commit | What changed |
|---|---|---|---|
| 1 | 2026-10-19 | `08a24dc` | First commit: POC plan, `DESIGN.md`, both skins (green player with presenter notes), prototypes P0–P4, engine, test, tools |
| 2 | 2026-10-19 | `a63ddb6` | First Laragon tests: full-length crossfades; no repeat after a cut; Skip uses `heard`; 24-hour clock; `prototypes/.htaccess` no-cache; engine test now 42 checks; test log started |
| 3 | 2026-10-19 | `a36a512` | Phone tests over USB: P1 fixed 20 s delay; `tools/phone.mjs` (eval/nav/offline); README "Testing on an Android phone over USB"; P1/P2/P4 pass on two devices |
| 4 | 2026-10-19 | `91bdc0b` | `HANDOVER.md` and `CLAUDE.md` added; P3 partly passed (user heard the mic, ducking works) |
| 5 | 2026-10-19 | `d7403dd` | Private GitHub repo created; `origin` set and `main` pushed |
| 6 | 2026-10-19 | this round's commit | `docs/project-map.html` project map added and published (light theme); milestone plan with estimates |

## Open issues

- **P3 unfinished:** gate behaviour, Record 20 s and the USB headset aren't reported yet.
- **Not tested on cPanel at all**, Cloudflare included. The default `control_key` must be changed
  before any upload.
- The **studio has no notes editor** yet, and the **hi-fi player** doesn't have the presenter
  notes panel (only the green one does).
- **P1 and P2 listeners are 20 s apart.** P2 plays at the live edge, P1 holds 20 s behind. Fine
  for the POC; choose one target delay during build-out.
- The **handover in P4** shows about 90 ms deck drift (a tiny jump at go-live). Under 100 ms, but
  worth a look.
- The **last live piece** after End show can be very short (about 1 s). It plays fine, but check
  how the tail is flushed.
- **BUTT on the user's PC:** the only remaining copy is in an old session folder that the app
  clears about a week after 2026-10-19. Reinstall BUTT before the next live show; its server
  settings are kept in `%APPDATA%\buttrc`.
- `design/skins/make_green_skin.py` still expects the original `wordwave-*.html` file names.

## Working agreements

- Commit and push only when the user asks. End commit messages with the `Co-Authored-By` line.
- Artifacts are light theme only and never navy (the user's standing rule). The radio skins in
  `design/` are the one dark exception, by explicit request.
- Ask the user questions as multiple choice (pick-from-options), never as open prose.
- Never store or repeat passwords or keys in the repo, chat or this file.
- After each code round: run the engine test, update the POC test log if tests ran, and update
  this file.
