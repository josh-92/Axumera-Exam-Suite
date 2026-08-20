# Deployment Guide

This guide covers taking EAES from a working install to a production
deployment: server requirements, the hardening checklist, HTTPS, backups
and restore, and the migration procedure. It was verified against the
release candidate (2026-08-09) — every command below was exercised against
a fresh install on XAMPP.

## 1. Server requirements

- **Apache** with `mod_rewrite` and `mod_headers` (the root `.htaccess`
  uses `RewriteRule` for folder protection and `Header always set` for
  response headers — both modules must be loaded). Verify with
  `httpd -M` on the server.
- **PHP 8.1+** (tested on 8.4) with `pdo_mysql`, `mbstring`, `json`,
  `openssl`, `curl` (optional, for semantic duplicate detection) and
  `PharData`/`SimpleXML` (optional, for .xlsx student imports).
- **MySQL 5.7+ / MariaDB 10.4+**.
- The deployment folder should be named `eaes_exam_system` (the bundled
  `.htaccess` uses `RewriteBase /eaes_exam_system/`; if you deploy under a
  different name, update `RewriteBase` to match — the folder-protection
  rules themselves do not depend on it).

No Composer, no Node build step, no framework.

## 2. Production hardening checklist

Run the installer once (`installer/install.php`), then:

1. **Remove or lock the installer.** `storage/installed.lock` is a hard
   stop — the installer refuses to run again (including the old `?force=`
   bypass, which was removed). For defense in depth, delete the
   `installer/` folder or block it with a local `.htaccess`
   (`Require all denied`).
2. **Activate the license.** Upload the signed `license.lic` from the
   vendor on the activation screen, or place it at `storage/license.lic`.
   The license is hardware-bound: it validates against the server's
   motherboard UUID and caches the result for 24 h in
   `storage/cache/license.cache`.
3. **Confirm debug is off.** The installer writes `APP_DEBUG=false` and
   `APP_ENV=production`; leave them that way. With debug off, errors show
   a generic page and are written to `storage/logs/php-error.log`.
4. **Enable HTTPS and force it.** Configure Apache with an SSL virtual
   host, then set `FORCE_HTTPS=true` in `.env`. With this on:
   - plain-HTTP requests are redirected to `https://` (302),
   - session cookies are marked `Secure`,
   - `Strict-Transport-Security` is sent on HTTPS responses.
   Session cookies are always `HttpOnly` + `SameSite=Lax`.
5. **Keep the protected paths protected.** The root `.htaccess` denies
   direct web access to `app/`, `storage/`, `database/`, `partials/` and
   `.env*` (HTTP 403). Verify after deploy:
   ```bash
   curl -o /dev/null -s -w '%{http_code}\n' https://example.com/eaes_exam_system/app/config.php   # 403
   curl -o /dev/null -s -w '%{http_code}\n' https://example.com/eaes_exam_system/.env             # 403
   ```
6. **Confirm security headers.** Every response carries
   `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
   `Referrer-Policy: strict-origin-when-cross-origin` and a restrictive
   `Content-Security-Policy`.

## 3. HTTPS on Apache

Minimal recipe for XAMPP / stock Apache (adjust paths for your platform):

```apache
<VirtualHost *:443>
    ServerName exam.example.com
    DocumentRoot "C:/xampp/htdocs/eaes_exam_system"
    SSLEngine on
    SSLCertificateFile      "conf/ssl.crt/server.crt"
    SSLCertificateKeyFile   "conf/ssl.key/server.key"
    # (optional) Redirect plain HTTP in a second vhost:
</VirtualHost>

<VirtualHost *:80>
    ServerName exam.example.com
    Redirect permanent / https://exam.example.com/
</VirtualHost>
```

Then set `APP_URL=https://exam.example.com/eaes_exam_system` and
`FORCE_HTTPS=true` in `.env`. The application also honours
`HTTP_X_FORWARDED_PROTO` when it sits behind a TLS-terminating proxy.

## 4. Backup and restore

**What to back up**

| Item | Why |
|---|---|
| MySQL database (`mysqldump`) | All exams, questions, students, attempts, analytics |
| `.env` | `APP_KEY` signs CSRF tokens and license binding; DB credentials |
| `storage/license.lic` | The activation file (hardware-bound) |
| `storage/backups/` | Anything the app itself wrote there |

**Backup**

```bash
# One shot (Windows XAMPP paths shown):
mysqldump -u root -p --databases eaes_exam > eaes_exam_$(date +%F).sql

# Keep the app configuration next to the dump:
cp .env eaes_exam_$(date +%F).env
cp storage/license.lic eaes_exam_$(date +%F).lic

# Schedule daily (cron / Task Scheduler) with retention, e.g. 14 days.
```

**Restore**

```bash
# 1. Create/recreate the database and load the dump:
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS eaes_exam CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
mysql -u root -p eaes_exam < eaes_exam_2026-08-09.sql

# 2. Restore .env and the license file, then restart Apache.
```

The restore was smoke-tested end to end (dump → drop → re-import → data
verified intact). Because the app keeps full attempt history and soft-deletes
students, a restored database is immediately consistent.

## 5. Migrations

- **Fresh installs** — the installer loads `database/schema.sql` and then
  applies every file in `database/migrations/` (skipping the three whose
  DDL `schema.sql` already ships), recording each in `schema_migrations`.
  The result is immediately clean for the CLI runner.
- **Existing installs** — upgrade with the CLI runner:

  ```bash
  php database/migrate.php
  ```

  It applies only pending migrations (tracked in `schema_migrations`) and
  is safe to run repeatedly; a fully migrated install reports
  `No pending migrations.` Migration files are ordinary SQL — review them
  before applying in production, and back up the database first.

## 6. Release-candidate checklist (2026-08-10)

Verified on a clean install from the release workspace:

- [x] Installer: 4-step wizard, `installed.lock` written, re-run blocked
      (including `?force=1`).
- [x] All schema + migration tables present on a fresh install;
      `php database/migrate.php` reports no pending migrations.
- [x] Admin + student login flows with CSRF, rate limiting, bcrypt.
- [x] AI feature set removed cleanly (entry points, services, repositories,
      JS/CSS, database tables and migrations, nav links, docs) with the
      core exam/question-bank/student flows re-verified over HTTP.
- [x] Anonymous requests to API endpoints and admin pages rejected.
- [x] Regular student flow: wait room → portal → autosave → submit →
      already-taken gate.
- [x] Protected paths return 403; `APP_DEBUG=false`;
      `FORCE_HTTPS=true` redirects to HTTPS and marks cookies `Secure`;
      HSTS + security headers present.
- [x] Backup/restore procedure smoke-tested.
- [x] PHP lint clean on every shipped PHP file; full regression suite green
      (0 failures).

See `docs/SECURITY.md` for the threat model and accepted risks.
