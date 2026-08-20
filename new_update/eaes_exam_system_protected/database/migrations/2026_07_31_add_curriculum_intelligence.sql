CREATE TABLE curriculum_chapters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject VARCHAR(100) NOT NULL,
    grade VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    target_weight DECIMAL(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE curriculum_topics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chapter_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    target_weight DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    FOREIGN KEY (chapter_id) REFERENCES curriculum_chapters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link questions to curriculum topics
ALTER TABLE questions 
ADD COLUMN IF NOT EXISTS curriculum_topic_id INT NULL,
ADD CONSTRAINT fk_question_topic FOREIGN KEY (curriculum_topic_id) REFERENCES curriculum_topics(id) ON DELETE SET NULL;