CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(20) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO subjects (name, code, sort_order, status) VALUES
('Français', 'FR', 1, 'ACTIVE'),
('Arabe', 'AR', 2, 'ACTIVE'),
('Anglais', 'ANG', 3, 'ACTIVE'),
('Mathématiques', 'MATH', 4, 'ACTIVE'),
('Histoire-Géographie', 'H.G', 5, 'ACTIVE'),
('Éducation Islamique', 'II', 6, 'ACTIVE'),
('Sport / EPS', 'EPS', 7, 'ACTIVE'),
('SVT', 'SVT', 8, 'ACTIVE'),
('Physique-Chimie', 'PC', 9, 'ACTIVE'),
('Informatique', 'INFO', 10, 'ACTIVE'),
('Philosophie', 'PHILO', 11, 'ACTIVE')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order),
    status = VALUES(status);

CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_level_id INT NOT NULL,
    subject_id INT NULL,
    subject VARCHAR(120) NOT NULL,
    teacher_id INT NULL,
    teacher_name VARCHAR(120) NULL,
    room VARCHAR(80) NULL,
    year_value INT NULL,
    week_number TINYINT NULL,
    day_of_week ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY') NOT NULL,
    day_order TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    notes TEXT NULL,
    status ENUM('ACTIVE', 'CANCELLED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY schedules_school_day_idx (school_id, year_value, week_number, day_order, start_time),
    KEY schedules_class_day_idx (class_level_id, year_value, week_number, day_of_week, start_time),
    KEY schedules_teacher_day_idx (teacher_id, year_value, week_number, day_of_week, start_time),
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (class_level_id) REFERENCES class_levels(id) ON DELETE CASCADE
);
