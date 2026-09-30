<?php
declare(strict_types=1);

require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/data/catalog.php';

$pdo = databaseConnection();
$checks = [];
$semesterStatement = $pdo->prepare('SELECT id FROM semesters WHERE name = ?');
$semesterStatement->execute([$semester]);
$semesterId = (int) $semesterStatement->fetchColumn();
$studentId = (int) $pdo->query("SELECT id FROM users WHERE username = 'student01'")->fetchColumn();
$checks['semesters'] = (int) $pdo->query('SELECT COUNT(*) FROM semesters')->fetchColumn() === 2;
$checks['current_offerings'] = (int) $pdo->query("SELECT COUNT(*) FROM offerings WHERE semester_id = {$semesterId}")->fetchColumn() === count($offerings);
$checks['historical_offerings'] = (int) $pdo->query("SELECT COUNT(*) FROM offerings WHERE semester_id <> {$semesterId}")->fetchColumn() === 2;
$expectedSeats = array_sum(array_column($offerings, 'selected'));
$checks['current_active_seats'] = (int) $pdo->query("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id = e.offering_id WHERE o.semester_id = {$semesterId} AND e.status = 'active'")->fetchColumn() === $expectedSeats;
$checks['full_class'] = (int) $pdo->query("SELECT COUNT(*) FROM enrollments WHERE offering_id = 'OS312-01' AND status = 'active'")->fetchColumn() === 10;
$checks['unassigned_teachers'] = (int) $pdo->query("SELECT COUNT(*) FROM offerings WHERE semester_id = {$semesterId} AND teacher_id IS NULL")->fetchColumn() === 2;
$checks['no_seed_teacher_claims'] = (int) $pdo->query('SELECT COUNT(*) FROM teacher_claims')->fetchColumn() === 0;
$checks['prerequisites'] = (int) $pdo->query('SELECT COUNT(*) FROM course_prerequisites')->fetchColumn() === 7;
$checks['student_choices'] = $pdo->query("SELECT kind, choice_rank, offering_id FROM selection_choices WHERE student_id = {$studentId} ORDER BY FIELD(kind, 'backup', 'primary'), choice_rank")->fetchAll() === [
    ['kind' => 'backup', 'choice_rank' => 1, 'offering_id' => 'OS312-01'],
    ['kind' => 'backup', 'choice_rank' => 2, 'offering_id' => 'DSP342-01'],
    ['kind' => 'primary', 'choice_rank' => 1, 'offering_id' => 'SE301-01'],
    ['kind' => 'primary', 'choice_rank' => 2, 'offering_id' => 'DB305-01'],
    ['kind' => 'primary', 'choice_rank' => 3, 'offering_id' => 'CN309-01'],
    ['kind' => 'primary', 'choice_rank' => 4, 'offering_id' => 'AI320-01'],
];
$checks['student_seats'] = (int) $pdo->query("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id = e.offering_id WHERE e.student_id = {$studentId} AND o.semester_id = {$semesterId} AND e.status = 'active'")->fetchColumn() === 4;
$checks['historical_grades'] = $pdo->query("SELECT c.name, g.grade_value FROM grades g JOIN enrollments e ON e.id = g.enrollment_id JOIN offerings o ON o.id = e.offering_id JOIN courses c ON c.code = o.course_code WHERE e.student_id = {$studentId} ORDER BY c.code")->fetchAll() === [
    ['name' => '数据结构', 'grade_value' => '优秀'],
    ['name' => '程序设计基础', 'grade_value' => '良好'],
];
$checks['inactive_placeholders'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND is_active = 0")->fetchColumn() === $expectedSeats - 4;
$checks['demo_login_hash'] = password_verify(
    'Demo@2026',
    (string) $pdo->query("SELECT password_hash FROM users WHERE username = 'student01'")->fetchColumn()
);
$checks['disabled_teacher'] = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'import_teacher_01' AND is_active = 0")->fetchColumn() === 1;

foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
