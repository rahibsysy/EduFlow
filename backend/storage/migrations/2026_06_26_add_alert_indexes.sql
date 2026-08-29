ALTER TABLE monthly_fees
    ADD INDEX monthly_fees_school_status_idx (school_id, status),
    ADD INDEX monthly_fees_student_status_idx (student_id, status),
    ADD INDEX monthly_fees_school_period_idx (school_id, year_value, month_label);

ALTER TABLE payments
    ADD INDEX payments_school_date_idx (school_id, payment_date),
    ADD INDEX payments_student_idx (student_id),
    ADD INDEX payments_monthly_fee_idx (monthly_fee_id);
