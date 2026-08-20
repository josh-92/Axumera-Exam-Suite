-- Soft-delete / archiving for students (2026-08-09)
-- "Remove" now archives instead of permanently deleting, so a mistaken
-- removal can be reversed from Admin → Students → Archived.
--
--   deleted_at NULL          → active student (normal login, listing)
--   deleted_at set (DATETIME) → archived: excluded from login/lookups,
--                               listed under "Archived", restorable.
--
-- Attempt history and per-student question shuffles are KEPT while
-- archived (rows are never deleted until an admin purges permanently,
-- which still relies on the existing FK ON DELETE CASCADE).
--
-- NOTE: the UNIQUE key on (roll_number, stream) still applies to archived
-- rows, so re-adding the same roll number is blocked until the archived
-- record is restored or purged — provision() returns a clear message.

ALTER TABLE `students`
  ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft-delete marker: NULL = active, set = archived (restorable)' AFTER `last_login_at`,
  ADD KEY `idx_students_deleted_at` (`deleted_at`);
