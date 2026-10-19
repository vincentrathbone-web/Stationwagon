# Stationwagon prototypes

Small, separate tests for the risky parts of Stationwagon. The plan, risks and pass criteria are
in [`docs/POC-PLAN.md`](../docs/POC-PLAN.md).

| Folder | What it tests | Open |
|---|---|---|
| `p0-host-check/` | Can this host run Stationwagon? | `p0-host-check/index.php` |
| `p1-hls-autodj/` | AutoDJ as an HLS stream of byte ranges inside the MP3s; Skip | `p1-hls-autodj/index.html` |
| `p2-crossfade-player/` | Two-deck player with real crossfades, following the same timeline | `p2-crossfade-player/index.html` |
| `p3-mic-chain/` | Gate, compressor, EQ, limiter and ducking in the browser | `p3-mic-chain/index.html` |
| `p4-live-studio/` | Go live in sync, encode MP3 in the browser, upload 6 s pieces, end show | `p4-live-studio/index.html` |

Shared code:

- `config.php`: all settings (piece length, crossfade, timeouts, control key)
- `shared/mp3.php`: pure-PHP MP3 frame scanner (finds the 6-second cut points)
- `shared/library.php`: scans `media/` and caches each song's index
- `shared/timeline.php`: the planner; skip, go live, live pieces, end show, timeout
- `api/stream.php`: the live HLS playlist
- `api/timeline.php`: timeline around now plus the server clock (JSON)
- `api/control.php`: skip, golive, endlive, reset, rescan (POST, needs the control key)
- `api/upload.php`: receives live pieces from the studio
- `shared/js/`: server clock, deck, mic chain, MP3 frame splitter

## Run it on Laragon (local)

1. Put at least 3 MP3s in `prototypes/media/`. A few songs from the station library are ideal.
   Four test tracks are already there on this PC (a tone with a tick every second and a beep
   every 10 s, so you can hear whether two devices are in sync). To make them again, download
   `https://cdn.jsdelivr.net/npm/lamejs@1.2.1/lame.min.js` and run
   `node prototypes/tools/make-test-mp3s.js path/to/lame.min.js prototypes/media`.
2. Make the `prototypes` folder reachable through Laragon's Apache, for example with a junction
   (run in an administrator Command Prompt):

   ```bat
   mklink /J C:\laragon\www\stationwagon "D:\VS Code\Stationwagon\prototypes"
   ```

   Then open `http://localhost/stationwagon/p0-host-check/index.php` (add `:8080` if your Laragon
   Apache uses that port).
3. Work through P0 → P1 → P2 → P3 → P4. The microphone works on `localhost` without HTTPS.

Use Apache (Laragon or cPanel) for P0, P1, P2 and P4. PHP's built-in server
(`php -S localhost:8090 -t prototypes`) is only good for poking the API: it ignores `.htaccess`
and answers byte-range requests with the whole file, so HLS pieces and seeking break. P3 needs no
server at all beyond serving the files.

The engine can also be tested without a browser:

```bash
php prototypes/tests/engine_test.php
```

## Run it on the cPanel account

1. Change `control_key` in `config.php`.
2. Upload the whole `prototypes/` folder (for example to `public_html/stationwagon-poc/`)
   together with a few MP3s in `media/`.
3. Make sure `data/` is writable (755 or 775 is usually enough).
4. Use an HTTPS address: browsers only allow the microphone on HTTPS.
5. Open P0 first. Every required row must be green.

## Two-device sync test (P1 and P2)

Open the same page on a laptop and a phone, side by side, and compare the **stream clock** (P1)
or listen for the song changes (P2). Press **Skip** on one device: both should change at the
same moment.

## Live test (P4 with P1)

1. Laptop with headphones: open P4, press **Prepare studio**, allow the mic.
2. Phone: open P1 and press **Listen**.
3. Laptop: press **Go live**. Deck A picks up the current song. On the phone, nothing should
   change at the handover.
4. Fade Deck A, press **Mic on air** and talk. The phone hears you about 18–25 s later.
5. Press **End show**: the AutoDJ continues with the next song.
6. Safety-net test: go live again and pull the network cable. Within about 10 s the station
   goes back to the AutoDJ.

## Resetting

**Reset timeline** on P1 clears the plan; it is rebuilt on the next request. Delete
`data/library.json` to force a full rescan of `media/`.
