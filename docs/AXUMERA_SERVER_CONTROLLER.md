# AxumeraServer controller

`controller/AxumeraServer.cs` builds to `build/runtime/AxumeraServer.exe` with `scripts/build-controller.ps1` using the installed Microsoft .NET Framework C# compiler. It is a controller only; the PHP application remains unchanged.

## First-run mode

The controller distinguishes two concepts: **runtime dependencies** (Apache/PHP/MariaDB binaries, `config/ports.json`, `health.php`, `installer/install.php` — always mandatory) and **installation state** (`.env` + `storage/installed.lock`). A fresh installation legitimately lacks both state files, so `start` now auto-detects that state and enters **first-run setup mode** instead of failing:

- Validates every runtime dependency, then reports `[INFO] Fresh installation detected.`
- Initializes the private MariaDB data directory with the bundled `mysql_install_db.exe` (or `mariadb-install-db.exe`; an existing non-empty data directory is reused, never re-initialized).
- Starts MariaDB, starts Apache, verifies the configured port is listening, and verifies the setup wizard (`/installer/install.php`) answers HTTP 200.
- Opens the browser wizard and waits while it runs. The wizard (unchanged PHP application) creates the database and applies the authoritative schema, writes `.env`, creates the first administrator, and only then writes `storage/installed.lock`.
- Once `.env` + `installed.lock` exist, verifies the database connection through `/health.php` and transitions to normal server mode, printing the standard `AXUMERA SERVER IS RUNNING` screen.

The controller never writes `.env`, never creates `installed.lock` itself, never creates an administrator, and never bypasses the wizard's HTTP 403 lockout — it only orchestrates the existing secure setup. The explicit `setup` command is fresh-install-only and is **refused** (exit 1) when the installation is already initialized; it uses the same orchestration. `stop` during first-run setup exits cleanly without writing a lock. The controller monitors loopback MariaDB/Apache availability instead of assuming their launcher-process lifetime, and `stop` requests graceful MariaDB shutdown after Apache stops.

On 2026-08-04 this was executed in a disposable empty runtime: setup page HTTP 200, authoritative schema initialization, secure first-admin creation, setup lockout, then normal restart and health HTTP 200.

The executable derives its root from its own executable location (`AppContext.BaseDirectory`), validates required binaries/configuration/application files, generates installation-specific Apache/PHP/MariaDB configuration, starts MariaDB, waits for `mysqladmin ping`, starts Apache, performs `/health.php`, logs to `logs/axumera-server.log`, and reports `SERVER READY` only after a successful health check. It maintains a per-installation mutex, records owned child PIDs, monitors them, and supports `start`, `status`, and `stop` commands.

## Runtime validation fix (2026-08-08)

The controller previously required `runtime\mariadb\bin\mysql_install_db.exe` unconditionally. The bundled MariaDB runtime is started with `mysqld.exe` and does not ship that setup-only tool, so every launch failed with the generic `Required runtime file is missing` before any service started. The controller now:

- Derives the installation root from `AppContext.BaseDirectory` (never `Directory.GetCurrentDirectory()`).
- Reports each required file as `[FOUND] <absolute path>` or `[MISSING] <absolute path>` and explains exactly why startup was stopped.
- Requires `mysql_install_db.exe` (or `mariadb-install-db.exe` when that is the shipped name) only in `setup` mode; normal `start` never demands it.
- Recognizes `mysqld.exe` as the MariaDB server and `mysqladmin.exe` as its admin client; `mysql.exe` is never treated as a server.
- Verifies startup operationally (MariaDB `ping`, Apache TCP listen on the configured port, then `/health.php`) before reporting RUNNING.
- Shows distinct `RUNNING`, `STARTUP FAILED` (with reason/executable/command), and `ALREADY RUNNING` screens, and keeps the window open while the controller is active or after a failure.
- Guards against duplicate instances with a stable cross-process mutex name (SHA-1 of the installation root); `string.GetHashCode()` is per-process randomized and cannot be used for that.

## First-run state handling fix (2026-08-08)

Previously, normal `start` treated a missing `.env` / `storage/installed.lock` as missing runtime files and aborted with `AXUMERA SERVER STARTUP FAILED` — a false failure on every fresh installation. The controller now treats "runtime valid + application uninitialized" as first-run setup mode (see above) and only reports a genuine `[MISSING]` when an actual runtime dependency is absent.

A second, independent packaging bug was found while testing the fresh-install flow: `config/ports.json` was written by `scripts/build-runtime.ps1` with `Set-Content -Encoding utf8`, which in Windows PowerShell 5.1 emits a UTF-8 BOM. The wizard's `json_decode()` rejects the BOM, so it could not detect the private runtime and fell back to manual database configuration ("Database name is required."). The scripts now write `ports.json` without a BOM (`System.Text.UTF8Encoding($false)`), and the shipped file is BOM-free.

Executed verification (fresh-install clone with no `.env`, no lock, no data directory): `start` printed the first-run screen, initialized the data directory, started MariaDB (`mysqld.exe`) and Apache (`httpd.exe`), the wizard answered 200, and after driving the wizard through steps 2–4 the controller detected initialization, verified the DB connection via `/health.php` (HTTP 200), and printed `AXUMERA SERVER IS RUNNING`. The schema was applied (all tables), the first administrator existed, `.env` contained a random `APP_KEY`/`DB_PASS` with the `axumera_app@127.0.0.1` user, and the wizard returned HTTP 403 afterwards. Existing-install verification: `start` ran in normal mode, a second `start` printed `ALREADY RUNNING` without duplicates, `setup` was refused, MariaDB stayed bound to `127.0.0.1`, and `stop`/restart cycles left no orphan processes.


Executed verification: compilation succeeded and a clean synthetic base runtime completed controller start → MariaDB readiness → Apache/PHP/application health → controller stop. The controller uses no XAMPP path. The existing PowerShell scripts remain available for build, initialization, diagnosis, and test operations.

## Apache URL mapping fix (2026-08-08)

The PHP application redirects unlicensed requests to `/eaes_exam_system/license.php` (`app/bootstrap.php` computes the prefix from its own directory name — an XAMPP-subdirectory assumption), but the controller served the application at the Apache **document root** with no mapping for that prefix. Apache therefore resolved `/eaes_exam_system/license.php` to the nonexistent `<app>\eaes_exam_system\license.php` and answered 404, even though `license.php` exists on disk.

`WriteRuntimeConfiguration()` now emits `Alias /eaes_exam_system "<app>"` in the generated `runtime/apache/conf/axumera-httpd.conf`. Both URL forms resolve to the application directory: root URLs (`/health.php`, `/license.php`, `/installer/install.php`) are unchanged, and the prefixed form (`/eaes_exam_system/...`) served via the alias maps to the same `<Directory>` protections (`.env`, `app/`, `database/`, `storage/` denied). The same edit defines the `combined` `LogFormat` nickname that the `CustomLog` directive references, so the access log records real request lines instead of the literal string `combined`.

Executed verification on an initialized clone (ports 8090/3310): `GET /eaes_exam_system/license.php` → HTTP 200 with the real activation page; `/eaes_exam_system/`, `/eaes_exam_system/index.php`, `/eaes_exam_system/adminlogin.php` → 200; `/eaes_exam_system/health.php` → `{"status":"ok"}`; root `/`, `/adminlogin.php`, `/license.php` → 200; the license-gate redirect chain lands on the working page; `/installer/install.php` → HTTP 403 (lock intact); access log shows real combined-format entries; MariaDB stayed loopback-only; stop/restart cycle clean with no duplicates. The controller was rebuilt (`build-controller.ps1`), synced to `distribution/staging/Axumera/AxumeraServer.exe`, and `distribution/Axumera_Setup.exe` was recompiled.
