-- 将已有演示库中尚未关闭的当前学期截止时间延长至 2027 年。
USE course_registration;

UPDATE semesters
SET selection_ends_at = '2027-10-09 17:00:00'
WHERE name = '2026—2027学年 第一学期'
  AND status = 'open'
  AND selection_ends_at < '2027-10-09 17:00:00';

SELECT name, selection_ends_at, status
FROM semesters
WHERE name = '2026—2027学年 第一学期';
