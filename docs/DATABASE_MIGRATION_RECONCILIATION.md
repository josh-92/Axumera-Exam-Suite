# Database migration reconciliation

## Current authoritative state

`database/schema.sql` is the only authoritative **fresh-install** schema. It has no schema-version table, migration ledger, or application migration runner. `installer/install.php` imports that file directly. The current database state is therefore an unversioned EAES core schema containing `admin_users`, `settings`, `exams`, `questions`, `students`, `exam_attempts`, `exam_question_shuffles`, `login_attempts`, and `activity_log`.

`database/migrate_legacy.php` is a separate, one-time data-copy utility. It expects a freshly installed current schema and copies data from a renamed legacy database; it is not a schema migration tracker.

## Classification

### A. Already represented in the current schema

| Migration | Current-schema representation | Fresh install action |
|---|---|---|
| `2026_07_26_add_integrity_tracking.sql` | `exam_attempts.violation_count`, `integrity_status`, and `idx_integrity_status` | Never apply |
| `2026_07_26_add_question_shuffling.sql` | `exams.shuffle_questions`, `shuffle_choices`, and `exam_question_shuffles` | Never apply |

These scripts are valid historical upgrades only for databases that predate the respective features and have been positively identified as such. They are non-idempotent (`ADD COLUMN`/`CREATE TABLE` without guards) and fail on a current fresh install.

### B. Obsolete or incompatible with the EAES core lineage

| Migration range | Finding | Classification |
|---|---|---|
| `2026_07_29_add_blueprint_builder.sql` | Foreign keys refer to a non-existent `admins` table. None of its tables exist in `schema.sql`. | incompatible branch |
| `2026_07_30_add_intelligent_generator.sql` | Depends on the preceding blueprint tables and again refers to `admins`; it adds fields absent from the fresh schema. | incompatible branch |
| `2026_07_31_add_curriculum_intelligence.sql` | Depends on the generator/expanded question model but has no incompatible admin FK itself. Its tables/column are absent from `schema.sql`. | dependent incompatible branch |
| `2026_08_01` through `2026_08_03` | Depend on `generated_exams`, which is not present in `schema.sql`. | dependent incompatible branch |
| `2026_08_04_add_enterprise_approval_workflow.sql` | Refers to a non-existent `users` table and introduces an incompatible approval model. | incompatible branch |

These are not silently “obsolete”: application files for blueprint, generator, curriculum, analytics, recommendations, and approval workflows reference their intended tables. The current schema is therefore incomplete for those features, but it is unsafe to treat these historical scripts as a fresh-install chain.

### C. Required for future upgrades

No submitted migration is currently certified as a future upgrade beyond the two dated 2026-07-26 scripts. Future upgrades must introduce a `schema_migrations` ledger and versioned, idempotent steps with explicit preconditions. A certified path must be built only after taking an export/backup and mapping each supported customer baseline.

### D. Migrations requiring rewrite or compatibility handling

The 2026-07-29 through 2026-08-04 branch needs a dedicated compatibility design: choose the canonical identity table (`admin_users` or a new controlled identity migration), reconcile the exam data model (`exams` versus `generated_exams`), then create replacement migrations that are tested from a recorded baseline. Do not edit or delete the historical files; replacements must be new, separately named migration artifacts with an upgrade plan and rollback/backup requirements.

### E. Never auto-apply on a fresh installation

All current migration files must not be automatically applied to a fresh install. The two 2026-07-26 scripts duplicate schema content; the remainder fails due to missing prerequisites or represents an unreconciled feature branch. `initialize-database.ps1` therefore imports only `schema.sql` by default and requires an explicit `-ApplyLegacyMigrations` opt-in for forensic/staging use.

## Deterministic policy

* **Fresh installation:** import only `schema.sql`, create the app database user and first administrator, then write an installed lock.
* **Existing customer installation:** take a verified backup, identify the customer’s actual table/column baseline, and run only a certified upgrade chain recorded in `schema_migrations`.
* **Unknown or mixed state:** do not run automatic DDL. Capture a schema-only inventory and stop for a data-preserving reconciliation decision.

This policy intentionally does not alter customer data or production application behavior.
