# Installation Guide

## 1. Requirements

- PHP 8.1+ with `pdo_mysql`, `mbstring`, `json` extensions enabled
- MySQL 5.7+ or MariaDB 10.4+
- Apache with `mod_rewrite` and `mod_headers`

XAMPP (Windows/macOS/Linux) satisfies all of the above out of the box.

## 2. Deploy the files

1. Extract the product ZIP.
2. Copy the `eaes_exam_system` folder into your web root:
   - XAMPP (Windows): `C:\xampp\htdocs\eaes_exam_system`
   - XAMPP (Linux): `/opt/lampp/htdocs/eaes_exam_system`
   - Any other Apache/PHP host: your configured document root
3. Make sure the `storage/` folder is writable by the web server user
   (on Linux: `chmod -R 755 storage` is usually enough; XAMPP on Windows
   needs no extra permission changes).

## 3. Run the installer

Open `http://localhost/eaes_exam_system/installer/install.php` in your
browser.

**Step 1 — Requirements check.** The wizard verifies your PHP version,
required extensions, and folder permissions. Fix anything marked ❌
before continuing.

**Step 2 — Database configuration.** Enter your MySQL host/port/user/
password and the database name to use (it will be created automatically
if it doesn't exist). The wizard writes these values into a new `.env`
file at the project root and imports `database/schema.sql`.

**Step 3 — Administrator account.** Choose a username and password
(minimum 8 characters) for your first admin login. This is stored with
a bcrypt hash, never plaintext.

**Step 4 — Done.** The wizard writes `storage/installed.lock` so it
won't run again by accident. From here you're taken straight to the
admin login page.

> **Re-running the installer:** delete `storage/installed.lock` (and,
> if you want a totally clean slate, drop and recreate the database)
> and revisit `installer/install.php`.

## 4. Harden for production

After installing, do the following before putting the system in front
of real students. See [`docs/DEPLOYMENT.md`](DEPLOYMENT.md) for the full
production checklist, HTTPS recipe, and the backup/restore and migration
procedures.

- **Restrict or delete `installer/`.** It's only needed once. The
  `storage/installed.lock` guard is a hard stop (the old `?force=1`
  re-run bypass was removed), but deleting the folder or blocking it
  with an `.htaccess` (`Require all denied`) is defense in depth.
- **Activate your license.** Upload the signed `license.lic` supplied
  with your purchase on the activation screen (it lands in
  `storage/license.lic`). See `docs/LICENSE.txt`. The license is
  hardware-bound and cached for 24 h in `storage/cache/`.
- **Turn off debug mode.** Confirm `APP_DEBUG=false` and
  `APP_ENV=production` in `.env` (both are the installer's defaults).
- **Enable HTTPS** at the web server level, then set `FORCE_HTTPS=true`
  in `.env` so plain-HTTP requests redirect to HTTPS, session cookies
  are marked `Secure`, and HSTS is sent.
- **Back up regularly.**
  ```bash
  mysqldump -u root -p --databases eaes_exam > eaes_exam_$(date +%F).sql
  cp .env eaes_exam_$(date +%F).env
  cp storage/license.lic eaes_exam_$(date +%F).lic
  ```
  Keep a copy of `.env` somewhere safe — its `APP_KEY` signs CSRF
  tokens and license binding. See `docs/DEPLOYMENT.md` for restore
  steps.

## 5. Upgrading an existing install

Existing installs upgrade with the idempotent CLI runner:

```bash
php database/migrate.php
```

It applies only pending migrations (tracked in `schema_migrations`) and
is safe to run repeatedly. Fresh installs already record every migration
at install time, so the runner reports `No pending migrations.` on them.

## 6. Upgrading from the original prototype

## 5. Upgrading from the original prototype

If you have an existing install of the pre-refactor version (the one
with `db.php`, `adminlogin.php`, etc. directly using raw mysqli), see
`database/migrate_legacy.php` for a guided data migration path that
preserves your exams, questions, admin accounts, and student history.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Service Unavailable" on any page | Wrong DB credentials in `.env`, or MySQL isn't running |
| Redirected to `installer/install.php` in a loop | `storage/installed.lock` couldn't be written — check folder permissions |
| Blank white page | Set `APP_DEBUG=true` temporarily in `.env` to see the underlying error, and check `storage/logs/` |
| "License not active" banner | Add `LICENSE_KEY`/`LICENSE_DOMAIN` to `.env` — see `docs/LICENSE.txt` |
