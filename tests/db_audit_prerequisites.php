<?php
declare(strict_types=1);
// Destructive fixtures are allowed only on the runner's disposable MySQL instance.
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/student_data.php';
require __DIR__ . '/../Page/admin_closure_db.php';
$pdo = databaseConnection();
$expected = getenv('CR_TEST_DATADIR');
$actual = $pdo->query('SELECT @@datadir')->fetchColumn();
if (PHP_SAPI !== 'cli' || !$expected || realpath($expected) !== realpath($actual)) {
    throw new RuntimeException('Refusing to modify a non-isolated database.');
}
$checks = [];
function auditCheck(bool $passed, string $name, array $detail = []): void {
    global $checks;
    $checks[] = ['name' => $name, 'passed' => $passed, 'detail' => $detail];
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
$student = (int)$pdo->query("SELECT id FROM users WHERE username='student01'")->fetchColumn();
$teacher = (int)$pdo->query("SELECT id FROM users WHERE username='teacher01'")->fetchColumn();
$current = require __DIR__ . '/../Page/data/current_semester.php';
$q = $pdo->prepare('SELECT id FROM semesters WHERE name=?');
$q->execute([$current]);
$semesterId = (int)$q->fetchColumn();
$passedCourse = $pdo->query("SELECT o.course_code,c.name,g.enrollment_id FROM grades g JOIN enrollments e ON e.id=g.enrollment_id JOIN offerings o ON o.id=e.offering_id JOIN courses c ON c.code=o.course_code WHERE e.student_id=$student AND g.grade_value IN ('A','B','C','D','优秀','良好') LIMIT 1")->fetch();
$pdo->prepare("UPDATE semesters SET selection_starts_at=DATE_SUB(NOW(), INTERVAL 1 DAY),selection_ends_at=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id=?")->execute([$semesterId]);
$insertCourse = $pdo->prepare('INSERT INTO courses(code,name,description) VALUES(?,?,?)');
$insertCourse->execute(['AUDIT_SAME_NAME',$passedCourse['name'],'Same name, different course code']);
$insertCourse->execute(['AUDIT_UNPASSED','Audit unpassed course','No matching grade']);
foreach (['AUDIT_TARGET'=>'AUDIT_SAME_NAME','AUDIT_VALID'=>$passedCourse['course_code'],'AUDIT_DENIED'=>'AUDIT_UNPASSED'] as $code=>$prerequisite) {
    $insertCourse->execute([$code,$code,'Prerequisite test']);
    $pdo->prepare('INSERT INTO course_prerequisites(course_code,prerequisite_code) VALUES(?,?)')->execute([$code,$prerequisite]);
    $pdo->prepare("INSERT INTO offerings(id,course_code,semester_id,class_name,teacher_id,location) VALUES(?,?,?,'01',?,'Audit')")->execute([$code,$code,$semesterId,$teacher]);
    $pdo->prepare("INSERT INTO offering_times(offering_id,weekday,starts_at,ends_at) VALUES(?,7,'20:00:00','21:00:00')")->execute([$code]);
}
foreach (['AUDIT_TIME_A'=>['20:00:00','21:00:30'], 'AUDIT_TIME_B'=>['21:00:00','22:00:00'], 'AUDIT_TIME_C'=>['21:00:30','22:00:00']] as $code=>$time) {
    $insertCourse->execute([$code,$code,'Second-precision time boundary']);
    $pdo->prepare("INSERT INTO offerings(id,course_code,semester_id,class_name,teacher_id,location) VALUES(?,?,?,'01',?,'Audit')")->execute([$code,$code,$semesterId,$teacher]);
    $pdo->prepare('INSERT INTO offering_times(offering_id,weekday,starts_at,ends_at) VALUES(?,6,?,?)')->execute([$code,$time[0],$time[1]]);
}
require __DIR__ . '/../Page/data/catalog_runtime.php';
$denied = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_DENIED'],[],$offerings);
auditCheck($denied !== [], 'unpassed distinct-name prerequisite is rejected', $denied);
$errors = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_TARGET'],[],$offerings);
$q=$pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id=? AND offering_id='AUDIT_TARGET' AND status='active'");
$q->execute([$student]);
$occupied=(int)$q->fetchColumn();
auditCheck($errors !== [] && $occupied === 0, 'same-name different-code prerequisite must not grant enrollment', ['errors'=>$errors,'active_seats'=>$occupied,'passed_code'=>$passedCourse['course_code'],'required_code'=>'AUDIT_SAME_NAME']);
auditCheck(!in_array('AUDIT_SAME_NAME',dbClosingCompletedCodes($pdo,$student),true), 'closure uses codes and does not consider the homonym completed');
$valid = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_VALID'],[],$offerings);
auditCheck($valid === [], 'actual prerequisite permits enrollment', $valid);
$pdo->prepare("UPDATE grades SET grade_value='F' WHERE enrollment_id=?")->execute([$passedCourse['enrollment_id']]);
$repeat = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_VALID'],[],$offerings);
auditCheck($repeat === [], 'already-enrolled course is preserved without rechecking prerequisites', $repeat);
$q=$pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE student_id=? AND offering_id='AUDIT_VALID' AND status='active'");
$q->execute([$student]);
auditCheck((int)$q->fetchColumn() === 1, 'resubmission preserves previous seat');
$stale = $offerings;
$stale['AUDIT_TARGET']['prerequisites'] = [];
$staleErrors = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_TARGET'],[],$stale);
auditCheck($staleErrors !== [], 'stale catalog cannot remove required prerequisites', $staleErrors);
$mixed = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_VALID','AUDIT_DENIED'],[],$offerings);
auditCheck($mixed !== [], 'retaining enrollment does not bypass prerequisites of a new course', $mixed);
$backup = dbSaveStudentSelection($pdo,$student,$semesterId,[],['AUDIT_VALID'],$offerings);
auditCheck($backup !== [], 'moving enrollment to backup still validates prerequisites', $backup);
$drop = dbDropStudentOffering($pdo,$student,$semesterId,'AUDIT_VALID');
auditCheck($drop === [], 'prerequisite correction does not prevent dropping a course', $drop);
$again = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_VALID'],[],$offerings);
auditCheck($again !== [], 'dropped course requires prerequisites when selected again', $again);
$overlap = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_TIME_A','AUDIT_TIME_B'],[],$offerings);
auditCheck($overlap !== [], 'thirty-second overlap must reject enrollment', $overlap);
$adjacent = dbSaveStudentSelection($pdo,$student,$semesterId,['AUDIT_TIME_A','AUDIT_TIME_C'],[],$offerings);
auditCheck($adjacent === [], 'exactly adjacent second-precision meetings are allowed', $adjacent);
file_put_contents(__DIR__.'/function_audit_boundaries.json', json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
exit(in_array(false,array_column($checks,'passed'),true)?1:0);
