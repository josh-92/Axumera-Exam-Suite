# Installer architecture

`installer/Axumera_Setup.iss` is the Inno Setup 6 fresh-install source. `scripts/prepare-distribution.ps1` rebuilds a clean private runtime, builds `AxumeraServer.exe`, and prepares `distribution/staging/Axumera` without initialized MariaDB data, `.env`, activation state, logs, or test material.

## First run

The installer requires elevation and refuses a selected directory that already contains `data\mariadb`. After files are installed it runs `AxumeraServer.exe setup` without credentials or license arguments. Setup mode accepts only a new, empty MariaDB data directory and refuses if `.env` or `storage/installed.lock` exists. It initializes the private loopback MariaDB service, starts Apache on loopback, and opens `installer/install.php` locally.

The wizard uses `application/eaes_exam_system/database/schema.sql` only; it does not execute historical migrations. In a packaged runtime it fixes the database destination to the installation's loopback MariaDB port, creates a random-password `axumera_app@127.0.0.1` account, and writes that application credential to the private `.env`. The administrator password is submitted only through the local browser form, stored with PHP `PASSWORD_DEFAULT`, and is neither an Inno variable nor a controller argument. Completion writes `storage/installed.lock`; the wizard then returns HTTP 403 and controller setup mode also refuses rerun. Fresh installations remain unactivated because no license is staged.

## Persistent-state boundary and updates

Replaceable components are `runtime` and application code. Customer state is `data` (including `data/mariadb`), `config`, `license`, and `logs`; uninstall preserves them. The current application's `.env`, `application/eaes_exam_system/storage/license.lic`, sessions, and uploads are also persistent exceptions inside `application` and a future updater must explicitly preserve them. It must never replace `data/mariadb`, `.env`, a license file, uploads, or logs, and must not run fresh setup.

No `Axumera_Update.exe` is implemented. Future database upgrades must use the controlled, versioned migration plan in `DATABASE_MIGRATION_RECONCILIATION.md`, not the historical migration folder automatically. See `UPDATE_ARCHITECTURE.md` and `INSTALLER_VALIDATION.md`.

## LAN launchers

The server installer includes `Axumera_Admin.exe`, a native launcher that checks local health, offers to start the controller, and opens the existing administrator login in the system browser. The separate `Axumera_Student_Setup.exe` includes only `Axumera_Student.exe`; it remembers a verified server address in the user's Local AppData and opens the existing student login in the system browser. No browser engine is bundled, avoiding an unverified WebView runtime dependency.

Apache listens on the configured application HTTP port for the trusted school LAN; MariaDB remains loopback-only. The server installer creates a Windows Firewall inbound TCP 8088 rule restricted to the Private profile. See `SERVER_DEPLOYMENT.md` and `STUDENT_DEPLOYMENT.md`.
