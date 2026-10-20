# Stationwagon: notes for agent sessions

- **Read `HANDOVER.md` first.** It has the current status, the decisions so far, the open issues
  and the dev environment (Laragon on port 8080, the PHP path, the phone-over-USB setup).
- **After every round of code changes, update `HANDOVER.md`** (status, decisions, scope changes,
  change log, open issues, versions) and the `MAP` data in `docs/project-map.html` (then
  republish it) as part of the same change. The handover's first section lists the steps.
- Run the engine test after engine changes:
  `"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" prototypes/tests/engine_test.php`
- Run all app tests after app changes, on Laragon and on the server (commands in HANDOVER.md
  "Dev environment" and "Test server"): `app/tests/install_test.php`, `auth_test.php`, `planner_test.php`, `manage_test.php`, `notes_test.php`, `studio_test.php`, `listeners_test.php`, `web_test.php`, and on this PC also the Node tests `prep_test.mjs`, `upload_browser_test.mjs`, `player_browser_test.mjs` (`--listen-only` for the server) and `studio_browser_test.mjs` (Laragon only), and after player changes `player_phone_test.mjs` on the Android phone (it must be unlocked).
- Do the whole loop yourself (write, deploy, set up, test); never hand the user manual steps.
- Record test results in the test log in `docs/POC-PLAN.md`.
- Commit and push only when the user asks.
