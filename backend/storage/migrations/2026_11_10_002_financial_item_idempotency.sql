-- Historical rows keep a NULL key.  New operations carry a stable key and
-- cannot be inserted twice, including during concurrent retries.
ALTER TABLE student_financial_items
    ADD COLUMN idempotency_key VARCHAR(190) NULL AFTER item_code,
    ADD UNIQUE KEY student_financial_items_school_idempotency_unique (school_id, idempotency_key);
