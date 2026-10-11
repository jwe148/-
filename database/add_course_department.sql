-- 旧库补充课程院系：可重复执行，仅给院系为空的已知演示课程填样例。
USE course_registration;
SET @department_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='courses' AND column_name='department');
SET @department_sql = IF(@department_exists=0, "ALTER TABLE courses ADD COLUMN department VARCHAR(100) NOT NULL DEFAULT ''", 'SELECT 1');
PREPARE department_stmt FROM @department_sql;
EXECUTE department_stmt;
DEALLOCATE PREPARE department_stmt;
UPDATE courses SET department=CASE code WHEN 'PRE-LA' THEN '数学学院' WHEN 'DSP342' THEN '电子信息学院' ELSE '计算机学院' END
WHERE department='' AND code IN ('SE301','DB305','CN309','OS312','AI320','HCI326','UX328','SEC335','WEB337','DSP342','ML340','PRE-PROG','PRE-DS','PRE-LA');
