# Stationwagon

Radio software for small stations that runs on ordinary **shared cPanel hosting**: an AutoDJ
with real crossfades, a stream for listeners, and a browser or Windows studio for live shows.
No root, no Docker, no extra ports, no programs running all day.

Status: **proof of concept**. The first station it's designed around is Radio WordWave /
Radio WoordGolf.

## In this repo

| Path | What |
|---|---|
| [`HANDOVER.md`](HANDOVER.md) | Where the work stands, decisions, change log and open issues. Read this first |
| [`docs/POC-PLAN.md`](docs/POC-PLAN.md) | What we must prove first, how, and the build-out order afterwards |
| [`prototypes/`](prototypes/) | Runnable tests for each risky part (host check, HLS AutoDJ, crossfade player, mic chain, live studio) |
| [`design/DESIGN.md`](design/DESIGN.md) | Screens, skin tokens and copy rules |
| [`design/skins/`](design/skins/) | Clickable mock-ups of the player, studio and manager in two skins (hi-fi and green) |

## How it works (short version)

- A **timeline** plans which song plays when, hours ahead, on demand.
- Listeners get **HLS**: a tiny playlist plus short pieces that are byte ranges inside the
  original MP3s. Apache serves them, and Cloudflare can cache them.
- Our own player can mix two decks for **real crossfades**, following the same timeline.
- Live shows come from a **browser studio** that mixes mic and music, encodes MP3 and uploads a
  piece every 6 seconds.

Start with [`prototypes/README.md`](prototypes/README.md).
