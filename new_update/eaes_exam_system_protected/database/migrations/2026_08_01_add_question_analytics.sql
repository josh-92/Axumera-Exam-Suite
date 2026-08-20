-- Track individual student responses per question instance
CREATE TABLE student_exam_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    question_id INT NOT NULL,
    is_correct TINYINT(1) NOT NULL,
    is_skipped TINYINT(1) DEFAULT 0,
    time_spent_seconds INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES generated_exams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cached Question Psychometric Metrics
CREATE TABLE question_analytics_cache (
    question_id INT PRIMARY KEY,
    total_attempts INT DEFAULT 0,
    correct_count INT DEFAULT 0,
    wrong_count INT DEFAULT 0,
    skip_count INT DEFAULT 0,
    correct_percentage DECIMAL(5,2) DEFAULT 0.00,
    wrong_percentage DECIMAL(5,2) DEFAULT 0.00,
    skip_rate DECIMAL(5,2) DEFAULT 0.00,
    difficulty_index DECIMAL(5,4) DEFAULT 0.0000, -- Proportion correct (0.0 to 1.0)
    discrimination_index DECIMAL(5,4) DEFAULT 0.0000, -- Upper/Lower group correlation (-1.0 to 1.0)
    average_time_seconds DECIMAL(8,2) DEFAULT 0.00,
    reliability_score DECIMAL(5,4) DEFAULT 0.0000,
    quality_score DECIMAL(5,2) DEFAULT 0.00,
    classification ENUM('Excellent', 'Good', 'Needs Review', 'Retire') DEFAULT 'Needs Review',
    last_calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;