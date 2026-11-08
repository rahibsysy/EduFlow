-- Preserve legacy TEACHER rows while allowing the canonical PROFESSOR value.
ALTER TABLE personnel_profiles
    MODIFY COLUMN personnel_type ENUM('TEACHER', 'PROFESSOR', 'ADMINISTRATIVE_STAFF') NOT NULL;
