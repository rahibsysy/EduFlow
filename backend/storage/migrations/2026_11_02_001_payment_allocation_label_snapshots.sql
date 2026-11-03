-- New allocations receive the item label while the financial item is locked.
-- Existing rows are backfilled from the current label at migration time only;
-- this reconstructs a usable display value but cannot prove the original label.
SET @schema_name := DATABASE();
SET @sql := IF(
    EXISTS(
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = @schema_name
          AND TABLE_NAME = 'payment_allocations'
          AND COLUMN_NAME = 'item_label_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE payment_allocations ADD COLUMN item_label_snapshot VARCHAR(120) NULL COMMENT ''Allocation-time label; legacy values backfilled at migration time'' AFTER amount'
);
PREPARE statement FROM @sql;
EXECUTE statement;
DEALLOCATE PREPARE statement;

-- Legacy values are a best-effort reconstruction from the label currently stored
-- on the financial item. New allocations are populated by PaymentService.
UPDATE payment_allocations pa
INNER JOIN student_financial_items fi
    ON fi.id = pa.student_financial_item_id
   AND fi.school_id = pa.school_id
SET pa.item_label_snapshot = fi.label
WHERE pa.item_label_snapshot IS NULL OR TRIM(pa.item_label_snapshot) = '';

ALTER TABLE payment_allocations
    MODIFY COLUMN item_label_snapshot VARCHAR(120) NOT NULL
    COMMENT 'Allocation-time label; legacy values backfilled at migration time';
