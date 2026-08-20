CREATE TABLE exam_recommendation_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    action_type VARCHAR(50) NOT NULL, -- e.g., 'REPLACE_QUESTION', 'ADD_QUESTION'
    original_question_id INT NULL,
    new_question_id INT NULL,
    reason_applied TEXT NOT NULL,
    applied_by INT NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES generated_exams(id) ON DELETE CASCADE,
    FOREIGN KEY (original_question_id) REFERENCES questions(id) ON DELETE SET NULL,
    FOREIGN KEY (new_question_id) REFERENCES questions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;