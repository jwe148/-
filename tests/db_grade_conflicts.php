<?php
declare(strict_types=1);

// 只允许运行在测试运行器创建的独立 MySQL 数据目录。
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/teacher_grades_db.php';
$pdo = databaseConnection();
$expectedDir = getenv('CR_TEST_DATADIR');
if (!$expectedDir || realpath($expectedDir) !== realpath($pdo->query('SELECT @@datadir')->fetchColumn())) {
    throw new RuntimeException('Refusing tests outside the isolated MySQL instance');
}
function gradeCheck(bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . PHP_EOL;
}
function gradeState(PDO $pdo): array {
    $result = [];
    foreach (['grades', 'grade_change_logs', 'grade_submissions'] as $table) {
        $result[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll();
    }
    return $result;
}
$teacher = (int) $pdo->query("SELECT id FROM users WHERE username='teacher01'")->fetchColumn();
$offering = 'PRE-PROG-2025';
$roster = dbTeacherGradeRoster($pdo, $teacher, $offering);
gradeCheck($roster !== null && count($roster['students']) >= 2, 'completed class fixture available');
$students = $roster['students'];
$first = $students[0];
$second = $students[1];
$id = (int) $first['enrollment_id'];
$oldRow = teacherGradeSnapshot([$first]);
$oldClass = teacherGradeSnapshot($students);
gradeCheck($oldClass === teacherGradeSnapshot(array_reverse($students)), 'snapshot independent of display order');
gradeCheck(dbConfirmTeacherGrades($pdo, $teacher, $offering, $oldClass) === [], 'fresh class can be confirmed');
$newGrade = $first['grade_value'] === 'A' ? 'B' : 'A';
gradeCheck(dbSaveTeacherGrade($pdo, $teacher, $offering, $id, $newGrade, $oldRow) === [], 'first window saves grade');
$after = gradeState($pdo);
gradeCheck(dbSaveTeacherGrade($pdo, $teacher, $offering, $id, 'F', $oldRow) !== [] && gradeState($pdo) === $after, 'stale manual edit preserves grades logs and confirmation');
gradeCheck(dbSaveTeacherGrade($pdo, $teacher, $offering, $id, '', $oldRow) !== [] && gradeState($pdo) === $after, 'stale clear cannot erase new grade');
$import = dbImportTeacherGrades($pdo, $teacher, $offering, [$first['username'] => 'F', $second['username'] => 'F'], $oldClass);
gradeCheck($import['errors'] !== [] && $import['updated'] === 0 && gradeState($pdo) === $after, 'stale CSV rejects entire batch');
gradeCheck(dbConfirmTeacherGrades($pdo, $teacher, $offering, $oldClass) !== [] && gradeState($pdo) === $after, 'stale confirmation changes no data');
gradeCheck(dbSaveTeacherGrade($pdo, $teacher, $offering, (int) $second['enrollment_id'], 'D', teacherGradeSnapshot([$second])) === [], 'unrelated student edit is not blocked');
$fresh = dbTeacherGradeRoster($pdo, $teacher, $offering);
$freshClass = teacherGradeSnapshot($fresh['students']);
gradeCheck(dbConfirmTeacherGrades($pdo, $teacher, $offering, $freshClass) === [], 'refresh allows confirmation');
$freshFirst = array_values(array_filter($fresh['students'], static fn($row) => (int) $row['enrollment_id'] === $id))[0];
$confirmed = gradeState($pdo);
gradeCheck(dbSaveTeacherGrade($pdo, $teacher, $offering, $id, $newGrade, teacherGradeSnapshot([$freshFirst])) === [] && gradeState($pdo) === $confirmed, 'unchanged save preserves confirmation and logs');
$same = dbImportTeacherGrades($pdo, $teacher, $offering, [$first['username'] => $newGrade], $freshClass);
gradeCheck($same['errors'] === [] && $same['updated'] === 0 && gradeState($pdo) === $confirmed, 'unchanged CSV preserves confirmation');
gradeCheck(dbSaveTeacherGrade($pdo, $teacher, $offering, $id, '', teacherGradeSnapshot([$freshFirst])) === [], 'fresh clear allowed');
$cleared = dbTeacherGradeRoster($pdo, $teacher, $offering);
gradeCheck(dbConfirmTeacherGrades($pdo, $teacher, $offering, teacherGradeSnapshot($cleared['students'])) !== [], 'incomplete grades cannot be confirmed');
