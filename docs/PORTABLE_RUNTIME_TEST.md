# Portable runtime test record

## Executed result — 2026-08-03

The test procedure is implemented in `scripts/verify-runtime.ps1` and `scripts/smoke-test.ps1`. It validates PHP extensions, Apache configuration syntax, MariaDB TCP readiness, and HTTP `/health.php`, which includes application bootstrap and a PDO database query.

Executed from a fresh `build/runtime` with no use of XAMPP: `build-runtime.ps1 -Force`, `initialize-database.ps1` against generated data, then `start-axumera.ps1`. Passed: bundled PHP extension validation, Apache syntax validation, MariaDB startup/readiness, Apache startup, HTTP `/health.php`, application bootstrap, and PDO database `SELECT 1`. The runtime was then stopped with `stop-axumera.ps1`.

Admin and student login and an exam-flow smoke test are intentionally **not claimed**: the app's license gate requires a valid purpose-made test license, and no approved non-production student/exam dataset was available. No production data was modified. The supplied legacy migrations were deliberately not run because their table references are incompatible with the current clean schema; this is recorded in the audit.
