-- =====================================================================
-- EAES Migration: 2026_08_06_add_question_bank_crud
-- Purpose  : Make the question bank fully production-ready.
--   1. exam_id + question_number become NULLABLE so standalone bank rows
--      can exist (bank questions have exam_id = NULL, while materialized exam
--      copies keep exam_id set — the legacy exam engine reads by exam_id).
--   2. Add bank metadata columns (question, type, difficulty, topic,
--      subject, grade, is_public, tags) plus the status columns the
--      intelligent generator / approval workflow already query:
--         * status          -> 'approved' (generator pool filter)
--         * approval_status -> 'Approved' (enterprise approval workflow)
--   3. Add audit/soft-delete columns (created_by, created_at, updated_at,
--      archived_at) and provenance (source_question_id) so snapshots
--      created when a bank question is assigned to an exam can be traced.
--   4. exam_question_assignments pivot: records which bank questions are
--      attached to which exam, with per-question points and position.
--
-- Idempotent: every statement is safe to re-run (ADD COLUMN IF NOT EXISTS,
-- existence-guarded FK creation, DROP INDEX IF EXISTS).
-- Engine: MariaDB 10.4+ (XAMPP). utf8mb4, InnoDB.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Step 1: ownership columns become nullable
-- ---------------------------------------------------------------------
ALTER TABLE questions
    MODIFY COLUMN exam_id INT(11) NULL DEFAULT NULL
    COMMENT 'NULL = standalone bank question. Set = materialized question inside an exam';

ALTER TABLE questions
    MODIFY COLUMN question_number INT(11) NULL DEFAULT NULL
    COMMENT 'Exam-scoped position. NULL for standalone bank rows';

-- ---------------------------------------------------------------------
-- Step 2: bank metadata columns
-- ---------------------------------------------------------------------
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS question TEXT NULL
        COMMENT 'Bank question text. Kept in sync with question_text for legacy compatibility.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) NOT NULL DEFAULT 'MCQ'
        COMMENT 'Question type: MCQ, True/False, Essay.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS difficulty VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'Difficulty level: easy, medium, hard.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS topic VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Free-text topic, e.g. Algebra, Photosynthesis.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS subject VARCHAR(100) NULL DEFAULT NULL
        COMMENT 'Subject/course, e.g. Mathematics, Biology.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS grade VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Grade/year level, e.g. Grade 12, Form 4.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = visible to all, 0 = creator only.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS tags VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Comma-separated keyword tags.';

-- ---------------------------------------------------------------------
-- Step 3: status columns + soft-delete + provenance + timestamps
-- ---------------------------------------------------------------------
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'approved'
        COMMENT "Bank lifecycle: 'approved' (usable), 'archived' (soft-deleted). The intelligent generator filters status = 'approved'.";

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS approval_status VARCHAR(30) NOT NULL DEFAULT 'Approved'
        COMMENT "Enterprise approval workflow state: Draft / Pending Review / Approved / Rejected. Defaults to Approved so bank questions are immediately usable.";

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS created_by INT(11) NULL DEFAULT NULL
        COMMENT 'Admin account that authored this question.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS source_question_id INT(11) NULL DEFAULT NULL
        COMMENT 'For materialized exam copies: the bank question id they were copied from. NULL for standalone bank rows.';

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL DEFAULT NULL
        COMMENT 'Soft-delete timestamp. NULL while active.';

-- ---------------------------------------------------------------------
-- Step 4: lookup indexes
-- ---------------------------------------------------------------------
-- Generator pool pattern: WHERE status='approved' AND subject=? AND grade=? AND difficulty=? AND is_public=1
DROP INDEX IF EXISTS idx_bank_lookup ON questions;
ALTER TABLE questions
    ADD INDEX idx_bank_lookup (status, subject, grade, difficulty, is_public);

-- Bank list / snapshot provenance lookups
DROP INDEX IF EXISTS idx_qb_owner ON questions;
ALTER TABLE questions
    ADD INDEX idx_qb_owner (exam_id, source_question_id);

-- NOTE: no explicit index on created_by here. The fk_qb_creator foreign key
-- below auto-creates (and owns) the supporting index on that column, so
-- declaring one explicitly would make this migration non-idempotent
-- (InnoDB refuses to drop an index that a foreign key still needs).

-- ---------------------------------------------------------------------
-- Step 5: foreign keys (existence-guarded so this migration is idempotent)
-- ---------------------------------------------------------------------
SET @fk_creator := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'questions' AND CONSTRAINT_NAME = 'fk_qb_creator');
SET @sql_creator := IF(@fk_creator = 0,
    'ALTER TABLE questions ADD CONSTRAINT fk_qb_creator FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL',
    'SELECT 1 INTO @eaes_noop');
PREPARE stmt_creator FROM @sql_creator; EXECUTE stmt_creator; DEALLOCATE PREPARE stmt_creator;

SET @fk_source := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'questions' AND CONSTRAINT_NAME = 'fk_qb_source');
SET @sql_source := IF(@fk_source = 0,
    'ALTER TABLE questions ADD CONSTRAINT fk_qb_source FOREIGN KEY (source_question_id) REFERENCES questions(id) ON DELETE CASCADE',
    'SELECT 1 INTO @eaes_noop');
PREPARE stmt_source FROM @sql_source; EXECUTE stmt_source; DEALLOCATE PREPARE stmt_source;

-- ---------------------------------------------------------------------
-- Step 6: assignment pivot — which bank questions are attached to which
-- exam, with per-question points and ordering. Cascade rules:
--   * exam deleted    -> its assignment rows disappear
--   * bank q. deleted -> its assignment rows disappear (hard deletes never
--     happen through the app, which only soft-deletes via archive)
--   * assigned_by     -> SET NULL keeps history if an admin is removed
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS exam_question_assignments (
    id INT(11) NOT NULL AUTO_INCREMENT,
    exam_id INT(11) NOT NULL,
    question_id INT(11) NOT NULL COMMENT 'Bank question id (source).',
    snapshot_id INT(11) NULL DEFAULT NULL COMMENT 'Materialized copy inside the exam (questions.id).',
    points DECIMAL(6,2) NOT NULL DEFAULT 1.00 COMMENT 'Marks allocated to this question in the exam.',
    position INT(11) NOT NULL DEFAULT 0 COMMENT 'Display order within the exam (mirrors question_number).',
    assigned_by INT(11) NULL DEFAULT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_exam_question (exam_id, question_id),
    KEY idx_eqa_question (question_id),
    KEY idx_eqa_snapshot (snapshot_id),
    CONSTRAINT fk_eqa_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_eqa_question FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_eqa_assigner FOREIGN KEY (assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
-- End of migration
-- =====================================================================
