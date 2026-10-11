-- 可选：仅用于旧演示库。重复执行会重新授予已撤销的样例资格。
USE course_registration;
INSERT IGNORE INTO teacher_qualifications(teacher_id,course_code)
SELECT u.id,c.code FROM users u CROSS JOIN courses c
WHERE u.username='teacher01' AND u.role='teacher' AND u.is_active=1;
