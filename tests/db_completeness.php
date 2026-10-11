<?php
declare(strict_types=1);

// 仅允许测试运行器的独立实例；会创建测试数据。
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/student_data.php';
require __DIR__ . '/../Page/teacher_data.php';
require __DIR__ . '/../Page/teacher_grades_db.php';
require __DIR__ . '/../Page/admin_closure_db.php';

if (!in_array($argv[1] ?? '', ['--worker', '--disposable-demo'], true)) { exit(1); }
$pdo = databaseConnection();
$expectedDir = getenv('CR_TEST_DATADIR');
$actualDir = $pdo->query('SELECT @@datadir')->fetchColumn();
if (!$expectedDir || realpath($expectedDir) !== realpath($actualDir)) {
    throw new RuntimeException('Refusing tests outside the isolated MySQL instance');
}
if (($argv[1] ?? '') === '--worker') {
    [$mode, $sid, $oid, $actor, $gate] = array_slice($argv, 2);
    $until = microtime(true) + 10;
    while (!is_file($gate) && microtime(true) < $until) { usleep(1000); }
    if (!is_file($gate)) { exit(2); }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $result = $mode === 'claim'
        ? dbChangeTeacherClaim($pdo, (int) $actor, (int) $sid, $oid, 'claim')
        : dbCloseSemester($pdo, (int) $actor, (int) $sid);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit(0);
}
if (($argv[1] ?? '') !== '--disposable-demo') { exit(1); }
$checks = 0;
function completeCheck(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . PHP_EOL;
    $checks++;
}
function completeSql(string $sql, array $params = []): void {
    global $pdo;
    $pdo->prepare($sql)->execute($params);
}
function completeSnapshot(): array {
    global $pdo;
    $result = [];
    foreach (['users','semesters','courses','course_prerequisites','offerings','offering_times','teacher_claims','selection_choices','enrollments','grades','offering_closure_results','closure_backup_attempts'] as $table) {
        $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll();
        usort($rows, static fn(array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        $result[$table] = $rows;
    }
    return $result;
}
function completeProcess(array $command): array {
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start worker'); }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}
function completeRace(string $kind, int $sid, string $oid, array $actors): array {
    $gate = tempnam(sys_get_temp_dir(), 'cr_complete_');
    if ($gate === false) { throw new RuntimeException('Cannot create test gate'); }
    unlink($gate);
    $workers = [];
    try {
        foreach ($actors as $actor) {
            $process = proc_open([PHP_BINARY, __FILE__, '--worker', $kind, (string)$sid, $oid, (string)$actor, $gate], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start race worker'); }
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        usleep(300000); file_put_contents($gate, 'start');
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($process);
            if ($code !== 0) { throw new RuntimeException('Race worker failed: ' . $err); }
            $result = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $result;
        }
        return $results;
    } finally {
        if (is_file($gate)) { unlink($gate); }
    }
}

$baseline = completeSnapshot();
foreach ([[], ['--wrong'], ['--demo']] as $arguments) {
    [$code] = completeProcess(array_merge([PHP_BINARY, __DIR__ . '/../database/import_demo.php'], $arguments));
    completeCheck($code === 1 && completeSnapshot() === $baseline, 'import refuses missing/wrong flag or existing data: ' . implode(' ', $arguments));
}

$students = array_map('intval', $pdo->query("SELECT id FROM users WHERE username IN ('student01','student02','student03') ORDER BY username")->fetchAll(PDO::FETCH_COLUMN));
$teacher = (int)$pdo->query("SELECT id FROM users WHERE username='teacher01'")->fetchColumn();
$admin = (int)$pdo->query("SELECT id FROM users WHERE username='admin01'")->fetchColumn();
completeSql("INSERT INTO users (username,display_name,role,password_hash,is_active) VALUES ('complete_teacher','Complete teacher','teacher','!',1)");
$teacher2 = (int)$pdo->lastInsertId();
completeSql("INSERT INTO users (username,display_name,role,password_hash,is_active) VALUES ('complete_admin','Complete admin','admin','!',1)");
$admin2 = (int)$pdo->lastInsertId();
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
completeSql('INSERT INTO semesters (name,selection_starts_at,selection_ends_at) VALUES (?,?,?)', ['COMPLETE', $now->modify('-2 days')->format('Y-m-d H:i:s'), $now->modify('+1 day')->format('Y-m-d H:i:s')]);
$sid = (int)$pdo->lastInsertId();
$catalog = []; $ids = [];
for ($i=0; $i<6; $i++) {
    $id = 'COMPLETE-' . $i; $ids[] = $id;
    completeSql('INSERT INTO courses (code,name,description) VALUES (?,?,?)', [$id, $id, 'end-to-end fixture']);
    completeSql("INSERT INTO offerings (id,course_code,semester_id,class_name,location) VALUES (?,?,?,'01','test')", [$id,$id,$sid]);
    completeSql('INSERT INTO offering_times (offering_id,weekday,starts_at,ends_at) VALUES (?,1,?,?)', [$id,sprintf('%02d:00:00',8+$i),sprintf('%02d:00:00',9+$i)]);
    $catalog[$id] = ['code'=>$id,'name'=>$id,'teacher'=>'待认领','capacity'=>10,'selected'=>0,'status'=>'open','prerequisites'=>[],'times'=>[['day'=>'周一','time'=>sprintf('%02d:00—%02d:00',8+$i,9+$i)]]];
}
$primary = array_slice($ids,0,4); $backup = array_slice($ids,4);
foreach ($students as $student) {
    completeCheck(dbSaveStudentSelection($pdo,$student,$sid,$primary,$backup,$catalog) === [], 'end-to-end each student saves four plus two: ' . $student);
}
completeCheck((int)$pdo->query("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE o.semester_id=$sid AND e.status='active'")->fetchColumn() === 12, 'end-to-end only primary choices occupy seats');

// 同一业务的两次并行提交，仅验证一致性，不测试流量容量。
$race = completeRace('claim',$sid,$ids[0],[$teacher,$teacher2]);
completeCheck(count(array_filter($race, static fn(array $r): bool => $r === [])) === 1, 'simultaneous teacher claim has exactly one winner');
$winner = (int)$pdo->query("SELECT teacher_id FROM offerings WHERE id='COMPLETE-0'")->fetchColumn();
$claim = $pdo->query("SELECT teacher_id FROM teacher_claims WHERE offering_id='COMPLETE-0'")->fetchAll(PDO::FETCH_COLUMN);
completeCheck(array_map('intval',$claim) === [$winner], 'claim record and offering agree after simultaneous claim');
foreach (array_slice($ids,1,3) as $id) {
    completeCheck(dbChangeTeacherClaim($pdo,$winner,$sid,$id,'claim') === [], 'end-to-end teacher claims adjacent class ' . $id);
}

// 第二个上课时段必须参与冲突检查，而非只检查第一个时段。
completeSql("INSERT INTO offering_times (offering_id,weekday,starts_at,ends_at) VALUES (?,1,'08:30:00','08:45:00')", [$ids[4]]);
$before = completeSnapshot();
completeCheck(dbChangeTeacherClaim($pdo,$winner,$sid,$ids[4],'claim') !== [] && completeSnapshot() === $before, 'second meeting conflict rejects teacher claim without mutation');
$changed = $catalog;
$changed[$ids[4]]['times'][] = ['day'=>'周一','time'=>'08:30—08:45'];
$swapped = [$ids[0],$ids[1],$ids[2],$ids[4]];
completeCheck(dbSaveStudentSelection($pdo,$students[0],$sid,$swapped,[$ids[3],$ids[5]],$changed) !== [] && completeSnapshot() === $before, 'second meeting conflict rejects student update and preserves previous seats');
completeSql('DELETE FROM offering_times WHERE offering_id=? AND starts_at=?',[$ids[4],'08:30:00']);

completeCheck(dbDropStudentOffering($pdo,$students[0],$sid,$ids[3]) === [], 'end-to-end drop releases one seat');
completeCheck(dbDropStudentOffering($pdo,$students[0],$sid,$ids[3]) !== [], 'repeated drop is rejected');
completeCheck(dbSaveStudentSelection($pdo,$students[0],$sid,$primary,$backup,$catalog) === [], 'end-to-end resave restores dropped seat without duplicates');
completeCheck((int)$pdo->query("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE o.semester_id=$sid AND e.status='active'")->fetchColumn() === 12, 'resave returns total active seats to twelve');

completeCheck(dbTeacherGradeRoster($pdo,$winner,$ids[0]) === null, 'end-to-end current open course unavailable for grades');
completeSql('UPDATE semesters SET selection_ends_at=? WHERE id=?',[$now->modify('-1 day')->format('Y-m-d H:i:s'),$sid]);
$race = completeRace('close',$sid,'',[$admin,$admin2]);
completeCheck(count(array_filter($race, static fn(array $r): bool => $r === ['changed'=>true,'errors'=>[]])) === 1 && count(array_filter($race, static fn(array $r): bool => $r === ['changed'=>false,'errors'=>[]])) === 1, 'simultaneous closure commits once and returns idempotent second result');
completeCheck((int)$pdo->query("SELECT COUNT(*) FROM offering_closure_results r JOIN offerings o ON o.id=r.offering_id WHERE o.semester_id=$sid")->fetchColumn() === 6, 'simultaneous closure records each offering once');
completeCheck($pdo->query("SELECT status FROM semesters WHERE id=$sid")->fetchColumn() === 'closed', 'end-to-end semester becomes closed');
completeCheck((int)$pdo->query("SELECT COUNT(*) FROM offerings WHERE semester_id=$sid AND status='confirmed'")->fetchColumn() === 4, 'end-to-end four classes of three students confirmed');
$before = completeSnapshot();
completeCheck(dbSaveStudentSelection($pdo,$students[0],$sid,$primary,$backup,$catalog) !== [] && dbDropStudentOffering($pdo,$students[0],$sid,$ids[0]) !== [] && dbChangeTeacherClaim($pdo,$winner,$sid,$ids[5],'claim') !== [] && completeSnapshot() === $before, 'end-to-end closed semester blocks all enrollment/claim changes');

$roster = dbTeacherGradeRoster($pdo,$winner,$ids[0]);
completeCheck(is_array($roster) && count($roster['students']) === 3, 'end-to-end new closed course roster contains three students');
$row = array_values(array_filter($roster['students'],static fn(array $r): bool => $r['username'] === 'student01'))[0];
$enrollmentId = (int)$row['enrollment_id'];
$otherBefore = dbStudentGrades($pdo,$students[1]);
completeCheck(dbSaveTeacherGrade($pdo,$winner,$ids[0],$enrollmentId,'A') === [], 'end-to-end teacher enters new course grade');
$own = array_values(array_filter(dbStudentGrades($pdo,$students[0]), static fn(array $r): bool => $r['course'] === 'COMPLETE-0'));
completeCheck(count($own) === 1 && $own[0]['grade'] === 'A', 'end-to-end student sees newly entered grade');
completeCheck(dbStudentGrades($pdo,$students[1]) === $otherBefore, 'end-to-end another student cannot see owner grade');
completeCheck(dbSaveTeacherGrade($pdo,$winner,$ids[0],$enrollmentId,'B') === [] && (int)$pdo->query("SELECT COUNT(*) FROM grades WHERE enrollment_id=$enrollmentId")->fetchColumn() === 1, 'end-to-end grade correction updates one row');
completeCheck(dbSaveTeacherGrade($pdo,$winner,$ids[0],$enrollmentId,'') === [], 'end-to-end grade can be cleared');
completeCheck(!in_array('COMPLETE-0',array_column(dbStudentGrades($pdo,$students[0]),'course'),true), 'cleared grade disappears from student published grades');
$before = completeSnapshot();
completeCheck(dbCloseSemester($pdo,$admin,$sid) === ['changed'=>false,'errors'=>[]] && completeSnapshot() === $before, 'post-grading repeated closure leaves grades unchanged');
completeCheck((int)$pdo->query("SELECT COUNT(*) FROM selection_choices sc JOIN enrollments e ON e.student_id=sc.student_id AND e.offering_id=sc.offering_id WHERE sc.semester_id=$sid AND sc.kind='primary' AND e.status<>'active'")->fetchColumn() === 0, 'confirmed primary choices all retain active enrollments');
echo 'CHECKS ' . $checks . PHP_EOL;
