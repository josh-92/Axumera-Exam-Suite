# Changelog

## 2.1.0 — Exam Lockdown & Integrity Monitoring

### Added
- Fullscreen lockdown gate on `examportal.php`: students must enter
  fullscreen before questions are shown.
- Server-verified detection of tab switches, window blur, and fullscreen
  exits (`report_violation.php` + `AttemptRepository::recordViolation()`),
  each written to the existing `activity_log` audit trail.
- Copy, cut, paste, right-click, and common devtools shortcuts
  (F12, Ctrl+Shift+I/J/C, Ctrl+U) are blocked during an exam.
- `exam_attempts.violation_count` / `integrity_status` columns — an
  attempt is auto-flagged once violations cross a configurable threshold.
- On-screen integrity indicator and warning overlay for the student;
  a flagged-attempts badge and per-student violation counts for admins
  (dashboard badge + new columns in the scoreboard CSV export).
- Configurable via `.env`: `INTEGRITY_LOCKDOWN_ENABLED`,
  `INTEGRITY_WARN_THRESHOLD`, `INTEGRITY_FLAG_THRESHOLD`, and an optional
  `INTEGRITY_AUTO_SUBMIT_THRESHOLD` (off by default) to force-submit an
  attempt after repeated violations.
- `database/migrations/2026_07_26_add_integrity_tracking.sql` for
  upgrading an existing 2.0.x database; new installs get this from
  `schema.sql` automatically.

### Note
This is a deterrent and audit layer, not unbreakable DRM — a student
with a second device can still work around it. It is meant to catch and
discourage casual, in-browser cheating and give teachers a reviewable
record, matching how other CBT platforms describe this class of feature.

## 2.0.0 — Commercial Production Rebuild

Complete refactor of the original prototype into a production-ready
product, preserving all original functionality.

### Added
- Web-based 4-step installer (`installer/install.php`): requirements
  check, database setup, admin account creation, done.
- Server-authoritative exam timer (`exam_attempts.started_at`) — immune
  to refresh, tab duplication, or client clock tampering.
- Server-side autosave every 15s (configurable) via `autosave.php`,
  replacing the old localStorage-only approach.
- Per-question difficulty/accuracy report and CSV export.
- Analytics dashboard: platform totals, per-exam summary table, score
  distribution chart (Chart.js).
- Offline, domain-locked commercial license key gate
  (`app/Core/License.php`) with an admin-facing status page.
- CSRF protection on every state-changing request.
- Admin login rate limiting / temporary lockout after repeated
  failures.
- Audit log (`activity_log` table + daily file log) for admin and
  student actions.
- `database/migrate_legacy.php` — guided upgrade path from the
  original prototype's database.
- Full documentation set under `docs/`.
- `students` table now stores identity only; a new `exam_attempts`
  table stores one row per (student, exam) so historical results are
  never overwritten by a later exam.

### Changed
- All database access moved from raw mysqli string concatenation to
  PDO with prepared statements (`app/Repositories/*`).
- Admin passwords moved from plaintext to `password_hash()`, with
  transparent upgrade of legacy plaintext rows on next login.
- `review.php` now sources questions from the currently-live exam only
  (previously pulled every question in the database).
- Grading (`submit_exam.php`) now grades from the server's own
  autosaved answers rather than trusting the client's final submission
  payload.
- UI restructured around a shared design-token stylesheet
  (`assets/css/style.css`) plus page-specific stylesheets, replacing
  large blocks of inline `<style>` per page.
- Exam-portal and admin-panel JavaScript extracted into
  `assets/js/exam.js` and `assets/js/admin.js`.
- MathJax bundled locally (`assets/vendor/mathjax/`) instead of
  relying on an unpinned CDN reference.

### Fixed
- SQL injection exposure from string-concatenated queries.
- Timer reset exploit (refreshing the page previously restarted the
  full countdown).
- A student re-registering for a later exam previously overwrote their
  earlier exam's score in the same row.
- `review.php` pulling unrelated exams' questions into the summary
  grid.

### Security
- See `docs/SECURITY.md` for the full current security posture.
