-- 兼容旧脚本文件名：将当前开放学期截止时间延长至 2026 年 11 月 1 日 17:00（北京时间）。
USE course_registration;

UPDATE semesters
SET selection_ends_at = '2026-11-01 17:00:00'
WHERE name = '2026—2027学年 第一学期'
  AND status = 'open'
  AND selection_ends_at < '2026-11-01 17:00:00';

SELECT name, selection_ends_at, status
FROM semesters
WHERE name = '2026—2027学年 第一学期';
