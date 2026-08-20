-- =====================================================================
-- EAES - Examination Automation & Evaluation System
-- Database schema (fresh install)
-- Engine: InnoDB, utf8mb4
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- admin_users
-- ---------------------------------------------------------------------
CREATE TABLE `admin_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) DEFAULT '',
  `role` VARCHAR(30) NOT NULL DEFAULT 'admin',
  `failed_attempts` INT(11) NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- settings — simple key/value store for site-wide configuration
-- ---------------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- exams
-- ---------------------------------------------------------------------
CREATE TABLE `exams` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `exam_name` VARCHAR(150) NOT NULL,
  `duration` INT(11) NOT NULL COMMENT 'minutes',
  `stream` VARCHAR(50) NOT NULL,
  `is_live` TINYINT(1) NOT NULL DEFAULT 0,
  `color_theme` VARCHAR(20) DEFAULT '#ff4a71',
  `json_filename` VARCHAR(255) DEFAULT 'questions.json',
  `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Per-student question order randomization',
  `shuffle_choices` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Per-student multiple-choice option order randomization',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_is_live` (`is_live`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- questions — BOTH a standalone question bank and exam-scoped snapshots.
--   * Bank rows:        exam_id = NULL, source_question_id = NULL
--   * Materialized      exam_id set, source_question_id = bank question id
--     exam copies:      (created when a bank question is assigned to an exam)
--   * Legacy import     exam_id set, source_question_id = NULL
--     rows:             (created by ExamImportService from exam JSON files)
-- The exam-taking engine reads rows by exam_id, so assigning a bank
-- question simply materializes a copy into the target exam. Bank rows keep
-- question_text in sync with question so legacy consumers still work.
-- ---------------------------------------------------------------------
CREATE TABLE `questions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `exam_id` INT(11) NULL DEFAULT NULL,
  `question_number` INT(11) NULL DEFAULT NULL,
  `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
  `paragraph_text` TEXT DEFAULT NULL,
  `question_text` TEXT NOT NULL,
  `option_a` TEXT NOT NULL,
  `option_b` TEXT NOT NULL,
  `option_c` TEXT NOT NULL,
  `option_d` TEXT NOT NULL,
  `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
  `question` TEXT NULL COMMENT 'Bank question text. Kept in sync with question_text.',
  `type` VARCHAR(50) NOT NULL DEFAULT 'MCQ' COMMENT 'MCQ, True/False, Essay.',
  `difficulty` VARCHAR(20) NULL DEFAULT NULL COMMENT 'easy, medium, hard.',
  `topic` VARCHAR(255) NULL DEFAULT NULL,
  `subject` VARCHAR(100) NULL DEFAULT NULL,
  `grade` VARCHAR(50) NULL DEFAULT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 1,
  `tags` VARCHAR(500) NULL DEFAULT NULL COMMENT 'Comma-separated keyword tags.',
  `status` VARCHAR(30) NOT NULL DEFAULT 'approved' COMMENT "'approved' (usable) | 'archived' (soft-deleted).",
  `approval_status` VARCHAR(30) NOT NULL DEFAULT 'Approved' COMMENT 'Enterprise approval workflow state.',
  `created_by` INT(11) NULL DEFAULT NULL COMMENT 'Admin account that authored this question.',
  `last_used_at` DATETIME NULL DEFAULT NULL,
  `source_question_id` INT(11) NULL DEFAULT NULL COMMENT 'For exam copies: originating bank question id.',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `archived_at` DATETIME NULL DEFAULT NULL COMMENT 'Soft-delete timestamp.',
  PRIMARY KEY (`id`),
  KEY `idx_exam_id` (`exam_id`),
  KEY `idx_exam_qnum` (`exam_id`, `question_number`),
  KEY `idx_bank_lookup` (`status`, `subject`, `grade`, `difficulty`, `is_public`),
  KEY `idx_qb_owner` (`exam_id`, `source_question_id`),
  KEY `idx_qb_creator` (`created_by`),
  CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_qb_creator` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qb_source` FOREIGN KEY (`source_question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- exam_question_assignments — which bank questions are attached to which
-- exam, with per-question points and ordering. Deleting an exam (or a
-- hard-deleted bank question) removes its assignment rows automatically.
-- ---------------------------------------------------------------------
CREATE TABLE `exam_question_assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `exam_id` INT(11) NOT NULL,
  `question_id` INT(11) NOT NULL COMMENT 'Bank question id (source).',
  `snapshot_id` INT(11) NULL DEFAULT NULL COMMENT 'Materialized copy inside the exam (questions.id).',
  `points` DECIMAL(6,2) NOT NULL DEFAULT 1.00 COMMENT 'Marks allocated to this question in the exam.',
  `position` INT(11) NOT NULL DEFAULT 0 COMMENT 'Display order within the exam.',
  `assigned_by` INT(11) NULL DEFAULT NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_exam_question` (`exam_id`, `question_id`),
  KEY `idx_eqa_question` (`question_id`),
  KEY `idx_eqa_snapshot` (`snapshot_id`),
  CONSTRAINT `fk_eqa_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eqa_question` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_eqa_assigner` FOREIGN KEY (`assigned_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- students — identity registry only (one row per roll_number + stream)
-- ---------------------------------------------------------------------
CREATE TABLE `students` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(100) NOT NULL,
  `roll_number` INT(11) NOT NULL,
  `stream` VARCHAR(50) NOT NULL,
  `section` VARCHAR(10) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_roll_stream` (`roll_number`, `stream`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- exam_attempts — one row per (student, exam): server-authoritative
-- timer, autosaved answers, and final grading result.
-- ---------------------------------------------------------------------
CREATE TABLE `exam_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `student_id` INT(11) NOT NULL,
  `exam_id` INT(11) NOT NULL,
  `answers` LONGTEXT NOT NULL DEFAULT '{}' COMMENT 'JSON: {question_number: option}',
  `flags` LONGTEXT NOT NULL DEFAULT '{}' COMMENT 'JSON: {question_number: true}',
  `score` INT(11) DEFAULT NULL,
  `total_questions` INT(11) DEFAULT NULL,
  `status` ENUM('in_progress','submitted','auto_submitted') NOT NULL DEFAULT 'in_progress',
  `started_at` DATETIME NOT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `last_saved_at` DATETIME DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `violation_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Count of exam-integrity events (tab switch, fullscreen exit, etc.)',
  `integrity_status` ENUM('clean','flagged') NOT NULL DEFAULT 'clean' COMMENT 'flagged once violation_count crosses the configured review threshold',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_student_exam` (`student_id`, `exam_id`),
  KEY `idx_exam_id` (`exam_id`),
  KEY `idx_integrity_status` (`integrity_status`),
  CONSTRAINT `fk_attempts_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempts_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- exam_question_shuffles — one row per (student, exam), written exactly
-- once. Holds this student's permanent, server-generated display order
-- for the exam's questions and (optionally) each question's multiple
-- choice options. The UNIQUE KEY on (student_id, exam_id) is what makes
-- "shuffle only once" durable: a second INSERT attempt (duplicate tab,
-- retry after a dropped connection, etc.) always fails on this key, so
-- the very first generated order is the only one that can ever exist.
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- login_attempts — brute-force protection ledger for admin logins
-- ---------------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_username_time` (`username`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- activity_log — audit trail for admin + student + system events
-- ---------------------------------------------------------------------
CREATE TABLE `activity_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `actor_type` VARCHAR(20) NOT NULL,
  `actor_identifier` VARCHAR(100) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor` (`actor_type`, `actor_identifier`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
