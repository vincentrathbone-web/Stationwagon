# Stationwagon proof-of-concept plan

Stationwagon is radio software that installs on ordinary **shared cPanel hosting**: no root,
no Docker, no extra ports, no programs running all day. It covers what a small church station
needs: AutoDJ music around the clock, a stream people can listen to, and live shows with
proper crossfades.

This plan lists what we must prove **before** building the full product, the prototype that
proves each point, and how to judge it.

## The design in one paragraph

A PHP app with MySQL (plain JSON files in the prototypes) plans a **timeline**: which song plays
when, with crossfade points. Listeners get the stream as **HLS**: a tiny playlist file plus
short audio pieces that are just byte ranges inside the original MP3 files. Apache serves those
like any other file, and Cloudflare can cache them. Our own web player can instead play the
songs on **two decks with real crossfades**, following the same timeline, so every listener
hears the same thing at the same moment. Live shows come from a **browser studio**: it records
the mic, mixes in the music, encodes MP3 in the browser and uploads a piece every 6 seconds. The
timeline switches to those pieces until the show ends, then the AutoDJ continues. Every action
(skip, go live, end show) lands on a **stream-time** point, so all listeners hear it at the
same moment.

## Risks and the prototype that answers each

| # | Risk (what could kill the idea) | Prototype | Proves |
|---|---|---|---|
| R1 | The shared host blocks something we rely on (byte ranges, rewrite rules, file writes, upload size, cron) | **P0 Host check** | The host can run Stationwagon at all |
| R2 | Players can't play byte-range MP3 pieces over HLS smoothly | **P1 HLS AutoDJ** | Gapless HLS playback from the original MP3s on phone and desktop |
| R3 | A timeline computed on request keeps everyone in sync, and skips land cleanly | **P1** + **P2** | Same song, same second, on two devices; skips work |
| R4 | Live crossfades and skips in the browser sound smooth | **P2 Crossfade player** | Equal-power crossfades and 2 s skip fades on cue, no clicks or gaps |
| R5 | Browser mic processing sounds broadcast-ready (gate, compressor, EQ, limiter, ducking) | **P3 Mic chain** | A preacher sounds clean and even; music ducks under speech |
| R6 | A browser can encode and upload live audio reliably, and the AutoDJ hands over without a bump | **P4 Live studio** | In-sync handover, 6 s pieces, about 12–20 s delay, fallback when the DJ drops |
| R7 | Many listeners overload a shared account | **P1** with Cloudflare (load notes in P1's README) | Cloudflare serves the audio pieces, and PHP sees about one playlist request per piece |

## Prototypes

All prototypes live in [`prototypes/`](../prototypes/). They are plain PHP 8 and vanilla
JavaScript with no build step. Run them on **Laragon** first (`http://localhost/…`, where the
browser allows the mic), then upload the `prototypes/` folder to the cPanel account and repeat.

### P0 Host check: `prototypes/p0-host-check/`

A single page that reports, in plain words, whether the host can run Stationwagon:

- PHP version and limits (execution time, memory, upload and POST size)
- Writable data folder, free disk space
- Range requests on audio files (needed for byte-range HLS and for seeking)
- Apache `mod_rewrite` with time variables (`%{TIME_SEC}`), for the "Apache tells the time"
  trick
- Whether `exec`/`ffmpeg` exist (nice to have, not required)
- Server clock against the browser clock
- Whether Cloudflare sits in front (`CF-Ray` header)

**Pass:** every "required" row is green on the real cPanel account.

### P1 HLS AutoDJ: `prototypes/p1-hls-autodj/`

- `shared/mp3.php` reads an MP3's frame headers in pure PHP and finds a cut point about every
  6 seconds. No ffmpeg needed.
- `shared/timeline.php` plans songs back to back, hours ahead, on demand (cron optional). Song
  changes land on piece boundaries, so the HLS stream and the two-deck player agree.
- `stream.php` builds a live HLS playlist (`#EXT-X-BYTERANGE` into the original files) of the
  last 5 pieces that have begun, with steady numbering and discontinuity markers between songs.
- `control.php` handles **Skip**: it cuts the current song at the next boundary and re-plans.
- `index.html` is a test player using hls.js (Safari plays HLS natively), with a debug panel
  showing the song, position, delay and buffer.

**Pass:** 30 minutes of playback with no gaps or stalls on Chrome (desktop), Safari (iPhone) and
Chrome (Android). Two devices play the same song within about 1 second of each other. Skip
changes the song for both.

### P2 Crossfade player: `prototypes/p2-crossfade-player/`

- Reads the same timeline as P1 (`timeline.php` returns entries around "now" plus server time).
- Two decks (audio elements routed through Web Audio gain nodes) with equal-power crossfades.
- Joins mid-song at the right second. Corrects drift over 0.3 s with a short dip instead of a
  jump.
- Picks up skips within one poll (3 s) and does a 2 s skip fade at the cut point.
- Its debug panel shows server clock offset, expected against actual position, and drift.

**Pass:** crossfades sound smooth with no clicks. Two browsers stay within about 0.3 s. A skip
from P1's control panel fades on cue in both.

### P3 Mic chain: `prototypes/p3-mic-chain/`

- Mic → low cut → **gate** (AudioWorklet) → presence EQ → **compressor** → **limiter** →
  monitor and meters.
- Music player (choose a local file) with **ducking** driven by the processed mic level.
- Presets (Preacher, Music show, Interview), gain-reduction meter, gate lamp, and
  "Record 20 s" to listen back.
- `shared/js/micchain.js` is reused by P4.

**Pass:** a test reader on a laptop mic and a USB headset sounds clean and level. Room noise
disappears between sentences. Music ducks smoothly and returns without pumping.

### P4 Live studio: `prototypes/p4-live-studio/`

- **Go live:** the server cuts the AutoDJ at the next boundary (T) and opens a live block. The
  studio plays the current song on Deck A at exactly the AutoDJ's position. At T it starts
  sending its own mix, so listeners hear no change.
- Mic chain from P3, crossfader between the song deck and silence/next song, talk button with
  ducking.
- In-browser MP3 encoding (lamejs) in 6-second pieces, uploaded with sequence numbers. It retries
  on failure and shows the last-sent time.
- **End show:** closes the live block, and the AutoDJ continues with the next song.
- **Safety net:** if no piece arrives for 8 s, the server closes the block itself and the AutoDJ
  resumes.
- Listeners use P1's player and hear the live block in the same HLS stream.

**Pass:** a 20-minute live test from a laptop on Wi-Fi with no missing pieces, delay under 20 s,
and a handover in and out with no audible bump. Pulling the network cable falls back to the
AutoDJ within about 10 s.

## Test log

Fill in as you go (copy this table into an issue or keep it here). ☑ passed · ◐ partly tested · ☐ not yet.

| Prototype | Laragon | cPanel | Notes |
|---|---|---|---|
| P0 Host check | ☑ 2026-10-19 | ☑ 2026-10-20 | Laragon: all required checks pass. cPanel (wordwave.controll.co.za, LiteSpeed, PHP 8.1.34, HTTPS, Cloudflare JNB): all required checks pass; clock 0.12 s off, round trip 328 ms. |
| P1 HLS AutoDJ | ☑ 2026-10-19 | ☑ 2026-10-20 | Laragon: PC + phone over USB. Fixed: cut song could come straight back; Skip from a lagging listener cut the next song; clock showed "PM.5"; devices 1.0 s apart, so P1 now holds a fixed 20 s delay. cPanel: PC (fibre) + phone (4G) both lock at 19.98–19.99 s, no stalls. |
| P2 Crossfade player | ☑ 2026-10-19 | ☑ 2026-10-20 | Laragon: same-second 6.0 s crossfades, drift under 10 ms; fixed variable-length crossfades. cPanel: PC + phone (4G) crossfaded in the same second, 6.0 s, drift −13 ms and 7 ms, round trip ~60 ms. |
| P3 Mic chain | ☑ 2026-10-20 | n/a | User, PC with headphones: mic, gate, recording and ducking all good ("All good"). Also runs on the phone inside P4. |
| P4 Live studio | ☑ 2026-10-19 | ☑ 2026-10-20 | Laragon: phone studio → PC listener, end show, safety net (phone offline → timeout → AutoDJ). cPanel: phone studio on 4G uploads every 6 s with an empty queue; PC listener went live with no stall; AutoDJ back right after End show. Safety net not repeated on cPanel (same code path). |
| Cloudflare caching (P1 behind a proxied subdomain) | n/a | ☑ 2026-10-20 | MP3 byte ranges HIT once sent as `public, max-age=31536000, immutable`. Playlist: Cache Rule on `…/api/stream.php*` plus `s-maxage=2`. 100 simulated listeners for 60 s: 990 requests, 961 HIT, 29 reached PHP (about one per 2 s), 0 failed, median 45 ms. |

The app (after the POC; every round's suite results are in `HANDOVER.md`):

| Part | Laragon | cPanel | Phone | Notes |
|---|---|---|---|---|
| Listener player (v0.4) | ☑ 2026-10-20 | ☑ 2026-10-20 | ☑ 2026-10-20 | Headless Edge: joins within 0.05 s, crossfades, live held at 20.00 s, stream-only 20.05 s, a frozen sound engine is nudged and rebuilt (20 checks; 13 listen-only on cPanel). Android phone (Xiaomi, Chrome 155, live server): joins within 0.11 s, crossfades; screen locked 2 min: 4 songs on time, engine clock 99.1 % of real time (10 checks). Found and fixed: the phone ran a week-old cached `player.js` (assets now tagged by content); Listen hung behind the lock screen; the engine clock freezes about every 60 s while locked (watchdog, about 0.5 s of sound lost each time). Safari and iPhones not tried. |
| Presenter notes (v0.4) | ☑ 2026-10-20 | ☑ 2026-10-20 | n/a | Rules test 44 on Laragon (MySQL) and cPanel (MariaDB). Headless Edge on Laragon: a note posted at a show's start is held back, appears 20.5 s later tagged Live and New with a toast, shown as text with its Bible Gateway link, and stays after the show (6 checks). Screenshots at 1280 and 390 px match the mock-up. Posting comes with the studio. |
| Live studio (v0.4) | ☑ 2026-10-20 | ☑ 2026-10-20 | not tried | Rules test 61 on Laragon (MySQL) and cPanel (MariaDB), including the schema 1 → 2 upgrade. Headless Edge with the fake microphone on Laragon (43 checks): a DJ without a block can't go live; one in their block switches on, plans a running order (kept after a reload), goes live; Deck A picks up the AutoDJ song 3 ms from the timeline; the first sample lands on the handover time; pieces of 6.03–6.06 s go out with an empty queue, scan as whole MP3s, hold the music and appear in the listener playlist; Fade to B crossfades; a verse is posted, removed and a note posted from the drawer; End show (confirmed in the page) hands back to the AutoDJ. On cPanel (with the user's OK) the admin went live for one minute with test tones: Deck A 2 ms from the timeline, 10 pieces with no errors, a listener tab heard it through Cloudflare 20.0 s behind with no dropouts, End show back to the AutoDJ. The safety net also ended an earlier attempt by itself when the test script crashed. Screenshots at 1280 and 390 px match the mock-up. |
| Output strip (v0.4) | ☑ 2026-10-20 | ☑ 2026-10-20 | not yet (phone not connected) | Headless Edge. Player (33 checks on Laragon; 18 with `--listen-only` on the server through Cloudflare): the meter runs sample-exact in the sound engine; the strip reads 18–19 s ahead (7 song pieces, 4 live pieces in a live show); what it mixed ahead matched what went out to 0.0 dB on every source, through a crossfade and into a live show; each song file came over the network once between the decks and the look-ahead (Laragon 1.92 MB for 1.92 MB of files; server 1.16 MB of 1.44 MB); songs drawn in their colours. Studio (54 checks, Laragon): the same through Fade to B, toggle hides and shows it, height 50 px, arrow keys 70 px, remembered. `web_test.php` 116 / 46: songs near now carry their pieces, a piece is a 206 byte range through Cloudflare. Screenshots at 1280 and 390 px and hidden look right. Found while testing: a too-strict cache check (it counted the look-ahead's first read of the next song as a second download); it now counts each file's bytes from the browser's network log. |
| Output waveform (prototype) | ☑ 2026-10-20 | n/a | n/a | An artifact, not in the app yet. Headless Edge over HTTP: the AudioWorklet meter runs sample-exact (from a file:// page it falls back to analysers, now without gaps); through two crossfades (A→B, B→A) with the voice on air the readouts follow the mix (ducking to −11.6 dB, limiter up to −1.4 dB, voice compressor up to −9.7 dB); no page errors; checked at 1300 and 400 px wide. Found and fixed while testing: the browser tests left headless Edge running (and playing); `upload_browser_test.mjs` (12) and `studio_browser_test.mjs` (43) pass again on Laragon and now leave nothing running. |


## Result (2026-10-20)

**The proof of concept passes.** Every risk has an answer on the real shared host
(`wordwave.controll.co.za`, LiteSpeed, PHP 8.1, Cloudflare in front), with a PC on fibre and a
phone on 4G:

| # | Risk | Answer |
|---|---|---|
| R1 | Host blocks something | No. Byte ranges, rewrites, writable `data/`, 512 MB uploads, HTTPS all work |
| R2 | Byte-range HLS from the original MP3s | Plays gaplessly on desktop and Android Chrome, no stalls |
| R3 | On-request timeline keeps everyone in sync | Two devices on different networks hold the same delay to within ~10 ms; skips land on both at once |
| R4 | Crossfades and skips sound smooth | 6.0 s equal-power crossfades and 2 s skip fades, in the same second on both devices |
| R5 | Browser mic processing | User test on a PC with headphones: mic, gate, recording and ducking all good |
| R6 | Live encode and upload, handover, fallback | Live from a 4G phone: a piece every 6 s, empty queue, no stall at handover; AutoDJ resumes after End show or when the DJ drops (tested on Laragon) |
| R7 | Many listeners overload the account | Cloudflare serves the audio, and the playlist reaches PHP about once per 2 s per Cloudflare location, however many listeners |

**What we changed on the way** (details in `HANDOVER.md`): full-length crossfades; no repeat
after a cut; Skip uses what the presser hears; HLS players hold a fixed 20 s delay; server-only
`config.local.php`; long-lived caching for MP3s; a 2 s edge cache for the playlist.

**Known limits to carry into the build:**

- Listeners hear everything about 20 s late (HLS), including the DJ. The two-deck player hears
  the live edge; pick one delay for both during the build.
- The host's main `public_html/.htaccess` forces `no-cache` on everything below it, and Wordfence
  WAF runs before every PHP request. The station's own `.htaccess` must keep overriding cache
  headers, and PHP endpoints should stay light.
- MP3s are cached for a year, so a changed file needs a new name.

## What we deliberately left out of the POC

- **MySQL**, accounts, logins and the station manager UI. JSON files and one shared key stand in.
- **Upload-time audio prep** (ffmpeg.wasm levelling and fades). It's well-trodden, so it goes in
  build-out step 2.
- **The Windows app (Tauri).** On Windows it runs the same studio page inside WebView2, which is
  Chromium, so testing P3 and P4 in Chrome or Edge covers it.
- **The "Apache tells the time" static-playlist trick.** P0 checks the host supports it. We only
  build it if P1's load notes show PHP struggling.
- **Recording to podcast, listener stats, skins** (the designs exist in `design/`).

## After the POC: build-out order

Progress (2026-10-20): steps 1 and 2 are done (Core & manager, `v0.1`, closed as `0.1.0`). Step 3
is under way: the two-deck listener player with its HLS fallback is built and tested on desktop and an
Android phone; the embed and a now-playing API are still to come. Step 4 is under way: the live
studio works in the app (decks, mixer, presets, running order, notes for listeners); recording to a
podcast is still to come. `HANDOVER.md` has the details.

1. **Core:** MySQL schema, installer (WordPress-style), timeline planner as a service class, cron
   plus lazy planning, settings.
2. **Station manager:** library with in-browser prep and upload, playlists, schedule, DJs, sound
   and safety settings (designs in `design/`).
3. **Listener player and embed:** two-deck mode with an HLS fallback, now-playing API.
4. **DJ studio:** full decks, running order, presets, recording to podcast.
5. **Windows app:** Tauri wrapper, local Icecast bridge for BUTT and Mixxx users.
6. **Polish:** skins, listener stats, optional external Icecast relay for classic radio apps.
