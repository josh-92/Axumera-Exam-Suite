# Installer validation record

## Build and package audit

| Check | Result | Evidence |
|---|---|---|
| Clean staging generation | PASS | `scripts/prepare-distribution.ps1` rebuilt runtime, controller, and clean staging on 2026-08-04. |
| Package secret/data audit | PASS | No `.env`, license, initialized MariaDB data, logs, `.git`, E2E/test/Frebuff artifacts, private key, or test credential was present in staging. Remaining text hits for licensing were application source references, not secrets. |
| Inno compiler | PASS | Inno Setup 6.7.3 on the controlled build workstation. |
| Installer compilation | PASS | The revised `installer/Axumera_Setup.iss` compiled on 2026-08-04. |
| Artifact | PASS | `distribution/Axumera_Setup.exe`, 53,491,713 bytes, built 2026-08-04 17:11 local build time. |

The compiler banner identified this local compiler as **“Non-commercial use only.”** Because Axumera is commercial, release distribution is **BLOCKED** until the build machine is confirmed to use a license/tooling configuration permitted for commercial distribution.

## First-run validation

**BLOCKED — INTERACTIVE UAC ENVIRONMENT REQUIRED.** The controlled build environment cannot complete the elevated Inno user interface. No claim is made that `Axumera_Setup.exe` itself has been interactively installed.

The same files and controller flow were executed in an isolated disposable private-runtime clone on 2026-08-04. `AxumeraServer.exe setup` initialized a new empty MariaDB data directory, served the loopback wizard, applied the authoritative `schema.sql`, and accepted a generated in-memory test password through the local HTTP form.

| Check | Result | Evidence |
|---|---|---|
| First setup page | PASS | HTTP 200 on the private loopback port. |
| Fresh schema and first admin | PASS | `admin_users` existed; the test administrator stored a `$2y$` bcrypt hash. |
| No plaintext administrator password in `.env` | PASS | Generated password was absent from `.env`. |
| Setup lock | PASS | Wizard returned HTTP 403 after `installed.lock` was written. |
| Controller setup rerun | PASS | Exit code 1: already initialized; setup mode refused. |
| Normal controller restart | PASS | HTTP 200 health check; controller remained running; app used `axumera_app`, not MariaDB root. |

No development/test license, signing key, customer data, or test credentials were placed in staging or the compiled artifact. The disposable validation directory is not installer staging and is not packaged.

## Update safety

The updater is implemented and synthetic tests passed on 2026-08-05. Do not use the installer as an updater; see `UPDATE_ARCHITECTURE.md` for the tested update/rollback behavior and boundaries.

## LAN deployment additions (2026-08-04)

| Check | Result | Evidence |
|---|---|---|
| LAN Apache binding | PASS | Disposable initialized runtime: health 200 and TCP 8098 reachable through LAN address `192.168.1.11`. |
| MariaDB exposure | PASS | Generated `axumera-my.ini` retained `bind-address=127.0.0.1`. |
| Native launchers | PASS (build) | `Axumera_Admin.exe` and `Axumera_Student.exe` compiled with the .NET Framework compiler. |
| Student package scope | PASS | Student staging contains only `Axumera_Student.exe`; no PHP, Apache, MariaDB, database, or application files. |
| Server package scope | PASS | Admin launcher present; server staging has no `.env`, license, or initialized MariaDB data. |
| Server installer recompile | PASS | `distribution/Axumera_Setup.exe` rebuilt after LAN/firewall/admin-launcher changes. |
| Student installer compile | PASS | `distribution/Axumera_Student_Setup.exe` compiled successfully. |

The launcher GUIs and both installers have **not** undergone an interactive UAC/Desktop test in this environment. Their build/package results do not substitute for that validation.
