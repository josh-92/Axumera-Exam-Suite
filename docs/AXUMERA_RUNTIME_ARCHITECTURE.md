# Axumera private runtime architecture

`build/runtime` is the portable product boundary. It contains a curated Apache, PHP and MariaDB runtime, a clean copy of the PHP application, per-installation data and logs. It contains neither XAMPP nor phpMyAdmin.

`start-axumera.ps1` derives all server paths from its own location, regenerates Apache, PHP and MariaDB configuration, starts MariaDB first, waits for its TCP health probe, then starts Apache and checks `/health.php`. Both services bind to loopback by default. `stop-axumera.ps1` requests graceful shutdown.

The application is served at the Apache document root (rather than an XAMPP subdirectory). Its existing `.htaccess` uses `RewriteBase /eaes_exam_system/`; this is a known application routing assumption that must be validated against every route before a commercial release. Static entry points and the health endpoint work at root; a small application rewrite-base adjustment may be required for rewritten routes.

No `AxumeraServer.exe` is shipped at this milestone. The PowerShell controller scripts are the executable controller contract and are deliberately kept separate from the PHP application; a signed native wrapper can be added after its packaging and operational requirements are selected.

The only application change is `health.php` plus allowing that endpoint past the existing license gate. It returns only `{"status":"ok"}` or `{"status":"unhealthy"}` after application bootstrap and a database `SELECT 1`; no secrets are exposed.
