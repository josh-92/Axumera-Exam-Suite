-- Enforce approval state on questions
ALTER TABLE questions 
ADD COLUMN approval_status ENUM('Draft', 'Pending Review', 'Approved', 'Rejected', 'Archived') DEFAULT 'Draft',
ADD COLUMN created_by INT NULL,
ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- Create Approval History/Audit Log
CREATE TABLE approval_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('question', 'exam', 'blueprint') NOT NULL,
    entity_id INT NOT NULL,
    action VARCHAR(50) NOT NULL, -- e.g., 'SUBMITTED', 'APPROVED', 'REJECTED', 'ARCHIVED'
    old_status VARCHAR(50) NOT NULL,
    new_status VARCHAR(50) NOT NULL,
    acted_by INT NOT NULL,
    comments TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Notification System
CREATE TABLE user_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: Ensure `users` table has an updated role enum: 
-- ENUM('Teacher', 'Reviewer', 'Department Head', 'Vice Principal', 'Administrator')