<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--disposable-demo') {
    exit(1);
}
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../database/import_more_demo.php';
require __DIR__ . '/../Page/student_data.php';
require __DIR__ . '/../Page/teacher_data.php';
require __DIR__ . '/../Page/teacher_grades_db.php';
require __DIR__ . '/../Page/admin_closure_db.php';
$pdo = databaseConnection();
$expectedDir = getenv('CR_TEST_DATADIR');
if (!$expectedDir || !realpath($expectedDir) || realpath($expectedDir) !== realpath($pdo->query('SELECT @@datadir')->fetchColumn())) {
    throw new RuntimeException('Refusing expanded demo tests outside isolated MySQL');
}
function checkMore(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
    echo 'PASS ' . $name . PHP_EOL;
}
function moreSnapshot(PDO $pdo): array
{
    $rows = [];
    foreach (['users', 'person_profiles', 'semesters', 'courses', 'course_prerequisites', 'offerings', 'offering_times', 'enrollments', 'selection_choices', 'grades', 'grade_change_logs', 'grade_submissions', 'selection_drafts', 'teacher_qualifications'] as $table) {
        $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1,2')->fetchAll();
    }
    return $rows;
}
$baseline = moreSnapshot($pdo);
$originalCounts = $pdo->query("SELECT o.id,COUNT(e.id) AS n FROM offerings o LEFT JOIN enrollments e ON e.offering_id=o.id AND e.status='active' WHERE o.status='open' GROUP BY o.id ORDER BY o.id")->fetchAll(PDO::FETCH_KEY_PAIR);
$pdo->exec("CREATE TRIGGER more_demo_failure BEFORE INSERT ON users FOR EACH ROW BEGIN IF NEW.username='teacher02' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected seed failure'; END IF; END");
$failed = false;
try {
    importMoreDemoData($pdo);
} catch (PDOException $error) {
    $failed = true;
} finally {
    $pdo->exec('DROP TRIGGER more_demo_failure');
}
checkMore($failed && !$pdo->inTransaction() && moreSnapshot($pdo) === $baseline, 'interrupted import rolls back all additions');
$pdo->exec("INSERT INTO users(username,display_name,role,password_hash) VALUES('student04','Existing student','student','Keep@2026')");
$collision = moreSnapshot($pdo);
$failed = false;
try {
    importMoreDemoData($pdo);
} catch (PDOException $error) {
    $failed = true;
}
checkMore($failed && moreSnapshot($pdo) === $collision, 'username conflict preserves existing user and rolls back');
$pdo->exec("DELETE FROM users WHERE username='student04'");
checkMore(importMoreDemoData($pdo), 'expanded demo imports into seeded database');
$currentCounts = $pdo->query("SELECT o.id,COUNT(e.id) AS n FROM offerings o LEFT JOIN enrollments e ON e.offering_id=o.id AND e.status='active' WHERE o.status='open' GROUP BY o.id ORDER BY o.id")->fetchAll(PDO::FETCH_KEY_PAIR);
checkMore(array_intersect_key($currentCounts, $originalCounts) === $originalCounts, 'original current classes retain their seat counts');
checkMore($pdo->query("SELECT * FROM selection_choices WHERE offering_id NOT LIKE 'TEST%' ORDER BY 1,2")->fetchAll() === $baseline['selection_choices'], 'original student plan unchanged');
checkMore((int) $pdo->query("SELECT COUNT(*) FROM person_profiles WHERE social_security_number LIKE 'DEMO-%'")->fetchColumn() === 16, 'all 16 named test users have profiles');
checkMore((int) $pdo->query("SELECT COUNT(*) FROM offerings WHERE id LIKE 'TEST%'")->fetchColumn() === 12, 'ten current and two historical classes created');
checkMore((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username LIKE 'extra_seat_%' AND is_active=0")->fetchColumn() === 22, '22 additional seat accounts cannot log in');
$users = $pdo->query("SELECT username,id FROM users WHERE username LIKE 'student%' OR username LIKE 'teacher%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$password = $pdo->query("SELECT password_hash FROM users WHERE username='student13'")->fetchColumn();
checkMore(password_verify('Demo@2026', $password) && $password !== 'Demo@2026', 'student13 uses a working hashed password');
checkMore((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username IN ('student15','teacher05') AND is_active=0")->fetchColumn() === 2, 'disabled student and teacher available');
checkMore((int) $pdo->query('SELECT COUNT(*) FROM teacher_qualifications WHERE teacher_id=' . (int) $users['teacher04'])->fetchColumn() === 0, 'teacher04 initially has no qualification');
$saved = moreSnapshot($pdo);
checkMore(!importMoreDemoData($pdo) && moreSnapshot($pdo) === $saved, 'repeat import changes nothing');

require __DIR__ . '/../Page/data/catalog_runtime.php';
$sid = $selectionPeriod['semester_id'];
checkMore(count($offerings) === 21 && count($offerings['TEST108-01']['times']) === 2, 'all classes load and second time slot is retained');
$teacher02Grades = dbTeacherGradeOfferings($pdo, (int) $users['teacher02']);
$teacher02Pending = dbTeacherUnavailableGradeOfferings($pdo, (int) $users['teacher02']);
checkMore(array_column($teacher02Grades, 'id') === ['TEST-PRE-LA-2025'], 'teacher02 grade entry contains only own closed confirmed class');
checkMore(count(array_intersect(['TEST101-01', 'TEST102-01', 'TEST106-01'], array_column($teacher02Pending, 'id'))) === 3, 'teacher02 current classes are listed as not yet gradeable');
$teacher02Roster = dbTeacherGradeRoster($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025');
$teacher02Stats = teacherGradeStatistics($teacher02Roster['students']);
checkMore($teacher02Stats['total'] === 4 && $teacher02Stats['missing'] === 0 && $teacher02Stats['distribution']['A'] === 1, 'teacher grade statistics include distribution and completion');
checkMore(dbConfirmTeacherGrades($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025') === [], 'complete class grades can be confirmed');
$student04Enrollment = 0;
foreach ($teacher02Roster['students'] as $student) {
    if ($student['username'] === 'student04') $student04Enrollment = (int) $student['enrollment_id'];
}
checkMore($student04Enrollment > 0 && dbSaveTeacherGrade($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025', $student04Enrollment, 'D') === [], 'manual grade correction succeeds');
checkMore((int) $pdo->query("SELECT COUNT(*) FROM grade_submissions WHERE offering_id='TEST-PRE-LA-2025'")->fetchColumn() === 0, 'grade correction revokes prior confirmation');
$manualLogs = (int) $pdo->query("SELECT COUNT(*) FROM grade_change_logs WHERE offering_id='TEST-PRE-LA-2025' AND change_source='manual'")->fetchColumn();
checkMore($manualLogs === 1, 'manual grade correction is audited');
checkMore(dbSaveTeacherGrade($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025', $student04Enrollment, 'D') === []
    && (int) $pdo->query("SELECT COUNT(*) FROM grade_change_logs WHERE offering_id='TEST-PRE-LA-2025'")->fetchColumn() === 1, 'unchanged grade does not create duplicate audit log');
$badImport = dbImportTeacherGrades($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025', ['not_in_class' => 'A']);
checkMore($badImport['errors'] !== [] && (int) $pdo->query("SELECT COUNT(*) FROM grade_change_logs WHERE offering_id='TEST-PRE-LA-2025'")->fetchColumn() === 1, 'invalid CSV roster entry rolls back whole import');
$goodImport = dbImportTeacherGrades($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025', ['student04' => 'A']);
checkMore($goodImport === ['errors' => [], 'updated' => 1]
    && (int) $pdo->query("SELECT COUNT(*) FROM grade_change_logs WHERE offering_id='TEST-PRE-LA-2025' AND change_source='csv'")->fetchColumn() === 1, 'CSV grade import updates and audits valid rows');
$csvFile = tempnam(sys_get_temp_dir(), 'grades');
try {
    file_put_contents($csvFile, "\xEF\xBB\xBF账号,姓名,成绩\nstudent04,测试学生4,B\n");
    $parsedCsv = parseTeacherGradeCsv($csvFile);
    checkMore($parsedCsv === ['errors' => [], 'rows' => ['student04' => 'B']], 'export-compatible UTF-8 CSV parses correctly');
    foreach ([
        "username,grade\nstudent04\n",
        "username,grade\nstudent04,A,extra\n",
        "username,grade,成绩\nstudent04,A,B\n",
        "username,账号,grade\nstudent04,student08,A\n",
        "username,grade\nstudent08,B\nstudent04\n",
    ] as $index => $invalidCsv) {
        file_put_contents($csvFile, $invalidCsv);
        $parsedCsv = parseTeacherGradeCsv($csvFile);
        checkMore($parsedCsv['errors'] !== [] && $parsedCsv['rows'] === [], 'malformed CSV rejects entire batch case ' . $index);
    }
    file_put_contents($csvFile, "username,grade\nstudent04,\n");
    checkMore(parseTeacherGradeCsv($csvFile) === ['errors' => [], 'rows' => ['student04' => '']], 'explicit empty grade cell remains a valid clear request');
    require_once __DIR__ . '/../Page/csv.php';
    $legacyRoster = dbTeacherGradeRoster($pdo, (int) $users['teacher01'], 'PRE-PROG-2025');
    $stream = fopen($csvFile, 'wb');
    writeCsvRow($stream, ['账号', '姓名', '成绩']);
    foreach ($legacyRoster['students'] as $student) {
        writeCsvRow($stream, [$student['username'], $student['display_name'], $student['grade_value'] ?? '']);
    }
    fclose($stream);
    $parsedCsv = parseTeacherGradeCsv($csvFile);
    $beforeImport = moreSnapshot($pdo);
    checkMore($parsedCsv['errors'] === []
        && dbImportTeacherGrades($pdo, (int) $users['teacher01'], 'PRE-PROG-2025', $parsedCsv['rows']) === ['errors' => [], 'updated' => 0]
        && moreSnapshot($pdo) === $beforeImport, 'legacy Chinese grades round-trip through CSV without data changes');
    $forged = dbImportTeacherGrades($pdo, (int) $users['teacher01'], 'PRE-PROG-2025', ['student04' => 'B', 'student01' => '不及格']);
    checkMore($forged['errors'] !== [] && moreSnapshot($pdo) === $beforeImport, 'changed legacy grade rejects entire mixed batch');
    $mixedRows = $parsedCsv['rows'];
    $mixedRows['student04'] = 'B';
    checkMore(dbImportTeacherGrades($pdo, (int) $users['teacher01'], 'PRE-PROG-2025', $mixedRows) === ['errors' => [], 'updated' => 1], 'CSV can retain legacy grades while editing a letter grade');
    checkMore(dbImportTeacherGrades($pdo, (int) $users['teacher01'], 'PRE-PROG-2025', $parsedCsv['rows']) === ['errors' => [], 'updated' => 1], 'restore letter grade after mixed legacy import');
} finally {
    unlink($csvFile);
}
checkMore(count(dbTeacherGradeLogs($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025')) === 2, 'teacher can view own grade audit history');
checkMore(dbConfirmTeacherGrades($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025') === [], 'corrected class can be confirmed again');
$beforeNoop = moreSnapshot($pdo);
checkMore(dbSaveTeacherGrade($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025', $student04Enrollment, 'A') === []
    && dbImportTeacherGrades($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025', ['student04' => 'A']) === ['errors' => [], 'updated' => 0]
    && moreSnapshot($pdo) === $beforeNoop, 'unchanged save and import preserve confirmation and audit');
$pdo->exec("DELETE FROM grade_submissions WHERE offering_id='TEST-PRE-LA-2025'");
foreach (['', '   ', null] as $index => $blankGrade) {
    $pdo->prepare('UPDATE grades SET grade_value=? WHERE enrollment_id=?')->execute([$blankGrade, $student04Enrollment]);
    $blankRoster = dbTeacherGradeRoster($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025');
    $beforeConfirm = moreSnapshot($pdo);
    checkMore(teacherGradeStatistics($blankRoster['students'])['missing'] === 1
        && dbConfirmTeacherGrades($pdo, (int) $users['teacher02'], 'TEST-PRE-LA-2025') !== []
        && moreSnapshot($pdo) === $beforeConfirm, 'blank grades block confirmation consistently case ' . $index);
}
$pdo->prepare('UPDATE grades SET grade_value=? WHERE enrollment_id=?')->execute(['A', $student04Enrollment]);
checkMore($offerings['TEST102-01']['selected'] === 9 && $offerings['TEST103-01']['selected'] === 10 && $offerings['TEST104-01']['selected'] === 2, 'last-seat full and low-enrollment scenarios ready');
checkMore(count(dbCompletedCourseNames($pdo, (int) $users['student04'])) === 4 && dbCompletedCourseNames($pdo, (int) $users['student05']) === [], 'passing and failing prerequisite histories differ');
checkMore(dbSelectionDraft($pdo, (int) $users['student07'], $sid)['primary'][0] === 'TEST101-01', 'student07 has an incomplete draft');
checkMore(!offeringTimeConflict($offerings['TEST101-01'], $offerings['TEST106-01']) && offeringTimeConflict($offerings['TEST108-01'], $offerings['SE301-01']), 'endpoint and second-slot conflicts behave as intended');
$primary = ['TEST101-01', 'TEST102-01', 'TEST104-01', 'TEST106-01'];
$backup = ['TEST105-01', 'TEST109-01'];
checkMore(validateStudentSelection($primary, $backup, $offerings, dbCompletedCourseNames($pdo, (int) $users['student04'])) === [], 'student04 has a valid ready-to-submit plan');
checkMore(validateStudentSelection($primary, $backup, $offerings, dbCompletedCourseNames($pdo, (int) $users['student05'])) !== [], 'student05 fails prerequisite validation');
checkMore(dbChangeTeacherClaim($pdo, (int) $users['teacher04'], $sid, 'TEST109-01', 'claim') !== [], 'unqualified teacher cannot claim');
checkMore(dbChangeTeacherClaim($pdo, (int) $users['teacher02'], $sid, 'TEST109-01', 'claim') === [], 'qualified teacher can claim an empty class');
$pdo->exec("UPDATE person_profiles SET revision=revision+1, person_status='测试已修改' WHERE user_id=" . (int) $users['student14']);
$changed = moreSnapshot($pdo);
checkMore(!importMoreDemoData($pdo) && moreSnapshot($pdo) === $changed, 'repeat import retains edited profiles and teaching assignments');

$pdo->prepare("UPDATE semesters SET selection_ends_at=? WHERE id=?")->execute([(new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->modify('-1 hour')->format('Y-m-d H:i:s'), $sid]);
$admin = (int) $pdo->query("SELECT id FROM users WHERE username='admin01'")->fetchColumn();
checkMore(dbCloseSemester($pdo, $admin, $sid)['errors'] === [], 'expanded data can close registration');
$q = $pdo->query("SELECT id,status FROM offerings WHERE id IN ('TEST104-01','TEST105-01')")->fetchAll(PDO::FETCH_KEY_PAIR);
checkMore($q['TEST104-01'] === 'cancelled' && $q['TEST105-01'] === 'cancelled', 'low-enrollment and unclaimed classes cancelled');
$remainingClaims = dbTeacherClaims($pdo, (int) $users['teacher03'], (int) $sid);
checkMore(!in_array('TEST104-01', $remainingClaims, true) && !in_array('TEST107-01', $remainingClaims, true)
    && in_array('TEST103-01', $remainingClaims, true), 'teaching list and schedule exclude cancelled classes but retain confirmed classes');
$plan8 = dbStudentSelection($pdo, (int) $users['student08'], $sid);
$plan9 = dbStudentSelection($pdo, (int) $users['student09'], $sid);
checkMore(in_array('TEST106-01', $plan8['primary'], true) && in_array('TEST102-01', $plan9['primary'], true), 'students receive their available backups after closure');
checkMore(!importMoreDemoData($pdo), 'repeat import after closure leaves final results intact');
echo 'Expanded demo tests complete' . PHP_EOL;
