<?php
declare(strict_types=1);

// 仅用于空的开发数据库。正式数据应由教务数据源导入。
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--demo') {
    fwrite(STDERR, "用法：php database/import_demo.php --demo\n");
    exit(1);
}

require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/data/catalog.php';

$pdo = databaseConnection();
foreach (['users', 'semesters', 'courses', 'offerings', 'enrollments'] as $table) {
    if ((int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) {
        fwrite(STDERR, "数据库已有数据（{$table}），演示导入已取消。\n");
        exit(1);
    }
}

$days = ['周一' => 1, '周二' => 2, '周三' => 3, '周四' => 4, '周五' => 5];
$prerequisiteCodes = ['程序设计基础' => 'PRE-PROG', '数据结构' => 'PRE-DS', '线性代数' => 'PRE-LA'];
$userInsert = $pdo->prepare('INSERT INTO users (username, display_name, role, password_hash, is_active) VALUES (?, ?, ?, ?, ?)');
$courseInsert = $pdo->prepare('INSERT INTO courses (code, name, description) VALUES (?, ?, ?)');
$prerequisiteInsert = $pdo->prepare('INSERT INTO course_prerequisites (course_code, prerequisite_code) VALUES (?, ?)');
$offeringInsert = $pdo->prepare('INSERT INTO offerings (id, course_code, semester_id, class_name, teacher_id, location, capacity) VALUES (?, ?, ?, ?, ?, ?, ?)');
$historyOfferingInsert = $pdo->prepare('INSERT INTO offerings (id, course_code, semester_id, class_name, teacher_id, location, capacity, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$timeInsert = $pdo->prepare('INSERT INTO offering_times (offering_id, weekday, starts_at, ends_at) VALUES (?, ?, ?, ?)');
$enrollmentInsert = $pdo->prepare('INSERT INTO enrollments (student_id, offering_id, status, source) VALUES (?, ?, ?, ?)');
$historyEnrollmentInsert = $pdo->prepare('INSERT INTO enrollments (student_id, offering_id, status, source, registered_at) VALUES (?, ?, ?, ?, ?)');
$choiceInsert = $pdo->prepare('INSERT INTO selection_choices (student_id, semester_id, offering_id, kind, choice_rank) VALUES (?, ?, ?, ?, ?)');
$gradeInsert = $pdo->prepare('INSERT INTO grades (enrollment_id, grade_value) VALUES (?, ?)');

try {
    $pdo->beginTransaction();
    $demoHash = password_hash('Demo@2026', PASSWORD_DEFAULT);
    $loginIds = [];
    foreach ([
        ['student01', '演示学生', 'student'],
        ['student02', '演示学生二', 'student'],
        ['student03', '演示学生三', 'student'],
        ['teacher01', '演示教师', 'teacher'],
        ['admin01', '演示教务员', 'admin'],
    ] as [$username, $name, $role]) {
        $userInsert->execute([$username, $name, $role, $demoHash, 1]);
        $loginIds[$username] = (int) $pdo->lastInsertId();
    }

    $teacherIds = [];
    foreach ($offerings as $course) {
        $name = $course['teacher'];
        if ($name === '待认领' || isset($teacherIds[$name])) {
            continue;
        }
        $username = sprintf('import_teacher_%02d', count($teacherIds) + 1);
        $userInsert->execute([$username, $name, 'teacher', '!', 0]);
        $teacherIds[$name] = (int) $pdo->lastInsertId();
    }

    $semesterInsert = $pdo->prepare('INSERT INTO semesters (name, selection_starts_at, selection_ends_at, status, closed_at) VALUES (?, ?, ?, ?, ?)');
    $semesterInsert->execute([$semester, $selectionPeriod['start'], $selectionPeriod['end'], 'open', null]);
    $semesterId = (int) $pdo->lastInsertId();
    $semesterInsert->execute(['2025—2026学年 第二学期', '2026-02-16 08:00:00', '2026-03-01 17:00:00', 'closed', '2026-03-02 00:00:00']);
    $historySemesterId = (int) $pdo->lastInsertId();

    foreach ($prerequisiteCodes as $name => $code) {
        $courseInsert->execute([$code, $name, '先修课程资料，仅用于本地演示。']);
    }
    $seenCourses = [];
    $prerequisites = [];
    foreach ($offerings as $course) {
        if (!isset($seenCourses[$course['code']])) {
            $courseInsert->execute([$course['code'], $course['name'], $course['description']]);
            $seenCourses[$course['code']] = true;
        }
        if ($course['prerequisite'] !== '无') {
            $prerequisites[$course['code']] = $prerequisiteCodes[$course['prerequisite']];
        }
    }
    foreach ($prerequisites as $code => $requiredCode) {
        $prerequisiteInsert->execute([$code, $requiredCode]);
    }

    $primary = ['SE301-01', 'DB305-01', 'CN309-01', 'AI320-01'];
    $backup = ['OS312-01', 'DSP342-01'];
    $seatNumber = 0;
    foreach ($offerings as $id => $course) {
        [$startsAt, $endsAt] = explode('—', $course['time']);
        $teacherId = $teacherIds[$course['teacher']] ?? null;
        $offeringInsert->execute([
            $id, $course['code'], $semesterId, $course['class'], $teacherId,
            $course['location'], $course['capacity'],
        ]);
        $timeInsert->execute([$id, $days[$course['day']], $startsAt, $endsAt]);

        // student01 占四个首选名额，其余名额由不可登录的独立占位学生保留。
        if (in_array($id, $primary, true)) {
            $enrollmentInsert->execute([$loginIds['student01'], $id, 'active', 'primary']);
        }
        $placeholderCount = $course['selected'] - (in_array($id, $primary, true) ? 1 : 0);
        if ($placeholderCount < 0) {
            throw new LogicException('演示课程人数小于 student01 的首选占座人数：' . $id);
        }
        for ($seat = 0; $seat < $placeholderCount; $seat++) {
            $seatNumber++;
            $userInsert->execute([sprintf('seed_seat_%04d', $seatNumber), '演示占位学生', 'student', '!', 0]);
            $enrollmentInsert->execute([(int) $pdo->lastInsertId(), $id, 'active', 'primary']);
        }
    }

    foreach ($primary as $index => $id) {
        $choiceInsert->execute([$loginIds['student01'], $semesterId, $id, 'primary', $index + 1]);
    }
    foreach ($backup as $index => $id) {
        $choiceInsert->execute([$loginIds['student01'], $semesterId, $id, 'backup', $index + 1]);
    }

    // 两门已完成的先修课对应当前学生成绩页面的演示内容。
    foreach ([
        ['PRE-PROG-2025', 'PRE-PROG', '周一', '08:00', '09:40', '良好'],
        ['PRE-DS-2025', 'PRE-DS', '周二', '08:00', '09:40', '优秀'],
    ] as [$id, $code, $day, $startsAt, $endsAt, $grade]) {
        $historyOfferingInsert->execute([$id, $code, $historySemesterId, '01 班', $loginIds['teacher01'], '历史课程', 10, 'confirmed']);
        $timeInsert->execute([$id, $days[$day], $startsAt, $endsAt]);
        $historyEnrollmentInsert->execute([$loginIds['student01'], $id, 'active', 'primary', '2026-02-20 12:00:00']);
        $gradeInsert->execute([(int) $pdo->lastInsertId(), $grade]);
        $historyEnrollmentInsert->execute([$loginIds['student02'], $id, 'active', 'primary', '2026-02-20 12:05:00']);
        $gradeInsert->execute([(int) $pdo->lastInsertId(), '及格']);
    }
    $pdo->commit();
    $selectedCount = array_sum(array_column($offerings, 'selected'));
    echo '演示数据导入完成：当前学期 ' . count($offerings) . ' 个教学班、' . $selectedCount . ' 个已选名额（其中 ' . $seatNumber . ' 个占位名额），另有 4 条历史成绩。' . PHP_EOL;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '导入失败：' . $error->getMessage() . PHP_EOL);
    exit(1);
}
