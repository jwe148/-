<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => '只支持查询。'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require __DIR__ . '/auth.php';
    $user = currentUser();
    if ($user === null || $user['role'] !== 'student') {
        http_response_code($user === null ? 401 : 403);
        echo json_encode(['error' => '请使用学生账号登录。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require __DIR__ . '/data/catalog.php';
    require_once __DIR__ . '/student_data.php';
    $availability = [];
    if (databaseModeEnabled()) {
        $pdo = databaseConnection();
        $query = $pdo->prepare('SELECT id, selection_starts_at, selection_ends_at, status FROM semesters WHERE name = ?');
        $query->execute([$semester]);
        $period = $query->fetch();
        if (!is_array($period)) {
            throw new RuntimeException('当前学期不存在。');
        }
        $selectionPeriod = ['start' => $period['selection_starts_at'], 'end' => $period['selection_ends_at'], 'status' => $period['status']];
        // 只返回名额及本人是否占座，不返回其他学生的身份或记录。
        $query = $pdo->prepare("SELECT o.id, o.capacity, o.status, COUNT(e.id) AS selected,
            COALESCE(MAX(e.student_id = ?), 0) AS owned
            FROM offerings o LEFT JOIN enrollments e ON e.offering_id = o.id AND e.status = 'active'
            WHERE o.semester_id = ? GROUP BY o.id, o.capacity, o.status ORDER BY o.id");
        $query->execute([$user['id'], $period['id']]);
        foreach ($query->fetchAll() as $row) {
            $availability[$row['id']] = [
                'remaining' => max(0, (int) $row['capacity'] - (int) $row['selected']),
                'owned' => (bool) $row['owned'],
                'status' => $row['status'],
            ];
        }
    } else {
        $owned = studentSelection()['primary'];
        foreach ($offerings as $id => $course) {
            $hasSeat = in_array($id, $owned, true);
            $availability[$id] = [
                'remaining' => max(0, $course['capacity'] - $course['selected'] - ($hasSeat ? 1 : 0)),
                'owned' => $hasSeat,
                'status' => $course['status'] ?? 'open',
            ];
        }
    }
    $open = selectionOpen($selectionPeriod);
    session_write_close();
    echo json_encode(['open' => $open, 'offerings' => (object) $availability], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Course registration availability failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => '名额暂时无法刷新，请稍后重试。'], JSON_UNESCAPED_UNICODE);
}
