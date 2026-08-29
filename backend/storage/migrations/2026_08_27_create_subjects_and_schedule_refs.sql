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

CREATE TABLE IF NOT EXISTS subject_class_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    class_level_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY subject_class_levels_unique (subject_id, class_level_id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (class_level_id) REFERENCES class_levels(id) ON DELETE CASCADE
);

INSERT IGNORE INTO subject_class_levels (subject_id, class_level_id)
SELECT s.id, cl.id
FROM subjects s
CROSS JOIN class_levels cl
WHERE
    s.code IN ('FR', 'AR', 'ANG', 'MATH', 'H.G', 'II', 'EPS', 'SVT', 'PC', 'INFO')
    OR (
        s.code = 'PHILO'
        AND (
            LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%tronc commun%'
            OR UPPER(COALESCE(cl.code, '')) = 'TC'
            OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%1 bac%'
            OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%1bac%'
            OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%2 bac%'
            OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%2bac%'
        )
    );

DELETE scl FROM subject_class_levels scl
INNER JOIN subjects s ON s.id = scl.subject_id
INNER JOIN class_levels cl ON cl.id = scl.class_level_id
WHERE s.code = 'INFO'
  AND (
      LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%1 bac%'
      OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%1bac%'
      OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%2 bac%'
      OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%2bac%'
  );

DELETE scl FROM subject_class_levels scl
INNER JOIN subjects s ON s.id = scl.subject_id
INNER JOIN class_levels cl ON cl.id = scl.class_level_id
WHERE s.code = 'H.G'
  AND (
      LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%2 bac%'
      OR LOWER(CONCAT(cl.name, ' ', COALESCE(cl.code, ''))) LIKE '%2bac%'
  );

SET @q := (
    SELECT IF(
        NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schedules')
        OR EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'schedules' AND column_name = 'subject_id'),
        'SELECT 1',
        'ALTER TABLE schedules ADD COLUMN subject_id INT NULL AFTER class_level_id'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schedules')
        OR EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'schedules' AND column_name = 'teacher_id'),
        'SELECT 1',
        'ALTER TABLE schedules ADD COLUMN teacher_id INT NULL AFTER subject'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schedules')
        OR EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'schedules' AND index_name = 'schedules_teacher_day_idx'),
        'SELECT 1',
        'ALTER TABLE schedules ADD KEY schedules_teacher_day_idx (teacher_id, year_value, week_number, day_of_week, start_time)'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schedules')
        OR EXISTS(SELECT 1 FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'schedules_subject_id_fk'),
        'SELECT 1',
        'ALTER TABLE schedules ADD CONSTRAINT schedules_subject_id_fk FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        NOT EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schedules')
        OR EXISTS(SELECT 1 FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND constraint_name = 'schedules_teacher_id_fk'),
        'SELECT 1',
        'ALTER TABLE schedules ADD CONSTRAINT schedules_teacher_id_fk FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
