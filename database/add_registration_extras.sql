-- 已有库升级：新增表，保留已有选课与成绩。可重复执行。
USE course_registration;
CREATE TABLE IF NOT EXISTS selection_drafts (
 student_id BIGINT UNSIGNED NOT NULL,
 semester_id BIGINT UNSIGNED NOT NULL,
 choices JSON NOT NULL,
 PRIMARY KEY(student_id,semester_id),
 FOREIGN KEY(student_id) REFERENCES users(id),
 FOREIGN KEY(semester_id) REFERENCES semesters(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS teacher_qualifications (
 teacher_id BIGINT UNSIGNED NOT NULL,
 course_code VARCHAR(32) NOT NULL,
 PRIMARY KEY(teacher_id,course_code),
 FOREIGN KEY(teacher_id) REFERENCES users(id),
 FOREIGN KEY(course_code) REFERENCES courses(code)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS billing_outbox (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 student_id BIGINT UNSIGNED NOT NULL,
 semester_id BIGINT UNSIGNED NOT NULL,
 payload JSON NOT NULL,
 status ENUM('pending','failed','sent') NOT NULL DEFAULT 'pending',
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_error VARCHAR(200) NULL,
 sent_at DATETIME NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_billing(student_id,semester_id),
 FOREIGN KEY(student_id) REFERENCES users(id),
 FOREIGN KEY(semester_id) REFERENCES semesters(id)
) ENGINE=InnoDB;
