-- =====================================================================
-- EAES upgrade script: exam-integrity tracking (tab-switch / fullscreen
-- exit / lockdown violation counters on exam_attempts).
--
-- Safe to run once against an existing EAES 2.x database. Fresh installs
-- via installer/install.php already get these columns from schema.sql
-- and do not need this file.
--
-- USAGE:
--   mysql -u <user> -p <database_name> < database/migrations/2026_07_26_add_integrity_tracking.sql
-- =====================================================================

ALTER TABLE `exam_attempts`
  ADD COLUMN `violation_count` INT(11) NOT NULL DEFAULT 0
    COMMENT 'Count of exam-integrity events (tab switch, fullscreen exit, etc.)'
    AFTER `user_agent`,
  ADD COLUMN `integrity_status` ENUM('clean','flagged') NOT NULL DEFAULT 'clean'
    COMMENT 'flagged once violation_count crosses the configured review threshold'
    AFTER `violation_count`,
  ADD KEY `idx_integrity_status` (`integrity_status`);
