<?php
declare(strict_types=1);

// 只操作运行器创建的独立 MySQL 实例。
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--disposable-demo', '--closure'], true)) { exit(1); }
require __DIR__ . '/../Page/student_data.php';
require __DIR__ . '/../Page/data/catalog_runtime.php';
$pdo = databaseConnection();
$expected = getenv('CR_TEST_DATADIR');
if (!$expected || realpath($expected) !== realpath($pdo->query('SELECT @@datadir')->fetchColumn())) {
    throw new RuntimeException('Refusing tests outside the isolated MySQL instance');
}
$student = (int) $pdo->query("SELECT id FROM users WHERE username='student02'")->fetchColumn();
$semester = $selectionPeriod['semester_id'];
$primary = ['SE301-01', 'DB305-01', 'CN309-01', 'AI320-01'];
function limitCheck(bool $passed, string $name): void {
    if (!$passed) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . PHP_EOL;
}
function limitSnapshot(PDO $pdo, int $student): array {
    $result = [];
    foreach (['selection_choices', 'enrollments', 'selection_drafts'] as $table) {
        $result[$table] = $pdo->query("SELECT * FROM $table WHERE student_id=$student ORDER BY 1")->fetchAll();
    }
    return $result;
}
if (($argv[1] ?? '') === '--closure') {
    require __DIR__ . '/../Page/admin_closure_db.php';
    $admin = (int) $pdo->query("SELECT id FROM users WHERE username='admin01'")->fetchColumn();
    foreach ([[3, 0], [3, 1], [3, 2], [2, 2], [1, 2], [0, 2], [4, 2]] as [$count, $backupCount]) {
        $choices = array_slice($primary, 0, $count);
        $backups = array_slice(['WEB337-01', 'DSP342-01'], 0, $backupCount);
        limitCheck(dbSaveStudentSelection($pdo, $student, $semester, $choices, $backups, $offerings) === [], "save $count primary + $backupCount backup");
        $closed = dbCloseSemester($pdo, $admin, $semester);
        limitCheck($closed['changed'] && $closed['errors'] === [], "close registration for $count+$backupCount");
        $promotedCount = min(4 - $count, $backupCount);
        $q = $pdo->prepare("SELECT e.offering_id, e.source FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE e.student_id=? AND o.semester_id=? AND e.status='active' ORDER BY e.offering_id");
        $q->execute([$student, $semester]);
        $rows = $q->fetchAll();
        $expectedIds = array_merge($choices, array_slice($backups, 0, $promotedCount));
        sort($expectedIds);
        limitCheck(array_column($rows, 'offering_id') === $expectedIds, "$count+$backupCount final schedule respects four-course maximum and backup order");
        limitCheck(count(array_filter($rows, static fn($row): bool => $row['source'] === 'backup')) === $promotedCount, "$count+$backupCount eligible backups become registered courses");
        $plan = dbStudentSelection($pdo, $student, $semester);
        limitCheck(count($plan['primary']) === count($expectedIds) && count($plan['backup']) === $backupCount - $promotedCount, "$count+$backupCount promoted choices move from backup to primary");
        limitCheck(dbReopenSemester($pdo, $admin, $semester) === [], "$count+$backupCount restore before next isolated case");
    }
    limitCheck(dbSaveStudentSelection($pdo, $student, $semester, array_slice($primary, 0, 3), ['OS312-01', 'DSP342-01'], $offerings) === [], 'save three primary with full first backup');
    $closed = dbCloseSemester($pdo, $admin, $semester);
    limitCheck($closed['changed'] && $closed['errors'] === [], 'close with unavailable first backup');
    $q = $pdo->prepare('SELECT outcome FROM closure_backup_attempts WHERE student_id=? AND semester_id=? ORDER BY backup_rank');
    $q->execute([$student, $semester]);
    limitCheck($q->fetchAll(PDO::FETCH_COLUMN) === ['full', 'promoted'], 'skip full first backup and promote eligible second backup');
    limitCheck(dbStudentSelection($pdo, $student, $semester)['primary'] === ['SE301-01', 'DB305-01', 'CN309-01', 'DSP342-01'], 'three primary plus eligible second backup form four registered courses');
    exit(0);
}
$active = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE e.student_id=? AND o.semester_id=? AND e.status='active'");
foreach (range(0, 4) as $count) {
    $choices = array_slice($primary, 0, $count);
    limitCheck(dbSaveStudentSelection($pdo, $student, $semester, $choices, [], $offerings) === [], "submit $count primary without backup");
    $active->execute([$student, $semester]);
    limitCheck((int) $active->fetchColumn() === $count && dbStudentSelection($pdo, $student, $semester) === ['primary'=>$choices, 'backup'=>[]], "persist exactly $count courses");
}
$before = limitSnapshot($pdo, $student);
foreach ([
    [array_merge($primary, ['WEB337-01']), [], 'five primary'],
    [[], ['WEB337-01', 'DSP342-01', 'OS312-01'], 'three backup'],
    [[[]], [], 'nested id'],
    [['SE301-01', 'SEC335-01'], [], 'partial time conflict'],
    [['ML340-01'], [], 'partial missing prerequisite'],
    [['SE301-01'], ['SE301-01'], 'duplicate'],
] as [$choices, $backup, $name]) {
    limitCheck(dbSaveStudentSelection($pdo, $student, $semester, $choices, $backup, $offerings) !== [], "$name rejected");
    limitCheck(limitSnapshot($pdo, $student) === $before, "$name preserves original plan and seats");
}
limitCheck(dbSaveStudentSelection($pdo, $student, $semester, ['', 'SE301-01', '', 'CN309-01'], ['', 'DSP342-01'], $offerings) === [], 'blank slots accepted');
limitCheck(dbStudentSelection($pdo, $student, $semester) === ['primary'=>['SE301-01', 'CN309-01'], 'backup'=>['DSP342-01']], 'blank slots omitted and choice order preserved');
$active->execute([$student, $semester]);
limitCheck((int) $active->fetchColumn() === 2, 'reducing selection releases old seats');
limitCheck(dbSaveStudentSelection($pdo, $student, $semester, ['', '', '', ''], ['', ''], $offerings) === [], 'empty form clears existing plan');
$active->execute([$student, $semester]);
limitCheck((int) $active->fetchColumn() === 0 && dbStudentSelection($pdo, $student, $semester) === ['primary'=>[], 'backup'=>[]], 'empty plan releases all seats');
limitCheck(dbSaveStudentSelection($pdo, $student, $semester, [], [], $offerings) === [], 'repeated empty submission without locked offerings');
