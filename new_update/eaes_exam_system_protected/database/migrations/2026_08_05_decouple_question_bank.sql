-- =====================================================================
-- EAES Migration: 2026_08_05_decouple_question_bank
-- Purpose  : Complete the question bank by:
--   1. Making exam_id nullable (bank questions have no exam owner)
--   2. Replacing CASCADE-delete FK with SET NULL (bank questions survive exam deletion)
--   3. Adding is_public, tags, subject, grade, type, difficulty, topic columns
--   4. Adding a composite index for the pool-query pattern in the generator
-- =====================================================================

-- Step 1: Drop the CASCADE foreign key that forces question→exam coupling
ALTER TABLE questions DROP FOREIGN KEY fk_questions_exam;

-- Step 2: Make exam_id nullable — bank-authored questions will have exam_id = NULL
ALTER TABLE questions MODIFY COLUMN exam_id INT(11) NULL DEFAULT NULL;

-- Step 3: Restore the FK as SET NULL — if an exam is deleted, bank questions are
--         not destroyed; only their exam_id reference is cleared.
ALTER TABLE questions
    ADD CONSTRAINT fk_questions_exam
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE SET NULL;

-- Step 4: Add bank-specific metadata columns
--         (IF NOT EXISTS guards against re-running this migration)

-- Primary question text for bank-created rows.
-- Legacy rows keep question_text; bank rows use question.
-- Queries should use COALESCE(question, question_text) for display.
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS question TEXT NULL
        COMMENT 'Bank question text. Use COALESCE(question, question_text) for display.';

-- Question type: MCQ, True/False, Short Answer, Essay, etc.
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS type VARCHAR(50) NULL DEFAULT 'MCQ';

-- Difficulty level — used by blueprint rule filtering
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS difficulty ENUM('easy', 'medium', 'hard') NULL DEFAULT NULL;

-- Free-text topic — used by the intelligent generator (e.g. "Algebra", "Photosynthesis")
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS topic VARCHAR(255) NULL DEFAULT NULL;

-- Subject the question belongs to (e.g. "Mathematics", "Biology")
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS subject VARCHAR(100) NULL DEFAULT NULL;

-- Grade/year level (e.g. "Grade 12", "11", "Form 4")
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS grade VARCHAR(50) NULL DEFAULT NULL;

-- Visibility flag: 1 = visible to all generators, 0 = creator/admin only
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 1;

-- Comma-separated keyword tags for freeform search
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS tags VARCHAR(500) NULL DEFAULT NULL;

-- Step 4b: Bank lifecycle status — the composite index below references it.
-- (This column was omitted from the original migration; the canonical
--  definition lives in 2026_08_06_add_question_bank_crud.sql.)
ALTER TABLE questions
    ADD COLUMN IF NOT EXISTS status VARCHAR(30) NOT NULL DEFAULT 'approved'
        COMMENT "Bank lifecycle: 'approved' (usable), 'archived' (soft-deleted).";

-- Step 5: Composite index optimised for the bank pool query pattern:
--         WHERE status = 'approved' AND subject = ? AND grade = ? AND difficulty = ? AND is_public = 1
DROP INDEX IF EXISTS idx_bank_lookup ON questions;
ALTER TABLE questions
    ADD INDEX idx_bank_lookup (status, subject, grade, difficulty, is_public);

-- =====================================================================
-- End of migration
-- =====================================================================
