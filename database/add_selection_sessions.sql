CREATE TABLE IF NOT EXISTS selection_sessions (
  session_key CHAR(64) NOT NULL PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  semester_id BIGINT UNSIGNED NOT NULL,
  auth_stamp CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY ix_selection_session_period (semester_id, expires_at),
  FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE
) ENGINE=InnoDB;
