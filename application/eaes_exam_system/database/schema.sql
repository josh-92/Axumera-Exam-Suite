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
-- questions — exam-scoped snapshots. Each row belongs to exactly one
-- exam (exam_id), making questions a child of the exam lifecycle rather
-- than a standalone store.
-- ---------------------------------------------------------------------
CREATE TABLE `questions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `exam_id` INT(11) NOT NULL,
  `question_number` INT(11) NOT NULL,
  `is_passage` TINYINT(1) NOT NULL DEFAULT 0,
  `paragraph_text` TEXT DEFAULT NULL,
  `question_text` TEXT NOT NULL,
  `option_a` TEXT NOT NULL,
  `option_b` TEXT NOT NULL,
  `option_c` TEXT NOT NULL,
  `option_d` TEXT NOT NULL,
  `correct_answer` VARCHAR(5) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_exam_id` (`exam_id`),
  KEY `idx_exam_qnum` (`exam_id`, `question_number`),
  CONSTRAINT `fk_questions_exam` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
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

-- ---------------------------------------------------------------------
-- schema_migrations — ledger for database schema upgrades
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `version` VARCHAR(100) NOT NULL,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_migration_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;


