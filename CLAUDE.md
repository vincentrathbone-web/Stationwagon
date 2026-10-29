# Stationwagon: notes for agent sessions

- **Read `HANDOVER.md` first.** It has the current status, the decisions so far, the open issues
  and the dev environment (Laragon on port 8080, the PHP path, the phone-over-USB setup).
- **After every round of code changes, update `HANDOVER.md`** (status, decisions, scope changes,
  change log, open issues, versions) and the `MAP` data in `docs/project-map.html` and in the
  copy the user opens, `prototypes/Stationwagon Project Map.html`, as part of the same change. The handover's first section lists the steps.
- Run the engine test after engine changes:
  `"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" prototypes/tests/engine_test.php`
- Run all app tests after app changes, on Laragon and on the server (commands in HANDOVER.md
  "Dev environment" and "Test server"): `app/tests/install_test.php`, `auth_test.php`, `planner_test.php`, `manage_test.php`, `notes_test.php`, `studio_test.php`, `listeners_test.php`, `web_test.php`, and on this PC also the Node tests `prep_test.mjs`, `upload_browser_test.mjs`, `player_browser_test.mjs` (`--listen-only` for the server), `studio_browser_test.mjs`, `podcast_browser_test.mjs` and `skin_browser_test.mjs` (Laragon, or the server with `SW_HELPER_SSH`), and after player or studio changes `player_phone_test.mjs` and `studio_phone_test.mjs` on the Android phone (it must be unlocked). After changes to the Windows app (`desktop/`) or the studio: `cargo test` in `desktop/src-tauri`, `desktop/tests/app_test.mjs` (Laragon and the server), `butt_test.mjs` (with a BUTT folder) and, after updater or plugin changes, `update_test.mjs` (commands in HANDOVER.md). After changes to the Android app (`mobile/`), the studio or the Stationwagon Updates plugin: `mobile/tests/android_test.mjs` on the phone (two test builds first: `bash tools/build-android.sh 0.7.0 test` and `0.7.1 test`; the phone unlocked).
- Do the whole loop yourself (write, deploy, set up, test); never hand the user manual steps.
- **A round is done only when every feature in it is fully written and tested.** Don't close a round
  with "limits", "not tried yet" or "comes later" left over: fix each one in the same round, or put it
  to the user as a multiple-choice decision before going on. A limit that truly can't be fixed (a
  browser rule, something only the user can do) is said plainly and agreed with the user, not just
  listed under Open issues. (The user, 2026-10-21.)
- Record test results in the test log in `docs/POC-PLAN.md`.
- Commit and push only when the user asks.
