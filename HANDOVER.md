# Stationwagon handover

Read this first. It records where the work stands, what was decided and why, and what changed in
each round, so that anyone (a person or a new agent session) can pick up where the last one
stopped.

**Last updated:** 2026-10-20, after round 13 (station manager screens: library upload, playlists,
schedule, DJs, Skip).

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
| `prototypes/tools/` | `make-test-mp3s.js` (test tones), `phone.mjs` (drive the phone's Chrome over adb), `deploy-cpanel.sh` (upload to cPanel over SSH), `load-listeners.mjs` (simulated listeners) |
| `prototypes/config.local.php` | Server-only overrides (its control key). Never committed; denied by `.htaccess` |
| `prototypes/media/`, `prototypes/data/` | Test MP3s and runtime state; gitignored except their README / `.htaccess` |
| `app/` | **The real app** (Core & manager, `v0.1`). Front controller `index.php` → `src/App.php`; version in `app/VERSION` |
| `app/src/` | `bootstrap.php` (autoloader, `Stationwagon\` → `src/`), `Config`, `Db` (PDO, UTC, table prefix), `Schema` (tables, `Schema::VERSION`), `Installer`, `InstallPage`, `View`, `Session` (cookie settings, form keys), `Auth` (sign-in, roles, lock-out), `AuthPage` (sign-in / sign-out / account pages), `Request` (visitor address through Cloudflare), `Mp3` (frame scanner + ID3 tags), `Library` (add songs, stored by content hash), `Planner` (the timeline on MySQL: plan, skip, go live, pieces, schedule), `Stream` (HLS playlist), `Playlists`, `Schedule` (weekly blocks, time and day helpers, the week grid), `Staff` (people and who may change whom), `ManagePage` (the station manager screens and their form actions) |
| `app/api/` | `stream.php` (listener HLS playlist; real file so `.htaccess` gives it the 2 s Cloudflare header) and `timeline.php` (JSON for the two-deck player and studio) |
| `app/views/`, `app/assets/` | PHP page templates (wrapped in `layout.php`; the manager screens are in `views/manage/`, framed by `frame.php`), the green-skin styles `app.css`, and `upload.js` (uploads songs one by one with progress) |
| `app/tests/` | `install_test.php` (31 checks), `auth_test.php` (sign-in logic, 39), `planner_test.php` (library + planner, 71), `manage_test.php` (library edits, playlists, schedule, staff rules, 51), `web_test.php` (pages, manager screens and stream over HTTP, 81; `--public`: 32), `lib.php` (shared helpers: throwaway database and install) |
| `app/cli/` | `install.php` (install over SSH: the form's fields as JSON on stdin), `set-password.php <email>` (new password on stdin; for when nobody can sign in), `import.php <mp3s>` (add songs), `plan.php` (cron: plan ahead) |
| `app/storage/` | Songs (`media/<sha1>.mp3`), live pieces (`live/<show id>/00000.mp3`), `cache/`, `sessions/`; gitignored; only `.mp3` is served |
| `app/config.local.php`, `app/install.key` | Written by the installer / the deploy script. Gitignored and denied by `.htaccess` |
| `tools/deploy-app.sh` | Upload `app/` to cPanel over SSH (keeps the server's settings and storage) |
| `tools/deploy/` | Gitignored secrets for the test server: setup key, database password, admin sign-in; `shots/` holds screenshots |
| `tools/shot.mjs` | Full-page screenshots with headless Edge, signed in if asked, at any width; flags pages wider than the screen. `node tools/shot.mjs <out> <base> <width> [--signin <email> <password file>] <paths…>` |

### Published design mock-ups (claude.ai artifacts, private)

| Screen | Hi-fi skin | Green skin |
|---|---|---|
| Listener player | https://claude.ai/artifact/9vPMTeSZVDjgU3HaGKcjGU | https://claude.ai/artifact/CHULBMqMWbg25LjTcovES8 |
| DJ studio | https://claude.ai/artifact/BLzkA52kEozDMxttoJAg2m | https://claude.ai/artifact/BntgUDVB3s69T6CMZYgzsz |
| Station manager | https://claude.ai/artifact/NeuMPpuuQxvEAmBcF3cd5h | https://claude.ai/artifact/QxYADjUAtv1qbaQVugHuCq |

The repo copies in `design/skins/` are the source: edit those and republish to the same URL.

## Test server (cPanel)

- **https://wordwave.controll.co.za/stationwagon-poc/** (P0: `p0-host-check/index.php`).
  Subdomain made 2026-10-19 with `uapi SubDomain addsubdomain` on the `controll` SSH account;
  document root `~/public_html/wordwave.controll.co.za`; the POC is in `stationwagon-poc/` under it.
- Server: LiteSpeed (honours `.htaccess`), PHP 8.1.34, IP 169.239.217.60.
- DNS: Cloudflare account `vincent.rathbone@gmail.com` (the user signs in; no API token on this
  PC). Record `wordwave` A → 169.239.217.60, **proxied**, like `demo`. The same Cloudflare account also holds
  `wordwave.co.za`, which the user owns.
- **Cloudflare Cache Rule** "Stationwagon playlist (2 s)" on the controll.co.za zone: `http.request.full_uri wildcard "https://wordwave.controll.co.za/*/api/stream.php*"` → Eligible for cache, Edge TTL from the origin header, **Browser TTL: Respect origin TTL** (added 2026-10-20 with the user's OK). Covers the POC, the app and a future station at the root.
  Without the Browser TTL setting Cloudflare rewrote the playlist's `max-age=0` to the zone's 2 h
  Browser Cache TTL (it overrides any browser header on cached responses, `no-cache` and `no-store`
  too), which would freeze browser players. `web_test.php` checks the header through Cloudflare.
- The main site's `~/public_html/.htaccess` (WordPress, "Flying Pages Plugin Fix") sets `Cache-Control: no-cache` on everything below it, the subdomain included, and Wordfence WAF is prepended to every PHP request. Don't edit that file; override headers in our own `.htaccess`.
- Origin certificate is self-signed until AutoSSL replaces it (check started 2026-10-20). Cloudflare
  currently accepts it.
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
  (and `install_test.php`, `planner_test.php`, `manage_test.php`). Web test: `php tests/web_test.php https://wordwave.controll.co.za/stationwagon-app --public`
  (from the server or this PC). The signed-in web checks run only on Laragon: no test accounts are
  made on the public server.
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
  php app/tests/planner_test.php                                   # library and planner, 71 checks
  php app/tests/manage_test.php                                    # manager rules, 51 checks
  php app/tests/web_test.php http://localhost:8080/stationwagon-app # pages, manager screens, stream: 81 checks
  ```
  The web test signs in a throwaway manager, uploads, edits and deletes its own songs, playlists,
  blocks and DJs, and removes them afterwards. A test song that is on air at the end is hidden
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

**Core & manager (`v0.1`) has started.** Done: the app layout (`app/`), schema v1 (12 tables) and the
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

## Next steps

Continue **Core & manager (`v0.1`)** per the build-out order in `docs/POC-PLAN.md`:

1. ~~Project layout~~, ~~MySQL schema~~, ~~web installer~~ (done 2026-10-20).
2. ~~Install on the test server~~ (done 2026-10-20 over SSH).
3. ~~Sign-in, roles and the manager shell~~ (done 2026-10-20).
4. ~~Planner on MySQL, with a cron~~ (done 2026-10-20).
5. ~~Station manager screens: library upload, playlists, schedule, DJs, Skip~~ (done 2026-10-20).
   Left for later in v0.1: the "Sound & safety" settings screen from the design.
6. Then **Player & studio (`v0.4`)**: the listener player on `api/timeline.php` / `api/stream.php`,
   and studio endpoints for `Planner::goLive` / `addPiece` / `endLive` (the planner already does these).

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

The POC is unversioned (its commits are the record). The app's version is in `app/VERSION`.

| Date | Version | Why |
|---|---|---|
| 2026-10-20 | `0.1.0-dev` | Core & manager build started (app layout, schema v1, installer). It becomes `0.1.0` when the v0.1 milestone is done |

Schema version: **1** (`Schema::VERSION`; stored in the `settings` table at install).

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
| 13 | 2026-10-20 | this round's commit | Station manager screens: `Playlists`, `Schedule`, `Staff`, `ManagePage`; `Library` edit / search / delete; views in `views/manage/` (overview, library, playlists, playlist, schedule, DJs), `upload.js`, manager styles; flash messages (`Session::flash`); `manage_test.php` (51) and a manager section in `web_test.php` (81 / 32 public); `tools/shot.mjs` (headless screenshots); fixes from screenshots (hidden elements, column and form alignment); deployed; all tests pass on Laragon and the server |

## Open issues

- **USB headset** not tried with P3 (the user's PC test passed).
- Cloudflare still holds the old copies of the 4 test MP3s with the old 2-hour header. They expire
  on their own; new files cache correctly.
- **Safety net** (silence → AutoDJ) was only tested on Laragon.
- The origin's **self-signed certificate** should be replaced by AutoSSL; check
  `uapi SSL installed_hosts` on the server.
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
- The test-server admin (`vincent@controll.co.za`) has a generated password, kept in
  `tools/deploy/controll.admin.local.txt`. It can be changed on `/account` after signing in.
- No **"Sound & safety"** screen yet (station settings like crossfade and live timeout); the
  values are the defaults from install.
- The library has no "show song titles" switch or play weights from the design yet.
- The test station's 4 songs are test tones; real music can now be uploaded from Music library.
- Track pieces are cut at the station's `segment_seconds` when imported; changing that setting
  later needs a rescan (not built).
- The live studio has no web endpoints yet (the planner methods are tested; they come with the
  studio in `v0.4`).
- The **signed-in** web checks run only on Laragon. On the server they're covered by `auth_test.php`
  and the public web checks.
- `design/skins/make_green_skin.py` still expects the original `wordwave-*.html` file names.

## Working agreements

- Commit and push only when the user asks. End commit messages with the `Co-Authored-By` line.
- Artifacts are light theme only and never navy (the user's standing rule). The radio skins in
  `design/` are the one dark exception, by explicit request.
- Ask the user questions as multiple choice (pick-from-options), never as open prose.
- **Do the whole loop yourself**: write, deploy, set up and test. Never end a round by handing the
  user a manual step (setup, sign-in, clicking through). Credentials you need: generate them and keep
  them in gitignored `tools/deploy/*.local.txt`.
- Never store or repeat passwords or keys in the repo, chat or this file.
- After each code round: run the engine test (if the engine changed) and all of `app/tests/`
  locally and on the server, update the POC test log if prototype tests ran, and update this file and
  the project map.
