ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'user', 'professeur') NOT NULL;

SET @add_subject_weekly_hours := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'subject_class_levels'
        AND column_name = 'weekly_hours'
    ),
    'SELECT 1',
    'ALTER TABLE subject_class_levels ADD COLUMN weekly_hours TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER class_level_id'
  )
);
PREPARE stmt_add_subject_weekly_hours FROM @add_subject_weekly_hours;
EXECUTE stmt_add_subject_weekly_hours;
DEALLOCATE PREPARE stmt_add_subject_weekly_hours;
