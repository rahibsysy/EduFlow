SET @q := (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'schedules'
              AND column_name = 'year_value'
        ),
        'SELECT 1',
        'ALTER TABLE schedules ADD COLUMN year_value INT NULL AFTER room'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'schedules'
              AND column_name = 'week_number'
        ),
        'SELECT 1',
        'ALTER TABLE schedules ADD COLUMN week_number TINYINT NULL AFTER year_value'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'schedules'
              AND index_name = 'schedules_school_week_day_idx'
        ),
        'SELECT 1',
        'ALTER TABLE schedules ADD KEY schedules_school_week_day_idx (school_id, year_value, week_number, day_order, start_time)'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
    SELECT IF(
        EXISTS(
            SELECT 1 FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'schedules'
              AND index_name = 'schedules_class_week_day_idx'
        ),
        'SELECT 1',
        'ALTER TABLE schedules ADD KEY schedules_class_week_day_idx (class_level_id, year_value, week_number, day_of_week, start_time)'
    )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
