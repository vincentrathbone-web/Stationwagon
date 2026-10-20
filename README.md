# Stationwagon

Radio software for small stations that runs on ordinary **shared cPanel hosting**: an AutoDJ
with real crossfades, a stream for listeners, and a browser or Windows studio for live shows.
No root, no Docker, no extra ports, no programs running all day.

The first station it's designed around is Radio WordWave / Radio WoordGolf.

**Status (20 Oct 2026):** the proof of concept passed on the real shared host. **Core & manager
(`v0.1`) is done**: installer, sign-in and roles, the planner on MySQL, and the station manager
(library with song prep on upload, playlists, schedule, DJs, Sound & safety). **Player & studio
(`v0.4`) is under way**: the listener player works in desktop browsers and on an Android phone,
screen locked too. Next: presenter notes, then the live studio in the app. The test station runs at
https://wordwave.controll.co.za/stationwagon-app/.

## In this repo

| Path | What |
|---|---|
| [`HANDOVER.md`](HANDOVER.md) | Where the work stands, decisions, change log and open issues. Read this first |
| [`app/`](app/) | The real app: plain PHP 8.1+ and MySQL / MariaDB, with its tests in `app/tests/` |
| [`tools/`](tools/) | Deploy to cPanel over SSH (`deploy-app.sh`) and page screenshots (`shot.mjs`) |
| [`docs/POC-PLAN.md`](docs/POC-PLAN.md) | What the proof of concept had to prove, the test log, and the build-out order |
| [`docs/project-map.html`](docs/project-map.html) | A one-page picture of milestones, parts and the next step |
| [`prototypes/`](prototypes/) | The proof of concept: host check, HLS AutoDJ, crossfade player, mic chain, live studio |
| [`design/DESIGN.md`](design/DESIGN.md) | Screens, skin tokens and copy rules |
| [`design/skins/`](design/skins/) | Clickable mock-ups of the player, studio and manager in two skins (hi-fi and green) |

## How it works (short version)

- A **timeline** in MySQL plans which song plays when, hours ahead, from playlists and a weekly
  schedule. A cron job keeps it planned, and so does every listener request when it runs short.
- Listeners get **HLS**: a tiny playlist plus short pieces that are byte ranges inside the
  original MP3s. Apache serves them, and Cloudflare caches them.
- The station page's **player** follows the same timeline 20 seconds behind the server clock, so
  every listener hears the same moment. It mixes two decks for real crossfades and plays live shows
  from the stream.
- Songs are **levelled in the browser** on upload (−16 LUFS, silence trimmed, short fades).
- Live shows come from a **browser studio** that mixes mic and music, encodes MP3 and uploads a
  piece every 6 seconds (proved in the prototypes; coming to the app in `v0.4`).

To run it, see "Dev environment" and "Test server" in [`HANDOVER.md`](HANDOVER.md).
