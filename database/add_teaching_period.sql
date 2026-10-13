-- 已有库补充教学结束时间，可重复执行；保留选课、成绩和已设置的日期。
USE course_registration;
SET @teaching_period_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='semesters' AND column_name='teaching_ends_at');
SET @teaching_period_sql = IF(@teaching_period_exists=0, 'ALTER TABLE semesters ADD COLUMN teaching_ends_at DATETIME NULL AFTER selection_ends_at', 'SELECT 1');
PREPARE teaching_period_stmt FROM @teaching_period_sql;
EXECUTE teaching_period_stmt;
DEALLOCATE PREPARE teaching_period_stmt;
-- 旧记录先按选课开始后的五个月补默认值；教务可在学期设置中改为实际结束时间。
UPDATE semesters SET teaching_ends_at=DATE_ADD(selection_starts_at, INTERVAL 5 MONTH)
WHERE teaching_ends_at IS NULL;
