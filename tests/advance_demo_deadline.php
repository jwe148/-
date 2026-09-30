<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--disposable-demo') {
    fwrite(STDERR, "仅供可丢弃的演示测试库使用：php tests/advance_demo_deadline.php --disposable-demo\n");
    exit(1);
}
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/data/catalog.php';

$pdo = databaseConnection();
$query = $pdo->prepare("SELECT s.id, s.status, s.selection_ends_at,
    (SELECT COUNT(*) FROM offerings o WHERE o.semester_id = s.id) AS offering_count,
    (SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id = e.offering_id
      WHERE o.semester_id = s.id AND e.status = 'active' AND o.id = 'HCI326-01') AS hci_count,
    (SELECT COUNT(*) FROM offering_closure_results r JOIN offerings o ON o.id = r.offering_id
      WHERE o.semester_id = s.id) AS result_count
    FROM semesters s WHERE s.name = ?");
$query->execute([$semester]);
$row = $query->fetch();
if (!is_array($row) || $row['status'] !== 'open' || (int) $row['offering_count'] !== count($offerings)
    || (int) $row['hci_count'] !== 1 || (int) $row['result_count'] !== 0) {
    fwrite(STDERR, "不是预期的待关闭演示测试状态，未修改截止时间。\n");
    exit(1);
}
$deadline = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
    ->modify('-1 minute')->format('Y-m-d H:i:s');
$update = $pdo->prepare('UPDATE semesters SET selection_ends_at = ? WHERE id = ? AND status = ?');
$update->execute([$deadline, $row['id'], 'open']);
echo "PASS 演示测试库选课时间已截止\n";
