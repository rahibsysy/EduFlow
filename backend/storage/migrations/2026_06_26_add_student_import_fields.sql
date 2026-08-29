ALTER TABLE students
    ADD COLUMN gender VARCHAR(20) NULL AFTER date_of_birth,
    ADD COLUMN class_name VARCHAR(100) NULL AFTER class_level,
    ADD COLUMN address VARCHAR(255) NULL AFTER phone;
