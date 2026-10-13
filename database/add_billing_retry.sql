-- 已有库升级：可重复执行，保留历史账单，不重新计算金额。
USE course_registration;
SET @billing_retry_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='billing_outbox' AND column_name='next_attempt_at');
SET @billing_retry_sql = IF(@billing_retry_exists=0, 'ALTER TABLE billing_outbox ADD COLUMN next_attempt_at DATETIME NULL AFTER sent_at', 'SELECT 1');
PREPARE billing_retry_stmt FROM @billing_retry_sql;
EXECUTE billing_retry_stmt;
DEALLOCATE PREPARE billing_retry_stmt;
