# Stationwagon

Radio software for small stations that runs on ordinary **shared cPanel hosting**: an AutoDJ
with real crossfades, a stream for listeners, and a studio for live shows in the browser, on
Windows or on Android. No root, no Docker, no extra ports, no programs running all day.

The first station it's designed around is Radio WordWave / Radio WoordGolf. Its website and Android listener app live in the **[WordWave repo](https://github.com/vincentrathbone-web/WordWave)** and read from this station through public JSON endpoints (now playing, schedule, podcasts, verse of the day, stream).

**New here? Start with the [Getting started guide](docs/getting-started.html)** (download it and
open it in a browser). It covers setting up a station, running it, and going live, with a contents
list and quick search.

## Status (10 Nov 2026)

| Part | Version | State |
|---|---|---|
| Server app (`app/`) | `0.9.11-dev` | Station manager (full width), an Ads screen (ads the AutoDJ plays between songs, paced by plays a day), AutoDJ, listener player, browser studio, podcasts, listener stats, best-quality song prep, uploads in any common format and a details window with licensing, a sortable Music library table with star ratings: done, tested and **live** |
| Windows studio app | [`v0.6.4`](https://github.com/vincentrathbone-web/Stationwagon/releases/tag/v0.6.4) | Released; finds the station from the website's address; offers each new version in an update window |
| Android studio app | [`android-v0.7.4`](https://github.com/vincentrathbone-web/Stationwagon/releases/tag/android-v0.7.4) | Released; finds the station from the website's address; offers each new version in an update window |
| WordPress plugin | `1.2.1` | Installs and updates a station next to a WordPress site |

**The first station is live**: Radio WordWave plays its music on Stationwagon at
https://wordwave.co.za/radio/ (the studio is at `/studio`, for signed-in staff), and the website
plays it through the embed. It has run live music for over a week, so the planned week beside
AzuraCast is no longer needed. **Next: the launch steps (`v1.0`)**: guides for managers and DJs,
the upgrade path without WordPress, and online song lookups (to investigate).

> **Since 0.6.2 and 0.7.2 the apps are signed with new keys.** If you have Windows 0.6.1 / Android 0.7.1 or
> older, uninstall it once and install the new one from the studio's **Get the app** link. From 0.6.3
> and 0.7.3 on, the apps show an **update window** when a new version is out: what it brings, then
> **Update now** or **Remind me later**.

[`HANDOVER.md`](HANDOVER.md) has the full status, decisions and open issues.

## What you need

- Shared hosting with cPanel, **PHP 8.1+** and **MySQL or MariaDB**. A WordPress site makes
  setup easiest. A VPS with HestiaCP (nginx in front) works too: see [`docs/hestia/`](docs/hestia/)
  for the nginx rules that keep the station's private files private.
- No cron job needed: the background jobs also run during visits (a cron job is optional).
- Cloudflare in front is recommended: it caches the listener stream.
- For DJs: Chrome or Edge, Safari on iPhone and iPad, the Windows app (Windows 10/11) or the
  Android app (Android 8+).

## Quick install

The files are in the public [stationwagon-releases](https://github.com/vincentrathbone-web/stationwagon-releases/releases/latest) repo (Releases → Latest).

**With WordPress**

1. Download `stationwagon-wordpress.zip`.
2. In WordPress: **Plugins → Add New Plugin → Upload Plugin**, choose the zip, **Install Now**, **Activate**.
3. Open **Stationwagon** in the admin menu and press **Set up my station**. The easy answers are
   already picked: a folder of the site (`/radio`), the site's own database, background jobs during
   visits. A subdomain, a database of its own or a cron job can be made for you with the cPanel login.
4. Open the manager and upload songs in **Music library**. The station plays straight away.

Updating the plugin updates the station.

**Without WordPress**

1. Download `setup.php` and upload it to your web space (cPanel → **File Manager**), for example into `public_html`.
2. Open it in your browser (`https://example.com/setup.php`). It checks the server, asks where the
   station lives (a subfolder `/radio`, picked by default; that folder; or a new subdomain made
   through cPanel), downloads the station from the releases and hands over to its installer.
3. The installer asks the same easy questions: a database made for you through cPanel (or one you
   made), background jobs during visits (or a cron job added for you, or the line to paste), the
   station's name and your sign-in.

`setup.php` works for an hour after it is uploaded and removes itself when done.
To build the files yourself: `python tools/build-wp-plugin.py` and `python tools/build-setup.py`.
[The Getting started guide](docs/getting-started.html) has every step with troubleshooting.

## How it works (short version)

- A **timeline** in MySQL plans which song plays when, hours ahead, from playlists and a weekly
  schedule. A cron job keeps it planned, and so does every listener request when it runs short.
- Listeners get **HLS**: a tiny playlist plus short pieces that are byte ranges inside the
  original MP3s. Apache serves them, and Cloudflare caches them.
- The station page's **player** follows the same timeline 20 seconds behind the server clock, so
  every listener hears the same moment. It mixes two decks for real crossfades and plays live shows
  from the stream. A small **embed player**, a **Now playing card** (`now`: album art, the song and a
  link to your Listen page, no sound) and a now-playing API (`api/nowplaying.php`) put the station on
  other websites.
- Under the staff's screens (the studio, the manager and the account page) runs **the bar**: what's on
  air, a scrolling strip of the output and the song's progress, with a speaker button and a volume
  slider. It opens muted, keeps playing with no gap while you move between screens, and goes quiet by
  itself in the studio and Song prep. In a browser the screens open inside a frame page with the bar
  under them; the Windows and Android apps have a bar area of their own.
- Songs are **prepared in the browser** on upload: silence trimmed with short fades, **mastered**
  with the station's preset (optional), levelled to the loudness target (−16 LUFS by default) and
  encoded **once** with real **LAME 3.100** (160 kbps by default; sample rate, precision, dither and
  encoding quality are settings). An MP3 that needs nothing done is kept exactly as it came.
- **Any common format** can be uploaded: WAV, FLAC, M4A (AAC, ALAC), AAC, Ogg, Opus and WMA are turned
  into MP3 in the browser by the station's own small FFmpeg decoder, keeping their tags and cover.
- Before each song is added, a **details window** asks for its album art, title and artist, the
  recording (label, ISRC, ℗), the song (songwriters, publisher, ISWC, ©) and its **licence** (type,
  number, link, valid until, terms), filled in from the file's own tags. Missing songwriters, licence or
  publisher are warned about but don't block; listeners can see all of it from the player.
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

  Both apps get their updates through the **Stationwagon Updates** WordPress plugin (`wordpress/`),
  which hands out the newest GitHub release. When the app opens and a newer version is out, an
  **update window** shows what it brings (the release's notes: New features, Improvements, Fixes,
  Warning) with **Update now** and **Remind me later**, in the look picked in the studio.
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
| [`wordpress/stationwagon/`](wordpress/stationwagon/) | The Stationwagon plugin: installs a station next to a WordPress site with a setup wizard, keeps it up to date, and adds the `[stationwagon_player]` shortcode |
| [`wordpress/stationwagon-updates/`](wordpress/stationwagon-updates/) | The Stationwagon Updates plugin that hands out the apps' updates |
| [`tools/`](tools/) | Deploy to cPanel (`deploy-app.sh`), build the apps (`build-desktop.sh`, `build-android.sh`), screenshots (`shot.mjs`), song-change previews (`song-changes.mjs`) and the mastering fit tools |
| [`docs/POC-PLAN.md`](docs/POC-PLAN.md) | What the proof of concept had to prove, the test log, and the build-out order |
| [`docs/project-map.html`](docs/project-map.html) | A one-page picture of milestones, parts and the next step |
| [`prototypes/`](prototypes/) | The proof of concept: host check, HLS AutoDJ, crossfade player, mic chain, live studio |
| [`design/DESIGN.md`](design/DESIGN.md) | Screens, skin tokens and copy rules |
| [`design/skins/`](design/skins/) | Clickable mock-ups of the player, studio and manager in two skins (hi-fi and green) |

To run it on a PC, see "Dev environment" in [`HANDOVER.md`](HANDOVER.md); "The live station" there
covers deploying to wordwave.co.za.
