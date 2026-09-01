CREATE TABLE IF NOT EXISTS teacher_class_levels (
    teacher_id INT NOT NULL,
    class_level_id INT NOT NULL,
    PRIMARY KEY (teacher_id, class_level_id),
    KEY teacher_class_levels_class_level_id_idx (class_level_id),
    CONSTRAINT teacher_class_levels_teacher_fk FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT teacher_class_levels_class_level_fk FOREIGN KEY (class_level_id) REFERENCES class_levels(id) ON DELETE CASCADE
);
