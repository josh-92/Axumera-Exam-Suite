-- =====================================================================
-- EAES Migration: 2026_08_07_add_student_passwords
-- Purpose  : Gate the student exam portal behind ID + password.
--   1. password_hash  — bcrypt hash; NULL until the student registers
--      (or "claims" a legacy password-less row created by the old
--      name/roll login). A NULL hash means the account is not yet
--      usable.
--   2. last_login_at  — recorded on every successful student login so
--      teachers can spot dormant/impersonated accounts.
-- Both columns are additive and nullable, so existing databases keep
-- working untouched (the old rows simply cannot log in until claimed).
-- =====================================================================

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'bcrypt hash; NULL until the student registers/claims an account';

ALTER TABLE students
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL DEFAULT NULL
        COMMENT 'Timestamp of the most recent successful student login';

-- =====================================================================
-- End of migration
-- =====================================================================
