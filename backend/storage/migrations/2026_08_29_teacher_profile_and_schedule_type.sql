SET @add_user_gender := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'users'
        AND column_name = 'gender'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN gender ENUM(''MALE'', ''FEMALE'') NULL AFTER status'
  )
);
PREPARE stmt_add_user_gender FROM @add_user_gender;
EXECUTE stmt_add_user_gender;
DEALLOCATE PREPARE stmt_add_user_gender;

SET @add_class_group_name := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
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

SET @add_user_phone := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'users'
        AND column_name = 'phone'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL AFTER gender'
  )
);
PREPARE stmt_add_user_phone FROM @add_user_phone;
EXECUTE stmt_add_user_phone;
DEALLOCATE PREPARE stmt_add_user_phone;

SET @add_user_address := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'users'
        AND column_name = 'address'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN address VARCHAR(255) NULL AFTER phone'
  )
);
PREPARE stmt_add_user_address FROM @add_user_address;
EXECUTE stmt_add_user_address;
DEALLOCATE PREPARE stmt_add_user_address;

SET @add_user_primary_school := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'users'
        AND column_name = 'primary_school'
    ),
    'SELECT 1',
    'ALTER TABLE users ADD COLUMN primary_school VARCHAR(150) NULL AFTER address'
  )
);
PREPARE stmt_add_user_primary_school FROM @add_user_primary_school;
EXECUTE stmt_add_user_primary_school;
DEALLOCATE PREPARE stmt_add_user_primary_school;

SET @add_schedule_is_external := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
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

SET @add_schedule_type := (
  SELECT IF(
    NOT EXISTS(
      SELECT 1 FROM information_schema.tables
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
    )
    OR NOT EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
        AND column_name = 'is_external'
    )
    OR EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
        AND column_name = 'schedule_type'
    ),
    'SELECT 1',
    'ALTER TABLE schedules ADD COLUMN schedule_type ENUM(''eduflow_course'', ''external_busy'') NOT NULL DEFAULT ''eduflow_course'' AFTER is_external'
  )
);
PREPARE stmt_add_schedule_type FROM @add_schedule_type;
EXECUTE stmt_add_schedule_type;
DEALLOCATE PREPARE stmt_add_schedule_type;

SET @schedule_class_nullable := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
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

SET @sync_schedule_type := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
        AND column_name = 'is_external'
    )
    AND EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'schedules'
        AND column_name = 'schedule_type'
    ),
    'UPDATE schedules SET schedule_type = CASE WHEN is_external = 1 THEN ''external_busy'' ELSE ''eduflow_course'' END',
    'SELECT 1'
  )
);
PREPARE stmt_sync_schedule_type FROM @sync_schedule_type;
EXECUTE stmt_sync_schedule_type;
DEALLOCATE PREPARE stmt_sync_schedule_type;
