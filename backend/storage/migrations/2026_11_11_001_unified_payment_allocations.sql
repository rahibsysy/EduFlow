-- A new allocation can target exactly one current obligation: either a legacy
-- monthly fee or a flexible financial item. Existing rows and payments remain
-- untouched, which keeps historical receipts valid.
SET @schema_name = DATABASE();

SET @has_monthly_column = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='payment_allocations' AND COLUMN_NAME='monthly_fee_id');
SET @sql = IF(@has_monthly_column=0, 'ALTER TABLE payment_allocations ADD COLUMN monthly_fee_id INT NULL AFTER student_financial_item_id', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @has_item_fk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='payment_allocations' AND CONSTRAINT_NAME='payment_allocations_item_school_fk');
SET @sql = IF(@has_item_fk=1, 'ALTER TABLE payment_allocations DROP FOREIGN KEY payment_allocations_item_school_fk', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
ALTER TABLE payment_allocations MODIFY student_financial_item_id INT NULL;
ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_item_school_fk FOREIGN KEY (school_id, student_financial_item_id) REFERENCES student_financial_items(school_id, id) ON DELETE RESTRICT;

SET @has_monthly_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='payment_allocations' AND INDEX_NAME='payment_allocations_school_monthly_idx');
SET @sql = IF(@has_monthly_idx=0, 'ALTER TABLE payment_allocations ADD KEY payment_allocations_school_monthly_idx (school_id, monthly_fee_id)', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
SET @has_monthly_unique = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='payment_allocations' AND INDEX_NAME='payment_allocations_payment_monthly_unique');
SET @sql = IF(@has_monthly_unique=0, 'ALTER TABLE payment_allocations ADD UNIQUE KEY payment_allocations_payment_monthly_unique (payment_id, monthly_fee_id)', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @has_fee_index = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@schema_name AND TABLE_NAME='monthly_fees' AND INDEX_NAME='monthly_fees_school_id_id_idx');
SET @sql = IF(@has_fee_index=0, 'ALTER TABLE monthly_fees ADD KEY monthly_fees_school_id_id_idx (school_id, id)', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
SET @has_monthly_fk = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='payment_allocations' AND CONSTRAINT_NAME='payment_allocations_monthly_fee_school_fk');
SET @sql = IF(@has_monthly_fk=0, 'ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_monthly_fee_school_fk FOREIGN KEY (school_id, monthly_fee_id) REFERENCES monthly_fees(school_id, id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;

SET @has_target_check = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=@schema_name AND TABLE_NAME='payment_allocations' AND CONSTRAINT_NAME='payment_allocations_one_target_check');
SET @sql = IF(@has_target_check=0, 'ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_one_target_check CHECK ((student_financial_item_id IS NOT NULL) <> (monthly_fee_id IS NOT NULL))', 'SELECT 1');
PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;
