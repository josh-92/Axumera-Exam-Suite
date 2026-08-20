# Changelog

## Unreleased

### AI feature set removed (2026-08-10)

The AI layer — Blueprint Builder, Intelligent Generator, Curriculum
Intelligence, Exam Recommendations, question embeddings (Ollama) and the
per-question psychometric cache — has been **removed cleanly** so the
core EAES examination system stands alone. Nothing replaced it; the AI
features are simply gone.

- **Deleted** the AI entry points (`api_blueprints.php`, `api_generator.php`,
  `api_curriculum.php`, `api_recommendations.php`, `api_exam_attempt.php`,
  `admin_blueprint_builder.php`, `admin_generated_exams.php`,
  `admin_curriculum_intelligence.php`, `admin_exam_recommendations.php`),
  the AI services (`BlueprintService`, `IntelligentGeneratorService`,
  `CurriculumIntelligenceService`, `ExamRecommendationEngine`,
  `EmbeddingService`, `ExamAttemptService`), the AI repositories
  (`BlueprintRepository`, `ExamGeneratorRepository`, `CurriculumRepository`,
  `RecommendationRepository`, `ExamAttemptRepository`), their JS/CSS assets
  (`blueprint.js`, `generator.js`, `curriculum.js`, `recommendations.js`,
  `ai_tools.css`), and the `database/embed_question_bank.php` tool.
- **Deleted** the AI migrations and their tables: `exam_blueprints` +
  rules + shares, `generated_exams` + questions + `generation_logs`,
  `curriculum_chapters`/`curriculum_topics`, `student_exam_answers`,
  `question_analytics_cache`, `student_exam_sessions`/`responses`,
  `exam_recommendation_logs`, `question_embeddings`. Fresh installs never
  create them; `schema_migrations` and the installer's migration loop pick
  up the smaller chain automatically.
- **Question-bank duplicate detection is now exact-match only** — the
  Ollama semantic layer (`EmbeddingService`, `question_embeddings`, the
  `llm.*` config keys and `LLM_*` env vars) is gone; the deterministic
  normalized exact-match gate remains.
- **Admin navigation** now shows Exams, Question Bank, Analytics, Students,
  Settings, License (no AI links). The `analytics.php` dashboard and the
  per-question report (`download_report.php`) are core features and stay —
  they read the core `exams`/`students`/`exam_attempts` tables.
- **Tests updated:** the AI-only suites (`generated_exam_e2e_test.php`,
  `per_question_analytics_test.php`) were removed; `fresh_install_e2e_test.php`,
  `security_regressions_test.php` and `admin_pages_render_test.php` no
  longer reference AI pages/tables/endpoints and now guard that the AI
  entry points stay deleted. Full regression suite re-run green (0 failures)
  and PHP lint clean on every shipped file.

### Release verification (2026-08-09)

- **Generated-exam / AI workflows repaired.** A release E2E found three
  latent defects in the obfuscated AI layer, all of which broke the
  documented flows in production:
  - `BlueprintRepository::checkAccess()` ran one prepared statement that
    reused the `:id`/`:user_id` named parameters twice (UNION), which
    PDO rejects under the app's native prepares (`HY093`) — every
    generate/generateExamInstance call 500'd. It now runs the two checks
    as separate statements.
  - `RecommendationRepository::swapQuestion()` had its two guard branches
    inverted by the obfuscator: it threw "Exam not found" when the exam
    existed and "Original question is not part of this exam" when the
    question was present, so `apply_swap` could never perform a swap. The
    guards now behave as documented (not-found vs. not-owned vs. not-in-
    exam) and the ownership check is exercised by tests.
  - `ExamGeneratorRepository::lockExam()` returned `PDOStatement::execute()`
    (true even when 0 rows matched), so a non-owner's lock call reported
    success. It now returns `rowCount() > 0`, so the API's "Failed to
    lock exam." branch is reachable and the published/locked state is
    accurate.
- **Fresh installs now record their migrations.** `installer/install.php`
  writes every applied migration into `schema_migrations` (new table), so
  `php database/migrate.php` on a fresh install reports
  `No pending migrations.` instead of re-running `CREATE TABLE`
  statements that already exist (which used to fail).
- **New release tests:** `tests/generated_exam_e2e_test.php` (49 checks —
  blueprint → generate → ownership → lock → student start/submit/grade →
  regular-exam autosave leg), `tests/per_question_analytics_test.php`
  (35 checks — deterministic correct/incorrect/unanswered analytics,
  before/after attempts, passages, shuffles, zero-attempt questions,
  duplicate-refresh) and `tests/fresh_install_e2e_test.php` (94 checks —
  real 4-step web installer over HTTP on a throwaway copy, migration
  table check, AI pages, generated-exam + student flows, installer lock,
  anonymous/student/admin BOLA/IDOR matrix, protected paths, HTTPS
  redirect, Secure cookies, HSTS). A shared
  `tests/_scratch_schema.php` helper builds the real schema + migration
  chain for the DB tests.

### Security (adversarial audit, 2026-08-09)
- **Exam deadline is now enforced server-side on autosave** — `autosave.php`
  used to accept answers indefinitely after the timer expired (a scripted
  client got unlimited extra time); it now refuses saves once the attempt is
  past duration + grace and finalizes the attempt as `auto_submitted` from
  the answers already on the server. Regression: `tests/security_regressions_test.php`.
- **Late submissions are classified correctly** — `submit_exam.php` computed
  the deadline from a clamped value, so the `auto_submitted` branch was dead
  code and every late submit looked on-time. It now uses raw elapsed time.
- **Duplicate submissions can no longer clobber a result** —
  `AttemptRepository::markSubmitted()` only applies while the attempt is
  still `in_progress`; a racing second submit is told the attempt is already
  recorded instead of overwriting the first score.
- **Installer re-run bypass removed** — `installer/install.php?force=1`
  defeated the `installed.lock` guard and let an anonymous visitor re-run
  setup (overwriting `.env` and taking the app offline); the lock is now a
  hard stop until the file is removed on the server.
- **Generated-exam / AI APIs repaired and locked down** — the endpoints
  (`api_exam_attempt`, `api_blueprints`, `api_curriculum`, `api_generator`,
  `api_recommendations`) called a session getter that does not exist in
  this build, so every request returned 500. They now use the real session
  keys, require CSRF on every POST, and `ExamAttemptService` verifies the
  submitted session belongs to the caller (IDOR fix) and allows only one
  session per (student, exam), closing an unlimited re-grading loop.
  `Database::getConnection()` shim restores the instance API the AI
  repositories expect.
- **Stored XSS via question content closed** — `exam.js`/`exam_session.js`
  rendered teacher-authored question/option/paragraph text with `innerHTML`;
  an imported question file containing `<img onerror=…>` executed in every
  student's browser. All of it now goes through an HTML escaper (MathJax
  `$…$` still works because it scans text nodes).
- **Student-login account enumeration closed** — `slogin.php` no longer
  distinguishes "no account with this roll" from "wrong password".

### Security (release-candidate hardening, 2026-08-09)
- **AI feature set completed: broken approvals removed, four real tools
  shipped.** The enterprise approval workflow was half-built against a
  `users` table that never existed (its migration would also have flipped
  the question bank's `approval_status` default to Draft); the page, JS,
  service, repository, API and `2026_08_04` migration were removed. The
  remaining four AI tools — Blueprint Builder, Intelligent Generator,
  Curriculum Intelligence, Exam Recommendations — were static `.html`
  shells whose PHP never executed; they are now real authed PHP admin
  pages (`admin_blueprint_builder.php`, `admin_generated_exams.php`,
  `admin_curriculum_intelligence.php`, `admin_exam_recommendations.php`)
  with `window.EAES_CSRF` wired into every POST and HTML escaping on all
  DB-rendered content, linked from the admin nav. The dead
  `student_exam_session.html` shell was removed (backend kept).
- **Migrations are now real.** `database/migrations/*.sql` were never
  applied by fresh installs and two of them referenced a nonexistent
  `admins` table (fixed to `admin_users`). New `database/migrate.php` CLI
  applies pending migrations idempotently (tracked in `schema_migrations`)
  and the installer runs the same chain after `schema.sql`. The dev
  database was migrated; all AI tables (blueprints, generated exams,
  curriculum, analytics, recommendations, embeddings) are live.
- **Per-IP + per-account rate limiting.** `RateLimiter` now throttles by
  client IP (20 failures / 15 min) and by generic account key over the
  `login_attempts` ledger; wired into student login, admin login, and the
  password-reset verification (which previously used a session-only
  lockout that cookie-clears defeated). Installer's `.env` template fixed:
  `ADMIN_MAX_LOGIN_ATTEMPTS` was 100, now 5.
- **BOLA fixed in the AI layer.** `IntelligentGeneratorService::generate`
  now checks blueprint access; `regenerate` and the generator `view`
  require exam creator ownership; `RecommendationRepository::swapQuestion`
  verifies the exam owner before mutating.
- **HTTPS enforcement.** `FORCE_HTTPS=true` now actually redirects
  plain-HTTP requests to HTTPS (previously it only set Secure cookies +
  HSTS).
- **`RecommendationRepository::swapQuestion` fixed** — it inserted into
  `generated_exam_questions` without the NOT NULL `rule_id`/`order_index`
  columns, so every swap would have failed.

### Added
- **Admin page-render smoke test** — `tests/admin_pages_render_test.php`
  renders every admin page as a logged-in admin with no query string and
  asserts its key buttons/nav links are present, so a missing-parameter
  bug that silently hides UI (like the Students toolbar regression) can't
  come back unnoticed.
- **Exam-integrity attack simulation** — `tests/exam_integrity_simulation_test.php`
  runs the late-save, answer-injection, cross-student isolation,
  duplicate-submit race, and shuffle-immutability scenarios against a
  scratch DB (16 checks).
- **Rate-limiting regression checks** — per-IP and per-account ledger
  behaviour plus installer `.env` defaults (in
  `tests/security_regressions_test.php`).
- **`database/migrate.php`** — idempotent CLI migration runner
  (tracked in `schema_migrations`) for upgrading existing installs.
- **Admin → Settings page** — create additional admin accounts, reset
  another admin's password (one-time temp password shown once, lockout
  cleared), and delete admin accounts from the web. Self-reset and
  self-delete are blocked, and the last remaining admin can't be deleted.
- **Admin recovery CLI** — `php database/reset_admin_password.php` resets a
  forgotten or locked admin password directly from the server's command
  line (list accounts, generate a one-time temp password, or set an
  explicit one). The reset also clears the failed-attempt lockout.
- **Bulk removal with preview** — "Remove by List" now shows a confirm
  screen (exact students, attempt counts, blocked/not-found rows) before
  anything is archived; the preview expires after 30 minutes.
- **Soft-delete / archiving for students** — every "Remove" now archives
  (`students.deleted_at`) instead of permanently deleting; an **Archived**
  tab lists removed students with Restore and Delete-permanently actions.
  Attempt history survives archive/restore; only a permanent purge runs
  the FK cascade. Migration: `2026_08_09_add_student_archiving.sql`.
- **Bulk student import from Excel** — `.xlsx` uploads accepted alongside
  CSV/TXT (pure PHP via PharData + SimpleXML), with flexible stream/section
  labels (case, dashes, `NS`/`SS`, `N. Science`, Amharic labels).
- **Bulk student removal by list** — upload roll numbers (CSV/TXT/XLSX)
  to archive a whole class with per-line results and the live-exam guard.
- **Admin student management** — Add Student (auto temp passwords),
  Reset, Remove (archive), and Import — all CSRF-protected and audited.

### Changed
- Student removal wording everywhere: "Remove" means archive (restorable);
  "Delete permanently" is the only irreversible path and requires a strong
  double-confirm.
- `StudentRepository` and `AdminRepository` rewritten from obfuscated to
  readable PHP with identical behavior; `Database::useConnection()` test
  seam added.
- Student login is ID + password only (no self-registration); account
  creation and removal are admin-only.

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
