SET @add_class_level_name := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'class_levels'
        AND column_name = 'level_name'
    ),
    'SELECT 1',
    'ALTER TABLE class_levels ADD COLUMN level_name VARCHAR(180) NULL AFTER code'
  )
);
PREPARE stmt_add_class_level_name FROM @add_class_level_name;
EXECUTE stmt_add_class_level_name;
DEALLOCATE PREPARE stmt_add_class_level_name;

SET @add_class_school_year := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'class_levels'
        AND column_name = 'school_year'
    ),
    'SELECT 1',
    'ALTER TABLE class_levels ADD COLUMN school_year VARCHAR(20) NULL AFTER level_name'
  )
);
PREPARE stmt_add_class_school_year FROM @add_class_school_year;
EXECUTE stmt_add_class_school_year;
DEALLOCATE PREPARE stmt_add_class_school_year;
