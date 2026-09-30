<?php
declare(strict_types=1);

require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/data/catalog.php';

$pdo = databaseConnection();
$query = $pdo->prepare('SELECT id, status, closed_at FROM semesters WHERE name = ?');
$query->execute([$semester]);
$period = $query->fetch();
if (!is_array($period)) {
    throw new RuntimeException('未找到演示学期。');
}
$semesterId = (int) $period['id'];
$studentId = (int) $pdo->query("SELECT id FROM users WHERE username = 'student02'")->fetchColumn();
$checks = [];
$checks['closed_once'] = $period['status'] === 'closed' && $period['closed_at'] !== null;
$checks['all_offerings_recorded'] = (int) $pdo->query("SELECT COUNT(*) FROM offering_closure_results r JOIN offerings o ON o.id = r.offering_id WHERE o.semester_id = {$semesterId}")->fetchColumn() === count($offerings);
$checks['cancelled_offerings'] = $pdo->query("SELECT id FROM offerings WHERE semester_id = {$semesterId} AND status = 'cancelled' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) === ['HCI326-01', 'ML340-01', 'UX328-01'];
$checks['cancelled_seats_released'] = (int) $pdo->query("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id = e.offering_id WHERE o.semester_id = {$semesterId} AND o.status = 'cancelled' AND e.status = 'active'")->fetchColumn() === 0;
$checks['original_counts'] = $pdo->query("SELECT offering_id, original_active_count FROM offering_closure_results WHERE offering_id IN ('HCI326-01', 'ML340-01') ORDER BY offering_id")->fetchAll() === [
    ['offering_id' => 'HCI326-01', 'original_active_count' => 1],
    ['offering_id' => 'ML340-01', 'original_active_count' => 2],
];
$checks['backup_promoted'] = (int) $pdo->query("SELECT COUNT(*) FROM enrollments WHERE student_id = {$studentId} AND offering_id = 'DSP342-01' AND status = 'active' AND source = 'backup'")->fetchColumn() === 1;
$checks['student_final_seats'] = (int) $pdo->query("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id = e.offering_id WHERE e.student_id = {$studentId} AND o.semester_id = {$semesterId} AND e.status = 'active'")->fetchColumn() === 4;
$checks['final_choice_rank'] = (int) $pdo->query("SELECT COUNT(*) FROM selection_choices WHERE student_id = {$studentId} AND semester_id = {$semesterId} AND offering_id = 'DSP342-01' AND kind = 'primary' AND choice_rank = 4")->fetchColumn() === 1;
$checks['first_backup_remains'] = (int) $pdo->query("SELECT COUNT(*) FROM selection_choices WHERE student_id = {$studentId} AND semester_id = {$semesterId} AND offering_id = 'OS312-01' AND kind = 'backup'")->fetchColumn() === 1;
$checks['backup_attempt_reasons'] = $pdo->query("SELECT backup_rank, offering_id, outcome FROM closure_backup_attempts WHERE student_id = {$studentId} AND semester_id = {$semesterId} ORDER BY backup_rank")->fetchAll() === [
    ['backup_rank' => 1, 'offering_id' => 'OS312-01', 'outcome' => 'full'],
    ['backup_rank' => 2, 'offering_id' => 'DSP342-01', 'outcome' => 'promoted'],
];
$checks['capacity_respected'] = (int) $pdo->query("SELECT COUNT(*) FROM offerings o WHERE o.semester_id = {$semesterId} AND (SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = 'active') > o.capacity")->fetchColumn() === 0;

foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
