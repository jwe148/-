<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';
require_once __DIR__ . '/offering_view.php';
require_once __DIR__ . '/../db.php';

if (databaseModeEnabled()) {
    try {
        $pdo = databaseConnection();
        $periodQuery = $pdo->prepare('SELECT id, selection_starts_at, selection_ends_at, status FROM semesters WHERE name = ?');
        $periodQuery->execute([$semester]);
        $period = $periodQuery->fetch();
        if (!is_array($period)) {
            throw new RuntimeException('当前学期未导入数据库。');
        }
        $selectionPeriod = [
            'start' => $period['selection_starts_at'],
            'end' => $period['selection_ends_at'],
            'status' => $period['status'],
            'semester_id' => (int) $period['id'],
        ];
        $query = $pdo->prepare("SELECT o.id, o.course_code AS code, c.name, c.description,
            o.class_name AS class, o.location, o.capacity, o.status,
            COALESCE(u.display_name, '待认领') AS teacher,
            tc.teacher_id IS NOT NULL AS self_claimed,
            (SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = 'active') AS selected
            FROM offerings o JOIN courses c ON c.code = o.course_code
            LEFT JOIN users u ON u.id = o.teacher_id
            LEFT JOIN teacher_claims tc ON tc.offering_id = o.id
            WHERE o.semester_id = ? ORDER BY o.id");
        $query->execute([$selectionPeriod['semester_id']]);
        $offerings = [];
        foreach ($query->fetchAll() as $row) {
            $offerings[$row['id']] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'description' => $row['description'],
                'class' => $row['class'],
                'location' => $row['location'],
                'capacity' => (int) $row['capacity'],
                'selected' => (int) $row['selected'],
                'status' => $row['status'],
                'teacher' => $row['teacher'],
                'self_claimed' => (bool) $row['self_claimed'],
                'times' => [],
                'prerequisites' => [],
            ];
        }
        if ($offerings === []) {
            throw new RuntimeException('当前学期没有教学班。');
        }

        $days = [1 => '周一', 2 => '周二', 3 => '周三', 4 => '周四', 5 => '周五', 6 => '周六', 7 => '周日'];
        $timeQuery = $pdo->prepare('SELECT t.offering_id, t.weekday, t.starts_at, t.ends_at
            FROM offering_times t JOIN offerings o ON o.id = t.offering_id
            WHERE o.semester_id = ? ORDER BY t.weekday, t.starts_at, t.id');
        $timeQuery->execute([$selectionPeriod['semester_id']]);
        foreach ($timeQuery->fetchAll() as $row) {
            $offerings[$row['offering_id']]['times'][] = [
                'day' => $days[(int) $row['weekday']],
                'time' => substr($row['starts_at'], 0, 5) . '—' . substr($row['ends_at'], 0, 5),
            ];
        }
        foreach ($offerings as $id => $offering) {
            if ($offering['times'] === []) {
                throw new RuntimeException('教学班缺少上课时间：' . $id);
            }
        }

        $prerequisiteQuery = $pdo->prepare('SELECT cp.course_code, required.name
            FROM course_prerequisites cp JOIN courses required ON required.code = cp.prerequisite_code
            JOIN offerings o ON o.course_code = cp.course_code
            WHERE o.semester_id = ? GROUP BY cp.course_code, required.name ORDER BY required.name');
        $prerequisiteQuery->execute([$selectionPeriod['semester_id']]);
        $prerequisites = [];
        foreach ($prerequisiteQuery->fetchAll() as $row) {
            $prerequisites[$row['course_code']][] = $row['name'];
        }
        foreach ($offerings as &$offering) {
            $offering['prerequisites'] = $prerequisites[$offering['code']] ?? [];
            $offering['prerequisite'] = $offering['prerequisites'] === []
                ? '无' : implode('、', $offering['prerequisites']);
        }
        unset($offering);
    } catch (Throwable $error) {
        error_log('Course registration catalog load failed: ' . $error->getMessage());
        http_response_code(503);
        exit('课程数据暂不可用，请检查数据库配置与课程数据。');
    }
}
