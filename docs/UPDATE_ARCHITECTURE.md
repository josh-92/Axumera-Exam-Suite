# Update architecture status

**Status: IMPLEMENTED AND SYNTHETICALLY VALIDATED (2026-08-05).** `controller/AxumeraUpdate.cs` builds to `Axumera_Update.exe`; `scripts/build-update.ps1` stages a hash-verified package and `scripts/test-update-flow.ps1` / `scripts/test-update-rollback.ps1` validate success and rollback against disposable synthetic runtimes. A normal installer run remains fresh-install only and rejects a detected MariaDB data directory.

## Non-negotiable persistent state

- `data/mariadb`: all customer database records and MariaDB system data.
- `config`: ports and controller configuration.
- `license`: activation state.
- `logs`: operational history.
- `application/eaes_exam_system/.env`, `storage/license.lic`, uploads, and sessions: application-owned persistent files that a future updater must preserve explicitly.

## Required future update contract

1. Verify installation identity/version and available disk space; reject downgrade or unsupported source versions.
2. Stop `AxumeraServer.exe` and confirm Apache/MariaDB are down.
3. Create and verify a recoverable pre-update backup of the database plus `.env`, license, and uploads before any migration.
4. Stage replacement runtime/application files separately; do not delete current files until staging is verified.
5. Replace only updateable files and explicitly exclude every persistent path above.
6. Read a future schema-version ledger and apply only certified migrations newer than the recorded version. Do not run historical migrations blindly. On migration failure, stop and restore from the verified backup according to the update's documented procedure.
7. Restart the controller, require health success, and record the installed version.

The implemented updater stops local services, creates a pre-update backup, excludes persistent paths from replacement, runs the controlled migration runner, restarts and health-checks the server, and restores the pre-update database/configuration/version on failure. The 2026-08-05 tests verified a synthetic student and customer setting survive a successful update, and that an intentionally invalid migration returns non-zero while rollback restores the synthetic data, schema, version, and health.

Update packages must still be produced by the controlled build pipeline and used only against supported installed versions. The installer is not an updater.

## AXE 1.1 production updater (2026-08-08)

`controller/AxumeraUpdate11.cs` builds to `Axumera_Update.exe` with `scripts/build-axumera-update11.ps1`. It is the official update path from an installed AXE 1.0 deployment to AXE 1.1 and is distinct from the older `AxumeraUpdate.cs` console updater.

- **Authoritative source:** `new_update/eaes_exam_system_protected/` is scanned at build time; every shipped file is hashed (SHA-256) into a manifest embedded in the executable, so the source folder stays read-only at update time. The only generated file allowed in `new_update/` is `Axumera_Update.exe` (also copied to `distribution/Axumera_Update.exe`).
- **Target discovery:** the uninstall registry key for the installer AppId (`{4E5B38A1-9775-4BF5-9FA6-C450A1C1FEFE}_is1`, HKLM/HKCU × 32/64 views) plus structural verification (`AxumeraServer.exe`, `config/ports.json`, runtime binaries, `health.php`, `VERSION`). `--path` overrides discovery for testing; the source folder is explicitly rejected as a target. If no installation is found: `Existing Axumera installation not found.`
- **Manifest classification:** 113 application files ship (app/, PHP pages, assets/, database/ incl. versioned migrations, docs/, partials/, installer/install.php). Never shipped: `.env`, `storage/**` (license, lock, cache, logs, sessions, backups), `tests/**`, `desktop.ini`, `yakpro-po.cnf` — all preserved from the customer installation.
- **Flow:** GUI (or `--auto`) → admin/disk/version guards → SHA-256 package verification → **stop services** → timestamped verified backup in `%ProgramData%\Axumera Exam Suite\update-backups\` (full `data/mariadb` + full `application/eaes_exam_system` + `config/ports.json` + `config/axumera-my.ini`) → apply files (path-traversal-safe, application dir only) → regenerate `axumera-my.ini` (forward-slash paths; MariaDB option files treat `\` as an escape) → start MariaDB → run the packaged `database/run_migrations.php` → verify the `schema_migrations` ledger → stop MariaDB → write BOM-free `VERSION` → start `AxumeraServer.exe` → health check. Services are stopped **before** the backup so the MariaDB data directory is copied in a consistent, closed state; if the backup fails after stopping, the server is brought back up before reporting failure. If the update ports are still occupied by a foreign process after stopping, the update aborts before any change (`A process is still holding the update port...`), so migrations can never run against another instance's database. Any failure after backup rolls back files, database, config and version, restarts the previous version (with a longer 180s health grace window), and reports `The previous version has been restored.`
- **Version contract:** AXE 1.0 (`VERSION` `1.0.0`) → AXE 1.1 (`1.1.0`). Downgrades and unsupported source versions are refused; an already-updated installation reports `already up to date`.
- **Migration runner is packaged with AXE 1.1:** the AXE 1.0 installers shipped a stale `database/run_migrations.php` that called `Database::getConnection()` (a method neither the shipped AXE 1.0 nor the AXE 1.1 `Database` class has — both expose `connection()`/`connectWith()`). Because the runner was previously *preserved* (not shipped), a real update replaced the Database class and then ran the stale runner against it → `Call to undefined method App\Core\Database::getConnection()` → migration failure → rollback. `database/run_migrations.php` (using `Database::connectWith()`, reading the preserved `.env`) is now part of the AXE 1.1 payload, so the updater replaces the stale runner before running migrations. Verified against a clone carrying the real installed stale runner: the update replaced it and migrations completed.
- **Executed 2026-08-08 against disposable initialized clones:** success path with the server **live** during the update (registry discovery, stop → consistent backup, 114 files applied incl. the runner, migrations `2026_08_05_decouple_question_bank` + `2026_08_06_add_question_bank_crud` applied and ledger-verified, health OK, `VERSION` 1.1.0, `.env`/installed.lock byte-identical, synthetic data/admin preserved, Apache LAN / MariaDB loopback, no duplicates); idempotent second run; and rollback after an injected migration failure restored AXE 1.0 (files incl. the original stale runner, DB ledger, version, health).
- **AXE 1.1 source bugs found during verification:** (1) the obfuscated `app/bootstrap.php` license gate had dropped `health.php` from the activation-exempt list (AXE 1.0 allowed `license.php`/`logout.php`/`health.php`), so `/health.php` 302-redirected on unactivated installs and the post-update health check failed — the new-version source was fixed to restore the `health.php` exemption, the gate itself is unchanged; (2) the obfuscated `installer/install.php` had lost the `http_response_code(403)` on the installed lock and added a `?force` query bypass that re-enabled setup on an initialized installation — the new-version source now refuses with HTTP 403 and no bypass, matching AXE 1.0. Both were verified live on an updated clone (`install.php` and `install.php?force=1` both answer 403).
