CREATE DATABASE IF NOT EXISTS salma_project;
USE salma_project;

CREATE TABLE IF NOT EXISTS schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    slug VARCHAR(160) NOT NULL UNIQUE,
    email_domain VARCHAR(160) NOT NULL UNIQUE,
    logo_path VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    country VARCHAR(100) NULL,
    primary_color VARCHAR(20) NOT NULL DEFAULT '#1E3A8A',
    secondary_color VARCHAR(20) NOT NULL DEFAULT '#22C55E',
    currency VARCHAR(10) NOT NULL DEFAULT 'MAD',
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'user') NOT NULL,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS class_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(50) NULL,
    level_name VARCHAR(180) NULL,
    group_name VARCHAR(100) NULL,
    school_year VARCHAR(20) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY class_levels_school_name_unique (school_id, name),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
);

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
('Éducation Islamique', 'I.I', 6, 'ACTIVE'),
('Sport / EPS', 'EPS', 7, 'ACTIVE'),
('SVT', 'SVT', 8, 'ACTIVE'),
('Physique-Chimie', 'PC', 9, 'ACTIVE'),
('Informatique', 'Info', 10, 'ACTIVE'),
('Philosophie', 'PHILO', 11, 'ACTIVE')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order),
    status = VALUES(status);

CREATE TABLE IF NOT EXISTS subject_class_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    class_level_id INT NULL,
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
    s.code IN ('FR', 'AR', 'ANG', 'MATH', 'H.G', 'I.I', 'EPS', 'SVT', 'PC', 'Info')
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
WHERE s.code = 'Info'
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

CREATE TABLE IF NOT EXISTS parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    parent_id INT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    date_of_birth DATE NULL,
    gender VARCHAR(20) NULL,
    class_level VARCHAR(100) NOT NULL,
    class_name VARCHAR(100) NULL,
    class_level_id INT NULL,
    parent_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30),
    address VARCHAR(255) NULL,
    monthly_amount DECIMAL(10,2) NOT NULL,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    school_year VARCHAR(20) NULL,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES parents(id) ON DELETE SET NULL,
    FOREIGN KEY (class_level_id) REFERENCES class_levels(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS monthly_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    month_label VARCHAR(20) NOT NULL,
    year_value INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) DEFAULT 0,
    remaining_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('PAID', 'PARTIAL', 'UNPAID') DEFAULT 'UNPAID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY monthly_fees_school_status_idx (school_id, status),
    KEY monthly_fees_student_status_idx (student_id, status),
    KEY monthly_fees_school_period_idx (school_id, year_value, month_label),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO payment_methods (code, label, status) VALUES
('CASH', 'Especes', 'ACTIVE'),
('CARD', 'Carte bancaire', 'ACTIVE'),
('BANK_TRANSFER', 'Virement bancaire', 'ACTIVE'),
('CHECK', 'Cheque', 'ACTIVE'),
('MOBILE', 'Paiement mobile', 'ACTIVE');

CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    student_id INT NOT NULL,
    monthly_fee_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method_id INT NULL,
    payment_method VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY payments_school_date_idx (school_id, payment_date),
    KEY payments_student_idx (student_id),
    KEY payments_monthly_fee_idx (monthly_fee_id),
    FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (monthly_fee_id) REFERENCES monthly_fees(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    class_level_id INT NOT NULL,
    subject_id INT NULL,
    subject VARCHAR(120) NOT NULL,
    teacher_id INT NULL,
    teacher_name VARCHAR(120) NULL,
    room VARCHAR(80) NULL,
    is_external TINYINT(1) NOT NULL DEFAULT 0,
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
