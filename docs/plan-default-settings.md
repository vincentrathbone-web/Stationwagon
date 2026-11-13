# Plan: WordWave's sound settings become the defaults for new stations

**Status:** done in round 86 (2026-11-13). The user chose to change the built-in chain itself (keeping its name
"Audition chain") rather than seed the "Audition2" starter preset described below; see HANDOVER's Decisions.

## Why

The user, 2026-11-13: the Sound & safety and Song prep settings on WordWave are the best ones for every
future station. They were copied onto Gansbaai the same day (see "Already done"); the code's defaults still
need to follow, so a new installation starts with them.

## Already done (live data only, no code)

- Gansbaai's `sw_stations.settings` now holds WordWave's values exactly, except its own look (`look`: `ocean`)
  and `jingles_crossfade_seconds` written as 1.0 (WordWave doesn't store it; 1.0 is its value from the default).
- Gansbaai's preset "Audition2" (id 1, its import default) now has WordWave's "Audition2" settings.
- The coming songs were planned again with the new values; `web_test.php --public` passed.
- Backups: `/home/controll1/pre-settings-copy-20261113-1635.sql` (the whole database) and
  `/home/controll1/settings-before-20261113-1635-copy-from-wordwave.sql` (`sw_stations` and `sw_prep_presets` only).

## The values (WordWave, 2026-11-13)

`Installer::defaultStationSettings()` (`app/src/Installer.php`) changes these; everything else already matches:

| Key | Now | New |
|---|---|---|
| `crossfade_seconds` | 6.0 | 8.0 |
| `skip_fade_seconds` | 2.0 | 4.0 |
| `ads_crossfade_seconds` | 2.0 | 1.0 |
| `jingles_every_songs` | 4 | 3 |
| `prep_kbps` | 160 | 224 |
| `prep_master` | 0 | 1 |
| `prep_target` | -16.0 | -11.0 |
| `prep_preset` | (not set: 0, the built-in Audition chain) | the new station's "Audition2" preset (see below) |

Unchanged and already WordWave's: `segment_seconds` 6, `plan_ahead_seconds` 10800, `recent_tracks` 5,
`ratings_influence` 0, `cut_lead_seconds` 1, `ads_enabled` 1, `ads_every_songs` 3, `ads_per_break` 1,
`ads_gap_minutes` 0, `jingles_enabled` 1, `jingles_crossfade_seconds` 1.0, `live_timeout_seconds` 8,
`listener_delay_seconds` 20, `live_fade_seconds` 6, `fade_shape` equal_power, `duck_db` -12, `rejoin_minutes` 10,
`silence_db` -40, `live_kbps` 192, `prep_level` 1, `prep_rate` 44100, `prep_precision` 32, `prep_dither` 1,
`prep_quality` 9. `look` stays out of the defaults (Green when not set, round 83).

Update the comments next to the values. They give the reasons for the old numbers, for example "mastering is off
unless chosen (round 39)" and "-16 LUFS". Say these are now WordWave's tuning, the user's choice on 2026-11-13.

## The import preset

The user's choice (pending; ask): **seed a preset** rather than change the built-in chain. The built-in "Audition chain"
(`master-core.js`'s `P`, `PrepPresets::BUILT_IN`) stays exactly as it is, because `prep_test.mjs` checks it sample
for sample and v1.13's server-side prep runs the same code.

- In `Installer::install()`, inside the same transaction, right after the station row is added: insert a
  `prep_presets` row named `Audition2` with the settings below. Then set the station's `prep_preset` to its id,
  either by writing the settings after the insert or by adding the id before `json_encode`.
- Better: keep the preset in one place, e.g. `PrepPresets::STARTER = ['name' => 'Audition2', 'settings' => [...]]`.
  The installer and the tests both read it from there.
- Existing stations are not touched (no schema step): WordWave and Gansbaai already have it.

The settings, with the floating-point noise rounded off (WordWave stores the same numbers with long tails):

```json
{"cross":[137,1147,7941],"threshold":[-18,-15.4,-22.6,-22.8],"ratio":[2.44,1.72,1.72,2.44],"knee":2,
 "gain":[1.5,0,0,3],"attackMs":0.3,"releaseMs":411,"mbThresh":-5.5,"mbMargin":-0.6,"shelfDb":-1.7,
 "exciteDb":-72,"sideDb":6.8,"maxGain":2.7,"ceiling":-0.1,"eqOn":false,"compOn":true,"finishOn":true,
 "eq":{"hp":{"on":false,"hz":30},"low":{"hz":100,"db":0},
       "bells":[{"hz":250,"db":0,"q":1},{"hz":1000,"db":0,"q":1},{"hz":4000,"db":0,"q":1}],"high":{"hz":10000,"db":0}},
 "simple":{"amount":0.36,"punch":0.11,"loudness":2.7,"ceiling":-0.1}}
```

Run it through `PrepPresets::save()`'s own checks (or a test that does), so the starter can't hold a value the
Song prep screen would refuse.

## What else follows from it

- **v1.13 (server-side prep)** must read the station's import preset and these settings (224 kbps, mastering on,
  −11 LUFS) exactly as `upload.js` and `prep.js` do, so a song prepared on the server matches one prepared in the browser.
- **New installs only.** The installer stores the whole settings list in the station's row, so existing stations
  keep what they have. Planner::settings fills only keys a station never stored.
- **Tests that assume the old defaults.** Check each one and update it to the new values where it states a default:
  - `install_test.php`
  - `manage_test.php`: "new uploads start with the built-in Audition chain", and `PrepPresets::defaultId()` being 0
  - `planner_test.php` and `ads_test.php`: the crossfades
  - `jingles_test.php`: every 4 songs
  - `web_test.php`
  - `browser_helper.php`
  - `upload_browser_test.mjs`: it says "a new station has mastering off" and switches mastering on itself
- **The installer rule (CLAUDE.md).** A change to the installer means rebuilding and testing these:
  - `python tools/build-wp-plugin.py`, then `wordpress/tests/setup_test.php` on the Laragon test WordPress (with the
    cPanel stand-in) and on the server's test WordPress.
  - `python tools/build-setup.py`, then `setup/tests/setup_php_test.php` on Laragon (with the cPanel stand-in).
  - The server run of `setup_php_test.php` downloads from the real release, so it needs a new `stationwagon-releases`
    release first. Ask the user about the release.
- **Docs:** HANDOVER (Decisions, Change log, Versions; the plugin's version if it is rebuilt), the POC-PLAN test log,
  and both copies of the project map.
