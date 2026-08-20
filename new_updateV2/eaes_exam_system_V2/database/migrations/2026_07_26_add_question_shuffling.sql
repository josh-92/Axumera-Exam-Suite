-- =====================================================================
-- EAES upgrade script: per-user question shuffling.
--
-- Adds two per-exam config flags (shuffle_questions, shuffle_choices)
-- and a new table, exam_question_shuffles, that permanently stores the
-- one-time-generated display order for each student's attempt.
--
-- Safe to run once against an existing EAES 2.x database. Fresh installs
-- via installer/install.php already get this from schema.sql and do not
-- need this file.
--
-- USAGE:
--   mysql -u <user> -p <database_name> < database/migrations/2026_07_26_add_question_shuffling.sql
-- =====================================================================

ALTER TABLE `exams`
  ADD COLUMN `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Per-student question order randomization'
    AFTER `json_filename`,
  ADD COLUMN `shuffle_choices` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Per-student multiple-choice option order randomization'
    AFTER `shuffle_questions`;

CREATE TABLE `exam_question_shuffles` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `exam_id` INT(11) NOT NULL,
  `attempt_id` INT(11) NOT NULL,
  `question_order` LONGTEXT NOT NULL COMMENT 'JSON array of original question_number values, in this student''s display order',
  `choice_order` LONGTEXT NOT NULL DEFAULT '{}' COMMENT 'JSON object {question_number: "cadb"}: display slot i shows the ORIGINAL option letter at position i of the string',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_exam_shuffle` (`student_id`, `exam_id`),
  KEY `idx_exam_id` (`exam_id`),
  KEY `idx_attempt_id` (`attempt_id`),
  CONSTRAINT `fk_shuffle_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shuffle_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_shuffle_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
