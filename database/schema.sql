-- 课程注册系统目标数据结构。建议 MySQL 8.0.16+，以执行下列 CHECK 约束。
-- 执行前请使用专用开发数据库；容量等跨行规则仍须由业务事务校验。
-- 本文件只建表，不导入演示账号或虚构选课人数。
CREATE DATABASE IF NOT EXISTS course_registration
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE course_registration;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  role ENUM('student', 'teacher', 'admin') NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  CONSTRAINT ck_users_active CHECK (is_active IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS semesters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  selection_starts_at DATETIME NOT NULL,
  selection_ends_at DATETIME NOT NULL,
  status ENUM('open', 'closing', 'closed') NOT NULL DEFAULT 'open',
  closed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_semesters_name (name),
  CONSTRAINT ck_semester_period CHECK (selection_starts_at < selection_ends_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
  code VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  description TEXT NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS course_prerequisites (
  course_code VARCHAR(32) NOT NULL,
  prerequisite_code VARCHAR(32) NOT NULL,
  PRIMARY KEY (course_code, prerequisite_code),
  CONSTRAINT fk_prerequisite_course FOREIGN KEY (course_code) REFERENCES courses (code),
  CONSTRAINT fk_prerequisite_required FOREIGN KEY (prerequisite_code) REFERENCES courses (code),
  CONSTRAINT ck_prerequisite_distinct CHECK (course_code <> prerequisite_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offerings (
  id VARCHAR(32) NOT NULL,
  course_code VARCHAR(32) NOT NULL,
  semester_id BIGINT UNSIGNED NOT NULL,
  class_name VARCHAR(50) NOT NULL,
  teacher_id BIGINT UNSIGNED NULL,
  location VARCHAR(100) NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL DEFAULT 10,
  status ENUM('open', 'confirmed', 'cancelled') NOT NULL DEFAULT 'open',
  PRIMARY KEY (id),
  KEY ix_offerings_semester_status (semester_id, status),
  KEY ix_offerings_teacher (teacher_id),
  CONSTRAINT fk_offering_course FOREIGN KEY (course_code) REFERENCES courses (code),
  CONSTRAINT fk_offering_semester FOREIGN KEY (semester_id) REFERENCES semesters (id),
  CONSTRAINT fk_offering_teacher FOREIGN KEY (teacher_id) REFERENCES users (id),
  CONSTRAINT ck_offering_capacity CHECK (capacity BETWEEN 1 AND 10)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offering_times (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  offering_id VARCHAR(32) NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  starts_at TIME NOT NULL,
  ends_at TIME NOT NULL,
  PRIMARY KEY (id),
  KEY ix_offering_times_offering (offering_id),
  CONSTRAINT fk_offering_time FOREIGN KEY (offering_id) REFERENCES offerings (id),
  CONSTRAINT ck_offering_weekday CHECK (weekday BETWEEN 1 AND 7),
  CONSTRAINT ck_offering_time_order CHECK (starts_at < ends_at)
) ENGINE=InnoDB;

-- 只记录教师主动认领；预先安排的任课教师没有此记录，不能由教师自行取消。
CREATE TABLE IF NOT EXISTS teacher_claims (
  offering_id VARCHAR(32) NOT NULL,
  teacher_id BIGINT UNSIGNED NOT NULL,
  claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (offering_id),
  KEY ix_teacher_claims_teacher (teacher_id),
  CONSTRAINT fk_teacher_claim_offering FOREIGN KEY (offering_id) REFERENCES offerings (id),
  CONSTRAINT fk_teacher_claim_teacher FOREIGN KEY (teacher_id) REFERENCES users (id)
) ENGINE=InnoDB;

-- 保留关闭时的原始人数和停开原因，供教务结果页与学生课表查看。
CREATE TABLE IF NOT EXISTS offering_closure_results (
  offering_id VARCHAR(32) NOT NULL,
  original_active_count TINYINT UNSIGNED NOT NULL,
  outcome ENUM('confirmed', 'no_teacher', 'under_minimum') NOT NULL,
  decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (offering_id),
  CONSTRAINT fk_closure_offering FOREIGN KEY (offering_id) REFERENCES offerings (id)
) ENGINE=InnoDB;

-- 有缺位时记录实际尝试过的备选及失败原因；未尝试的备选不写入。
CREATE TABLE IF NOT EXISTS closure_backup_attempts (
  student_id BIGINT UNSIGNED NOT NULL,
  semester_id BIGINT UNSIGNED NOT NULL,
  backup_rank TINYINT UNSIGNED NOT NULL,
  offering_id VARCHAR(32) NOT NULL,
  outcome ENUM('promoted', 'cancelled', 'full', 'missing_time', 'prerequisite', 'duplicate_course', 'time_conflict') NOT NULL,
  PRIMARY KEY (student_id, semester_id, backup_rank),
  CONSTRAINT fk_backup_attempt_student FOREIGN KEY (student_id) REFERENCES users (id),
  CONSTRAINT fk_backup_attempt_semester FOREIGN KEY (semester_id) REFERENCES semesters (id),
  CONSTRAINT fk_backup_attempt_offering FOREIGN KEY (offering_id) REFERENCES offerings (id),
  CONSTRAINT ck_backup_attempt_rank CHECK (backup_rank BETWEEN 1 AND 2)
) ENGINE=InnoDB;

-- 首选和备选的顺序分别从 1 开始。数量、同课程不同班与先修约束由服务端校验。
CREATE TABLE IF NOT EXISTS selection_choices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  semester_id BIGINT UNSIGNED NOT NULL,
  offering_id VARCHAR(32) NOT NULL,
  kind ENUM('primary', 'backup') NOT NULL,
  choice_rank TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_choice_offering (student_id, semester_id, offering_id),
  UNIQUE KEY uq_choice_rank (student_id, semester_id, kind, choice_rank),
  KEY ix_choice_offering (offering_id),
  CONSTRAINT fk_choice_student FOREIGN KEY (student_id) REFERENCES users (id),
  CONSTRAINT fk_choice_semester FOREIGN KEY (semester_id) REFERENCES semesters (id),
  CONSTRAINT fk_choice_offering FOREIGN KEY (offering_id) REFERENCES offerings (id),
  CONSTRAINT ck_choice_rank CHECK (
    (kind = 'primary' AND choice_rank BETWEEN 1 AND 4)
    OR (kind = 'backup' AND choice_rank BETWEEN 1 AND 2)
  )
) ENGINE=InnoDB;

-- 只有 status=active 的记录占用名额；容量仍需在事务内锁定教学班并校验。
CREATE TABLE IF NOT EXISTS enrollments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id BIGINT UNSIGNED NOT NULL,
  offering_id VARCHAR(32) NOT NULL,
  status ENUM('active', 'dropped', 'cancelled') NOT NULL DEFAULT 'active',
  source ENUM('primary', 'backup') NOT NULL DEFAULT 'primary',
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enrollment_student_offering (student_id, offering_id),
  KEY ix_enrollment_offering_status (offering_id, status),
  CONSTRAINT fk_enrollment_student FOREIGN KEY (student_id) REFERENCES users (id),
  CONSTRAINT fk_enrollment_offering FOREIGN KEY (offering_id) REFERENCES offerings (id)
) ENGINE=InnoDB;

-- 成绩等级尚待小组统一，暂不限制枚举值；未录入时 grade_value 为 NULL。
CREATE TABLE IF NOT EXISTS grades (
  enrollment_id BIGINT UNSIGNED NOT NULL,
  grade_value VARCHAR(8) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (enrollment_id),
  CONSTRAINT fk_grade_enrollment FOREIGN KEY (enrollment_id) REFERENCES enrollments (id)
) ENGINE=InnoDB;
