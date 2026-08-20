CREATE TABLE exam_blueprints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    creator_id INT NOT NULL,
    subject VARCHAR(100) NOT NULL,
    grade VARCHAR(50) NOT NULL,
    semester VARCHAR(50) NOT NULL,
    total_questions INT NOT NULL,
    total_marks DECIMAL(10,2) NOT NULL,
    time_limit_minutes INT NOT NULL,
    shuffle_questions TINYINT(1) DEFAULT 1,
    shuffle_choices TINYINT(1) DEFAULT 1,
    status ENUM('active', 'archived') DEFAULT 'active',
    version INT DEFAULT 1,
    parent_id INT NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES admins(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES exam_blueprints(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_blueprint_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blueprint_id INT NOT NULL,
    topic VARCHAR(255) NULL,
    chapter VARCHAR(255) NULL,
    difficulty ENUM('easy', 'medium', 'hard') NULL,
    question_type VARCHAR(50) NULL,
    source_teacher_id INT NULL,
    question_count INT NOT NULL,
    marks_per_question DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (blueprint_id) REFERENCES exam_blueprints(id) ON DELETE CASCADE,
    FOREIGN KEY (source_teacher_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_blueprint_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    blueprint_id INT NOT NULL,
    shared_with_admin_id INT NOT NULL,
    permission_level ENUM('view', 'edit') DEFAULT 'view',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (blueprint_id) REFERENCES exam_blueprints(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;