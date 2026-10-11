-- 已有数据库升级：可重复执行，不修改 users 或既有业务记录。
USE course_registration;
CREATE TABLE IF NOT EXISTS person_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  date_of_birth DATE NOT NULL,
  social_security_number VARCHAR(32) NOT NULL,
  person_status VARCHAR(32) NOT NULL,
  department VARCHAR(100) NULL,
  graduation_date DATE NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_person_identity (social_security_number),
  CONSTRAINT fk_person_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT ck_person_revision CHECK (revision >= 1)
) ENGINE=InnoDB;
