# Stationwagon

Radio software for small stations that runs on ordinary **shared cPanel hosting**: an AutoDJ
with real crossfades, a stream for listeners, and a studio for live shows in the browser, on
Windows or on Android. No root, no Docker, no extra ports, no programs running all day.

The first station it's designed around is Radio WordWave / Radio WoordGolf.

**New here? Start with the [Getting started guide](docs/getting-started.html)** (download it and
open it in a browser). It covers setting up a station, running it, and going live, with a contents
list and quick search.

## Status (29 Oct 2026)

| Part | Version | State |
|---|---|---|
| Server app (`app/`) | `0.8.0-dev` | Station manager, AutoDJ, listener player, browser studio, podcasts, listener stats: done and tested on the real shared host |
| Windows studio app | [`v0.6.1`](https://github.com/vincentrathbone-web/Stationwagon/releases/tag/v0.6.1) | Released; updates itself |
| Android studio app | [`android-v0.7.1`](https://github.com/vincentrathbone-web/Stationwagon/releases/tag/android-v0.7.1) | Released; updates itself |

**Next: the WordWave pilot (`v0.8`)**: fill the pilot station with test music, run it beside
AzuraCast for a week, then launch (`v1.0`). The test station runs at
https://wordwave.controll.co.za/stationwagon-app/ (the studio is at `/studio`, for signed-in staff).
[`HANDOVER.md`](HANDOVER.md) has the full status, decisions and open issues.

## What you need

- Shared hosting with cPanel, **PHP 8.1+** and **MySQL or MariaDB**.
- A cron job every 5 minutes (`php cli/plan.php`).
- Cloudflare in front is recommended: it caches the listener stream.
- For DJs: Chrome or Edge, Safari on iPhone and iPad, the Windows app (Windows 10/11) or the
  Android app (Android 8+).

## Quick install

1. In cPanel, make a MySQL database and a user with All Privileges.
2. Copy `app/` to the station's folder: `bash tools/deploy-app.sh <ssh host> public_html/<folder>`
   (or upload and extract it with File Manager).
3. Open the station's address and follow the installer: server check, setup key, database,
   station and timezone, your admin sign-in.
4. Add the cron job: `*/5 * * * * php /home/<account>/public_html/<folder>/cli/plan.php`.
5. Upload songs in **Music library**. The station plays straight away.

Updates: copy the new files over the old ones. The database upgrades itself.
Step by step, with screens and troubleshooting: [the Getting started guide](docs/getting-started.html).

## How it works (short version)

- A **timeline** in MySQL plans which song plays when, hours ahead, from playlists and a weekly
  schedule. A cron job keeps it planned, and so does every listener request when it runs short.
- Listeners get **HLS**: a tiny playlist plus short pieces that are byte ranges inside the
  original MP3s. Apache serves them, and Cloudflare caches them.
- The station page's **player** follows the same timeline 20 seconds behind the server clock, so
  every listener hears the same moment. It mixes two decks for real crossfades and plays live shows
  from the stream. A small **embed player** and a now-playing API (`api/nowplaying.php`) put the
  station on other websites.
- Songs are **prepared in the browser** on upload: **mastered** (matched to the station's Adobe
  Audition chain), levelled to −16 LUFS, silence trimmed, short fades. Both switches can be turned off.
- Live shows come from the **studio**, which mixes mic and music, encodes MP3 and uploads a piece
  every 6 seconds. A DJ goes live in their own schedule slot; Deck A picks up the AutoDJ song
  exactly where it is, so listeners hear no jump. If the pieces stop for 8 seconds, the AutoDJ takes
  over again by itself, and the DJ can **rejoin** the show.
- The studio comes three ways, all loading the station's own latest version:
  - the **browser** (`/studio`);
  - the **Windows app** (Tauri), with a local Icecast bridge so DJs can broadcast from **BUTT** or
    **Mixxx**;
  - the **Android app** (Capacitor), with Do Not Disturb on air and shows that keep going with the
    screen off.

  Both apps update themselves through the **Stationwagon Updates** WordPress plugin (`wordpress/`).
- Presenters post **notes for listeners** (Bible verses, references, video links, short notes)
  from the studio; listeners see each one when they reach that moment of the show.
- Every live show becomes a draft **podcast episode**: the studio records music and mic on the DJ's
  device, the DJ trims it in an editor and publishes it to the station's podcast page and RSS feed.
- **Listener stats** (now, today's peak, per day and month, countries, cities, devices) with a
  privacy page; each listen is kept for 30 days.
- **Sign-in** with roles (admin, manager, DJ), **Keep me signed in** for 30 days, and password
  saving in browsers and both apps.
- Every screen has two **looks**: Green and Hi-fi.
- The database **upgrades itself**: a new version's tables are added on deploy or on the first page
  view, so a station never needs a manual migration.

## In this repo

| Path | What |
|---|---|
| [`docs/getting-started.html`](docs/getting-started.html) | The training guide for admins, managers and DJs |
| [`HANDOVER.md`](HANDOVER.md) | Where the work stands, decisions, change log and open issues. Read this first when working on the code |
| [`app/`](app/) | The real app: plain PHP 8.1+ and MySQL / MariaDB, with its tests in `app/tests/` |
| [`app/cli/`](app/cli/) | Command-line jobs: `plan.php` (cron), `geo-update.php`, `install.php`, `upgrade.php`, `import.php`, `set-password.php` |
| [`desktop/`](desktop/) | The Windows studio app (Tauri 2) and its tests |
| [`mobile/`](mobile/) | The Android studio app (Capacitor) and its phone test |
| [`wordpress/`](wordpress/) | The Stationwagon Updates plugin that hands out the apps' updates |
| [`tools/`](tools/) | Deploy to cPanel (`deploy-app.sh`), build the apps (`build-desktop.sh`, `build-android.sh`), screenshots (`shot.mjs`), song-change previews (`song-changes.mjs`) and the mastering fit tools |
| [`docs/POC-PLAN.md`](docs/POC-PLAN.md) | What the proof of concept had to prove, the test log, and the build-out order |
| [`docs/project-map.html`](docs/project-map.html) | A one-page picture of milestones, parts and the next step |
| [`prototypes/`](prototypes/) | The proof of concept: host check, HLS AutoDJ, crossfade player, mic chain, live studio |
| [`design/DESIGN.md`](design/DESIGN.md) | Screens, skin tokens and copy rules |
| [`design/skins/`](design/skins/) | Clickable mock-ups of the player, studio and manager in two skins (hi-fi and green) |

To run it on a PC or the test server, see "Dev environment" and "Test server" in
[`HANDOVER.md`](HANDOVER.md).
