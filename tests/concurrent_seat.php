<?php
declare(strict_types=1);

// 只在全新、可丢弃的样例数据库运行：会给 student03 写入两门历史成绩。
require __DIR__ . '/../Page/data/catalog_runtime.php';
require __DIR__ . '/../Page/student_data.php';

$mode = $argv[1] ?? '';
if ($mode === '--worker') {
    $studentId = (int) ($argv[2] ?? 0);
    $semesterId = (int) ($argv[3] ?? 0);
    $gate = $argv[4] ?? '';
    $deadline = microtime(true) + 10;
    while (!is_file($gate) && microtime(true) < $deadline) {
        usleep(1000);
    }
    if (!is_file($gate)) {
        fwrite(STDERR, "等待并发开始信号超时。\n");
        exit(2);
    }
    try {
        $pdo = databaseConnection();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');
        $errors = dbSaveStudentSelection(
            $pdo,
            $studentId,
            $semesterId,
            ['SE301-01', 'DB305-01', 'CN309-01', 'AI320-01'],
            ['OS312-01', 'DSP342-01'],
            $offerings
        );
        echo json_encode(['student_id' => $studentId, 'errors' => $errors], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . PHP_EOL);
        exit(2);
    }
    exit(0);
}

if ($mode !== '--test-db' || !databaseModeEnabled()) {
    fwrite(STDERR, "用法：设置 CR_DB_* 后，在全新样例测试库运行 php tests/concurrent_seat.php --test-db\n");
    exit(1);
}

$pdo = databaseConnection();
$semesterId = $selectionPeriod['semester_id'];
$ids = [];
foreach (['student02', 'student03'] as $username) {
    $query = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role = 'student' AND is_active = 1");
    $query->execute([$username]);
    $id = (int) $query->fetchColumn();
    if ($id === 0 || dbStudentSelection($pdo, $id, $semesterId) !== ['primary' => [], 'backup' => []]) {
        fwrite(STDERR, "请使用全新导入的样例测试库。\n");
        exit(1);
    }
    $ids[] = $id;
}
$seatQuery = $pdo->query("SELECT COUNT(*) FROM enrollments WHERE offering_id = 'DB305-01' AND status = 'active'");
if ((int) $seatQuery->fetchColumn() !== 9 || !selectionOpen($selectionPeriod)) {
    fwrite(STDERR, "测试库的 DB305-01 须剩余一席，且选课期须开放。\n");
    exit(1);
}

// student02 已有先修成绩；给 student03 建立同样的历史资格。
$historyQuery = $pdo->prepare('SELECT id FROM offerings WHERE id = ?');
$enrollmentInsert = $pdo->prepare("INSERT INTO enrollments (student_id, offering_id, status, source)
    VALUES (?, ?, 'active', 'primary')");
$gradeInsert = $pdo->prepare('INSERT INTO grades (enrollment_id, grade_value) VALUES (?, ?)');
$pdo->beginTransaction();
try {
    foreach (['PRE-PROG-2025', 'PRE-DS-2025'] as $offeringId) {
        $historyQuery->execute([$offeringId]);
        if (!$historyQuery->fetchColumn()) {
            throw new RuntimeException('样例历史课程不存在：' . $offeringId);
        }
        $enrollmentInsert->execute([$ids[1], $offeringId]);
        $gradeInsert->execute([(int) $pdo->lastInsertId(), '及格']);
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}

$gate = tempnam(sys_get_temp_dir(), 'cr_race_');
if ($gate === false) {
    throw new RuntimeException('无法建立并发测试信号文件。');
}
unlink($gate);
$workers = [];
try {
    foreach ($ids as $id) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__, '--worker', (string) $id, (string) $semesterId, $gate],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动并发测试进程。');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    usleep(300000);
    file_put_contents($gate, 'start');
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('并发进程失败：' . trim($error));
        }
        $result = json_decode($output, true);
        if (!is_array($result)) {
            throw new RuntimeException('并发进程没有返回结果：' . $output);
        }
        $results[] = $result;
    }
} finally {
    if (is_file($gate)) {
        unlink($gate);
    }
}

$winner = null;
$loser = null;
foreach ($results as $result) {
    if ($result['errors'] === []) {
        $winner = (int) $result['student_id'];
    } elseif (str_contains(implode(' ', $result['errors']), '已满额')) {
        $loser = (int) $result['student_id'];
    }
}
$checks = [
    'one_winner_one_full' => $winner !== null && $loser !== null && $winner !== $loser,
    'capacity_not_exceeded' => (int) $pdo->query("SELECT COUNT(*) FROM enrollments WHERE offering_id = 'DB305-01' AND status = 'active'")->fetchColumn() === 10,
];
if ($winner !== null && $loser !== null) {
    $activeQuery = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id = e.offering_id
        WHERE e.student_id = ? AND o.semester_id = ? AND e.status = 'active'");
    $activeQuery->execute([$winner, $semesterId]);
    $winnerSeats = (int) $activeQuery->fetchColumn();
    $activeQuery->execute([$loser, $semesterId]);
    $loserSeats = (int) $activeQuery->fetchColumn();
    $checks['winner_has_four_seats'] = $winnerSeats === 4;
    $checks['loser_has_no_partial_seats'] = $loserSeats === 0;
    $checks['loser_has_no_partial_choices'] = dbStudentSelection($pdo, $loser, $semesterId) === ['primary' => [], 'backup' => []];
}
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
