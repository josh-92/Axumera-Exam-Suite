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
password change, autosave, submit) requires a per-session CSRF token
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
- Correct answers are never included in any payload sent to the
  student's browser.

## Headers & transport

`app/bootstrap.php` sets `X-Content-Type-Options: nosniff`,
`X-Frame-Options: SAMEORIGIN`, `Referrer-Policy:
strict-origin-when-cross-origin`, and a `Content-Security-Policy`
restricting scripts/styles to same-origin plus the specific CDNs used
(jsDelivr, for Chart.js). `Strict-Transport-Security` is added once
`FORCE_HTTPS=true`. The root `.htaccess` denies direct web access to
`app/`, `storage/`, `database/`, `partials/`, and `.env*`.

## Audit trail

Admin logins/logouts, exam create/edit/delete/start/stop, password
changes, and student exam submissions are all written to the
`activity_log` table via `App\Core\Logger::audit()`, alongside a daily
rotating file log in `storage/logs/`.

## Reporting a vulnerability

If you discover a security issue, please report it privately to the
vendor rather than filing a public issue, so a fix can be prepared
before disclosure.
