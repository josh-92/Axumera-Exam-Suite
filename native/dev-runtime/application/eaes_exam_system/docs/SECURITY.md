# Security Notes

This document summarizes the security posture of EAES for administrators
and reviewers.

## Authentication

- Admin passwords are stored with `password_hash()` (bcrypt). Any
  legacy plaintext password inherited from a pre-refactor database is
  transparently upgraded to a hash the next time that admin logs in
  successfully.
- Failed admin login attempts are tracked per-username in the
  `login_attempts` table; after `ADMIN_MAX_LOGIN_ATTEMPTS` (default 5)
  consecutive failures, the account is locked for
  `ADMIN_LOCKOUT_MINUTES` (default 15).
- Session cookies are `HttpOnly`, `SameSite=Lax`, and marked `Secure`
  automatically when `FORCE_HTTPS=true` or the request arrives over
  HTTPS. Session IDs are regenerated periodically and on login.

## Injection & input handling

- **All** database access goes through PDO prepared statements with
  bound parameters (`app/Repositories/*`). There is no string
  concatenation of user input into SQL anywhere in the codebase.
- Uploaded exam JSON is size-limited (5MB), extension-checked,
  content-sniffed, and then structurally validated
  (`App\Core\Validator::examJson`) before a single row is written —
  malformed items are rejected with a specific error instead of being
  silently coerced.
- All output rendered into HTML is passed through `htmlspecialchars()`.

## CSRF protection

Every state-changing request (exam create/edit, start/stop, delete,
password change, autosave, and submit) requires a per-session CSRF token
(`App\Core\Csrf`). Requests without a valid token receive HTTP 419 and
are rejected before touching the database.

## Exam integrity

- The countdown timer is anchored to a server-side `started_at`
  timestamp recorded the first time a student opens the portal — it
  cannot be extended by refreshing, editing local storage, or changing
  the system clock on the student's device.
- Grading (`submit_exam.php`) reads the answers **the server itself
  autosaved**, not whatever JSON the client's final request happens to
  contain — a modified client can't submit a fabricated answer set.
- **The deadline is enforced on every autosave**: once an attempt has
  run past duration + grace, `autosave.php` refuses new answers and
  finalizes the attempt as `auto_submitted` from the answers already on
  the server. A scripted client can no longer keep answering after time
  runs out.
- Late submissions are classified from raw elapsed time (the
  `auto_submitted` status), and `markSubmitted()` only applies while an
  attempt is still `in_progress`, so racing duplicate submissions can't
  overwrite the first result.
- Correct answers are never included in any payload sent to the
  student's browser.

## Output encoding (XSS)

Teacher-authored question/option/passage text can come from imported
files, so it is HTML-escaped before being inserted into the DOM in
`exam.js` and `exam_session.js`. MathJax (`$…$` delimiters) still
renders because it scans text nodes.

## Installer

Once `storage/installed.lock` exists the installer refuses to run — the
old `?force=1` bypass that allowed an anonymous re-run (and a `.env`
overwrite) was removed. Re-running requires deleting the lock file on
the server.

## Headers & transport

`app/bootstrap.php` sets `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
strict-origin-when-cross-origin`, and a `Content-Security-Policy`
restricting scripts/styles to same-origin plus the specific CDNs used
(jsDelivr, for Chart.js). `Strict-Transport-Security` is added once
`FORCE_HTTPS=true`, and `FORCE_HTTPS=true` now also redirects
plain-HTTP requests to HTTPS (previously it only affected cookie
flags). Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure`
when HTTPS is in use or forced; session IDs are regenerated on login
and every 15 minutes. The root `.htaccess` denies direct web access to
`app/`, `storage/`, `database/`, `partials/`, and `.env*`.

## Rate limiting

All authentication entry points are throttled over the
`login_attempts` ledger (DB-backed, survives cookie clears):

- per-account: 5 failures in 15 minutes locks the account
  (`ADMIN_MAX_LOGIN_ATTEMPTS` / `ADMIN_LOCKOUT_MINUTES`);
- per-IP: 20 failures in 15 minutes locks the network
  (`IP_MAX_LOGIN_ATTEMPTS` / `IP_LOCKOUT_MINUTES`);
- applies to student login (`slogin.php`), admin login
  (`adminlogin.php`), and password-reset verification (`forgot.php`);
  a successful login resets the failed-attempt ledger for that
  account and IP.

## Migrations

Fresh installs apply `database/schema.sql` plus every migration in
`database/migrations/` (in filename order) automatically. Existing
installs upgrade with `php database/migrate.php`, which tracks applied
files in `schema_migrations` and is safe to re-run. Migrations that
are already covered by `schema.sql` (shuffling, integrity tracking,
student archiving) are detected and skipped. Note: the
`2026_08_04` enterprise-approval migration was removed — it referenced
a `users` table that never existed and would have flipped the question
bank's `approval_status` default to `Draft`.

## Generated-exam / AI layer (removed)

The entire AI feature set — Blueprint Builder, Intelligent Generator,
Curriculum Intelligence, Exam Recommendations, question embeddings, and
the per-question psychometric cache — was removed on 2026-08-10. Its
API entry points, services, repositories, JS/CSS assets, database
tables and migrations were deleted; the admin navigation no longer
links to it; and question-bank duplicate detection is exact-match only
(no external services). No AI endpoint remains to be locked down. The
question bank itself is a deliberately shared pool: any admin can edit
or archive any bank question, and the UI shows the author.

## Audit trail

Admin logins/logouts, exam create/edit/delete/start/stop, password
changes, and student exam submissions are all written to the
`activity_log` table via `App\Core\Logger::audit()`, alongside a daily
rotating file log in `storage/logs/`.

## Reporting a vulnerability

If you discover a security issue, please report it privately to the
vendor rather than filing a public issue, so a fix can be prepared
before disclosure.
