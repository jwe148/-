<?php
declare(strict_types=1);

function dbTeacherGradeOfferings(PDO $pdo, int $teacherId): array
{
    $query = $pdo->prepare("SELECT o.id, o.class_name, c.name AS course_name, s.name AS semester_name
        FROM offerings o JOIN courses c ON c.code = o.course_code
        JOIN semesters s ON s.id = o.semester_id
        WHERE o.teacher_id = ? AND o.status = 'confirmed' AND s.status = 'closed'
        ORDER BY s.selection_starts_at DESC, c.name, o.id");
    $query->execute([$teacherId]);
    return $query->fetchAll();
}

function dbTeacherUnavailableGradeOfferings(PDO $pdo, int $teacherId): array
{
    $query = $pdo->prepare("SELECT o.id, o.class_name, o.status AS offering_status,
            c.name AS course_name, s.name AS semester_name, s.status AS semester_status
        FROM offerings o JOIN courses c ON c.code = o.course_code
        JOIN semesters s ON s.id = o.semester_id
        WHERE o.teacher_id = ?
          AND NOT (o.status = 'confirmed' AND s.status = 'closed')
        ORDER BY s.selection_starts_at DESC, c.name, o.id");
    $query->execute([$teacherId]);
    return $query->fetchAll();
}

function teacherGradeUnavailableReason(array $offering): string
{
    if (($offering['offering_status'] ?? '') === 'cancelled') {
        return '教学班已停开';
    }
    if (($offering['semester_status'] ?? '') !== 'closed') {
        return '选课尚未关闭';
    }
    return '教学班尚未确认开设';
}

function dbTeacherGradeRoster(PDO $pdo, int $teacherId, string $offeringId): ?array
{
    $query = $pdo->prepare("SELECT o.id, o.class_name, c.name AS course_name, s.name AS semester_name
        FROM offerings o JOIN courses c ON c.code = o.course_code
        JOIN semesters s ON s.id = o.semester_id
        WHERE o.id = ? AND o.teacher_id = ? AND o.status = 'confirmed' AND s.status = 'closed'");
    $query->execute([$offeringId, $teacherId]);
    $offering = $query->fetch();
    if (!is_array($offering)) {
        return null;
    }
    $rosterQuery = $pdo->prepare("SELECT e.id AS enrollment_id, u.username, u.display_name, g.grade_value
        FROM enrollments e JOIN users u ON u.id = e.student_id
        LEFT JOIN grades g ON g.enrollment_id = e.id
        WHERE e.offering_id = ? AND e.status = 'active'
        ORDER BY u.username");
    $rosterQuery->execute([$offeringId]);
    $offering['students'] = $rosterQuery->fetchAll();
    $submissionQuery = $pdo->prepare('SELECT submitted_at FROM grade_submissions WHERE offering_id = ? AND teacher_id = ?');
    $submissionQuery->execute([$offeringId, $teacherId]);
    $submittedAt = $submissionQuery->fetchColumn();
    $offering['submitted_at'] = $submittedAt === false ? null : $submittedAt;
    return $offering;
}

function teacherGradeRecorded($grade): bool
{
    return is_string($grade) && trim($grade) !== '';
}

function teacherLegacyGrade(string $grade): bool
{
    return in_array($grade, ['优秀', '良好', '中等', '及格', '不及格'], true);
}

function teacherGradeStatistics(array $students): array
{
    $distribution = array_fill_keys(['A', 'B', 'C', 'D', 'F', 'I', '历史'], 0);
    $scores = ['A' => 95, 'B' => 85, 'C' => 75, 'D' => 65, 'F' => 50,
        '优秀' => 95, '良好' => 85, '中等' => 75, '及格' => 65, '不及格' => 50];
    $total = count($students);
    $recorded = 0;
    $passed = 0;
    $scoreTotal = 0;
    $scoreCount = 0;
    foreach ($students as $student) {
        $grade = $student['grade_value'] ?? null;
        if (!teacherGradeRecorded($grade)) {
            continue;
        }
        if (array_key_exists($grade, $distribution)) {
            $distribution[$grade]++;
        } else {
            $distribution['历史']++;
        }
        $recorded++;
        if (in_array($grade, ['A', 'B', 'C', 'D', '优秀', '良好', '中等', '及格'], true)) {
            $passed++;
        }
        if (isset($scores[$grade])) {
            $scoreTotal += $scores[$grade];
            $scoreCount++;
        }
    }
    return [
        'total' => $total,
        'recorded' => $recorded,
        'missing' => $total - $recorded,
        'passed' => $passed,
        'pass_rate' => $recorded === 0 ? null : round($passed * 100 / $recorded, 1),
        'average' => $scoreCount === 0 ? null : round($scoreTotal / $scoreCount, 1),
        'distribution' => $distribution,
    ];
}

function dbTeacherGradeLogs(PDO $pdo, int $teacherId, string $offeringId, int $limit = 100): array
{
    $limit = max(1, min(100, $limit));
    $query = $pdo->prepare("SELECT l.old_grade, l.new_grade, l.change_source, l.changed_at,
            u.username, u.display_name
        FROM grade_change_logs l
        JOIN enrollments e ON e.id = l.enrollment_id
        JOIN users u ON u.id = e.student_id
        JOIN offerings o ON o.id = l.offering_id
        WHERE l.offering_id = ? AND o.teacher_id = ? AND l.teacher_id = ?
        ORDER BY l.id DESC LIMIT {$limit}");
    $query->execute([$offeringId, $teacherId, $teacherId]);
    return $query->fetchAll();
}

function parseTeacherGradeCsv(string $path): array
{
    if (!is_file($path) || filesize($path) === false || filesize($path) > 1024 * 1024) {
        return ['errors' => ['CSV文件无效或超过1MB。'], 'rows' => []];
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return ['errors' => ['无法读取CSV文件。'], 'rows' => []];
    }
    try {
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if (!is_array($header)) {
            return ['errors' => ['CSV文件为空。'], 'rows' => []];
        }
        $header = array_map(static fn($value): string => trim((string) $value), $header);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        $normalizedHeaders = array_map(static function (string $value): string {
            $value = strtolower($value);
            return ['账号' => 'username', '成绩' => 'grade'][$value] ?? $value;
        }, $header);
        foreach (['username', 'grade'] as $required) {
            if (count(array_keys($normalizedHeaders, $required, true)) > 1) {
                return ['errors' => ['CSV账号或成绩表头重复，请保留唯一列。'], 'rows' => []];
            }
        }
        $usernameIndex = array_search('账号', $header, true);
        if ($usernameIndex === false) {
            $usernameIndex = array_search('username', array_map('strtolower', $header), true);
        }
        $gradeIndex = array_search('成绩', $header, true);
        if ($gradeIndex === false) {
            $gradeIndex = array_search('grade', array_map('strtolower', $header), true);
        }
        if ($usernameIndex === false || $gradeIndex === false) {
            return ['errors' => ['CSV表头必须包含“账号”和“成绩”。'], 'rows' => []];
        }
        $rows = [];
        $errors = [];
        $line = 1;
        while (($columns = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($line > 501) {
                $errors[] = 'CSV最多允许500条成绩记录。';
                break;
            }
            if ($columns === [null]) {
                continue;
            }
            if (count($columns) !== count($header)) {
                $errors[] = "第{$line}行列数与表头不一致；清空成绩请保留成绩列并留空。";
                continue;
            }
            $username = trim((string) ($columns[$usernameIndex] ?? ''));
            $grade = strtoupper(trim((string) ($columns[$gradeIndex] ?? '')));
            if ($username === '' && $grade === '') {
                continue;
            }
            if ($username === '' || (!in_array($grade, ['', 'A', 'B', 'C', 'D', 'F', 'I'], true) && !teacherLegacyGrade($grade))) {
                $errors[] = "第{$line}行账号为空或成绩无效。";
                continue;
            }
            if (isset($rows[$username])) {
                $errors[] = "第{$line}行账号重复：{$username}。";
                continue;
            }
            $rows[$username] = $grade;
        }
        if ($rows === [] && $errors === []) {
            $errors[] = 'CSV中没有成绩记录。';
        }
        return ['errors' => $errors, 'rows' => $errors === [] ? $rows : []];
    } finally {
        fclose($handle);
    }
}

function dbLockTeacherGradeOffering(PDO $pdo, int $teacherId, string $offeringId): ?array
{
    $teacherQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1 FOR UPDATE");
    $teacherQuery->execute([$teacherId]);
    if (!$teacherQuery->fetchColumn()) {
        return null;
    }
    $offeringQuery = $pdo->prepare("SELECT o.id, o.status, o.teacher_id, s.status AS semester_status
        FROM offerings o JOIN semesters s ON s.id = o.semester_id
        WHERE o.id = ? FOR UPDATE");
    $offeringQuery->execute([$offeringId]);
    $offering = $offeringQuery->fetch();
    return is_array($offering) && (int) $offering['teacher_id'] === $teacherId
        && $offering['status'] === 'confirmed' && $offering['semester_status'] === 'closed'
        ? $offering : null;
}

function dbWriteTeacherGrade(PDO $pdo, int $teacherId, string $offeringId, int $enrollmentId, ?string $grade, string $source): bool
{
    $currentQuery = $pdo->prepare('SELECT grade_value FROM grades WHERE enrollment_id = ? FOR UPDATE');
    $currentQuery->execute([$enrollmentId]);
    $current = $currentQuery->fetchColumn();
    $oldGrade = $current === false ? null : $current;
    if ($oldGrade === $grade) {
        return false;
    }
    $save = $pdo->prepare('INSERT INTO grades (enrollment_id, grade_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE grade_value = VALUES(grade_value)');
    $save->execute([$enrollmentId, $grade]);
    $log = $pdo->prepare('INSERT INTO grade_change_logs
        (offering_id, enrollment_id, teacher_id, old_grade, new_grade, change_source)
        VALUES (?, ?, ?, ?, ?, ?)');
    $log->execute([$offeringId, $enrollmentId, $teacherId, $oldGrade, $grade, $source]);
    $pdo->prepare('DELETE FROM grade_submissions WHERE offering_id = ?')->execute([$offeringId]);
    return true;
}

function dbSaveTeacherGrade(PDO $pdo, int $teacherId, string $offeringId, int $enrollmentId, string $grade): array
{
    if ($offeringId === '' || strlen($offeringId) > 32 || $enrollmentId < 1) {
        return ['成绩记录无效。'];
    }
    if (!in_array($grade, ['', 'A', 'B', 'C', 'D', 'F', 'I'], true)) {
        return ['成绩等级无效，请选择 A、B、C、D、F 或 I。'];
    }

    try {
        $pdo->beginTransaction();
        if (dbLockTeacherGradeOffering($pdo, $teacherId, $offeringId) === null) {
            $pdo->rollBack();
            return ['只能录入本人已结课教学班的成绩。'];
        }
        $enrollmentQuery = $pdo->prepare("SELECT id FROM enrollments
            WHERE id = ? AND offering_id = ? AND status = 'active' FOR UPDATE");
        $enrollmentQuery->execute([$enrollmentId, $offeringId]);
        if (!$enrollmentQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['该学生不在当前教学班的有效名单中。'];
        }
        dbWriteTeacherGrade($pdo, $teacherId, $offeringId, $enrollmentId, $grade === '' ? null : $grade, 'manual');
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function dbImportTeacherGrades(PDO $pdo, int $teacherId, string $offeringId, array $rows): array
{
    if ($offeringId === '' || strlen($offeringId) > 32 || $rows === [] || count($rows) > 500) {
        return ['errors' => ['导入内容无效。'], 'updated' => 0];
    }
    try {
        $pdo->beginTransaction();
        if (dbLockTeacherGradeOffering($pdo, $teacherId, $offeringId) === null) {
            $pdo->rollBack();
            return ['errors' => ['只能导入本人已结课教学班的成绩。'], 'updated' => 0];
        }
        $rosterQuery = $pdo->prepare("SELECT u.username, e.id FROM enrollments e
            JOIN users u ON u.id = e.student_id
            WHERE e.offering_id = ? AND e.status = 'active' FOR UPDATE");
        $rosterQuery->execute([$offeringId]);
        $roster = $rosterQuery->fetchAll(PDO::FETCH_KEY_PAIR);
        $errors = [];
        foreach ($rows as $username => $grade) {
            $username = (string) $username;
            if (!is_string($grade) || (!in_array($grade, ['', 'A', 'B', 'C', 'D', 'F', 'I'], true) && !teacherLegacyGrade($grade))) {
                $errors[] = '导入内容包含无效账号或成绩。';
                break;
            }
            if (!isset($roster[$username])) {
                $errors[] = '账号不在当前教学班名单中：' . $username . '。';
            } elseif (teacherLegacyGrade($grade)) {
                // 旧中文等级仅允许原样保留；新增和修改仍使用字母等级。
                $existing = $pdo->prepare('SELECT grade_value FROM grades WHERE enrollment_id = ? FOR UPDATE');
                $existing->execute([(int) $roster[$username]]);
                if ($existing->fetchColumn() !== $grade) {
                    $errors[] = '账号 ' . $username . ' 的历史中文成绩已变化或并非原值，请重新导出或使用字母等级。';
                }
            }
        }
        if ($errors !== []) {
            $pdo->rollBack();
            return ['errors' => $errors, 'updated' => 0];
        }
        $updated = 0;
        foreach ($rows as $username => $grade) {
            $username = (string) $username;
            if (dbWriteTeacherGrade($pdo, $teacherId, $offeringId, (int) $roster[$username], $grade === '' ? null : $grade, 'csv')) {
                $updated++;
            }
        }
        $pdo->commit();
        return ['errors' => [], 'updated' => $updated];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function dbConfirmTeacherGrades(PDO $pdo, int $teacherId, string $offeringId): array
{
    try {
        $pdo->beginTransaction();
        if (dbLockTeacherGradeOffering($pdo, $teacherId, $offeringId) === null) {
            $pdo->rollBack();
            return ['只能确认本人已结课教学班的成绩。'];
        }
        $countQuery = $pdo->prepare("SELECT g.grade_value
            FROM enrollments e LEFT JOIN grades g ON g.enrollment_id = e.id
            WHERE e.offering_id = ? AND e.status = 'active' FOR UPDATE");
        $countQuery->execute([$offeringId]);
        $values = $countQuery->fetchAll(PDO::FETCH_COLUMN);
        $total = count($values);
        $graded = count(array_filter($values, 'teacherGradeRecorded'));
        if ($total === 0 || $graded !== $total) {
            $pdo->rollBack();
            return [$total === 0 ? '教学班没有有效学生，不能确认成绩。' : '仍有未录入成绩的学生，不能确认提交。'];
        }
        $save = $pdo->prepare('INSERT INTO grade_submissions (offering_id, teacher_id, submitted_at)
            VALUES (?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), submitted_at = CURRENT_TIMESTAMP');
        $save->execute([$offeringId, $teacherId]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
