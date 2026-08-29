SET @add_class_group_name := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'class_levels'
        AND column_name = 'group_name'
    ),
    'SELECT 1',
    'ALTER TABLE class_levels ADD COLUMN group_name VARCHAR(100) NULL AFTER level_name'
  )
);
PREPARE stmt_add_class_group_name FROM @add_class_group_name;
EXECUTE stmt_add_class_group_name;
DEALLOCATE PREPARE stmt_add_class_group_name;

SET @add_schedule_is_external := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
        AND column_name = 'is_external'
    ),
    'SELECT 1',
    'ALTER TABLE schedules ADD COLUMN is_external TINYINT(1) NOT NULL DEFAULT 0 AFTER room'
  )
);
PREPARE stmt_add_schedule_is_external FROM @add_schedule_is_external;
EXECUTE stmt_add_schedule_is_external;
DEALLOCATE PREPARE stmt_add_schedule_is_external;

SET @schedule_class_nullable := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
        AND column_name = 'class_level_id'
        AND is_nullable = 'NO'
    ),
    'ALTER TABLE schedules MODIFY class_level_id INT NULL',
    'SELECT 1'
  )
);
PREPARE stmt_schedule_class_nullable FROM @schedule_class_nullable;
EXECUTE stmt_schedule_class_nullable;
DEALLOCATE PREPARE stmt_schedule_class_nullable;
