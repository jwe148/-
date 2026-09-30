<?php
declare(strict_types=1);

require __DIR__ . '/catalog.php';
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
        $query = $pdo->prepare("SELECT o.id, o.capacity, o.status, COALESCE(u.display_name, '待认领') AS teacher,
            tc.teacher_id IS NOT NULL AS self_claimed,
            (SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = 'active') AS selected
            FROM offerings o LEFT JOIN users u ON u.id = o.teacher_id
            LEFT JOIN teacher_claims tc ON tc.offering_id = o.id WHERE o.semester_id = ?");
        $query->execute([$selectionPeriod['semester_id']]);
        $rows = $query->fetchAll();
        if (count($rows) !== count($offerings)) {
            throw new RuntimeException('数据库教学班与演示目录不一致。');
        }
        foreach ($rows as $row) {
            $id = $row['id'];
            if (!isset($offerings[$id])) {
                throw new RuntimeException('数据库中存在演示目录未收录的教学班。');
            }
            $offerings[$id]['capacity'] = (int) $row['capacity'];
            $offerings[$id]['selected'] = (int) $row['selected'];
            $offerings[$id]['teacher'] = $row['teacher'];
            $offerings[$id]['status'] = $row['status'];
            $offerings[$id]['self_claimed'] = (bool) $row['self_claimed'];
        }
    } catch (Throwable $error) {
        error_log('Course registration catalog load failed: ' . $error->getMessage());
        http_response_code(503);
        exit('课程数据暂不可用，请检查数据库配置与演示数据。');
    }
}
