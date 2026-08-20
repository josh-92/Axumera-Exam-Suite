-- Ensure questions table has lifecycle and tracking columns
ALTER TABLE questions 
ADD COLUMN IF NOT EXISTS status ENUM('draft', 'approved', 'retired') DEFAULT 'approved',
ADD COLUMN IF NOT EXISTS last_used_at TIMESTAMP NULL DEFAULT NULL;

-- Generated Exams Table
CREATE TABLE generated_exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blueprint_id INT NOT NULL,
    creator_id INT NOT NULL,
    exam_name VARCHAR(255) NOT NULL,
    total_questions INT NOT NULL,
    total_marks DECIMAL(10,2) NOT NULL,
    is_locked TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (blueprint_id) REFERENCES exam_blueprints(id) ON DELETE RESTRICT,
    FOREIGN KEY (creator_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapping Table with Explainability
CREATE TABLE generated_exam_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_id INT NOT NULL,
    rule_id INT NOT NULL,
    allocated_marks DECIMAL(10,2) NOT NULL,
    selection_reason TEXT NOT NULL,
    order_index INT NOT NULL,
    FOREIGN KEY (exam_id) REFERENCES generated_exams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
    FOREIGN KEY (rule_id) REFERENCES exam_blueprint_rules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deterministic Generation Logs
CREATE TABLE generation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NULL,
    blueprint_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    log_level ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES generated_exams(id) ON DELETE CASCADE,
    FOREIGN KEY (blueprint_id) REFERENCES exam_blueprints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;