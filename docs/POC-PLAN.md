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
| P0 Host check | ☑ 2026-10-19 | ☐ | All required checks pass (PHP 8.3.33, Apache 2.4.68, ranges 206, data/ blocked 403, clock offset 0.00 s). ffmpeg not found, no HTTPS: both fine locally. |
| P1 HLS AutoDJ | ☑ 2026-10-19 | ☐ | PC (built-in browser) + Android phone (Chrome 155 over USB): no stalls. Skip from the phone heard on both in the same second. Fixed: a cut song could come straight back; Skip from a lagging listener cut the next song; clock showed "PM.5"; hls.js left the two devices 1.0 s apart, so P1 now holds a fixed 20 s delay against the server clock (both lock at 20.03 s). Skip is heard ~20–26 s after the press. |
| P2 Crossfade player | ☑ 2026-10-19 | ☐ | PC + phone: both crossfaded in the same second, 6.0 s; drift −3 ms vs −7 ms; Skip fade 2.0 s. Phone clock was 0.15 s off and was corrected. Fixed: crossfades were 0–6 s long; the planner now always leaves a full 6 s. |
| P3 Mic chain | ◐ 2026-10-19 | n/a | The chain runs on the phone inside P4 (gate opens, ducking −12 dB, meters move). How it sounds still needs a person with headphones. |
| P4 Live studio | ☑ 2026-10-19 | ☐ | Phone as studio, PC as listener. Go live: Deck A picked up the current song (drift −90 ms), pieces uploaded every 6 s with an empty queue, the listener moved to the live show with no stall. End show: AutoDJ back at once. Safety net: phone taken offline mid-show, the server closed the show (timeout) and the listener went back to the AutoDJ with no stall; the phone got 410 and said so. |
| Cloudflare caching (P1 behind a proxied subdomain) | n/a | ☐ | |

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

1. **Core:** MySQL schema, installer (WordPress-style), timeline planner as a service class, cron
   plus lazy planning, settings.
2. **Station manager:** library with in-browser prep and upload, playlists, schedule, DJs, sound
   and safety settings (designs in `design/`).
3. **Listener player and embed:** two-deck mode with an HLS fallback, now-playing API.
4. **DJ studio:** full decks, running order, presets, recording to podcast.
5. **Windows app:** Tauri wrapper, local Icecast bridge for BUTT and Mixxx users.
6. **Polish:** skins, listener stats, optional external Icecast relay for classic radio apps.
