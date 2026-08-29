USE salma_project;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE payments;
TRUNCATE TABLE monthly_fees;
TRUNCATE TABLE students;
TRUNCATE TABLE parents;
TRUNCATE TABLE class_levels;
TRUNCATE TABLE users;
TRUNCATE TABLE schools;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO schools (
    id, name, code, slug, email_domain, logo_path, phone, address, city, country,
    primary_color, secondary_color, currency, status, created_at
) VALUES
(1, 'College Sainte Marie', 'CSM01', 'college-sainte-marie', 'sainte-marie.edu', NULL, '0522123456', 'Avenue des Ecoles 12', 'Casablanca', 'Maroc', '#1E3A8A', '#22C55E', 'MAD', 'ACTIVE', '2026-06-01 09:00:00');

INSERT INTO users (
    id, school_id, first_name, last_name, email, password, role, status, created_at
) VALUES
(1, NULL, 'Super', 'Admin', 'superadmin@eduflow.com', '$2y$10$XqC2r7yA1DsOZLbOT26c7.BjeIZwBCaQkw7ARzfSjThu29oLdhw06', 'super_admin', 'ACTIVE', '2026-06-01 09:10:00'),
(2, 1, 'Karim', 'El Amrani', 'karim.elamrani@sainte-marie.edu', '$2y$10$ad5cZzLMfaVB7sCADtZZfeXrtK0nTFRGmgms3axprgTB0UFG6usvm', 'admin', 'ACTIVE', '2026-06-01 09:12:00');

INSERT INTO class_levels (
    id, school_id, name, code, sort_order, status, created_at
) VALUES
(1, 1, 'CP', 'CP', 1, 'ACTIVE', '2026-06-01 09:15:00'),
(2, 1, 'CE1', 'CE1', 2, 'ACTIVE', '2026-06-01 09:16:00'),
(3, 1, '5ème', '5EME', 3, 'ACTIVE', '2026-06-01 09:17:00');

INSERT INTO parents (
    id, school_id, first_name, last_name, phone, email, created_at
) VALUES
(1, 1, 'Amina', 'Bennani', '0667001122', 'amina.bennani@example.com', '2026-06-01 09:20:00'),
(2, 1, 'Hassan', 'Ouazzani', '0667003344', 'hassan.ouazzani@example.com', '2026-06-01 09:21:00');

INSERT INTO students (
    id, school_id, parent_id, first_name, last_name, date_of_birth, gender, class_level, class_name, class_level_id,
    parent_name, phone, address, monthly_amount, discount_percent, school_year, status, created_at
) VALUES
(1, 1, 1, 'Sara', 'Idrissi', '2016-02-18', 'Femme', 'CP', 'CP A', 1, 'Amina Bennani', '0667001122', 'Rue du Lycée 5', 1200.00, 0.00, '2025-2026', 'ACTIVE', '2026-06-01 09:25:00'),
(2, 1, 2, 'Youssef', 'Mahmoud', '2015-10-03', 'Homme', 'CE1', 'CE1 B', 2, 'Hassan Ouazzani', '0667003344', 'Lotissement Al Jawhara', 1300.00, 10.00, '2025-2026', 'ACTIVE', '2026-06-01 09:26:00');

INSERT INTO monthly_fees (
    id, school_id, student_id, month_label, year_value, total_amount, amount_paid, remaining_amount, status, created_at
) VALUES
(1, 1, 1, '09', 2025, 1200.00, 1200.00, 0.00, 'PAID', '2026-06-01 09:30:00'),
(2, 1, 1, '10', 2025, 1200.00, 600.00, 600.00, 'PARTIAL', '2026-06-01 09:31:00'),
(3, 1, 2, '09', 2025, 1170.00, 0.00, 1170.00, 'UNPAID', '2026-06-01 09:32:00');

INSERT INTO payments (
    id, school_id, student_id, monthly_fee_id, amount_paid, payment_date, payment_method_id, payment_method, created_at
) VALUES
(1, 1, 1, 1, 1200.00, '2025-09-10', NULL, 'CASH', '2026-06-01 09:40:00'),
(2, 1, 1, 2, 600.00, '2025-10-15', NULL, 'CARD', '2026-06-01 09:41:00');

ALTER TABLE schools AUTO_INCREMENT = 2;
ALTER TABLE users AUTO_INCREMENT = 3;
ALTER TABLE class_levels AUTO_INCREMENT = 4;
ALTER TABLE parents AUTO_INCREMENT = 3;
ALTER TABLE students AUTO_INCREMENT = 3;
ALTER TABLE monthly_fees AUTO_INCREMENT = 4;
ALTER TABLE payments AUTO_INCREMENT = 3;
