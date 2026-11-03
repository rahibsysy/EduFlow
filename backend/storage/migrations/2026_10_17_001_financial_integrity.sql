DELIMITER $$
CREATE PROCEDURE assert_financial_school_integrity()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM monthly_fees mf
        INNER JOIN students s ON s.id = mf.student_id
        WHERE mf.school_id <> s.school_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add financial integrity constraints: monthly fee/student school mismatch';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM payments p
        INNER JOIN students s ON s.id = p.student_id
        WHERE p.school_id <> s.school_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add financial integrity constraints: payment/student school mismatch';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM payments p
        INNER JOIN monthly_fees mf ON mf.id = p.monthly_fee_id
        WHERE p.monthly_fee_id IS NOT NULL AND p.school_id <> mf.school_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add financial integrity constraints: payment/monthly fee school mismatch';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM monthly_fees
        GROUP BY school_id, student_id, month_label, year_value
        HAVING COUNT(*) > 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot add monthly fee uniqueness: duplicate school/student/month/year rows exist';
    END IF;
END$$
DELIMITER ;

CALL assert_financial_school_integrity();
DROP PROCEDURE assert_financial_school_integrity;

DELIMITER $$
CREATE PROCEDURE add_financial_integrity_objects()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND INDEX_NAME = 'students_school_id_id_unique') THEN
        SET @sql = 'ALTER TABLE students ADD UNIQUE KEY students_school_id_id_unique (school_id, id)'; PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_fees' AND INDEX_NAME = 'monthly_fees_school_student_period_unique') THEN
        SET @sql = 'ALTER TABLE monthly_fees ADD UNIQUE KEY monthly_fees_school_student_period_unique (school_id, student_id, month_label, year_value)'; PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_fees' AND INDEX_NAME = 'monthly_fees_school_id_id_unique') THEN
        SET @sql = 'ALTER TABLE monthly_fees ADD UNIQUE KEY monthly_fees_school_id_id_unique (school_id, id)'; PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'monthly_fees' AND CONSTRAINT_NAME = 'monthly_fees_student_school_fk') THEN
        SET @sql = 'ALTER TABLE monthly_fees ADD CONSTRAINT monthly_fees_student_school_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE'; PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'payments_student_school_fk') THEN
        SET @sql = 'ALTER TABLE payments ADD CONSTRAINT payments_student_school_fk FOREIGN KEY (school_id, student_id) REFERENCES students (school_id, id) ON DELETE CASCADE'; PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND CONSTRAINT_NAME = 'payments_monthly_fee_school_fk') THEN
        SET @sql = 'ALTER TABLE payments ADD CONSTRAINT payments_monthly_fee_school_fk FOREIGN KEY (school_id, monthly_fee_id) REFERENCES monthly_fees (school_id, id) ON DELETE CASCADE'; PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
    END IF;
END$$
DELIMITER ;

CALL add_financial_integrity_objects();
DROP PROCEDURE add_financial_integrity_objects;

CREATE TABLE payment_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    school_id INT NOT NULL,
    action VARCHAR(40) NOT NULL,
    before_data JSON NULL,
    after_data JSON NULL,
    reason TEXT NULL,
    changed_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY payment_history_school_payment_idx (school_id, payment_id, id),
    CONSTRAINT payment_history_payment_school_fk
        FOREIGN KEY (school_id, payment_id) REFERENCES payments (school_id, id) ON DELETE RESTRICT,
    CONSTRAINT payment_history_user_fk
        FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER payments_prevent_delete
BEFORE DELETE ON payments
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payments are immutable and must be cancelled, not deleted';
END$$

CREATE TRIGGER payments_prevent_cancelled_mutation
BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
    IF OLD.status = 'CANCELLED' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A cancelled payment is immutable';
    END IF;
    IF NEW.status = 'CANCELLED' AND (NEW.cancelled_at IS NULL OR NEW.cancelled_by_user_id IS NULL OR NEW.cancellation_reason IS NULL OR CHAR_LENGTH(TRIM(NEW.cancellation_reason)) = 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cancelled payments require date, author and reason';
    END IF;
END$$
DELIMITER ;
