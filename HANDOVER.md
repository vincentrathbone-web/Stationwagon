# Stationwagon handover

Read this first. It records where the work stands, what was decided and why, and what changed in
each round, so that anyone (a person or a new agent session) can pick up where the last one
stopped.

**Last updated:** 2026-10-22, round 33 (the Windows studio app: the app, the bridge for BUTT and Mixxx, signed updates; `v0.6.0` released on GitHub, so v0.6 is complete).

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
   parts, next step, the decision waiting on the user, today's changes) and the same data in the
   copy the user opens, `prototypes/Stationwagon Project Map.html` (the page wrapped in its own
   `<html>`; gitignored). It is no longer published as an artifact (the user, 2026-10-21)

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
| `docs/project-map.html` | Visual project map (milestones, parts, next step, open decision). The user opens the copy `prototypes/Stationwagon Project Map.html` (gitignored); the old artifact link is gone |
| `docs/POC-PLAN.md` | Risks R1–R7, prototypes P0–P4, pass criteria, **test log**, build-out order |
| `design/DESIGN.md` | Screens, skin tokens, type, shape, copy rules |
| `design/skins/hifi/`, `design/skins/green/` | Mock-ups: `player.html`, `studio.html`, `manager.html` |
| `design/skins/make_green_skin.py` | Builds the green skin from the hi-fi files (expects the original `wordwave-*.html` names) |
| `prototypes/config.php` | All POC settings (piece length, crossfade, timeouts, control key) |
| `prototypes/shared/` | Engine: `timeline.php` (planner), `mp3.php` (frame scanner), `library.php`, `util.php`; browser code in `js/` |
| `prototypes/api/` | `stream.php` (HLS), `timeline.php` (JSON), `control.php`, `upload.php` |
| `prototypes/p0…p4-*/` | The five prototype pages |
| `prototypes/tests/engine_test.php` | Command-line engine test (42 checks) |
| `prototypes/tools/` | `make-test-mp3s.js` (test tones), `phone.mjs` (drive the phone's Chrome over adb), `deploy-cpanel.sh` (upload to cPanel over SSH), `load-listeners.mjs` (simulated listeners) |
| `prototypes/config.local.php` | Server-only overrides (its control key). Never committed; denied by `.htaccess` |
| `prototypes/media/`, `prototypes/data/` | Test MP3s and runtime state; gitignored except their README / `.htaccess` |
| `app/` | **The real app** (Core & manager `v0.1` done; Player & studio `v0.4` under way). Front controller `index.php` → `src/App.php`; version in `app/VERSION` |
| `app/src/` | `bootstrap.php` (autoloader, `Stationwagon\` → `src/`), `App` (routes; `url()`, and `asset()` for links tagged by content), `Config`, `Db` (PDO, UTC, table prefix), `Schema` (tables, `Schema::VERSION`, step-by-step upgrades), `Installer`, `InstallPage`, `View`, `Session` (cookie settings, form keys), `Auth` (sign-in, roles, lock-out), `AuthPage` (sign-in / sign-out / account pages), `Request` (visitor address through Cloudflare), `Mp3` (frame scanner + ID3 tags), `Library` (add songs, stored by content hash), `Planner` (the timeline on MySQL: plan, skip, go live, pieces, schedule), `Stream` (HLS playlist), `Playlists`, `Schedule` (weekly blocks, time and day helpers, the week grid), `Staff` (people and who may change whom), `StationSettings` (the Sound & safety values and their limits: sliders and choices, MB an hour), `NowPlaying` (what listeners hear now, next and before, at the listener delay), `Notes` (presenter notes: rules, post/remove, the listeners' view), `Listeners` (pings from open players, "listening now", hourly and daily stats), `ManagePage` (the station manager screens and their form actions), `Studio` (the live studio's rules: who may go live when, show ownership, notes, running order), `StudioPage` (`/studio` and its JSON feed `/studio/api/*`), `Podcast` (episodes from live shows: takes, chunks, clips, edits, mixdown, publish, the feed, cleanup), `PodcastPage` (`/podcast`, `/podcast/feed`, `/manage/podcast`, the editor's JSON at `/podcast/api/*`) |
| `app/api/` | `stream.php` (listener HLS playlist; real file so `.htaccess` gives it the 2 s Cloudflare header), `timeline.php` (JSON for the two-deck player and studio, with `notes_rev` and `fade_shape`), `notes.php` (presenter notes for listeners, read only), `listen.php` (a player's ping, once a minute while it plays; answers the count) and `nowplaying.php` (public now / next / recent JSON for other websites, apps and the embed; any site may read it) |
| `app/views/`, `app/assets/` | PHP page templates (wrapped in `layout.php`; the station page is `player.php`; the manager screens are in `views/manage/`, framed by `frame.php`), the styles `app.css` (all tokens at the top: green, hi-fi, the embed's light look), `upload.js` (uploads songs one by one with progress), song prep: `prep.js` (decode in the browser), `prep-worker.js`, `prep-core.js` (loudness, trim, level, fades, encode; shared with the Node test), and `vendor/lame.min.js` (lamejs 1.2.1, LGPL-3.0, byte-identical to the npm package; licence beside it); the listener player `player.js` with `vendor/hls.min.js`, the presenter notes panel `notes.js` (hls.js 1.5.13, Apache-2.0, byte-identical to npm); the live studio `studio.js` (view `studio.php`) with the mic chain `studio-mic.js` (from the POC); `fades.js` (the station's fade shapes, shared by the player, the studio and the Sound & safety preview); `listeners.js` (the pings, shared by the player and the embed); `skin.js` (the Look picker: the device's skin on every page, `SwSkin.tok()` for canvases; the list of skins is `src/Skins.php`); `embed.js` with `views/embed.php` (the small player other websites frame at `/embed`, `?look=light` for light sites; it plays the stream at the listener delay); podcasts: `studio-recorder.js` (the studio's two-track recorder: an AudioWorklet, two encoder workers, 10 s chunks in IndexedDB, upload after End show), `podcast-core.js` (peaks, clips at silences, the mixdown plan, the chunk reader; shared with the Node test) and `podcast-editor.js` (the episode editor, view `manage/episode.php`); `views/podcast.php` (the public podcast page) and `views/manage/podcast.php` (episodes and the podcast's details) |
| `app/tests/` | `install_test.php` (31 checks), `auth_test.php` (sign-in logic, 39), `planner_test.php` (library + planner, 74), `manage_test.php` (library edits, song prep on the server side, playlists, schedule and today's list, Sound & safety with the choices, silence detection, staff rules, 81), `notes_test.php` (presenter notes: each kind, posting while on air, editing (posted again as new, the old version kept until listeners reach it), the listeners' view and where the show's sound ends, the change marker, the upgrade to schema 7; 57), `listeners_test.php` (pings, who counts as listening now, Stop (also one that overtakes the first ping), the hourly and daily stats in the station's time zone, tidying up, the upgrades; 84), `studio_test.php` (the schema upgrade, when a DJ may go live, who may send pieces, post, change and remove notes and end a show, the running order, rejoining; 80), `prep_test.mjs` (song prep maths against EBU Tech 3341, the last minute's loudness, and a real lamejs MP3, 27; Node), `upload_browser_test.mjs` (song prep end to end in headless Edge, 13; Node, Laragon only), `player_browser_test.mjs` (the listener player in headless Edge: join, crossfade, a frozen sound engine, the output strip, a live show with a presenter note, stream only, the embed framed by another site, both counting themselves as listening, an edited presenter note, the spectrum, the embed starting itself again; 56, or 36 with `--listen-only` for a public server), `player_phone_test.mjs` (the player on the Android phone over USB, screen locked for 2 minutes with the sleep key, the embed, the spectrum, switching the look; 18), `studio_browser_test.mjs` (the studio in headless Edge with a fake mic: go-live rules, running order, going live in sync, pieces, crossfade, the notes drawer, the output strip, rejoining after a reload, End show, the recording and its upload, the headphones menu, talk items, editing a note; 91, Laragon or the server with `SW_HELPER_SSH`), `studio_phone_test.mjs` (the studio on the phone with its own mic, its headphones output, the Do Not Disturb reminder, a notification sound mid-show, the recording, the look picked at sign-in; 19), `skin_browser_test.mjs` (the Look picker and the device's look on every screen, open tabs, the canvases, the embed's own picker in another site's frame; 30; Laragon and the server), `podcast_test.php` (episodes, takes, chunks, edits, mixdown, publish, the feed, cleanup, the upgrade to schema 6; 68), `podcast_core_test.mjs` (peaks, clips, the mixdown plan, the chunk reader; 23), `podcast_browser_test.mjs` (a recording through the editor to a published episode and the feed, an as-aired show, a recording cut short and filled in; 56; Laragon and the server) with `browser_helper.php` (accounts, tracks, a test live show, a test DJ with a block), `web_test.php` (pages, manager screens with the new Sound & safety, the studio and its feed, stream over HTTP, the look-ahead's pieces, now playing and the embed, the listener count and the Listeners screen, the podcast page, feed and screens, the Look picker and the all-token stylesheet, 186; `--public`: 92), `lib.php` (shared helpers: throwaway database and install) |
| `app/cli/` | `install.php` (install over SSH: the form's fields as JSON on stdin), `set-password.php <email>` (new password on stdin; for when nobody can sign in), `import.php <mp3s>` (add songs), `plan.php` (cron: plan ahead), `upgrade.php` (bring the database up to the code's schema; the deploy runs it) |
| `app/storage/` | Songs (`media/<sha1>.mp3`), live pieces (`live/<show id>/00000.mp3`), `cache/`, `sessions/`; gitignored; only `.mp3` is served |
| `app/config.local.php`, `app/install.key` | Written by the installer / the deploy script. Gitignored and denied by `.htaccess` |
| `tools/deploy-app.sh` | Upload `app/` to cPanel over SSH (keeps the server's settings and storage) |
| `desktop/` | **Stationwagon Studio for Windows** (Tauri 2, `v0.6`): `src-tauri/src/lib.rs` (the window, settings, what the station's pages may call, updates), `bridge.rs` (the stand-in Icecast/Shoutcast server on 127.0.0.1 for BUTT and Mixxx), `mp3.rs` (cuts the MP3 stream into whole frames; Rust unit tests), `ui/index.html` (the app's own settings page); `tests/` has `app_test.mjs`, `butt_test.mjs` and `update_test.mjs`. Builds land in `desktop/dist/` (gitignored) |
| `tools/build-desktop.sh` | Builds the app's installer, signed for the updater: `bash tools/build-desktop.sh [version] [test]` (the key is `~/.tauri-keys/stationwagon.key`, its password in `tools/deploy/updater-key.local.txt`) |
| `wordpress/stationwagon-updates/` | The WordPress plugin **Stationwagon Updates**, installed on controll.co.za: hands out the app's installer and updates from the private repo's GitHub releases with a read-only token kept on that server (Settings → Stationwagon Updates). `wordpress/tests/wp-stub.php` runs it under PHP's own web server for the update test |
| `tools/deploy/` | Gitignored secrets for the test server: setup key, database password, admin sign-in; `shots/` holds screenshots |
| `tools/shot.mjs` | Full-page screenshots with headless Edge, signed in if asked, at any width; flags pages wider than the screen. `node tools/shot.mjs <out> <base> <width> [--signin <email> <password file>] <paths…>` |
| `tools/song-changes.mjs` (+ `song-changes.html`) | Hear the station's song changes for a folder of songs before they go on air: prepares each song as on upload (`prep.js` in headless Edge), cuts pieces with `Mp3::scan`, plans each change by the planner's rule and mixes it as the player does, then writes a page of 15 s clips with charts and measurements (silence, quiet stretches, loudness step, the mix's peak). `SW_PHP=… node tools/song-changes.mjs <folder> <out dir> [--crossfade 6] [--piece 6]`. The out dir holds copies of the music |

### Published design mock-ups (claude.ai artifacts, private)

| Screen | Hi-fi skin | Green skin |
|---|---|---|
| Listener player | https://claude.ai/artifact/9vPMTeSZVDjgU3HaGKcjGU | https://claude.ai/artifact/CHULBMqMWbg25LjTcovES8 |
| DJ studio | https://claude.ai/artifact/BLzkA52kEozDMxttoJAg2m | https://claude.ai/artifact/BntgUDVB3s69T6CMZYgzsz |
| Station manager | https://claude.ai/artifact/NeuMPpuuQxvEAmBcF3cd5h | https://claude.ai/artifact/QxYADjUAtv1qbaQVugHuCq |
| Output waveform (prototype, green only; repo copy `design/output-waveform.html`) | | https://claude.ai/artifact/7B369bBNhrMkKLBLrv1Ssa |

The repo copies in `design/skins/` are the source: edit those and republish to the same URL. The
studio mock-ups don't have the notes drawer or the "Switch on" step; `design/DESIGN.md` ("Notes
drawer", "DJ studio as built") describes how the app does them.

## Test server (cPanel)

- **https://wordwave.controll.co.za/stationwagon-poc/** (P0: `p0-host-check/index.php`).
  Subdomain made 2026-10-19 with `uapi SubDomain addsubdomain` on the `controll` SSH account;
  document root `~/public_html/wordwave.controll.co.za`; the POC is in `stationwagon-poc/` under it.
- Server: LiteSpeed (honours `.htaccess`), **PHP 8.4.26** since 2026-10-21 (was 8.1.34; see Decisions), IP 169.239.217.60.
  The PHP version comes from cPanel's MultiPHP handler (`alt-php84` in the sites' `.htaccess`) and the
  extensions and limits from CloudLinux's PHP Selector (`selectorctl --interpreter=php`, user current 8.4).
  `/usr/local/bin/php` follows the folder's handler, so `php` in a site folder is 8.4 and in `~` the selector's.
- DNS: Cloudflare account `vincent.rathbone@gmail.com` (the user signs in; no API token on this
  PC). Record `wordwave` A → 169.239.217.60, **proxied**, like `demo`. The same Cloudflare account also holds
  `wordwave.co.za`, which the user owns.
- **Cloudflare Cache Rule** "Stationwagon playlist and now playing (2 s)" on the controll.co.za zone: `http.request.full_uri wildcard "https://wordwave.controll.co.za/*/api/stream.php*"` or the same for `*/api/nowplaying.php*` (now playing added 2026-10-21 with the user's OK) → Eligible for cache, Edge TTL from the origin header, **Browser TTL: Respect origin TTL** (added 2026-10-20 with the user's OK). Covers the POC, the app and a future station at the root.
  Without the Browser TTL setting Cloudflare rewrote the playlist's `max-age=0` to the zone's 2 h
  Browser Cache TTL (it overrides any browser header on cached responses, `no-cache` and `no-store`
  too), which would freeze browser players. `web_test.php` checks the header through Cloudflare.
- The main site's `~/public_html/.htaccess` (WordPress, "Flying Pages Plugin Fix") sets `Cache-Control: no-cache` on everything below it, the subdomain included, and Wordfence WAF is prepended to every PHP request. Don't edit that file; override headers in our own `.htaccess`.
- Origin certificate: Let's Encrypt from cPanel AutoSSL (checked 2026-10-21, valid to 17 Jan 2027; AutoSSL renews it).
- Cloudflare's **Email Address Obfuscation** is on for the zone: email addresses in pages reach browsers as a
  `/cdn-cgi/l/email-protection` link that a script decodes. `web_test.php` accepts that form.
- **App (Core & manager):** **https://wordwave.controll.co.za/stationwagon-app/**, **installed**
  2026-10-20 over SSH with `cli/install.php`: station "Radio WordWave / Radio WoordGolf"
  (Africa/Johannesburg), admin `vincent@controll.co.za`. The admin password is in
  `tools/deploy/controll.admin.local.txt` (gitignored). On air: the POC's 4 test tones (imported with
  `cli/import.php`); stream at `/stationwagon-app/api/stream.php`. **Cron** (added to the account's
  crontab next to an existing `ostic` job; keep that one): `*/5 * * * * /opt/cpanel/ea-php81/root/usr/bin/php
  …/stationwagon-app/cli/plan.php > /dev/null 2>&1`. Database `controllco_stationwagon`, user `controllco_swapp` (All Privileges), both made
  with `uapi Mysql …`; the password is in `tools/deploy/controll.db.local.txt` (gitignored). Deploy with
  `bash tools/deploy-app.sh controll public_html/wordwave.controll.co.za/stationwagon-app`: it replaces
  the code, keeps `config.local.php` and `storage/`, and until installed leaves a one-time `install.key`
  (copy in `tools/deploy/controll.install-key.local.txt`) that the web installer asks for and then deletes.
  **Tests on the server** use the empty database `controllco_swtest` (same user), never the station's:
  `SW_TEST_DB_HOST=localhost SW_TEST_DB_USER=controllco_swapp SW_TEST_DB_PASS=… SW_TEST_DB_NAME=controllco_swtest php tests/auth_test.php`
  (and `install_test.php`, `planner_test.php`, `manage_test.php`, `notes_test.php`, `studio_test.php`, `listeners_test.php`). Web test, signed in: on the
  server `php tests/web_test.php https://wordwave.controll.co.za/stationwagon-app` (throwaway DJ and manager accounts, removed
  afterwards; the user's OK of 2026-10-21); `--public` from this PC. The studio test can go live on the server from this PC:
  `SW_HELPER_SSH=controll:public_html/wordwave.controll.co.za/stationwagon-app node app/tests/studio_browser_test.mjs https://wordwave.controll.co.za/stationwagon-app`.
  The location database is in `storage/geo/` (121 MB, not served); after a fresh install run `php cli/geo-update.php`; the cron
  fetches each new month's file.
- **Deploy (POC):** `bash prototypes/tools/deploy-cpanel.sh controll public_html/wordwave.controll.co.za/stationwagon-poc`.
  It replaces the code and keeps the server's `data/`. The server's control key is in
  `prototypes/tools/deploy/controll.config.local.php` (gitignored). Paste it into the page's
  "Control key" field to use Skip or Go live on the server. Never print it in chat or docs.

## Dev environment (this PC)

- **Laragon** Apache 2.4.68 on **port 8080**, serving `C:\laragon\www`. PHP 8.3.33 at
  `C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe` (the version has changed before, so
  check `C:\laragon\bin\php\` if that path fails).
- Junction `C:\laragon\www\stationwagon` → `D:\VS Code\Stationwagon\prototypes`, so the
  prototypes open at `http://localhost:8080/stationwagon/…` (P0: `p0-host-check/index.php`).
- Engine test: `php prototypes/tests/engine_test.php` (use the full PHP path above; `php` isn't
  on PATH in Git Bash).
- **App on Laragon:** junction `C:\laragon\www\stationwagon-app` → `D:\VS Code\Stationwagon\app`,
  so it opens at `http://localhost:8080/stationwagon-app/`. Installed into database `stationwagon_dev`
  (Laragon MySQL 8.4.3, root with no password, `127.0.0.1`). The local admin's sign-in is in
  `app/tests/dev-admin.local.txt` (gitignored). To reinstall, delete `app/config.local.php` and drop
  the `sw_` tables. Tests (each makes and drops its own database or throwaway account):
  ```
  php app/tests/install_test.php                                   # installer, 31 checks
  php app/tests/auth_test.php                                      # sign-in logic, 39 checks
  php app/tests/planner_test.php                                   # library and planner, 72 checks
  php app/tests/manage_test.php                                    # manager rules, 81 checks
  php app/tests/notes_test.php                                     # presenter notes, 44 checks
  php app/tests/studio_test.php                                    # studio rules, 77 checks
  php app/tests/listeners_test.php                                 # listeners: pings, analytics, places, uniques, cap, 82 checks (needs storage/geo: php app/cli/geo-update.php)
  php app/tests/web_test.php http://localhost:8080/stationwagon-app # pages, manager, studio, stream, now playing, embed, listeners, privacy, skins: 186 checks
  node app/tests/prep_test.mjs                                     # song prep maths, 27 checks
  node app/tests/upload_browser_test.mjs http://localhost:8080/stationwagon-app # prep in a real browser, 13
  node app/tests/player_browser_test.mjs http://localhost:8080/stationwagon-app # the player and the embed, 56 (~4 min)
  node app/tests/player_browser_test.mjs https://wordwave.controll.co.za/stationwagon-app --listen-only # 36
  node app/tests/player_phone_test.mjs https://wordwave.controll.co.za/stationwagon-app # on the phone, with the embed, 18 (~4 min)
  node app/tests/studio_browser_test.mjs http://localhost:8080/stationwagon-app # the studio, 62 (~2.5 min)
  node app/tests/studio_phone_test.mjs http://localhost:8080/stationwagon-app # the studio on the phone, 21, with Chrome's count of sound gaps (~1 min)
  node app/tests/skin_browser_test.mjs http://localhost:8080/stationwagon-app # the Look picker on every screen, 30 (~1 min; the server too, with SW_HELPER_SSH)
  ```
  **The Windows app** (`desktop/`; Rust 1.98 and the Tauri CLI 2.11 are on this PC; `cargo build` in
  `desktop/src-tauri` makes the debug app the tests use):
  ```
  cargo test                                                       # in desktop/src-tauri: the MP3 frame cutter and station addresses, 6
  node desktop/tests/app_test.mjs [base url]                       # the app with a stand-in BUTT/Mixxx/Shoutcast, 56 (~5 min; the server too, with SW_HELPER_SSH)
  node desktop/tests/butt_test.mjs <folder with butt.exe> [base url] # the real BUTT in its three ways, 26 (~2 min)
  bash tools/build-desktop.sh 0.6.0 test && bash tools/build-desktop.sh 0.6.1 test
  node desktop/tests/update_test.mjs                               # the plugin and an update 0.6.0 → 0.6.1, 17 (~2 min; installs and uninstalls the test app)
  ```
  These put your own app settings back afterwards. The real installer: `bash tools/build-desktop.sh`
  (`desktop/dist/Stationwagon-Studio_<version>_x64-setup.exe` and its `.sig`, the files a release needs).
  The Node tests need `SW_PHP` set to the PHP path above, and run from a short folder (e.g. `D:\`).
  The test server has no Node, so these run on this PC; the player test's `--listen-only` form checks
  the public server from here (it changes nothing there). The phone test needs the phone unlocked
  with the port forward below; it locks the screen itself for 2 minutes, then wakes it (the lock
  screen shows; unlocking is the user's job).
  The web test signs in a throwaway manager, uploads, edits and deletes its own songs, playlists,
  blocks and DJs, changes Sound & safety, and puts everything back afterwards. A test song that is on air at the end is hidden
  (`[test] …`, status `error`) and deleted by the next run.
  Laragon's PHP allows **8 MB** per upload (`post_max_size`); the test server allows 512 MB.
  Laragon's `mysql` client is MariaDB's and fails on MySQL 8 logins; use the MySQL 8.4.3 client in
  `C:\laragon\bin\mysql\`.
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
- `phone.mjs offline` can leave that Chrome tab offline afterwards (pages then fail with
  NAME_NOT_RESOLVED or TIMED_OUT). Open a fresh tab with `adb shell am start …` and close the old
  one (`curl http://localhost:9222/json/close/<id>`).

## Current status (2026-10-20)

**The proof of concept passed.** All of R1–R7 are answered on the real shared host; the summary is
under "Result" in `docs/POC-PLAN.md`.

| Prototype | Laragon | cPanel |
|---|---|---|
| P0 Host check | ☑ | ☑ |
| P1 HLS AutoDJ | ☑ PC + phone | ☑ PC (fibre) + phone (4G), both locked at ~20.0 s |
| P2 Crossfade player | ☑ PC + phone | ☑ PC + phone, same-second 6 s crossfades |
| P3 Mic chain | ☑ user: "All good" | n/a |
| P4 Live studio | ☑ including the safety net | ☑ phone studio on 4G → PC listener |
| Cloudflare caching | n/a | ☑ audio HIT; playlist 961/990 HIT with 100 simulated listeners |

**Core & manager (`v0.1`) is done** (closed as `0.1.0` on 2026-10-20). In order of building: the app layout (`app/`), schema v1 (12 tables) and the
web installer. The installer test passes on Laragon (MySQL 8.4.3) and on the server (MariaDB 10.11);
the browser install works on Laragon. **The test server is installed** (over SSH): the home page
shows the station connected, `/install` says "Already installed", and the private files return 403.

**Sign-in works** (2026-10-20): `/signin`, sign-out, `/manage` (the manager home, tiles for the screens
still to build) and `/account` (change password). All tests pass on Laragon and on the server.

**The planner runs on MySQL** (2026-10-20): `Planner` replaces the POC's JSON timeline, follows
playlists (shuffle / in order) and the weekly schedule, and serves the listener stream at
`api/stream.php`. The test server streams the 4 test tones; a real browser (hls.js) played it
through Cloudflare with no errors. "On air" shows on the station page and `/manage`.

**The station manager screens work** (2026-10-20): Overview (on air, Skip, health), Music library
(drag-and-drop upload with progress, search, edit, remove), Playlists (shuffle / in order, add from
the library, reorder, switch off, delete), Schedule (week grid, add and remove playlist and live
blocks) and DJs (add people, change role, set a new password, remove). Checked by screenshot at
1280 and 390 px wide. All tests pass on Laragon; on the server the rule tests and the public web
test pass (the signed-in screens are tested on Laragon only).

**Sound & safety works** (2026-10-20): crossfade, skip fade, live cut-in fade, back-to-AutoDJ timeout
and songs not to repeat. Saving re-plans what isn't on air yet. Tested on Laragon and the server; the
screen was also opened (view only) as the admin on the server.

**Song prep on upload works** (2026-10-20): the browser decodes each song, measures its loudness
(BS.1770 / EBU R128, checked against the EBU's test cases), trims silence at both ends, levels it to
−16 LUFS, adds 20 ms fades and uploads a new 192 kbps MP3 with the original tags. On the live site a
40 s song took 3 s (view-only check as the admin; nothing was uploaded). **v0.1 is closed** as version
`0.1.0` (the user's OK, 2026-10-20).

**The listener player works** (2026-10-20, Player & studio `v0.4`): the station page is the player.
Every listener hears the timeline 20 s behind the server clock; songs crossfade on two decks, live
shows come from the HLS stream on a third deck at the same delay, and `?mode=stream` (or a browser
without Web Audio) plays the stream alone. In headless Edge: joins within 0.05 s, crossfades, live
held at 20.00 s, stream-only at 20.07 s, on Laragon and (listen-only) on the live server through
Cloudflare. **On the Android phone** (Chrome 155, live server, round 18): joins within 0.11 s, crossfades,
and with the screen locked for 2 minutes the songs keep changing on time (4 songs, 0 of 40 looks off by
over 0.3 s). That phone freezes the sound engine's clock about every 60 s while locked; the player's
watchdog notices within 0.3 s and nudges it, so each freeze costs about half a second of sound.

**Presenter notes for listeners work** (2026-10-20): "From the presenter" beside the player shows the
live show's verses, references, video links and notes (or the last show's, up to a week old), each
appearing when listeners, 20 s behind, reach the moment it was posted, with "New" and a toast during a
show. Presenters post them from the studio's notes drawer (round 21). Verse text is pasted by the
presenter; each verse keeps its version (KJV by default) and links to Bible Gateway.

**The live studio works** (2026-10-20, round 21): `/studio` for signed-in staff, built to the green
mock-up. Top bar (show, block progress, station clock, Notes, Go live), Decks A and B with waveforms,
Cue, Play, Stop and volume, the mixer (L/R/MIC meters, crossfader, Fade and Skip fades, Mic on air with
ducking), the microphone strip (input, preset, six modules with switches and sliders), the running order
(library search, songs, station IDs and talk items, planned times, the fill bar, load to A/B) and the
status strip. Notes for listeners open in a drawer from the side. A DJ can go live only in their own live
block (from 5 minutes before); managers and admins any time. Going live, Deck A picks up the AutoDJ song
exactly where the AutoDJ is (3 ms in the test) and the studio sends 6 s MP3 pieces that reach the
listener stream; End show (confirmed in the page) hands back to the AutoDJ. Schema version 2 adds the
running orders; older databases upgrade on deploy (`cli/upgrade.php`) or on the first page view.
Tested in headless Edge with Chromium's fake microphone on Laragon (43 checks). **On the live server**
(the user's OK, 2026-10-20) the admin went live for one minute with the test tones (mic off, no notes):
Deck A picked up the AutoDJ song 2 ms from the timeline, 10 pieces went out with no errors (at most one
waiting), a listener tab on the station page heard the show through Cloudflare 20.0 s behind with no
dropouts, and End show handed back to the AutoDJ. An earlier attempt stopped when the test script
crashed 10 s in; the server's safety net then ended that show by itself after 8 s, as designed.

**Output waveform: a working prototype** (round 22, an artifact; see "Published design mock-ups"). It
shows the wet mix, not the inputs: each source is tapped after its fades, ducking and compressor, and
the final output after the limiter, measured in 10 ms columns by an AudioWorklet. The user asked for it
in the player and the studio so fades, ducking, compression and silence skipping can be seen as well as
heard. Songs loaded into the prototype are prepared as on upload; a copy is in `design/output-waveform.html`.

**The output strip is in the app** (round 23; the user called the design final). The **player** has a
60 px strip under its display: the last 5 s measured on the left, the next 5 s mixed ahead on the
right, one colour per source (song A blue, song B pink, live yellow, the output a white line) and
faint level lines at −1, −6, −12 and −20 dB. It reads the coming 20 s itself (byte ranges of the
song files, served from the browser's cache, and copies of the live pieces), mixes them through the
planned fades, and that matches what then goes out within 1 dB. The **studio** has the same strip
above the decks, full width, 50 px high, with a show/hide toggle and a drag handle (its height is
remembered), showing 20 s and following Fade to A/B and the mic's ducking.

**Real music for testing** (round 22): the user's album "Alruin – Johannesburg Recordings" (22 MP3s,
206 MB, on this PC in `D:\Music\Albert musiek\…\Johannesburg Recordings`), measured with the app's own
prep maths. Useful cases: `10 Project Ibanez Rev 1` ends with 74 s of silence, `19 Redemption Process`
with 21 s of silence after a 15 s quiet tail, `20 Project Highway` with 10.5 s of silence and two gaps
just before it, `Cain` with 5 s of silence after a 19 s quiet tail; `02 Overdrive Project1` and
`13 Hail the Black` open with 11–13 s of quiet; loudness runs from −6.7 to −22.4 LUFS (`08 Project So
Iii`), so levelling matters. Stays on Laragon (not on the public test server).

**Sound & safety v0.4 works** (round 25; on the test server since 2026-10-21). The screen has four cards and a
fade-shape curve; new settings: fade shape (equal power, linear, S-curve: the player, its look-ahead
and the studio all fade with `fades.js`), music under the voice (−24 to 0 dB, the studio's mic presets
start from it), DJ rejoin window (0–30 min, default 10), move on when a song goes quiet (off, −50,
−40 or −30 dB, default −40), and the quality of live shows and prepared songs (96–256 kbps with MB an
hour, default 192). The listener delay is shown, not editable. Song prep now stores each song's last
minute of loudness (`tracks.outro`, schema 3) and the planner starts the next song where the music
stays below the level to the end (`Planner::quietFrom`; only for a quiet tail over 2 s, never cutting
a song below 30 s). The studio offers **Rejoin your show** after a reload or when the safety net ended
the show less than the window ago: it goes live again as a continuation show (`live_shows.continues_show_id`)
that takes over the notes, and the AutoDJ fills the gap; it also warns of **dead air** after 10 s of
silence on air. All tests pass on Laragon: engine, install, auth, planner, manage 81, notes, studio 77,
web 118, prep 27, upload 13, player 33, studio browser 60 (with a reload and Rejoin your show mid-show). Found while
building: resuming the *same* open show after a reload would have put the new audio at the stream time
where the old pieces stopped, so after a gap over about 12 s every later piece reaches listeners late;
rejoining always starts a continuation show instead (see Decisions).

**Now playing and the embed work** (round 25; on the test server since 2026-10-21, where a church page framed the live embed and it played 20.06 s behind). `api/nowplaying.php` tells other websites and apps what listeners hear (at the 20 s delay): now, the next 3 songs, the last 5. `/embed` is a small player any website can frame (dark, or light with `?look=light`); it shows what's on and plays the stream held 20 s behind, like the station's player (20.1 s in the test). The station page has "Put the player on your website" with the iframe code, a light-look switch and Copy. Found while testing: Edge says it can play HLS itself but can't, so the embed uses hls.js first (as the player does); and a muted audio element is paused in a background tab, so the embed waits for the delay at volume 0 instead. Also found (an older bug): **Skip pressed between Go live and the show's start** cut the song on air and dropped the waiting show from the timeline, leaving it open with nowhere to play; Skip now does nothing then ("A live show is on air or about to start").

**Listener count and stats work** (round 27). Every open player and embed pings once a minute while it plays (a random id per Listen; no cookie, no address kept), so the player's health line shows "Listening now", the manager's overview shows it with the most at once today, and the new **Listeners** screen has four tiles and two charts (the most at once per hour over 48 hours, hours listened per day over 30 days), each with a table view. Apps that play the stream address directly aren't counted.

**Every open limit addressed** (round 28, before podcasts). Each Listen is now kept 30 days with the address and browser details it came from (the user reversed the anonymous-ping decision): the **Listeners** screen adds unique listeners (per day and month), and who listens over the last 30 days by where, device, browser, app, country and city (DB-IP's free city database, looked up on the server and updated monthly by the cron). A **Privacy page** says what is kept and why, linked under the player and in the embed; managers set its contact address on the Listeners screen. Test runs carry a mark and stay out of the stats; one address may start at most 20 Listens in 10 minutes. The studio shows how many are listening and encodes MP3 in a worker. Tried for the first time: the studio on the phone (its own mic, live, End show), the embed on the phone (20 s behind through 2 minutes locked), the full signed-in web test and a live studio show on the test server. Now playing is cached at Cloudflare. All tests pass on Laragon, the server and the phone; what is left is in Open issues (hardware the user has to bring, and limits agreed as unfixable).

**Podcasts work** (round 29). Every live show becomes a **draft episode** (shows under 2 minutes are skipped). The studio records the show **on the device** in two time-synced tracks from the first sample on air: the music in stereo as it went out (ducking included) and the mic in mono, as 10 s MP3 chunks kept in the browser (IndexedDB) and uploaded after End show (or when the studio next opens). The **episode editor** (`/manage/podcast/<id>`, any browser) shows both tracks with zoom; the clips are split at every silence of 2 s or more; click a clip and press Delete to cut it (Ctrl+Z undoes); **Mixdown** mixes the kept clips in the browser, shrinking long gaps to 1 s, with a time estimate, and uploads one MP3; then the DJ or a manager publishes it. A show with no device recording becomes an episode from its aired pieces, and a recording that stopped early (a reload) is filled in from the aired show. The station has a **podcast page** (`/podcast`), an **RSS feed** with iTunes tags (`/podcast/feed`), and a manager screen with the podcast's details (title, description, cover, language, category). Raw tracks are deleted 30 days after publishing. On a phone or tablet the studio reminds the DJ to turn on Do Not Disturb and wear headphones (a notification sound can reach the mic; the mix and the recording themselves aren't turned down, measured on the phone). All tests pass on Laragon, the server and the phone.

**The small studio items work** (round 30). The status strip's **Headphones** is a menu of this computer's sound outputs (with more than one), and everything the DJ hears (the music going out and any deck on Cue) moves to the chosen one; it is remembered on that computer, falls back to the system default if that device is unplugged, and an output that can't be used says why. A posted note has **Edit**: its form fills in, Save changes posts it again as new (listeners get it with "New" and a toast when they reach that moment; the old version stays until then). In the running order, **opening the mic marks the next talk item as now**, and each talk row has a **Now** button to mark it by hand. Schema 7 adds `presenter_notes.replaced_by`. All tests pass on Laragon, the server and the phone.

**The spectrum works** (round 31). The player's display has the mock-up's spectrum: 28 segmented bars on a musical (logarithmic) scale from 40 Hz to 16 kHz, with peak marks that hold 0.6 s and then fall. It reads the station's output before the listener's volume, so turning down doesn't shrink it; after Stop the bars fall to nothing and drawing stops; with "reduce motion" set on the device it redraws once a second without peak marks; stream-only mode (no Web Audio) shows none. With the test tones the tallest bar is the tone's band, in headless Edge and on the phone. Colours are skin tokens (`--spec-hi`, `--spec-mid`, `--spec-lo`, `--spec-peak`), ready for the hi-fi skin.

**Skins work** (round 32). Every screen (the station page, the podcast and Privacy pages, sign-in, the studio and every manager screen) has a **Look** menu in its top bar: **Green** (the default) or **Hi-fi** (amber on graphite, bevelled square windows, Barlow and a glowing Share Tech Mono display). The choice is the viewer's, kept on that device (`localStorage` `sw.skin`, no cookie), so picking it on one screen changes every screen, and tabs already open switch at once; an inline script in `<head>` applies it before the page draws. The embed has a small **Look** button; framed on another website, browsers keep its storage apart, so its choice there is its own (the user's choice), and `?look=light` lays light colours over either skin. **All colours, corner sizes, bevels and fonts are tokens** at the top of `app.css` (one block per skin; `Skins::ALL` lists them), and the canvases (spectrum, output strip, deck waves, episode editor) read them through `SwSkin.tok()` and redraw when the look changes. The web test fails if a colour or corner size is written in the components. The station's old `skin` setting is no longer used. **Found and fixed on the way**: the presenter notes panel went from "Live" to "This morning" the moment a show ended, but listeners still hear its last 20 s; notes reaching them then lost their "New" tag and toast. Listeners now see the show live until they reach the end of its sound (`ends` in `api/notes.php`). **And the embed now starts again by itself** when something outside pauses it or its sound stops moving for 8 s: on the Xiaomi phone with its media volume at 0, MIUI's PowerKeeper pauses an app's audio after 60 s behind the lock screen (it takes it for silence); the station's own player recovered (Chrome restarts its sound engine stream), the embed didn't. All tests pass on Laragon, the server and the phone.

**The Windows studio app works** (round 33). **Stationwagon Studio** (`desktop/`, a 2.1 MB installer that installs for the one Windows user, no admin needed) opens the saved station's own studio page in WebView2, so it is always the server's version, and adds a **bridge for BUTT and Mixxx**: a stand-in Icecast server on 127.0.0.1 (port 8000 by default, a password made on each PC, mount `/live`, user `source`). BUTT or Mixxx sends to it as to Icecast (`SOURCE`, or `PUT` with `100-continue`) or Shoutcast (at port − 1, its titles via `admin.cgi`); MP3 only, one source at a time. When the sound arrives the studio **goes live by itself** if the DJ's slot allows it (the banner says why not), sends BUTT's MP3 frames unchanged as the station's pieces (no re-encoding), shows BUTT's song titles, and when BUTT stops it ends the show 5 s later and the AutoDJ takes over; BUTT back within 5 s carries the same show on (its first piece then goes after 1 s, within the station's 8 s timeout). Closing the app on air asks first. The show's podcast episode is made from what went on air. The station's pages may only listen to the bridge and read its status (and say on air, close, open settings); the app's settings and updates are out of their reach. **Updates**: when the app opens (never during a show) it checks the **Stationwagon Updates** plugin on controll.co.za, which reads the private repo's newest GitHub release with a read-only token kept on that server; a newer version installs by itself after its signature is checked against our own updater key (no paid certificate: Windows SmartScreen says "unknown publisher" at the first install). On Windows, the browser studio shows **Get the Windows app**. Tested with the real **BUTT 1.47** (portable) in all three ways, with a stand-in BUTT/Mixxx/Shoutcast on Laragon and on the test server over HTTPS, and with an update from 0.6.0 to 0.6.1 through the plugin (a wrong signature refused). **Released:** `v0.6.0` is on the repo's GitHub releases (the installer and its `.sig`, from commit `0358c8b`); the plugin has its token, so `…/desktop/installer` (the studio's **Get the Windows app**) hands out that installer and the update link answers apps older than 0.6.0 (both checked live: the same file byte for byte, the signature as built). Also found and fixed this round: **the studio crackled on the phone** when the phone was busy (an app updating in the background): it ran its sound engine with 3 ms buffers; phones and tablets now get "balanced" (about 22 ms): 0–1 gaps in 30 s instead of 19–24 (Chrome's own `playbackStats`, now checked by the phone test). Listeners never heard it: what went out had no holes even with the crackles.

**Song changes heard with the album** (round 24): `tools/song-changes.mjs` prepared all 22 songs as on upload and mixed the 21 changes as the player does; the clips page is at http://localhost:8080/song-changes/ (Laragon only, outside the repo). No clipping (highest peak −1.3 dB) and no dead air. Worth hearing: `19 → 20` (5.1 s very quiet), `20 → Cain` (quiet into quiet) and three levelling steps of 5.7–8.5 LU. Two songs couldn't reach −16 LUFS because of their peaks (−17.8 and −16.5): a true-peak limiter in song prep would fix that (see the decision on mastering plugins). Next: the user listens, then a live session.

## Next steps

**Core & manager (`v0.1`) is done**; now **Player & studio (`v0.4`)**. The order it was built in:

1. ~~Project layout~~, ~~MySQL schema~~, ~~web installer~~ (done 2026-10-20).
2. ~~Install on the test server~~ (done 2026-10-20 over SSH).
3. ~~Sign-in, roles and the manager shell~~ (done 2026-10-20).
4. ~~Planner on MySQL, with a cron~~ (done 2026-10-20).
5. ~~Station manager screens: library upload, playlists, schedule, DJs, Skip~~ (done 2026-10-20).
   ~~Sound & safety screen~~, ~~song prep on upload~~ (done 2026-10-20; prep re-encodes with lamejs
   instead of the ffmpeg.wasm the build-out order in `docs/POC-PLAN.md` named, see Decisions).
   **v0.1 closed as `0.1.0`** (2026-10-20).
6. **Now: Player & studio (`v0.4`)**: ~~the listener player~~, ~~presenter notes for listeners~~,
   ~~the live studio with its notes drawer~~ (done 2026-10-20, live-tested on the test server),
   ~~the output strip~~ (round 23), ~~song changes heard with the album~~ (round 24).
   ~~Sound & safety v0.4, now-playing API and embed~~ (round 25, deployed and tested on the server 2026-10-21).
7. **The rest of v0.4, in the order the user chose (2026-10-20):** ~~now-playing API and embed~~ (round 25)
   → ~~listener count and stats~~ (round 27) → ~~podcasts~~ (round 29) → ~~small studio items~~ (round 30:
   headphone output picker, edit a posted note, talk items mark "now") → ~~spectrum~~ (round 31) → ~~hi-fi skin~~ (round 32: skins picked per device). **v0.4 is complete.**
8. ~~**The Windows studio app (`v0.6`)**~~: Tauri app, local Icecast bridge for BUTT/Mixxx, signed installer
   with auto-update (round 33; `v0.6.0` released 22 Oct). **Next: the WordWave pilot (`v0.8`)**, then
   **launch (`v1.0`)** with the optional relay to an outside Icecast. A new app release: raise the version in
   `tauri.conf.json` and `Cargo.toml`, `bash tools/build-desktop.sh`, then a GitHub release `v<version>` with
   both files from `desktop/dist/` (`gh release create v<version> <exe> <exe>.sig --target <full commit id>`).
9. Later: mastering in song prep once the user has compared oXygen and master_me.
10. **An official Android app** is planned (the user, 2026-10-21; its milestone isn't set). iPhones and iPads
   keep the browser studio (no Apple Developer account). On the Android app's list: **Do Not Disturb on air,
   with a warning first** (see Decisions).

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
| 2026-10-20 | Test server at **wordwave.controll.co.za**, POC in `/stationwagon-poc/`, DNS proxied through Cloudflare | User's choice. Proxied so the Cloudflare caching test (R7) runs on the real setup |
| 2026-10-20 | Server settings live in `config.local.php` (gitignored, denied by `.htaccess`), made by the deploy script | Keeps the control key out of the repo and off the web |
| 2026-10-20 | MP3s (songs and live pieces) are sent as `public, max-age=31536000, immutable` | The host's default (`max-age=7200, must-revalidate`) made Cloudflare re-check with the server on every request. File names must change if a file's content changes |
| 2026-10-20 | The real station will use **wordwave.controll.co.za** (not wordwave.co.za) | User's choice. The test server already lives there; the POC stays in `/stationwagon-poc/`, so the station itself can later sit at the root |
| 2026-10-20 | Playlist is cached at Cloudflare for 2 s (Cache Rule + `s-maxage=2`; browsers `max-age=0`) | Without it every listener runs PHP every ~6 s. With it PHP runs about once per 2 s per Cloudflare location; 2 s of staleness is harmless at a 20 s delay |
| 2026-10-20 | **POC closed as passed**; next milestone is Core & manager | All seven risks answered on the real host |
| 2026-10-20 | The real app uses **MySQL** on the cPanel account (not SQLite) | User's choice; standard on shared hosting and room to grow to more stations |
| 2026-10-20 | The app lives in **`app/`**: plain PHP 8.1+, no Composer and no framework, one front controller, PSR-4-style autoloader | Upload-and-go on shared hosting, like the POC |
| 2026-10-20 | Schema: InnoDB, utf8mb4, a table prefix (default `sw_`), **no JSON columns** (MEDIUMTEXT holding JSON), stream times as `DECIMAL(15,3)` unix seconds, other times UTC `DATETIME`; the version is stored in `settings.schema_version` | Same behaviour on MySQL 8 and MariaDB 10.5+; the timeline needs millisecond stream times |
| 2026-10-20 | Supported databases: **MySQL 8.0+ or MariaDB 10.5+** (the installer checks) | The server has MariaDB 10.11, this PC MySQL 8.4 |
| 2026-10-20 | A WordPress-style **web installer** writes `config.local.php`. If the file can't be written, it shows the text to upload instead. It runs only until that file says installed | Users set up on cPanel without SSH |
| 2026-10-20 | On a public server the deploy leaves a **one-time setup key** (`install.key`) that the installer asks for and then deletes | Otherwise anyone who finds a fresh copy could install first and become admin |
| 2026-10-20 | The **green skin** is the default for new stations (station setting `skin`) | It is the one with presenter notes |
| 2026-10-20 | A **command-line installer** (`cli/install.php`, JSON on stdin) sits beside the web one; it reads `install.key` itself | Lets an agent or admin with SSH install without typing passwords into a web form; stdin keeps them out of the process list |
| 2026-10-20 | Test-server admin is **vincent@controll.co.za**; the station name is "Radio WordWave / Radio WoordGolf" | User's choice |
| 2026-10-20 | Sign-in with PHP sessions stored in `storage/sessions`; cookie `sw_session` is HttpOnly, SameSite=Lax, Secure on https, scoped to the app's folder; 12 h idle limit; every form has a key (CSRF) | Safe defaults on shared hosting, away from the host's shared `/tmp` |
| 2026-10-20 | Public pages never start a session | Listeners get no cookie, which keeps their pages cacheable later |
| 2026-10-20 | Roles rank **dj < manager < admin**; a page names the lowest role it needs | Simple and matches the designs |
| 2026-10-20 | Lock-out: 5 wrong tries per email or 20 per address in 15 min. The address comes from `CF-Connecting-IP` only when the request really comes from a Cloudflare range | Behind Cloudflare every visitor would otherwise look like a few Cloudflare addresses |
| 2026-10-20 | Changing a password signs out the account's other sessions; forgotten passwords are reset by an admin (or `cli/set-password.php`), no email reset for now | No mail setup needed yet |
| 2026-10-20 | **Claude writes, deploys, sets up and tests everything itself**; the user only answers decisions | User, 2026-10-20: "I need you to fully automate the writing and testing please" |
| 2026-10-20 | **Planner on MySQL**: `timeline` rows hold numbering and piece counts; a song's pieces come from `tracks.pieces`, a live show's from `live_pieces`; "recent songs" come from the timeline itself | One source of truth; a cut just shortens a row and deletes the rows after it |
| 2026-10-20 | Planning runs under a MySQL **named lock** (`GET_LOCK`), named from the database, table prefix and station | Named locks are server-wide on shared MariaDB; another account's app must never share ours |
| 2026-10-20 | Timeline history is kept 24 h. After a quiet spell (plan ran out more than 60 s ago) the stream restarts at "now" with numbering carrying on | Found by the test: the POC would have filled two quiet days with ~900 unheard songs on the first request. Gaps under 60 s (an ended live show) carry on seamlessly |
| 2026-10-20 | Songs are stored once as `storage/media/<sha1>.mp3`; titles and artists come from ID3 tags, else the file name | A file's address never changes, so the year-long immutable cache is always safe |
| 2026-10-20 | Playlists: shuffle (never one of the last 5) or in order. The schedule's playlist blocks use the station's clock, days as a bitmask (Mon = 1 … Sun = 64), overnight blocks belong to their start day, start = end means all day, newest block wins | Matches the schedule table from schema v1 |
| 2026-10-20 | Optional cron every 5 minutes (`cli/plan.php`); listeners' requests still plan lazily | Keeps the plan ready when nobody listens |
| 2026-10-20 | Cloudflare Cache Rule gets **Browser TTL: Respect origin TTL** | The user's OK, 2026-10-20. Origin-only fixes don't work: Cloudflare overrides every browser header on responses it caches |
| 2026-10-20 | Default station settings are the values the POC proved: 6 s pieces, 6 s crossfade, 2 s skip fade, 3 h plan ahead, 5 recent, 1 s cut lead, 8 s live timeout, 20 s listener delay | Proven on the real host |
| 2026-10-20 | Every change in the manager (songs, playlists, blocks) **replans at once** | What the manager sees is what plays next; pieces already published stay as they are |
| 2026-10-20 | A song **can't be removed while it's on air** or played in the last 2 minutes; its file is deleted only when no other song row uses it | Listeners are up to 20 s behind and may still be fetching its pieces |
| 2026-10-20 | Uploads take **MP3 only**. `upload.js` sends files one at a time with progress; without JavaScript the plain form still works | Shared hosts cap the size of one request; one file per request keeps each under the cap |
| 2026-10-20 | Who may change whom: admins give any role, managers give DJ or manager; nobody changes their own role or removes themselves on the DJs screen; the last admin stays | A manager can't lock out the admin, and nobody locks themselves out |
| 2026-10-20 | **Skip** in the manager is for managers and admins; DJs will skip from the studio | Matches the design; listeners never skip (decided 2026-10-19) |
| 2026-10-20 | Deleting a playlist also deletes its schedule blocks | A block with no playlist can't play anything |
| 2026-10-20 | The week grid shows 06:00–24:00; the part of an overnight block after midnight is drawn on the next day | Night hours rarely change; the blocks table lists every block in full |
| 2026-10-20 | Sound & safety shows **only settings that work today** (crossfade, skip fade, live cut-in fade, back-to-AutoDJ timeout, songs not to repeat). The design's fade shape, ducking, silence detection, rejoin window, sound quality and listener delay are listed on the screen as coming in v0.4 | A control that does nothing yet would mislead the manager; piece length also needs every song re-cut |
| 2026-10-20 | Go-live has its **own fade** (`live_fade_seconds`, default 6 s, as before when it used the crossfade) | The design has a separate live cut-in fade |
| 2026-10-20 | The back-to-AutoDJ timeout is at least one live piece + 2 s (8 s with 6 s pieces), at most 30 s | A shorter timeout would end every show before its first piece arrives |
| 2026-10-20 | **Song prep re-encodes in the browser** (Web Audio decode + lamejs), not ffmpeg.wasm and not "measure only" | User's choice. Every listener hears levelled songs, the encoder is 150 KB (ffmpeg.wasm ~30 MB) and already proven in the studio |
| 2026-10-20 | Prep values: −16 LUFS target; gain stops where a sample peak would pass −1 dBFS (no limiter) and at +12 dB; silence below −50 dBFS trimmed with 50 ms kept; 20 ms fades; 192 kbps, 44.1 kHz stereo (mono becomes stereo) | Common streaming loudness; no clipping and no noise blown up; crossfades do the real transitions |
| 2026-10-20 | A track's `sha1` is the **original file's** hash (sent by the browser); the stored file is named by its own content hash | The same song uploaded twice is caught even though each levelled copy can differ byte for byte |
| 2026-10-20 | When prep can't run (silent, unreadable, over 60 MB, an old browser, or the levelled copy too big for the server), the original goes up as it is, saying why | An upload never fails because of prep; the server still checks the file is an MP3 |
| 2026-10-20 | lamejs is served from the app (`assets/vendor/`), not a CDN | Shared hosting installs shouldn't depend on another site being up |
| 2026-10-20 | **v0.1 (Core & manager) closed as `0.1.0`**; next milestone Player & studio `v0.4` | User's choice: every v0.1 item built and passing on Laragon and the server |
| 2026-10-20 | **One delay for every listener**: the player plays the timeline `listener_delay_seconds` (20 s) behind the server clock, songs and live alike | Same moment on every device, and live pieces have time to arrive. Closes the POC's open issue that P1 and P2 listeners were 20 s apart |
| 2026-10-20 | The player: two song decks (crossfades, as P2) plus a **stream deck** (hls.js on `api/stream.php`, held at the delay as P1) for live shows, faded against the song decks; browsers without Web Audio, or `?mode=stream`, get the stream deck alone | Live audio exists only as pieces in the stream; the stream already holds the right moment |
| 2026-10-20 | Player safety nets: no song deck plays past its own end (plus its fade), and 3 s into a live show song decks fade out even if the stream isn't ready | One unexplained test run left a song playing under a live show; this holds whatever the cause |
| 2026-10-20 | hls.js 1.5.13 is served from the app (`assets/vendor/`), loaded only when the stream is needed | Same reason as lamejs; most listeners never need it during AutoDJ |
| 2026-10-20 | Scripts and styles are linked with a **hash of their content** (`App::asset`, `?v=` + 10 hex), not the version | The host tells browsers to keep JS for a week, so the phone ran an old player for a whole round while `0.4.0-dev` never changed. The prep worker's tag also covers `prep-core.js`, which it loads with the same tag |
| 2026-10-20 | **Sound engine watchdog**: when the Web Audio clock stands still for 0.3 s while "running", suspend and resume it; after two nudges that don't help, build the player again | The Xiaomi phone freezes it about every 60 s with the screen locked and fires no event; before the fix that was up to 25 s of silence. The engine also asks for `latencyHint: "playback"` (bigger buffers; didn't stop the freezes). A plain `<audio>` is no way round it: Chrome paused one as soon as the screen locked |
| 2026-10-20 | Presenter notes are **posted only from the live studio** (built with it); this round builds the listener side and the server rules | User's choice ("Wait for the studio"), over a stopgap notes page in the manager |
| 2026-10-20 | **Verse text is pasted by the presenter**; the app ships no Bible text. Each verse stores its version (default **KJV**, any short code such as AFR53) and links to Bible Gateway | User's choice for the text; no preference on the version, so per note with KJV as the default (matches the design) |
| 2026-10-20 | Notes belong to a live show. Listeners see the open show's notes, else the last show's from the past week; a new show never shows the previous show's notes under it. A show holds 50 notes | Matches the design ("This morning" between shows) without stale notes under a different presenter |
| 2026-10-20 | A note appears to listeners when they reach the moment it was posted (posted time ≤ server clock − 20 s) | Everyone hears the show 20 s late; a note shouldn't run ahead of what the presenter is saying |
| 2026-10-20 | The timeline sends `notes_rev` (one small query); the player fetches `api/notes.php` only when it changes | No extra polling per listener; links are built on the server, the panel is built as text (no HTML from notes) |
| 2026-10-20 | Milestone estimates **moved a month earlier**: v0.1 late Oct, v0.4 Nov, pilot Dec, launch Jan–Feb | User's choice; Core & manager had only 2 items left on 20 Oct |
| 2026-10-20 | The studio round builds **the whole designed studio** (decks, mixer, mic strip, running order, status strip) plus the notes editor; recording to a podcast waits | User: "Follow the stationwagon project map", which lists the studio as one item; recording isn't on the map for v0.4 |
| 2026-10-20 | Presenter notes are posted from a **drawer from the side** (a Notes button with a count in the top bar), over the right of the desk; full width on a phone | User's choice of three layouts |
| 2026-10-20 | **A DJ may go live only in their own live block**, from 5 minutes before it starts until it ends; a live block with no DJ named is open to every DJ; **managers and admins any time** (inside a block the show takes its name, else "Live with <name>"). A show runs on past its block until ended | User's choice ("Only in their block") |
| 2026-10-20 | Deck B and the running order take songs from the **station library** (search), not the DJ's own files | User's choice: listeners get levelled songs from the server |
| 2026-10-20 | **Schema version 2** adds `running_orders`. Upgrades are steps in `Schema::migrations()` that only add; they run after a deploy (`cli/upgrade.php`, called by `tools/deploy-app.sh`) and on the first page view (`Schema::ensure`, remembered in `storage/cache/schema-version`), under a named lock | Shared hosting has no migration tool; an older install must come up to date by itself |
| 2026-10-20 | The studio's endpoints are `/studio/api/<action>` through the front controller: signed-in only (401 JSON), POSTs carry the form key in an `X-CSRF` header, and the session is released at once so pieces never queue behind other requests. Pieces must start with an MP3 frame, be under 2 MB and last at most two piece lengths | One sign-in and session for the page and its feed; nothing else may write live audio |
| 2026-10-20 | Studio decks play **decoded audio buffers** (sample-exact pickup at go-live), not `<audio>` elements; only the AutoDJ song on air is decoded ahead | Exact sync; a decoded song costs about 10 MB a minute, so nothing else is kept |
| 2026-10-20 | Cue sends a deck to the DJ's headphones before the crossfader; the DJ never hears their own mic | Standard DJ desk behaviour; hearing your own voice late is distracting |
| 2026-10-20 | A running order belongs to the live block (`block:<id>`), or to the person outside blocks (`user:<id>`); 60 items at most: songs, station IDs (both from the library) and talk items with a length. Songs taken out of the library drop out of it | Plans are made for a show slot, which repeats weekly |
| 2026-10-20 | Studio fades (Fade to B, Skip) are **run by the sound engine** as gain curves; a timer only moves the slider | A fade driven by screen frames stalled in the test when the page wasn't being drawn; a DJ switching tabs mid-fade would have hit the same |
| 2026-10-20 | The DJ's mic preset, slider values and switches are remembered in their browser | Each studio PC has its own mic; nothing to share between people |
| 2026-10-20 | The **output strip shows the wet mix**: what goes out after fades, ducking, the compressor and the limiter, measured in the sound engine (an AudioWorklet, 10 ms columns); the part to the right of "now" is mixed ahead from the real audio, not from peaks stored at upload | The user: peaks at upload are the dry mix; the strip must show ducking and processing too, and "that is what an audio buffer should be for" |
| 2026-10-20 | The **player reads the coming 20 s itself**: song pieces as byte ranges of the files the decks play (the browser's cache serves them, so nothing downloads twice), live pieces copied as hls.js loads them; mixed ahead through the same fade curves the decks use | The user: "Load 20s ahead, process / read that 20s' graph and append to the display" |
| 2026-10-20 | The studio's strip sits **above the decks, full width, 50 px high**, with a show/hide toggle on the left and a drag handle to change its height (30–240 px, remembered) | The user's choice |
| 2026-10-20 | The studio's **ducking works per deck** (each deck has its own duck gain after the crossfader), so the strip can show each deck as it goes out | Needed to draw each source after its ducking |
| 2026-10-20 | **Mastering plugins compared first**: oXygen (BSD-3, JUCE, has a true-peak maximizer and Reference Match) and master_me (GPL-3, Faust, built for live streams and internet radio) are installed in Adobe Audition on this PC so the user can compare them before we take anything into song prep or the studio. Porting oXygen code is allowed with credit; master_me's code would make Stationwagon GPL, so only its ideas | The user wants to hear their strengths first. oXygen 1.2.1 has no Windows release: built here from source (`D:\Build\oXygen-1.2.1`, static C++ runtime, because Audition ships a 2015 `msvcp140.dll` that crashes the default build) |
| 2026-10-20 | **Milestones reordered**: finish `v0.4` (in this order: Sound & safety, now-playing API and embed, listener count and stats, podcasts, small studio items, spectrum, hi-fi skin), then the **Windows studio app as `v0.6`** (Tauri, a local Icecast bridge for BUTT/Mixxx, a signed installer with auto-update), then the WordWave pilot `v0.8`, then launch `v1.0` | User's choice ("Finish v0.4, then Windows, then pilot"); the project map was refactored around the final app the same day |
| 2026-10-20 | **Podcasts are a feed on the station**: the studio records the show and uploads it after End show; the station gets its own podcast page and RSS feed, and a manager screen to edit or hide episodes | User's choice |
| 2026-10-20 | **Listener count by anonymous ping**: each open player pings once a minute, with no cookies and no IP address kept; "listening now" in the player and the manager, plus daily and hourly charts | User's choice; nothing personal to store or protect |
| 2026-10-20 | **Relay to an outside Icecast** (for classic radio apps) comes later, as an option, off by default (`v1.0`) | User's choice |
| 2026-10-20 | Sound & safety settings are **sliders or choices** (`StationSettings::FIELDS`); choices are checked against their list. Fade shape and ducking apply at once; silence detection only to songs prepared from now on (older songs have no `outro`); the prepared-song quality only to new uploads; the live quality from the next show | Each setting acts where its data exists; nothing is re-encoded behind the manager's back |
| 2026-10-20 | **Silence detection** moves on at the last piece boundary at or before the point where the song stays below the level to its end (and never later than full length − crossfade); the quiet tail still plays out under the next song | Keeps the HLS pieces and the two-deck player in step; a long fade-out isn't cut dead |
| 2026-10-20 | **Rejoining always starts a continuation show** (`continues_show_id`, notes moved over, the old show closed as `rejoined` or `timeout`), never sends more pieces to the old one; the AutoDJ fills the gap | Sending to the open show would place new audio at the stream time where it stopped; after a gap over ~12 s listeners would stall for the rest of the show |
| 2026-10-20 | The **listener delay is shown, not editable** (it comes from the 6 s pieces) | Songs on air are already cut into pieces of that length; changing it needs a rescan of the library (an open issue) |
| 2026-10-20 | **Now playing** (`api/nowplaying.php`) is told at the listener delay (what the station's player is hearing), with now, the next 3 songs and the last 5; titles and times only, no file addresses; any site may read it (CORS `*`); `public, max-age=0, s-maxage=2`, ready for a Cloudflare cache rule like the playlist's | Other websites and apps should show what their listeners hear, not what the server plans 20 s ahead |
| 2026-10-20 | **The embed** is its own page at `/embed` (no site header, no session) that any site may frame (`frame-ancestors *`, no `X-Frame-Options`); `?look=light` gives a light version for light websites. It plays the HLS stream (no two-deck mix) and holds the same 20 s delay as the station's player, silent until it does; the code to copy is an iframe 124 px high, up to 520 px wide | One station per install for now, so no station in the address (the mock-up's `/embed/wordwave`). Holding the delay keeps the title shown in step with what's heard |
| 2026-10-20 | **Skip does nothing while a live show is on air or waiting to start** (the few seconds after Go live) | Cutting then dropped the waiting show from the timeline; the handover's own fade already moves the music on |
| 2026-10-21 | **How listeners are counted**: an open player (or embed) pings `api/listen.php` once a minute while it plays, with a random 16-hex id made when Listen is pressed (in memory only: no cookie, no storage), and a last ping on Stop or when the page closes (`sendBeacon`). "Listening now" = ids heard in the last 90 s. Nothing else is stored: no IP address, no browser details. Pings closer than 50 s add nothing | The user's choice (anonymous ping, 2026-10-20). Without a kept id a "listen" is one press of Listen, not one person, and the screen says so |
| 2026-10-21 | **Stats per hour** (`listener_hours`, UTC hours): the most at once, listener-minutes (one per ping) and listens; kept 400 days; quiet players' rows deleted after 10 minutes. Days and hour labels use the station's time zone | Small, enough for hourly and daily charts, and nothing personal |
| 2026-10-21 | The **Listeners** screen (managers): four tiles (now, most at once today, hours listened today, listens today), a 48-hour chart of the most at once per hour and a 30-day chart of hours listened per day, each with a table view and a tooltip per column; drawn on the server as SVG in one colour (`--chart: #18a84a`, checked with the dataviz validator against the dark card). "Listening now" also shows on the player's health line (from every timeline fetch) and the manager's overview; now playing carries the count for other sites | The design's health line and overview stat, plus the charts the user asked for |
| 2026-10-21 | **A round is done only when every feature is fully written and tested**; limits are fixed in the round or put to the user as a choice, and a limit that can't be fixed is agreed, not just listed | The user's rule, so nothing is forgotten (also in `CLAUDE.md`) |
| 2026-10-21 | **Listener analytics with addresses** (reverses the anonymous ping of 2026-10-20): each Listen keeps its address and user agent for **30 days**; device, browser and app from the user agent; country and city from **DB-IP Lite** stored and looked up on our server (CC BY 4.0 credit on the screen); unique listeners per day and month. After 30 days only totals are left (kept 400 days) | The user's choice, for analytics. Lookups on our own server send no address anywhere |
| 2026-10-21 | **Unique listeners** = one address and browser, marked per day as a truncated HMAC with a **salt that changes each month** (the old one is deleted), so a month's days count together and old marks can't be traced back | Counts people across days without keeping an identifier longer than a month |
| 2026-10-21 | **A Privacy page, no banner**: listening sets no cookies, so POPIA asks for a notice, not consent; the page (what, why, how long, who sees it, rights, Information Regulator, who to ask) is linked under the player and in the embed; the station sets its contact address on the Listeners screen | The user's choice |
| 2026-10-21 | **Test runs carry `StationwagonTest` in their user agent** and stay out of every total (they still count as listening now, which the tests check); **at most 20 new Listens per address in 10 minutes** (429 after that) | Test runs no longer bend the numbers; one address can't inflate them |
| 2026-10-21 | The studio's **MP3 encoder runs in a worker** (`studio-encoder.js`), with a generation number per show so an old show's tail is dropped | A busy page could otherwise delay the pieces |
| 2026-10-21 | **Signed-in tests on the test server**: the full web test (throwaway DJ and manager, removed afterwards) and the studio test live through Cloudflare; the Cloudflare cache rule covers now playing too | The user's OK |
| 2026-10-20 | Saved copies of the published mock-ups in `prototypes/*.html` are **gitignored** | The user's choice; the sources are in `design/` |
| 2026-10-21 | **Every live show becomes a draft episode**; the DJ or a manager publishes it. Shows under 2 minutes (`podcast_min_seconds`, 120) are skipped. A show and its rejoins are one episode | The user's choice |
| 2026-10-21 | **The studio records on the device in two tracks** (replaces "the studio records the show and uploads it after End show" of 2026-10-20): music in stereo as it went out (after fades and ducking, 128 kbps) and the mic in mono (96 kbps), started on the same sample as the first one on air; 10 s chunks cut on whole MP3 frames, kept in IndexedDB with peaks per chunk, uploaded after End show and again whenever the studio opens (not while live or while a show can be rejoined) | The user's choice. The device has the clean sound; 10 s chunks keep the browser's memory small and let a broken upload carry on |
| 2026-10-21 | **Clips split at every silence of 2 s or more** (below −50 dBFS, in 10 ms blocks); the editor (any browser) has two tracks, zoom, click a clip and Delete to cut it, undo, and **Mixdown**: kept clips are mixed in the browser, gaps of 2 s or more shrink to 1 s, deleted clips are muted on their own track with 10 ms ramps, 128 kbps stereo, uploaded in parts of about 1 MB | The user's choice of editor scope. Mixing in the browser needs no audio tools on shared hosting |
| 2026-10-21 | The mixdown shows **a time estimate** while it runs ("about N min left") | The user's choice ("OK, show an estimate") over a faster but harder server mix |
| 2026-10-21 | **Fallback: an episode from the aired pieces** when a show has no device recording; and a device recording that stopped early (over 1 s short, e.g. a reload) is **filled in from the aired show** right after it | The user's choice ("Fill from aired") |
| 2026-10-21 | **Raw tracks are deleted 30 days after publishing** (the published MP3 stays) | The user's choice |
| 2026-10-21 | The podcast has **a public page, an RSS feed** (iTunes tags, cached 5 minutes) and **a manager screen**; the editor's API is private and never cached (matched on `THE_REQUEST`, because the rewrite makes `REQUEST_URI` index.php) | The user's choice |
| 2026-10-21 | **Notification sounds**: the browser can't silence them. Measured on the phone: Chrome turns its own `<audio>` players down (the listener player), but the studio's mix and recording (Web Audio) stay level; the risk left is the sound reaching the mic. So the browser studio, on a phone or tablet, **reminds the DJ to turn on Do Not Disturb (Focus on an iPhone) and wear headphones** | The user's choice ("Note it + browser reminder") |
| 2026-10-21 | **The Android app will turn on Do Not Disturb at Go live and restore it at End show, warning the DJ first** (it needs Android's Do Not Disturb access, granted once in Settings) | The user, 2026-10-21: the final Android version is an installed app; iOS stays on the browser studio |
| 2026-10-21 | **Go live always starts at least the cut lead (1 s) ahead**: a song that ends inside the lead plays out and the next one is cut instead (`Planner::cut` with `$fullLead`; the POC engine too). Skip is unchanged | Found by the POC engine test (failed 3 of 15 runs): the show could start 0.08 s after Go live, before the studio even had the start time |
| 2026-10-21 | **The project map is kept in `prototypes/Stationwagon Project Map.html`** (with `docs/project-map.html` as the tracked copy), not republished as an artifact | The published artifact was gone; the user: "There is already a map under the prototypes folder which you can update" |
| 2026-10-21 | **Headphones menu**: one choice for everything the DJ hears (the music going out and any deck on Cue); the studio's sound engine moves to that output (`AudioContext.setSinkId`), remembered per computer; shown as a menu only with more than one output, else the output's name | The user's choice ("Everything the DJ hears") over a separate cue output |
| 2026-10-21 | **An edited note pops up again as new**: it is posted again at the moment of the edit (same kind), so listeners get it with "New" and a toast when they reach that moment; the old version stays for them until then (`replaced_by`), and is dropped from the server 2 minutes later. Removing a note removes its old versions too. Editing works while the show is on air, with the same rights as removing | The user's choice ("Pops up again as new") |
| 2026-10-21 | **Talk items mark themselves "now"** when the mic opens and the next item in the running order is a talk item; a **Now** button on each talk row marks one by hand (also to go back) | Recommended; the user had no preference |
| 2026-10-21 | A **Stop that overtakes a Listen's first ping** is kept as an ended Listen, so that ping can't count the player afterwards; the strip's look-ahead reads a song only once its deck isn't downloading it (else the same bytes came twice) | Both found on the test server, where PHP 8.4 without OPcache made the first ping take 1.4 s |
| 2026-10-21 | Schema upgrades skip an `ADD COLUMN` whose column is already there (MySQL error 1060) | A step cut short between the column and the version would otherwise fail on every later upgrade |
| 2026-10-22 | **Skins are the viewer's, per device, on every screen** (replaces "the station's `skin` setting" of 2026-10-20 and "the studio is green only"): a Look menu on every page; the choice kept in the browser so every screen on that device follows it, open tabs too; the embed has its own small Look button (in another website's frame its storage is that website's). Green stays the default | The user: "any user should be able to choose their own skin and that skin should be persistent for that device", "setting the look on one screen should apply the same look across all screens for that device"; the embed's own picker was the user's pick of three |
| 2026-10-22 | **Tokens are the one place for the look**: every colour, corner size, bevel and font in `app.css` is a token; a skin is one `:root[data-skin]` block plus a line in `Skins::ALL`; canvases read tokens through `SwSkin.tok()`; `web_test.php` fails on a colour or corner size written in the components | The user: the design "is not critical for now", what matters is that "the theming / central tokens should be preserved" |
| 2026-10-22 | **The embed starts itself again** (on a new audio element, the same listener ping, at most every 5 s) when paused from outside, when the stream stalls for good, or when the sound hasn't moved for 8 s while it should play; the phone test turns the media volume up one step with the volume key for its run and back down after | Found on the phone: MIUI pauses "silent" app audio (media volume 0) after 60 s with the screen off; the user chose both fixes |
| 2026-10-22 | **Presenter notes stay "Live" for listeners until they reach the end of the show's sound** (`ends` = start + the pieces it sent), not until the show closes | Listeners are 20 s behind: a note reaching them in the last 20 s is still new to them |
| 2026-10-22 | **The spectrum** (player display): 28 bars, log-spaced 40 Hz–16 kHz, each the loudest FFT bin in its band (an analyser of 4096 points on the station's mix, before the listener's volume, −90 to −20 dB); bars rise at once and fall 1.6 a second, peak marks hold 0.6 s then fall; it stops drawing when playback stops, redraws once a second with reduced motion, and isn't started in stream-only mode. Player only (the design's main window); colours are skin tokens | The mock-up's look, measured from what goes out; the same choices as the output strip (the station's sound, not the listener's volume) |
| 2026-10-22 | **BUTT or Mixxx connecting goes live by itself** (when the DJ's slot allows it; otherwise the banner says why); BUTT stopping ends the show 5 s later and the AutoDJ takes over; back within 5 s carries the same show on | The user's choice: as BUTT works with AzuraCast today |
| 2026-10-22 | **The Windows app shows the station's own studio page** (no copy inside the app) and adds the bridge; the station's pages may only use the bridge's events and status, say they're on air, close the app and open its settings | Always the server's version; nothing else of the app reachable from a web page (tested: the rest is refused) |
| 2026-10-22 | **The bridge passes BUTT's MP3 frames through unchanged**: no decoding or re-encoding, MP3 only, 127.0.0.1 only, one source at a time; Icecast (`SOURCE`, `PUT`) and Shoutcast 1 (port − 1) | The sound as BUTT made it; no audio work in the app; the real BUTT uses all three |
| 2026-10-22 | **Updates are signed with our own updater key only** (no paid code-signing certificate): the key is `~/.tauri-keys/stationwagon.key`, its password in gitignored `tools/deploy/updater-key.local.txt` | The user's choice: free; Windows SmartScreen warns once at the first install |
| 2026-10-22 | **Releases go on the private GitHub repo**, handed out by a new small WordPress plugin, **Stationwagon Updates**, on controll.co.za, which holds a read-only token (not part of the LinkFlow plugin) | The user's choice; the updater can't read a private repo, and the token must not be in the app |
| 2026-10-22 | **Updates are checked and installed when the app opens**, never during a show (installing is refused while on air or while BUTT sends) | Installing restarts the app, which would cut the show |
| 2026-10-22 | **The studio on phones and tablets uses "balanced" sound buffers** (about 22 ms); computers keep "interactive" | With 3 ms buffers a busy phone crackled (19–24 gaps in 30 s, measured with Chrome's `playbackStats`); "balanced" gave 0–1, "playback" 4–7. Only the DJ's own monitoring crackled: what went out had no holes |
| 2026-10-21 | **The test server runs PHP 8.4**: someone switched every site on the account to 8.4 in cPanel (16:06); the user chose to keep it. The PHP Selector was set to 8.4 with the extensions 8.1 had and the same limits (memory 2 GB, uploads 512 MB, 10 000 input vars). OPcache, ionCube, brotli and pspell aren't offered for 8.4 on this host | The bare 8.4 had no mbstring, so the app's library, listeners and podcasts crashed, and the WordPress site at controll.co.za ran out of memory (128 MB) |

## Scope changes

| Date | Change |
|---|---|
| 2026-10-19 | Added: **presenter notes** in the listener player (verses with KJV text and a Bible Gateway link, references, video links, plain notes; "Live" with "New" tags during a show). Green player only for now. The studio needs a matching notes editor (not designed) |
| 2026-10-19 | Not now: hosting only the Web DJ part ourselves. BUTT is enough for WordWave for the moment |
| 2026-10-19 | Out of the POC on purpose (see `POC-PLAN.md`): MySQL/accounts/manager UI, upload-time audio prep (ffmpeg.wasm), the Windows app (Tauri), the Apache clock-rewrite playlist trick, recording/podcasts/stats |
| 2026-10-19 | Lowering the 20 s listener delay (to about 10 s) is low priority, since only station staff press Skip |
| 2026-10-20 | The studio's **notes editor is designed and built** as a drawer from the side (user's choice); `design/DESIGN.md` describes it and the studio as built. The mock-ups in `design/skins/` don't show it |
| 2026-10-20 | Not in this round: **recording a show to a podcast** (in the mock-up's status strip and end-show banner). It isn't on the project map for v0.4; it comes later |
| 2026-10-20 | The studio is the **green skin only** for now (as the presenter notes are) |
| 2026-10-20 | **Added to `v0.4`**: listener count and stats, and podcasts (recording, upload, page, RSS feed, episode editing); recording was left out of round 21 and is now in |
| 2026-10-20 | **New milestone `v0.6`: the Windows studio app**, before the pilot (it was part of launch) |
| 2026-10-20 | Added for `v1.0`: an optional relay to an outside Icecast server, off by default |
| 2026-10-21 | **Added to `v0.4`**: listener analytics (unique listeners; device, browser, app; country and city) and a Privacy page |
| 2026-10-21 | Podcasts built as **device multitrack recording with an editor** (was: record the show and upload it); the editor's scope is two tracks, zoom, delete clips and Mixdown |
| 2026-10-21 | Added to the roadmap: **an official Android app** (milestone not set), with **Do Not Disturb on air, with a warning first**. iOS stays on the browser studio |

## Milestone plan (estimates)

Plan & design (done 19 Oct) → Proof of concept (done 20 Oct) → Core & manager `v0.1` (done 20 Oct,
`0.1.0`) → Player & studio `v0.4` (done 22 Oct) → Windows studio app `v0.6` (done 22 Oct, `v0.6.0` released) → **WordWave pilot**
`v0.8`, running beside AzuraCast before the switch (est. Dec) → Launch `v1.0` (est. Jan–Feb).
The Windows app moved before the pilot on 2026-10-20 (see Decisions).
Dates after 20 Oct are estimates; moved a month earlier on 2026-10-20 (see Decisions).

## Versions

The POC is unversioned (its commits are the record). The app's version is in `app/VERSION`.

| Date | Version | Why |
|---|---|---|
| 2026-10-20 | `0.1.0-dev` | Core & manager build started (app layout, schema v1, installer). It becomes `0.1.0` when the v0.1 milestone is done |
| 2026-10-20 | `0.1.0` | v0.1 closed: installer, sign-in, planner on MySQL, the manager screens, Sound & safety, song prep. The Player & studio work becomes `0.4.0-dev` with its first code change |
| 2026-10-20 | `0.4.0-dev` | Player & studio started (the listener player) |
| 2026-10-22 | `0.6.0-dev` | v0.4 complete (round 32, `65f2d8d`); the Windows studio app started. The app for Windows has its own version in `desktop/src-tauri/tauri.conf.json` and `Cargo.toml`: `0.6.0`, its first release. Both become `0.6.0` when that release is out |
| 2026-10-22 | `0.6.0` | v0.6 closed: the app's first release `v0.6.0` published on GitHub (from `0358c8b`). The WordWave pilot work becomes `0.8.0-dev` with its first code change |

Schema version: **7** (`Schema::VERSION`; stored in the `settings` table). Version 7 (round 30) adds `presenter_notes.replaced_by` (an edited note's old version); Laragon and the test server are on 7. Version 6 (round 29) adds `episodes`, `episode_takes` and `episode_chunks` (podcasts). Version 5 (round 28) makes `listeners` again (one row per Listen, kept 30 days, with address, browser details and what they say) and adds `listener_tally` and `listener_visitors`; Laragon and the test server are on 5. Version 4 (round 27) adds `listeners` and `listener_hours`. Version 3 (round 25) adds `tracks.outro` and `live_shows.continues_show_id`; Laragon and the test server are on 4 (the server went 2 → 3 and 3 → 4 by the deploys of 2026-10-21). Version 1 was the first
twelve tables; version 2 (2026-10-20) adds `running_orders`. The test server was upgraded 1 → 2 by the
deploy on 2026-10-20.

## Change log

| Round | Date | Commit | What changed |
|---|---|---|---|
| 1 | 2026-10-19 | `08a24dc` | First commit: POC plan, `DESIGN.md`, both skins (green player with presenter notes), prototypes P0–P4, engine, test, tools |
| 2 | 2026-10-19 | `a63ddb6` | First Laragon tests: full-length crossfades; no repeat after a cut; Skip uses `heard`; 24-hour clock; `prototypes/.htaccess` no-cache; engine test now 42 checks; test log started |
| 3 | 2026-10-19 | `a36a512` | Phone tests over USB: P1 fixed 20 s delay; `tools/phone.mjs` (eval/nav/offline); README "Testing on an Android phone over USB"; P1/P2/P4 pass on two devices |
| 4 | 2026-10-19 | `91bdc0b` | `HANDOVER.md` and `CLAUDE.md` added; P3 partly passed (user heard the mic, ducking works) |
| 5 | 2026-10-19 | `d7403dd` | Private GitHub repo created; `origin` set and `main` pushed |
| 6 | 2026-10-19 | `3565f27` | `docs/project-map.html` project map added and published (light theme); milestone plan with estimates |
| 7 | 2026-10-20 | `c56b6a1` | Test server: subdomain + Cloudflare record, `tools/deploy-cpanel.sh`, `config.local.php` override, `.htaccess` denies it and caches MP3s for a year; P0–P4 pass on cPanel; P3 passed |
| 8 | 2026-10-20 | `36b122a` | Address decision (wordwave.controll.co.za); playlist edge cache (Cache Rule + `.htaccess` override of the host's no-cache); `tools/load-listeners.mjs`; load test 961/990 HIT; POC result summary; MySQL chosen |
| 9 | 2026-10-20 | `67f6fa4` | Core & manager started: `app/` layout and front controller, schema v1 (12 tables), web installer with a one-time setup key, `tests/install_test.php` (31 checks; Laragon MySQL 8.4 and server MariaDB 10.11), `tools/deploy-app.sh`; test-server database made with uapi; app deployed to `/stationwagon-app/`; version `0.1.0-dev` |
| 10 | 2026-10-20 | `c1f47c6` | `app/cli/install.php` (install over SSH, JSON on stdin; tested on a throwaway copy); test server installed with it; live checks: home connected, `/install` locked, private files 403 |
| 11 | 2026-10-20 | `1738762` | Sign-in: `Session`, `Auth`, `AuthPage`, `Request`; pages `/signin`, `/signout`, `/manage`, `/account`; header shows who's signed in; `cli/set-password.php`; tests `auth_test.php` (39) and `web_test.php` (37 / 22 public) with shared `tests/lib.php`; test database `controllco_swtest` on the server; all tests pass on both |
| 12 | 2026-10-20 | `baf49aa` | Planner on MySQL: `Mp3`, `Library`, `Planner`, `Stream`; `api/stream.php`, `api/timeline.php`; `cli/import.php`, `cli/plan.php` + cron on the server; "On air" card; `planner_test.php` (71, makes its own silent MP3s) and stream checks in `web_test.php`; fixed: quiet-spell restart; Cloudflare Browser TTL fixed (the POC had the same latent bug); test tones on air on the server |
| 13 | 2026-10-20 | `2737f87` | Station manager screens: `Playlists`, `Schedule`, `Staff`, `ManagePage`; `Library` edit / search / delete; views in `views/manage/` (overview, library, playlists, playlist, schedule, DJs), `upload.js`, manager styles; flash messages (`Session::flash`); `manage_test.php` (51) and a manager section in `web_test.php` (81 / 32 public); `tools/shot.mjs` (headless screenshots); fixes from screenshots (hidden elements, column and form alignment); deployed; all tests pass on Laragon and the server |
| 14 | 2026-10-20 | `d2070c3` | Project map fixes: the dial counted done items as "left" (said 8, really 2) and done chips looked open; added the missing v0.1 items (Sound & safety, song prep on upload) and the live studio part; charset and viewport lines added; milestone estimates moved a month earlier |
| 15 | 2026-10-20 | `75034f1` | Sound & safety screen: `StationSettings`, `ManagePage::sound`, `views/manage/sound.php`, styles; new `live_fade_seconds` setting used by go-live and sent by `api/timeline.php`; tests: `manage_test.php` 62, `web_test.php` 87 / 32 public; deployed; all tests pass on Laragon and the server |
| 16 | 2026-10-20 | `065aa2b` | Song prep on upload: `prep-core.js`, `prep-worker.js`, `prep.js`, lamejs in `assets/vendor/`; `upload.js` prepares each song and sends the original's hash and loudness; `Library::import` keeps them (duplicates by the original's hash); "Levelled" label; tests `prep_test.mjs` (24), `upload_browser_test.mjs` (12), `manage_test.php` 67; **fixed**: `upload.js` posted to a wrong address (`form.action` is the form's hidden "action" field), so drag-and-drop uploads had failed with 404 since round 13; deployed; checked on the live site; **v0.1 closed as `0.1.0`** (`app/VERSION`) |
| 17 | 2026-10-20 | `5f4e27a` | The listener player: the station page (`views/player.php`) with `assets/player.js` (server clock, two song decks, a stream deck with hls.js at the delay, stream-only mode, media session, event log for tests), hls.js 1.5.13 in `assets/vendor/`, player styles, today's schedule (`Schedule::today`); tests `player_browser_test.mjs` (17 / 10 listen-only), live-show commands in `browser_helper.php`, `manage_test.php` 70, `web_test.php` 91 / 36; version `0.4.0-dev`; the old `home.php` and `_onair.php` views removed; deployed; all tests pass on Laragon and the server |
| 18 | 2026-10-20 | `7c1803a` | The player on the Android phone: `App::asset()` tags scripts and styles by content (the phone had kept an old `player.js`); `player.js` gets the sound engine watchdog, logs engine state, page hidden/shown and deck waits, uses `latencyHint: "playback"`, and no longer hangs on Listen behind a lock screen (resume waits 2 s at most); `player_browser_test.mjs` 20 checks (a frozen-engine part; live pieces made beforehand and sent on a fixed beat, which explains the earlier "song deck under a live show": the show had timed out); new `player_phone_test.mjs` (10, passes on the phone); `web_test.php` checks the content tags; deployed; all tests pass on Laragon, the server and the phone |
| 19 | 2026-10-20 | `6ec17ca` | Docs brought up to date: `README.md` (status, the app, `tools/`, how it works now), the handover's file list (`App`, the app's milestone), the build-out progress in `docs/POC-PLAN.md`, the project map. No code changes |
| 20 | 2026-10-20 | `0171ccb` | Presenter notes for listeners: `Notes` (rules per kind, post / remove for the studio, the listeners' view, `rev()`), `api/notes.php`, `notes_rev` in `api/timeline.php`, `Planner::db()`, the "From the presenter" panel (`views/player.php`, `assets/notes.js`, styles; notes appear at the listener delay), `.htaccess` no-store for `notes.php`; tests `notes_test.php` (44), `player_browser_test.mjs` 26 (notes part), `web_test.php` 95 / 40, note commands in `browser_helper.php`; the long silent sample song removed from Laragon's library (it broke crossfade checks); deployed; all tests pass on Laragon and the server |
| 21 | 2026-10-20 | `053f8c1` | The live studio: `Studio` (who may go live when, show ownership, notes, running order, library search), `StudioPage` (`/studio` and `/studio/api/*`), `views/studio.php`, `assets/studio.js` (clock, decks with waveforms, mixer, encoder and piece upload, running order, notes drawer), `assets/studio-mic.js` (the POC's mic chain), studio styles; schema version 2 (`running_orders`) with `Schema::upgrade`/`ensure` and `cli/upgrade.php` (run by the deploy script); "Studio" in the header and "Open the studio" on the overview; tests `studio_test.php` (61), `studio_browser_test.mjs` (43, fake mic), `web_test.php` 113 / 43, studio commands in `browser_helper.php`; **found and fixed**: the crossfade was driven by screen frames and stalled when the page wasn't drawn (the sound engine runs fades now), and Go live showed "Joining…" only after Deck A had loaded; deployed (server upgraded to schema 2); all tests pass on Laragon (the studio test 3 runs in a row) and the server; then, with the user's OK, one minute live on the test server as the admin: 10 pieces, no errors, a listener tab 20.0 s behind with no dropouts |
| 22 | 2026-10-20 | with round 23 | The **output waveform** prototype (an artifact, not in the app yet): the wet mix scrolling right to left past a centred "now", one colour per source (song A blue, song B pink, voice yellow, final output a white line), measured left of now and the plan to the right, with crossfade, ducking and voice compressor controls and events marked; songs loaded into it are prepared with the app's own `prep-core.js` (trimmed, levelled to −16 LUFS, as on upload; a switch turns it off), and an "Actual size in the player" copy of the player window shows the strip at 470 px wide and 60 px high, one column per screen pixel, with its own time span (10 s by default; the user asked for twice the first 30 px and a bit more detail), and level lines at −1 (dashed amber: song prep's peak ceiling and where the limiter starts), −6, −12 and −20 dB (−40 on the decibel scale) in both views, labelled in dB on either scale (the user: the levels show the best peak and how far to raise the volume); the lines are faint on purpose (white at 10 %, the −1 dB line amber at 35 %: "barely visible, but visible"); **found and fixed**: the browser tests and `tools/shot.mjs` left headless Edge running after each run (still playing sound), because `msedge.exe` hands over to the real browser and exits, so `proc.kill()` missed it; they now close Edge over its debug port and then end anything still using the test profile (`upload_browser_test.mjs` and `studio_browser_test.mjs` pass on Laragon and leave nothing running); the user's test album measured (see Current status) |
| 23 | 2026-10-20 | `5383b4c` | The **output strip in the app** (design final, see `design/DESIGN.md` "Output strip"): shared `assets/outwave.js` (10 ms columns, the mix-ahead renderer, the canvas strip with faint level lines and event marks) and `assets/outwave-meter.js` (the AudioWorklet meter); **player**: a 60 px strip under the display (10 s span), a mix bus before the volume so the listener's volume doesn't change it, the look-ahead reading song pieces (new `pieces` on song entries near now in `api/timeline.php`) and live pieces (an hls.js loader that keeps a copy); **studio**: a strip above the decks (toggle, drag handle, 20 s span), per-deck ducking, a fade plan so the mix ahead follows Fade to A/B, coloured source dots on the decks and the mic; source colours as tokens in `app.css`; the prototype copied to `design/output-waveform.html`; tests: `player_browser_test.mjs` 33 / 18 listen-only (the strip measures in the sound engine, reads 20 s ahead, what it mixed ahead matches what went out within 1 dB, songs aren't downloaded twice, colours drawn; live pieces), `studio_browser_test.mjs` 54 (the same through a crossfade, toggle, height), `web_test.php` 116 / 46 (pieces near now, a piece as a byte range); **found and fixed**: the studio section's id clashed with the headphones label (`stOut`), and the studio's plan went stale after a click until the next tick, and the player test's cache check counted the look-ahead's first read of the next song as a second download (it now counts each file's bytes from the browser's network log: each file came once); deployed; all tests pass on Laragon and the server; `player_phone_test.mjs` not run this round (the phone wasn't connected) |
| 24 | 2026-10-20 | with round 25 | **Song changes with real music**: `tools/song-changes.mjs` and `tools/song-changes.html` (prepare a folder of songs as on upload, cut pieces with `Mp3::scan`, plan each change by the planner's rule, mix it as the player does, write a page of 15 s clips with charts and measurements; quiet starts and endings are noted, not flagged); run on the user's album (results in Current status and the POC test log; clips on Laragon only); the tool closes its browser when stopped early. Mastering plugins oXygen and master_me installed in Adobe Audition for comparison (see Decisions). No app changes, so no app tests this round |
| 25 | 2026-10-20 | `911631a` | **Sound & safety v0.4**: `StationSettings` rewritten (sliders and choices: fade shape, music under the voice, rejoin window, silence level, live and prepared-song quality; `mbPerHour`), new defaults in `Installer`, `views/manage/sound.php` rewritten (selects, a fade-curve preview, MB an hour, the listener delay shown) with styles; `assets/fades.js` used by the player (and its look-ahead) and the studio; `fade_shape` in `api/timeline.php`; song prep measures the last minute (`outro`, `prep-core.js`), `upload.js` sends it, `Library::outro` checks it, `Planner::quietFrom` moves on before a quiet tail; prep encodes at the station's quality (`data-kbps`); the studio's encoder uses the live quality, its mic presets the station's ducking, a dead-air warning after 10 s, and **Rejoin your show** (`Studio::rejoinable`/`rejoin`, `/studio/api/rejoin`, `Planner::endLive` takes a reason); schema 3 (`tracks.outro`, `live_shows.continues_show_id`). Tests: `manage_test.php` 81, `studio_test.php` 77, `web_test.php` 118 / 46, `prep_test.mjs` 27, `upload_browser_test.mjs` 13, `studio_browser_test.mjs` 60 (reload mid-show, "Leave site?" asked and accepted, Rejoin your show: a continuation show at a fresh boundary, notes moved, pieces in order); all tests pass on Laragon. **Found and fixed**: rejoining the same open show would have made listeners stall after a gap over ~12 s (now a continuation show). Also the project map was refactored around the final app (v28), and decision rows from rounds 23–24 that had landed under Scope changes moved to Decisions. **Now playing and the embed**: `NowPlaying` and `api/nowplaying.php` (at the listener delay, CORS, 2 s edge-cache header), the `/embed` route in `App` (no header, `frame-ancestors *`, `?look=light`), `views/embed.php` and `assets/embed.js` (hls.js first, holds the 20 s delay, silent at volume 0 until then), embed styles and a light look in `app.css`, "Put the player on your website" on the station page (iframe code, light-look switch, Copy); tests: `web_test.php` 132 / 60 (now playing against the timeline, the embed page, framing, light look, the card), `player_browser_test.mjs` 38 (an embed part: a made-up church website frames it, it shows what's on, plays 20.1 s behind, stops; the test's Edge runs without its local-network check so a web page may frame localhost); **found and fixed**: Edge claims native HLS but can't play it, and pauses muted media in a background tab; and (older, from round 12) Skip in the seconds between Go live and the show's start dropped the show from the timeline (`Planner::cut` and `skip` now leave a waiting show alone; `planner_test.php` 72); screenshots of the embed (470 and 340 px, dark and light) and the station page (1280 and 390 px). The saved mock-up copies in `prototypes/*.html` are gitignored. All tests pass on Laragon. Not deployed |
| 26 | 2026-10-21 | `d5e7c8e` | **Round 25 deployed** to the test server (schema 2 → 3 by the deploy). Server tests on MariaDB: install 31, auth 39, planner 72, manage 81, notes 44, studio 77; from this PC: `web_test.php --public` 60 and `player_browser_test.mjs --listen-only` 23 through Cloudflare (the embed framed by a made-up church site, playing 20.06 s behind; stream only 19.70 s). Live headers: the embed sends `frame-ancestors *` and no `X-Frame-Options` (the host's WordPress `.htaccess` adds none), now playing has CORS `*` and the 2 s edge header (Cloudflare doesn't cache it yet: `DYNAMIC`). **Found and fixed** from a signed-in screenshot of Sound & safety on the server (view only): with four cards the screen put three side by side and squeezed each setting's text to one word a line, and the choices were cut off; it now has two wider columns (`.soundform .cols`), a setting's control goes under its text in a narrow card (container query), choices sit full width under their text and the fade curve is capped at 300 px. Checked at 1280, 900 and 390 px on Laragon and at 1280 on the server; deployed again; `web_test.php` passes on Laragon and `--public` on the server |
| 27 | 2026-10-21 | `f358712` | **Listener count and stats**: schema 4 (`listeners`, `listener_hours`), `Listeners` (ping, now, hourly, daily), `api/listen.php` (POST pings, sendBeacon on Stop, 400 for a bad id, 405 for GET; `.htaccess` no-store), `assets/listeners.js` (a random id per Listen, a ping a minute, a last ping on Stop or page close), the player's health line "Listening now" (from the timeline feed's new `listeners` and each ping), the embed pings too, now playing carries `listeners`, the overview's two tiles (listening now, most at once today), the **Listeners** screen (`manage/listeners`, managers: four tiles, a 48-hour and a 30-day column chart as server-drawn SVG with tooltips and table views; `--chart` checked with the dataviz validator), `.mmain > *` may shrink (wide content scrolls in its card). Tests: new `listeners_test.php` (28), `web_test.php` 146 / 69 public (pings, Stop, bad ids, the counts in the feeds, the screen and its access), `player_browser_test.mjs` 41 / 26 listen-only (the player and the embed count themselves; Stop takes the player off at once), `studio_test.php`'s upgrade checks now follow `Schema::VERSION` (77). Screenshots at 1280 and 390 px with sample data (removed afterwards) and live at 1280; found and fixed on the phone: the charts' minimum width made the whole page 572 px wide. Deployed (schema 3 → 4); all tests pass on Laragon and the server |
| 28 | 2026-10-21 | `22c4434` | **Every open limit addressed before podcasts** (the user's rule: a round is done only when every feature is fully written and tested; in `CLAUDE.md` and Working agreements). **Listener analytics** (reverses the anonymous-ping decision, the user's choice): schema 5 (`listeners` made again: one row per Listen kept 30 days with `ip`, `user_agent`, device, browser, app, country, city, minutes, `ended`, `test`; new `listener_tally` (daily totals by where, device, browser, app, country, city) and `listener_visitors` (a daily unique mark: an HMAC of address and browser with a monthly salt in `settings.listener_salt`, replaced each month)); `Agent` (reads device, browser and app from the user agent; the test mark `StationwagonTest`); `Geo` (our own reader for DB-IP's IP to City Lite MMDB file, looked up on the server, 0.4 ms a lookup; `update()` fetches the month's file, checks it and swaps it in); `cli/geo-update.php`, and `cli/plan.php` (cron) fetches the new file once a month (one try a day); `api/listen.php` passes `Request::clientAddress()` (the real address through Cloudflare) and the user agent, and answers 429 past 20 new Listens per address in 10 minutes; Stop marks a Listen ended (a late ping no longer brings it back); test runs count as listening now but stay out of every total. **Listeners screen**: six tiles (with unique listeners today and this month), a unique-listeners chart with a by-month table, "Who listens, the last 30 days" (where, device, browser, app, country, city as share-of-hours bars, top 6 and Others), the DB-IP credit (CC BY 4.0, its edition) and the privacy contact form (`StationSettings::privacyEmail` / `savePrivacyEmail`). **Privacy page** (`/privacy`, POPIA: what, why, how long, who sees it, rights, who to ask), linked under the player (`.lp-foot`) and in the embed (`.em-priv`). **Small fixes**: the studio shows "N listening" (its state feed); the studio's MP3 encoder runs in a worker (`assets/studio-encoder.js`, with the frame splitter; `lame.min.js` no longer on the page); the web test skips its silent test song if it is playing at the live edge; `make_green_skin.py` reads `hifi/` and refuses to overwrite `green/`. **Checked and closed**: old sessions are cleaned up on cPanel (none older than 2 days); AutoSSL replaced the self-signed certificate (Let's Encrypt to 17 Jan 2027); the very short last live piece is by design (End show sends what built up since the last 6 s boundary); a hidden listener tab fades back to the AutoDJ after a show (in the browser test and live on the server). **With the user's OK**: the Cloudflare Cache Rule now covers `api/nowplaying.php` too (renamed "Stationwagon playlist and now playing (2 s)"; MISS → HIT); the full signed-in web test runs on the server with throwaway accounts (removed afterwards); the studio test went live on the server through Cloudflare (`SW_HELPER_SSH`); the old test-run hourly stats on the server were deleted. **Found and fixed by the tests**: the Privacy page never showed the contact (the station loader skipped `settings`); through Cloudflare its Email Address Obfuscation rewrites addresses, so the checks accept its protected link. Tests: `listeners_test.php` 82, `web_test.php` 161 on Laragon / 162 signed in and 74 public on the server, `player_browser_test.mjs` 42 (a hidden tab fades back after a show) / 26 listen-only, `studio_browser_test.mjs` 62 on Laragon and live on the server, `player_phone_test.mjs` 15 (the embed in a frame keeps playing 20.0–20.1 s behind through 2 minutes locked; the test is marked so it stays out of the stats), new `studio_phone_test.mjs` 11 (the studio on the phone with its own mic: worker encoder, live, 6.06 s pieces, End show). Deployed (schema 4 → 5); all tests pass on Laragon, the server and the phone |
| 29 | 2026-10-21 | `0f4a442` | **Podcasts**: schema 6 (`episodes`, `episode_takes`, `episode_chunks`); `Podcast` (an episode per live show and its rejoins, device and aired takes, the aired take filling in a recording cut short, chunks, clips and edits, the mixdown's parts, publish and hide, the RSS feed, `sync` and `cleanup` from the cron) and `PodcastPage` (`/podcast`, `/podcast/feed`, `/manage/podcast`, `/manage/podcast/<id>`, `/podcast/api/*`); the studio's recorder `assets/studio-recorder.js` (an `sw-rec` worklet on the music bus and the mic, two encoder workers, 10 s chunks in IndexedDB with peaks, upload after End show and on the next visit; `studio-encoder.js` takes a channel count); `assets/podcast-core.js` and `assets/podcast-editor.js` (the canvas editor: two tracks or the aired one, zoom with buttons and Ctrl+wheel, click and Delete, undo, edits saved as you go, Mixdown with an estimate, publish, hide); views `podcast.php`, `manage/podcast.php`, `manage/episode.php`; "Podcast: past shows" under the player; `.htaccess` (the feed public for 5 minutes, the API no-store); `storage/.htaccess` serves the published MP3s and covers. **Notification sounds**: measured on the phone (Chrome ducks only its `<audio>` players; the studio's output stayed at −13.9 dB); the studio reminds phone and tablet users to turn on Do Not Disturb and wear headphones. **Found and fixed**: a beep at a cut clip's edge (the MP3 codec delay, 1105 samples, now skipped), a click at every aired piece join (pieces counted in whole frames), a mixdown that hung (its worker's "ready" was lost), the feed's cache header (`THE_REQUEST`), the upload of an old show's recording during a rejoin (now waits); Go live could start 0.08 s ahead when a song ended inside the 1 s lead (app and POC; `planner_test.php` 74 with a check that fails without the fix; the POC engine test passed 25 of 25 runs after); the phone tests now use Chrome's own debugging socket (Brave held the shared one) and the studio phone test clears its test notification (left in the shade, one could wake the locked phone during the player test); the studio test waits for search results instead of a fixed 1.2 s. **Test server**: found on PHP 8.4 with no extensions (switched in cPanel at 16:06); with the user's choice the selector was set to 8.4 with 8.1's extensions and limits, which also brought controll.co.za's WordPress site back from a 500. Tests: `podcast_test.php` 68, `podcast_core_test.mjs` 23, `podcast_browser_test.mjs` 56, `studio_browser_test.mjs` 74, `upload_browser_test.mjs` 13, `studio_phone_test.mjs` 17, `player_phone_test.mjs` 15, `web_test.php` 178, `planner_test.php` 74, engine 42. Deployed (schema 5 → 6); all tests pass on Laragon, the server (PHP 8.4) and the phone |
| 30 | 2026-10-21 | `178be60` | **The small studio items** (the user's choices): the **headphones menu** in the status strip (`stOutDev`; `listOutputs`/`setOutput` in `studio.js`, `ctx.setSinkId`, remembered as `sw.studio.output`, re-listed when devices change, an unusable output says why); **editing a posted note** (schema 7 `presenter_notes.replaced_by`; `Notes::edit` posts it again as new and keeps the old version for listeners until they reach the edit, `tidy` drops it 2 minutes later, `remove` takes old versions too, the studio sees only current notes, listeners get notes by posting time; `Studio::editNote`, `/studio/api/note-edit`; the drawer's Edit, Save changes and Cancel; `notes.js` hides an old version once its replacement is reached); **talk items** mark themselves now when the mic opens, and a Now button on talk rows. **Found and fixed on the test server**: a Stop that overtook a Listen's slow first ping left the player counted for 90 s (`Listeners::ping` keeps it as an ended Listen); the strip's look-ahead read a song's pieces while its deck was still downloading it, so the same bytes came twice (it now waits until the deck isn't downloading); the strip's colour check looked once and could catch a quiet song change (now up to six looks); the player phone test locked with the power key, a toggle, and once the screen came straight back on for the whole 2 minutes (it now uses the sleep key and checks the screen is off first). Also: schema upgrades skip an existing column (error 1060). Tests: `notes_test.php` 56, `studio_test.php` 80, `listeners_test.php` 84, `studio_browser_test.mjs` 91 (headphones: two fake outputs, switching there and back, an unplugged one; talk items; editing a note), `player_browser_test.mjs` 46 (an edited note: the old one until the change is reached, then the new one with a toast), `studio_phone_test.mjs` 18, `player_phone_test.mjs` 16 on the server, `web_test.php` 178 / 179. `studio_browser_test.mjs` missed one check once in 7 Laragon runs (which one wasn't caught: two runs ran in one command); 5 runs in a row passed after, and it passes on the server. Deployed (schema 6 → 7); all tests pass on Laragon, the server and the phone |
| 31 | 2026-10-22 | `05a9608` | **The spectrum** in the player's display (replaces the round 17 placeholder: 32 flat bars after the volume): `spectrum` in `player.js` (28 log bands, segmented bars, falling peak marks, stops when playback stops, reduced motion, not in stream-only mode), the analyser moved onto the station's mix, colour tokens `--spec-*` in `app.css`. Tests: `player_browser_test.mjs` 53 / 33 listen-only (the tallest bar is the playing tone's band, a pure tone lights at most 8 bars, the volume doesn't shrink it, after Stop it falls to nothing and stops, reduced motion redraws about once a second, none in stream-only mode), `player_phone_test.mjs` 17 (the phone shows the tone's band). **Found and fixed in the tests**: the test's page could sit in the background, where browsers draw nothing (it is brought to the front at the start and after the live part's second tab); the edited note's toast could be gone before the test looked (toasts are now recorded as they show). Screenshot of the display with the 440 Hz tone. Deployed; all tests pass on Laragon, the server and the phone |
| 32 | 2026-10-22 | `65f2d8d` | **Skins, picked per device** (the user's rules: every screen, kept per device, any screen changes them all, the embed with its own picker; the design itself not critical): `app.css` made all-token (about 60 colours, 80 corner sizes, the fonts, bevels and display glow moved into tokens; a hi-fi token block from `design/skins/hifi`; `:root.light` for the embed's light look over either skin); new `src/Skins.php` (the list, the `<head>` script, the picker) and `assets/skin.js` (picking, saving, other tabs, `SwSkin.tok()`); the Look menu in `layout.php` and a Look button in `embed.php`; Google Fonts also loads Barlow, Barlow Semi Condensed and Share Tech Mono; the output strip, the player, the studio's deck waves and the episode editor read their colours from tokens and redraw on a change; the station's `skin` setting dropped from new installs; the Privacy page says the volume and the look are kept on the device. **Found and fixed**: presenter notes turned "This morning" the moment a show closed, 20 s before listeners stopped hearing it (`Notes::forListeners` sends `ends`; `notes.js` stays live until then). Tests: new `skin_browser_test.mjs` (30: the default look, picking on the player, tokens, fonts, corners, the spectrum's colours, every screen signed in and out following the device's choice, the `<head>` script before the stylesheet, an open tab switching when another changes it, the studio's deck wave redrawing, the embed's light look over hi-fi, the embed's own button, framed on another site with its own storage, a private window), `web_test.php` 186 / 187 on the server / 92 public (the picker and `<head>` script on every page, no cookie, no colour or corner size outside the token blocks), `notes_test.php` 57 (the show's end), `player_phone_test.mjs` 18 and `studio_phone_test.mjs` 19 (the look on the phone). **Found and fixed in the tests**: the spectrum check now waits until one deck plays and its song is past its first 1.5 s (a song starting, or a quiet song fading out on the other deck, lights many bands); the player test's browser starts with Windows' occlusion detection off and brings its page to the front on every load (once the PC is idle, Windows marked even a headless page hidden, and a hidden page draws nothing); the Sound & safety count of drop-downs ignores the Look menu; the test tones tick at 1800 Hz every second and beep every 10 s, which light the spectrum's bands for ~0.6 s, so the spectrum checks (browser and phone) look between ticks; the phone tests open a Chrome tab with an Android intent when none is open. **Found on the phone and fixed**: with the phone's media volume at 0, MIUI's PowerKeeper paused Chrome's audio after 60 s behind the lock screen (`isLongTimeMusicSilent … pauseAudioTracks` in logcat); the embed stayed stopped (hls.js then reports a stall), so `embed.js` gets an event log (`swEmbed.events`), starts again by itself when paused from outside, on a stall hls.js gives up on, or when its sound hasn't moved for 8 s (same ping), and the phone test turns the volume up one step with the volume key (MIUI refuses a volume set from the shell) and back afterwards. `player_browser_test.mjs` 56 / 36 listen-only (the embed paused from outside, and with its clock frozen, starts again at 20 s as the same listener). Deployed; all tests pass on Laragon, the server and the phone |
| 33 | 2026-10-22 | `0358c8b`; release `v0.6.0`, `app/VERSION` 0.6.0: this round's commit | **The Windows studio app** (`v0.6`; the user's choices: BUTT connecting goes live by itself, our own updater key, releases from GitHub through a WordPress plugin): `desktop/` (Tauri 2.12: `lib.rs` with the settings, the station's capability added when its studio opens, the updater with `SW_UPDATE_ENDPOINT` for tests; `bridge.rs`, the stand-in Icecast/Shoutcast server; `mp3.rs`, the frame cutter with 6 unit tests; `ui/index.html`; an icon); `studio.js` takes the bridge's frames as its pieces when it runs in the app (goes live and rejoins by itself, ends 5 s after BUTT stops, a 1 s first piece after a drop, the close question, `set_busy`), cuts every piece on the exact frame (before, a batch could overshoot by up to 0.2 s), shows the bridge in the status strip, and "Get the Windows app" in a Windows browser; `tools/build-desktop.sh`; the WordPress plugin `wordpress/stationwagon-updates` (installed and active on controll.co.za, no token yet) and `wordpress/tests/wp-stub.php`; tests `desktop/tests/app_test.mjs` (56 on Laragon and on the server over HTTPS), `butt_test.mjs` (26, the real BUTT 1.47 portable: Icecast SOURCE, PUT and Shoutcast), `update_test.mjs` (17: the plugin, 0.6.0 → 0.6.1, a wrong signature refused). Found with the real BUTT and fixed: `PUT` needs "200 OK" after "100 Continue"; Shoutcast sends titles to port − 1. **Found on the phone and fixed:** the studio crackled while an app updated in the background: 3 ms buffers; phones now get "balanced" (0–1 gaps in 30 s instead of 19–24); `studio_phone_test.mjs` 21 (Chrome's gap count, and no holes in what went out) and leaves the studio afterwards. `app/VERSION` 0.6.0-dev. Deployed; all tests pass on Laragon, the server and the phone |

## Open issues

Every item from before round 28 was fixed, checked, decided with the user or moved to the roadmap on
2026-10-21 (see the change log, round 28). What is left:

**Waiting on the user (hardware)**

- **USB headset** not tried with the mic chain (P3; the user's PC test passed).
- **iPhone and Safari** not tried. Safari's own HLS can't be faded through Web Audio, so live
  hand-overs there use element volume steps (a hard switch on iPhones, whose volume is fixed).
  The studio's recording (AudioWorklet, workers, IndexedDB) isn't tried on an iPhone or iPad either.
- **BUTT on the user's PC** must be reinstalled before the next BUTT show; its server settings are
  kept in `%APPDATA%\buttrc`. BUTT's installer asks for admin rights (Windows' UAC prompt), so the tests
  used BUTT 1.47's portable build instead (in this session's scratchpad, not kept).
- **The Stationwagon Updates token** (fine-grained, the Stationwagon repo only, Contents read-only) is the
  user's; when it expires the app finds no updates and "Get the Windows app" answers 404 until a new one is
  saved in Settings → Stationwagon Updates (the page shows the last error).
- **Mixxx** is tested with a stand-in that speaks as libshout does (`PUT` with `100-continue`), not the real
  Mixxx (the user chose to test with the real BUTT only).

**Known limits (agreed with the user 2026-10-21; the app can't remove them)**

- Apps that play `api/stream.php` directly aren't counted as listeners: they never ping.
- Listen needs a tap, in the player and the embed (browsers' autoplay rule; the iframe's
  `allow="autoplay"` doesn't change that).
- **Locked Xiaomi phone:** the Web Audio clock freezes about every 60 s; the watchdog brings it back in
  about 0.5 s, which a listener may hear. Not tried on other Android phones. Starting to listen behind
  the lock screen isn't possible (Chrome blocks it; the player says "Tap Listen again").
- A browser that keeps no cache (private windows on some phones) would fetch the songs twice for the
  player's output strip. Not seen in any test.
- Made-up pings: one address may start at most 20 Listens in 10 minutes (more get 429 and aren't
  counted), but someone with many addresses could still inflate the numbers.
- Places on the Listeners screen are approximate (DB-IP Lite: a phone on mobile data can show a city far
  away).
- **Notification sounds in the browser studio** (agreed 2026-10-21): a web page can't silence them, so on a
  phone the sound can reach an open mic; the studio reminds the DJ (Do Not Disturb, headphones). The mix and
  the recording themselves aren't turned down. The Android app will switch Do Not Disturb on itself.

**On the roadmap, not open issues** (in the project map's order): the library's "show song titles" switch and play weights; "prepare again" / "level again" for
songs already in the library (older songs have no `outro` and the 4 test tones aren't levelled); and
changing the piece length (it needs a rescan of every song's pieces, so the listener delay stays shown,
not editable); the Android app (Do Not Disturb on air, with a warning first).

## Working agreements

- Commit and push only when the user asks. End commit messages with the `Co-Authored-By` line.
- Artifacts are light theme only and never navy (the user's standing rule). The radio skins in
  `design/` are the one dark exception, by explicit request.
- Ask the user questions as multiple choice (pick-from-options), never as open prose.
- **Do the whole loop yourself**: write, deploy, set up and test. Never end a round by handing the
  user a manual step (setup, sign-in, clicking through). Credentials you need: generate them and keep
  them in gitignored `tools/deploy/*.local.txt`.
- Never store or repeat passwords or keys in the repo, chat or this file.
- **A round is done only when every feature in it is fully written and tested.** Don't close a round
  with "limits", "not tried yet" or "comes later" left over: fix each one in the same round, or put it
  to the user as a multiple-choice decision before going on. A limit that truly can't be fixed (a
  browser rule, something only the user can do) is said plainly and agreed with the user, not just
  listed under Open issues. (The user, 2026-10-21.)
- After each code round: run the engine test (if the engine changed) and all of `app/tests/`
  locally and on the server, update the POC test log if prototype tests ran, and update this file and
  the project map.
