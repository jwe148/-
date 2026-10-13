-- 为已有数据库增加关闭前快照。可重复执行，不修改现有业务数据。
CREATE TABLE IF NOT EXISTS semester_closure_snapshots (
  semester_id BIGINT UNSIGNED NOT NULL,
  snapshot JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (semester_id),
  CONSTRAINT fk_closure_snapshot_semester FOREIGN KEY (semester_id) REFERENCES semesters (id)
) ENGINE=InnoDB;
