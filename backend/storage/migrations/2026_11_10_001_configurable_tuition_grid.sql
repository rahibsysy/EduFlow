-- Tuition is configured per school, school year, level and optional stream.
-- Deliberately no seed is inserted for 2026-2027 or later: each school must
-- confirm its own tariff grid before registration can use it.
CREATE TABLE IF NOT EXISTS tuition_fee_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    school_year VARCHAR(20) NOT NULL,
    level_name VARCHAR(120) NOT NULL,
    stream VARCHAR(120) NOT NULL DEFAULT '',
    monthly_amount DECIMAL(10,2) NOT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY tuition_fee_schedules_scope_unique (school_id, school_year, level_name, stream),
    KEY tuition_fee_schedules_school_year_idx (school_id, school_year),
    CONSTRAINT tuition_fee_schedules_school_fk FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
    CONSTRAINT tuition_fee_schedules_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE students
    ADD COLUMN monthly_discount_type ENUM('PERCENTAGE','FIXED') NULL AFTER discount_percent,
    ADD COLUMN monthly_discount_value DECIMAL(10,2) NULL AFTER monthly_discount_type,
    ADD COLUMN monthly_discount_reason VARCHAR(255) NULL AFTER monthly_discount_value,
    ADD COLUMN monthly_discount_author_id INT NULL AFTER monthly_discount_reason,
    ADD COLUMN monthly_discount_applied_at TIMESTAMP NULL AFTER monthly_discount_author_id,
    ADD CONSTRAINT students_monthly_discount_author_fk FOREIGN KEY (monthly_discount_author_id) REFERENCES users(id) ON DELETE SET NULL;

-- Existing categories and historical CANTEEN rows are intentionally retained.
-- Only active registration choices are normalized; no student item is created.
INSERT IGNORE INTO charge_categories (school_id, code, label, default_amount, periodicity, status)
SELECT id, 'DAYCARE', 'Garde', 120.00, 'MONTHLY', 'ACTIVE' FROM schools;
INSERT IGNORE INTO charge_categories (school_id, code, label, default_amount, periodicity, status)
SELECT id, 'REGISTRATION_INSURANCE', 'Inscription + assurance', 1100.00, 'ONE_TIME', 'ACTIVE' FROM schools;
UPDATE charge_categories SET default_amount=250.00, periodicity='MONTHLY'
WHERE code='TRANSPORT' AND status='ACTIVE';
UPDATE charge_categories SET default_amount=130.00, periodicity='ONE_TIME'
WHERE code='SMOCK' AND status='ACTIVE';
UPDATE charge_categories SET default_amount=100.00, periodicity='CUSTOM'
WHERE code='PAPER' AND status='ACTIVE';
