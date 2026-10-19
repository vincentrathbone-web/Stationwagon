# Stationwagon: notes for agent sessions

- **Read `HANDOVER.md` first.** It has the current status, the decisions so far, the open issues
  and the dev environment (Laragon on port 8080, the PHP path, the phone-over-USB setup).
- **After every round of code changes, update `HANDOVER.md`** (status, decisions, scope changes,
  change log, open issues, versions) as part of the same change. Its first section lists the
  steps.
- Run the engine test after engine changes:
  `"C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe" prototypes/tests/engine_test.php`
- Record test results in the test log in `docs/POC-PLAN.md`.
- Commit only when the user asks.
