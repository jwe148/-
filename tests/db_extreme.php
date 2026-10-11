<?php
declare(strict_types=1);

// 会创建学期、账号和失败注入触发器，只能在可丢弃的测试实例运行。
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--disposable-demo') {
    fwrite(STDERR, "用法：php tests/db_extreme.php --disposable-demo\n");
    exit(1);
}
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/student_data.php';
require __DIR__ . '/../Page/teacher_data.php';
require __DIR__ . '/../Page/teacher_grades_db.php';
require __DIR__ . '/../Page/admin_closure_db.php';

$pdo = databaseConnection();
$student = (int) $pdo->query("SELECT id FROM users WHERE username='student01'")->fetchColumn();
$teacher = (int) $pdo->query("SELECT id FROM users WHERE username='teacher01'")->fetchColumn();
$admin = (int) $pdo->query("SELECT id FROM users WHERE username='admin01'")->fetchColumn();
$otherStudent = (int) $pdo->query("SELECT id FROM users WHERE username='student03'")->fetchColumn();
$fillers = array_map('intval', $pdo->query("SELECT id FROM users WHERE role='student' AND is_active=0 ORDER BY id LIMIT 10")->fetchAll(PDO::FETCH_COLUMN));
if (!$student || !$teacher || !$admin || count($fillers) < 10) {
    throw new RuntimeException('需要先导入演示测试数据。');
}
$sequence = 0;
$checks = 0;
function checkExtreme(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
    $checks++;
    echo 'PASS ' . $name . PHP_EOL;
}
function executeExtreme(string $sql, array $params = []): void
{
    global $pdo;
    $pdo->prepare($sql)->execute($params);
}
function snapshotExtreme(): array
{
    global $pdo;
    $snapshot = [];
    foreach (['users', 'semesters', 'courses', 'course_prerequisites', 'offerings', 'offering_times', 'teacher_claims', 'selection_choices', 'enrollments', 'grades', 'offering_closure_results', 'closure_backup_attempts'] as $table) {
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
        usort($rows, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        $snapshot[$table] = $rows;
    }
    return $snapshot;
}
function fixtureExtreme(bool $expired = false): array
{
    global $pdo, $teacher, $sequence;
    $prefix = 'EXT' . ++$sequence;
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    executeExtreme('INSERT INTO semesters (name, selection_starts_at, selection_ends_at) VALUES (?, ?, ?)', [$prefix, $now->modify('-2 days')->format('Y-m-d H:i:s'), $now->modify($expired ? '-1 day' : '+1 day')->format('Y-m-d H:i:s')]);
    $semesterId = (int) $pdo->lastInsertId();
    $ids = [];
    $catalog = [];
    for ($i = 0; $i < 6; $i++) {
        $id = $prefix . '-' . $i;
        $ids[] = $id;
        executeExtreme('INSERT INTO courses (code, name, description) VALUES (?, ?, ?)', [$id, $id, 'extreme test fixture']);
        executeExtreme('INSERT INTO teacher_qualifications(teacher_id,course_code) VALUES(?,?)',[$teacher,$id]);
        executeExtreme('INSERT INTO offerings (id, course_code, semester_id, class_name, teacher_id, location) VALUES (?, ?, ?, ?, ?, ?)', [$id, $id, $semesterId, '01', $teacher, 'test']);
        $start = sprintf('%02d:00:00', 8 + $i);
        $end = sprintf('%02d:00:00', 9 + $i);
        executeExtreme('INSERT INTO offering_times (offering_id, weekday, starts_at, ends_at) VALUES (?, 1, ?, ?)', [$id, $start, $end]);
        $catalog[$id] = ['code' => $id, 'name' => $id, 'teacher' => 'teacher', 'capacity' => 10, 'selected' => 0, 'status' => 'open', 'prerequisites' => [], 'times' => [['day' => '周一', 'time' => substr($start, 0, 5) . '—' . substr($end, 0, 5)]]];
    }
    return [$semesterId, $ids, $catalog];
}
function prepareClosureExtreme(array $counts = [3, 3, 3, 1, 10, 3]): array
{
    global $pdo, $student, $fillers;
    [$semesterId, $ids, $catalog] = fixtureExtreme(true);
    foreach ($ids as $index => $id) {
        $members = $index < 4 ? array_merge([$student], $fillers) : $fillers;
        foreach (array_slice($members, 0, $counts[$index]) as $member) {
            executeExtreme('INSERT INTO enrollments (student_id, offering_id) VALUES (?, ?)', [$member, $id]);
        }
        executeExtreme('INSERT INTO selection_choices (student_id, semester_id, offering_id, kind, choice_rank) VALUES (?, ?, ?, ?, ?)', [$student, $semesterId, $id, $index < 4 ? 'primary' : 'backup', $index < 4 ? $index + 1 : $index - 3]);
    }
    executeExtreme('UPDATE offerings SET teacher_id=NULL WHERE id=?', [$ids[3]]);
    return [$semesterId, $ids, $catalog];
}
function expectExceptionExtreme(callable $action, string $name): void
{
    $thrown = false;
    try {
        $action();
    } catch (PDOException | RuntimeException $error) {
        $thrown = true;
    }
    checkExtreme($thrown, $name);
}

[$semesterId, $ids, $catalog] = fixtureExtreme();
$primary = array_slice($ids, 0, 4);
$backup = array_slice($ids, 4);
checkExtreme(dbSaveStudentSelection($pdo, $student, $semesterId, $primary, $backup, $catalog) === [], 'exact 4 primary and 2 backup accepted');
$baseline = snapshotExtreme();
$invalid = [
    [[], []], [array_slice($primary, 0, 3), $backup], [array_merge($primary, [$backup[0]]), $backup],
    [$primary, []], [$primary, [$backup[0]]], [$primary, array_merge($backup, ['x'])],
    [[$primary[0], $primary[0], $primary[2], $primary[3]], $backup],
    [$primary, [$primary[0], $backup[1]]],
    [['missing', $primary[1], $primary[2], $primary[3]], $backup],
    [[str_repeat('X', 33), $primary[1], $primary[2], $primary[3]], $backup],
    [[['nested'], $primary[1], $primary[2], $primary[3]], $backup],
    [[123, $primary[1], $primary[2], $primary[3]], $backup],
    [['', $primary[1], $primary[2], $primary[3]], $backup],
];
foreach ($invalid as $index => [$p, $b]) {
    checkExtreme(dbSaveStudentSelection($pdo, $student, $semesterId, $p, $b, $catalog) !== [], 'invalid selection shape/id rejected ' . $index);
    checkExtreme(snapshotExtreme() === $baseline && !$pdo->inTransaction(), 'invalid selection preserves all rows ' . $index);
}
executeExtreme("UPDATE users SET is_active=0 WHERE id=?", [$student]);
checkExtreme(dbSaveStudentSelection($pdo, $student, $semesterId, $primary, $backup, $catalog) !== [], 'disabled student cannot save');
executeExtreme("UPDATE users SET is_active=1 WHERE id=?", [$student]);
checkExtreme(dbSaveStudentSelection($pdo, $teacher, $semesterId, $primary, $backup, $catalog) !== [], 'teacher id cannot save a student schedule');
checkExtreme(dbDropStudentOffering($pdo, $otherStudent, $semesterId, $primary[0]) !== [], 'another student cannot drop owner enrollment');
checkExtreme(snapshotExtreme() === $baseline, 'cross-user rejection preserves rows');
foreach (['future', 'expired', 'closing', 'closed'] as $state) {
    if ($state === 'future') {
        executeExtreme('UPDATE semesters SET selection_starts_at=DATE_ADD(NOW(), INTERVAL 1 DAY), selection_ends_at=DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE id=?', [$semesterId]);
    } elseif ($state === 'expired') {
        executeExtreme('UPDATE semesters SET selection_starts_at=DATE_SUB(NOW(), INTERVAL 2 DAY), selection_ends_at=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE id=?', [$semesterId]);
    } else {
        executeExtreme('UPDATE semesters SET status=? WHERE id=?', [$state, $semesterId]);
    }
    $before = snapshotExtreme();
    checkExtreme(dbSaveStudentSelection($pdo, $student, $semesterId, $primary, $backup, $catalog) !== [] && dbDropStudentOffering($pdo, $student, $semesterId, $primary[0]) !== [], 'save and drop rejected in period state ' . $state);
    checkExtreme(snapshotExtreme() === $before, 'period rejection preserves rows ' . $state);
}
executeExtreme("UPDATE semesters SET status='open', selection_starts_at=DATE_SUB(NOW(), INTERVAL 1 DAY), selection_ends_at=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id=?", [$semesterId]);
executeExtreme("CREATE TRIGGER extreme_choice_failure BEFORE INSERT ON selection_choices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected choice failure'");
$before = snapshotExtreme();
try {
    expectExceptionExtreme(fn() => dbSaveStudentSelection($pdo, $student, $semesterId, $primary, $backup, $catalog), 'selection storage failure reaches rollback path');
    checkExtreme(snapshotExtreme() === $before && !$pdo->inTransaction(), 'storage failure restores deleted old choices and seats');
} finally {
    executeExtreme('DROP TRIGGER extreme_choice_failure');
}
checkExtreme(dbSaveStudentSelection($pdo, $student, $semesterId, $primary, $backup, $catalog) === [], 'selection succeeds after failure removed');
expectExceptionExtreme(fn() => executeExtreme('UPDATE offerings SET capacity=11 WHERE id=?', [$primary[0]]), 'database rejects capacity above 10');
expectExceptionExtreme(fn() => executeExtreme('UPDATE offerings SET capacity=0 WHERE id=?', [$primary[0]]), 'database rejects zero capacity');
expectExceptionExtreme(fn() => executeExtreme('UPDATE offering_times SET weekday=8 WHERE offering_id=?', [$primary[0]]), 'database rejects invalid weekday');
expectExceptionExtreme(fn() => executeExtreme('UPDATE offering_times SET ends_at=starts_at WHERE offering_id=?', [$primary[0]]), 'database rejects zero-duration meeting');
$duplicateCatalog = $catalog;
$duplicateCatalog[$backup[0]]['code'] = $duplicateCatalog[$primary[0]]['code'];
checkExtreme(validateStudentSelection($primary, $backup, $duplicateCatalog, []) !== [], 'same course in different offerings rejected');
executeExtreme('UPDATE offerings SET teacher_id=NULL WHERE id=?', [$backup[0]]);
checkExtreme(dbChangeTeacherClaim($pdo, $teacher, $semesterId, $backup[0], 'claim') === [], 'teacher claim with adjacent meeting accepted');
checkExtreme(dbChangeTeacherClaim($pdo, $teacher, $semesterId, $backup[0], 'claim') !== [], 'duplicate claim rejected');
checkExtreme(dbChangeTeacherClaim($pdo, $teacher, $semesterId, $primary[0], 'release') !== [], 'preassigned course cannot be released');
checkExtreme(dbChangeTeacherClaim($pdo, $teacher, $semesterId, $backup[0], 'release') === [], 'own explicit claim can be released');
executeExtreme('DELETE FROM offering_times WHERE offering_id=?', [$backup[0]]);
$before = snapshotExtreme();
checkExtreme(dbChangeTeacherClaim($pdo, $teacher, $semesterId, $backup[0], 'claim') !== [], 'teacher cannot claim offering without meeting data');
checkExtreme(snapshotExtreme() === $before, 'missing meeting rejection preserves rows');

$enrollment = (int) $pdo->query("SELECT e.id FROM enrollments e JOIN users u ON u.id=e.student_id WHERE u.username='student01' AND e.offering_id='PRE-PROG-2025'")->fetchColumn();
foreach (['A', 'B', 'C', 'D', 'F', 'I', ''] as $grade) {
    checkExtreme(dbSaveTeacherGrade($pdo, $teacher, 'PRE-PROG-2025', $enrollment, $grade) === [], 'valid grade accepted ' . ($grade === '' ? 'blank' : $grade));
    $expected = in_array($grade, ['A', 'B', 'C', 'D'], true);
    checkExtreme(in_array('程序设计基础', dbCompletedCourseNames($pdo, $student), true) === $expected, 'prerequisite pass/fail evaluated for ' . ($grade === '' ? 'blank' : $grade));
}
$before = snapshotExtreme();
foreach (['Z', 'a', ' A', str_repeat('A', 100)] as $grade) {
    checkExtreme(dbSaveTeacherGrade($pdo, $teacher, 'PRE-PROG-2025', $enrollment, $grade) !== [], 'invalid grade rejected ' . substr($grade, 0, 8));
}
checkExtreme(dbSaveTeacherGrade($pdo, $student, 'PRE-PROG-2025', $enrollment, 'A') !== [], 'student cannot enter grades');
checkExtreme(dbSaveTeacherGrade($pdo, $teacher, $primary[0], $enrollment, 'A') !== [], 'current open semester cannot receive grades');
checkExtreme(dbTeacherGradeRoster($pdo, $teacher, 'SE301-01') === null, 'teacher cannot read another teacher roster');
checkExtreme(snapshotExtreme() === $before, 'invalid grade and roster requests preserve data');
executeExtreme("UPDATE grades SET grade_value='良好' WHERE enrollment_id=?", [$enrollment]);

foreach (['full', 'all_full', 'cancelled', 'missing_time', 'prerequisite', 'time_conflict', 'duplicate_course'] as $scenario) {
    [$sid, $list] = prepareClosureExtreme($scenario === 'all_full' ? [3, 3, 3, 1, 10, 10] : [3, 3, 3, 1, 3, 3]);
    $outcome = $scenario;
    if (in_array($scenario, ['full', 'all_full'], true)) {
        foreach (array_slice($fillers, 3) as $filler) {
            if ($scenario === 'full') {
                executeExtreme('INSERT INTO enrollments (student_id, offering_id) VALUES (?, ?)', [$filler, $list[4]]);
            }
        }
        $outcome = 'full';
    } elseif ($scenario === 'cancelled') {
        executeExtreme('UPDATE offerings SET teacher_id=NULL WHERE id=?', [$list[4]]);
    } elseif ($scenario === 'missing_time') {
        executeExtreme('DELETE FROM offering_times WHERE offering_id=?', [$list[4]]);
    } elseif ($scenario === 'prerequisite') {
        executeExtreme('INSERT INTO course_prerequisites (course_code, prerequisite_code) VALUES (?, ?)', [$list[4], $list[5]]);
    } elseif ($scenario === 'time_conflict') {
        executeExtreme("UPDATE offering_times SET starts_at='08:00:00', ends_at='09:00:00' WHERE offering_id=?", [$list[4]]);
    } elseif ($scenario === 'duplicate_course') {
        executeExtreme('UPDATE offerings SET course_code=? WHERE id=?', [$list[0], $list[4]]);
    }
    $result = dbCloseSemester($pdo, $admin, $sid);
    checkExtreme($result === ['changed' => true, 'errors' => []], 'closure completes with backup case ' . $scenario);
    $query = $pdo->prepare('SELECT outcome FROM closure_backup_attempts WHERE student_id=? AND semester_id=? ORDER BY backup_rank');
    $query->execute([$student, $sid]);
    checkExtreme($query->fetchAll(PDO::FETCH_COLUMN) === [$outcome, $scenario === 'all_full' ? 'full' : 'promoted'], 'backup reason and order recorded ' . $scenario);
    $query = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE e.student_id=? AND o.semester_id=? AND e.status='active'");
    $query->execute([$student, $sid]);
    checkExtreme((int) $query->fetchColumn() === ($scenario === 'all_full' ? 3 : 4), 'final seats match successful substitutions ' . $scenario);
    $before = snapshotExtreme();
    checkExtreme(dbCloseSemester($pdo, $admin, $sid) === ['changed' => false, 'errors' => []] && snapshotExtreme() === $before, 'repeated closure preserves all rows ' . $scenario);
}

[$sid, $list] = fixtureExtreme(true);
foreach ([0, 2, 3, 10, 0, 0] as $index => $count) {
    foreach (array_slice($fillers, 0, $count) as $filler) {
        executeExtreme('INSERT INTO enrollments (student_id, offering_id) VALUES (?, ?)', [$filler, $list[$index]]);
    }
}
checkExtreme(dbCloseSemester($pdo, $admin, $sid)['changed'], 'closure accepts 0, 2, 3, 10 student boundary fixtures');
$query = $pdo->prepare('SELECT original_active_count, outcome FROM offering_closure_results WHERE offering_id IN (?, ?, ?, ?) ORDER BY offering_id');
$query->execute(array_slice($list, 0, 4));
checkExtreme($query->fetchAll() === [['original_active_count' => 0, 'outcome' => 'under_minimum'], ['original_active_count' => 2, 'outcome' => 'under_minimum'], ['original_active_count' => 3, 'outcome' => 'confirmed'], ['original_active_count' => 10, 'outcome' => 'confirmed']], 'only classes with at least three students remain confirmed');

[$sid, $list] = prepareClosureExtreme();
executeExtreme("CREATE TRIGGER extreme_close_failure BEFORE INSERT ON offering_closure_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected closure failure'");
$before = snapshotExtreme();
try {
    expectExceptionExtreme(fn() => dbCloseSemester($pdo, $admin, $sid), 'closure result write failure reaches rollback path');
    checkExtreme(snapshotExtreme() === $before && !$pdo->inTransaction(), 'closure failure restores status seats choices backup attempts');
} finally {
    executeExtreme('DROP TRIGGER extreme_close_failure');
}
checkExtreme(dbCloseSemester($pdo, $admin, $sid)['changed'], 'closure succeeds after failure removed');

[$sid, $list] = fixtureExtreme(true);
$before = snapshotExtreme();
checkExtreme(dbCloseSemester($pdo, $student, $sid)['errors'] !== [] && snapshotExtreme() === $before, 'non-admin closure preserves all rows');
executeExtreme('DELETE FROM offering_times WHERE offering_id IN (SELECT id FROM offerings WHERE semester_id=?)', [$sid]);
executeExtreme('DELETE FROM offerings WHERE semester_id=?', [$sid]);
$before = snapshotExtreme();
expectExceptionExtreme(fn() => dbCloseSemester($pdo, $admin, $sid), 'empty semester closure rejected');
checkExtreme(snapshotExtreme() === $before, 'empty semester rejection restores open state');

[$sid, $list] = prepareClosureExtreme();
executeExtreme("UPDATE enrollments SET status='dropped' WHERE student_id=? AND offering_id=?", [$student, $list[0]]);
$before = snapshotExtreme();
expectExceptionExtreme(fn() => dbCloseSemester($pdo, $admin, $sid), 'inconsistent primary choice and seat rejected');
checkExtreme(snapshotExtreme() === $before, 'inconsistent seat rejection preserves entire database');

[$sid, $list] = fixtureExtreme(true);
foreach (array_merge([$student], $fillers) as $member) {
    executeExtreme('INSERT INTO enrollments (student_id, offering_id) VALUES (?, ?)', [$member, $list[0]]);
}
$before = snapshotExtreme();
expectExceptionExtreme(fn() => dbCloseSemester($pdo, $admin, $sid), 'corrupt class with eleven active students rejected');
checkExtreme(snapshotExtreme() === $before, 'over-capacity corruption does not partially close semester');

// 原题补齐规则回归：已有退课缺位也应补齐；人数下限在补位后判定。
[$sid, $list] = prepareClosureExtreme([3, 3, 3, 3, 3, 3]);
executeExtreme('UPDATE offerings SET teacher_id=? WHERE id=?', [$teacher, $list[3]]);
executeExtreme("UPDATE enrollments SET status='dropped' WHERE student_id=? AND offering_id=?", [$student, $list[3]]);
executeExtreme('DELETE FROM selection_choices WHERE student_id=? AND semester_id=? AND offering_id=?', [$student, $sid, $list[3]]);
dbCloseSemester($pdo, $admin, $sid);
$query = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE e.student_id=? AND o.semester_id=? AND e.status='active'");
$query->execute([$student, $sid]);
checkExtreme((int) $query->fetchColumn() === 4, 'closure levels a preexisting dropped-primary gap');
$query = $pdo->prepare("SELECT offering_id, choice_rank FROM selection_choices WHERE student_id=? AND semester_id=? AND offering_id=? AND kind='primary'");
$query->execute([$student, $sid, $list[4]]);
checkExtreme($query->fetch() === ['offering_id'=>$list[4], 'choice_rank'=>4], 'dropped-primary replacement occupies the original missing rank');
[$sid, $list] = prepareClosureExtreme([3, 3, 3, 1, 2, 3]);
dbCloseSemester($pdo, $admin, $sid);
$query = $pdo->prepare('SELECT outcome FROM closure_backup_attempts WHERE student_id=? AND semester_id=? AND backup_rank=1');
$query->execute([$student, $sid]);
checkExtreme($query->fetchColumn() === 'promoted', 'leveling promotes the two-student first backup');
$query = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE offering_id=? AND status='active'");
$query->execute([$list[4]]);
checkExtreme((int)$query->fetchColumn() === 3, 'promoted first backup reaches the minimum of three');
$query = $pdo->prepare('SELECT status FROM offerings WHERE id=?');
$query->execute([$list[4]]);
checkExtreme($query->fetchColumn() === 'confirmed', 'rescued first backup remains confirmed');
$before = snapshotExtreme();
checkExtreme(dbCloseSemester($pdo,$admin,$sid) === ['changed'=>false,'errors'=>[]] && snapshotExtreme() === $before, 'rescued class remains unchanged on repeated closure');

// 第一轮补入后仍未达下限时，恢复备选记录并继续尝试第二备选。
[$sid, $list] = prepareClosureExtreme([3, 3, 3, 1, 1, 3]);
dbCloseSemester($pdo,$admin,$sid);
$query = $pdo->prepare('SELECT outcome FROM closure_backup_attempts WHERE student_id=? AND semester_id=? ORDER BY backup_rank');
$query->execute([$student,$sid]);
checkExtreme($query->fetchAll(PDO::FETCH_COLUMN) === ['cancelled','promoted'], 'provisional promotion cancelled below three and second backup tried');
$query = $pdo->prepare("SELECT kind,choice_rank FROM selection_choices WHERE student_id=? AND semester_id=? AND offering_id=?");
$query->execute([$student,$sid,$list[4]]);
checkExtreme($query->fetch() === ['kind'=>'backup','choice_rank'=>1], 'cancelled provisional promotion restores original backup rank');
$query->execute([$student,$sid,$list[5]]);
checkExtreme($query->fetch() === ['kind'=>'primary','choice_rank'=>4], 'second backup fills rank released by cancelled provisional promotion');
$query = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE offering_id=? AND status='active'");
$query->execute([$list[4]]);
checkExtreme((int)$query->fetchColumn() === 0, 'cancelled provisional class has no active seats');

[$sid, $list] = prepareClosureExtreme([3, 3, 3, 1, 1, 3]);
executeExtreme("CREATE TRIGGER extreme_two_phase_failure BEFORE INSERT ON offering_closure_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected two phase failure'");
$before = snapshotExtreme();
try {
    expectExceptionExtreme(fn()=>dbCloseSemester($pdo,$admin,$sid), 'two-phase closure fault reaches rollback');
    checkExtreme(snapshotExtreme() === $before && !$pdo->inTransaction(), 'two-phase rollback restores provisional and second-backup promotions');
} finally {
    executeExtreme('DROP TRIGGER extreme_two_phase_failure');
}
checkExtreme(dbCloseSemester($pdo,$admin,$sid)['changed'], 'two-phase closure recovers after removing failure');

// 学生有三门，但其中一门在第一轮补齐后仍只有两人，最终停开后补第二个空位。
[$sid,$list] = prepareClosureExtreme([3,3,2,3,3,3]);
executeExtreme('UPDATE offerings SET teacher_id=? WHERE id=?',[$teacher,$list[3]]);
executeExtreme("UPDATE enrollments SET status='dropped' WHERE student_id=? AND offering_id=?",[$student,$list[3]]);
executeExtreme('DELETE FROM selection_choices WHERE student_id=? AND semester_id=? AND offering_id=?',[$student,$sid,$list[3]]);
dbCloseSemester($pdo,$admin,$sid);
$query = $pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE e.student_id=? AND o.semester_id=? AND e.status='active'");
$query->execute([$student,$sid]);
checkExtreme((int)$query->fetchColumn() === 4, 'late primary cancellation fills new gap with remaining backup');
$query = $pdo->prepare("SELECT choice_rank FROM selection_choices WHERE student_id=? AND semester_id=? AND kind='primary' ORDER BY choice_rank");
$query->execute([$student,$sid]);
checkExtreme(array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN)) === [1,2,3,4], 'two filling passes leave exactly four unique primary ranks');
echo "CHECKS $checks\n";
