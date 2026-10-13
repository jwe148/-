USE course_registration;

-- 为已有数据库增加成绩确认及修改审计。可重复执行，不改动现有成绩。
CREATE TABLE IF NOT EXISTS grade_change_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offering_id VARCHAR(32) NOT NULL,
  enrollment_id BIGINT UNSIGNED NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  old_grade VARCHAR(8) NULL,
  new_grade VARCHAR(8) NULL,
  change_source ENUM('manual', 'csv') NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_grade_log_offering_time (offering_id, changed_at),
  CONSTRAINT fk_grade_log_offering FOREIGN KEY (offering_id) REFERENCES offerings (id),
  CONSTRAINT fk_grade_log_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id),
  CONSTRAINT fk_grade_log_teacher FOREIGN KEY (teacher_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grade_submissions (
  offering_id VARCHAR(32) NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (offering_id),
  CONSTRAINT fk_grade_submission_offering FOREIGN KEY (offering_id) REFERENCES offerings (id),
  CONSTRAINT fk_grade_submission_teacher FOREIGN KEY (teacher_id) REFERENCES users (id)
) ENGINE=InnoDB;
