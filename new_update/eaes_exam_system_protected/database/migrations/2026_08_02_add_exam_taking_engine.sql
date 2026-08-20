CREATE TABLE student_exam_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('in_progress', 'submitted', 'terminated') DEFAULT 'in_progress',
    score DECIMAL(10,2) DEFAULT 0.00,
    total_marks DECIMAL(10,2) NOT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (exam_id) REFERENCES generated_exams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_exam_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_answer VARCHAR(255) NULL,
    is_correct TINYINT(1) DEFAULT 0,
    is_skipped TINYINT(1) DEFAULT 1,
    time_spent_seconds INT DEFAULT 0,
    FOREIGN KEY (session_id) REFERENCES student_exam_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;