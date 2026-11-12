-- Retry protection for new payment requests. Historical payments intentionally
-- keep a NULL key, so no old operation is reinterpreted or rewritten.
SET @schema_name = DATABASE();
SET @has_column = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND COLUMN_NAME = 'idempotency_key'
);
SET @sql = IF(@has_column = 0,
    'ALTER TABLE payments ADD COLUMN idempotency_key VARCHAR(190) NULL AFTER monthly_fee_id',
    'SELECT 1');
PREPARE statement FROM @sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

SET @has_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'payments' AND INDEX_NAME = 'payments_school_idempotency_unique'
);
SET @sql = IF(@has_index = 0,
    'ALTER TABLE payments ADD UNIQUE KEY payments_school_idempotency_unique (school_id, idempotency_key)',
    'SELECT 1');
PREPARE statement FROM @sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;
