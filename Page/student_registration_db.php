<?php
declare(strict_types=1);

function dbStudentSelection(PDO $pdo, int $studentId, int $semesterId): array
{
    $query = $pdo->prepare('SELECT kind, offering_id FROM selection_choices WHERE student_id = ? AND semester_id = ? ORDER BY kind, choice_rank');
    $query->execute([$studentId, $semesterId]);
    $selection = ['primary' => [], 'backup' => []];
    foreach ($query->fetchAll() as $row) {
        $selection[$row['kind']][] = $row['offering_id'];
    }
    return $selection;
}

function dbStudentClosureChanges(PDO $pdo, int $studentId, int $semesterId): array
{
    $query = $pdo->prepare("SELECT o.id, c.name AS course_name, e.status, e.source, r.outcome
        FROM enrollments e JOIN offerings o ON o.id = e.offering_id
        JOIN courses c ON c.code = o.course_code
        LEFT JOIN offering_closure_results r ON r.offering_id = o.id
        WHERE e.student_id = ? AND o.semester_id = ?
          AND (e.status = 'cancelled' OR (e.status = 'active' AND e.source = 'backup'))
        ORDER BY o.id");
    $query->execute([$studentId, $semesterId]);
    return $query->fetchAll();
}

function dbStudentBackupAttempts(PDO $pdo, int $studentId, int $semesterId): array
{
    $query = $pdo->prepare('SELECT a.backup_rank, a.outcome, c.name AS course_name
        FROM closure_backup_attempts a JOIN offerings o ON o.id = a.offering_id
        JOIN courses c ON c.code = o.course_code
        WHERE a.student_id = ? AND a.semester_id = ? ORDER BY a.backup_rank');
    $query->execute([$studentId, $semesterId]);
    return $query->fetchAll();
}

function dbStudentGrades(PDO $pdo, int $studentId): array
{
    $query = $pdo->prepare("SELECT s.name AS semester, c.name AS course, g.grade_value AS grade
        FROM grades g JOIN enrollments e ON e.id = g.enrollment_id
        JOIN offerings o ON o.id = e.offering_id JOIN courses c ON c.code = o.course_code
        JOIN semesters s ON s.id = o.semester_id
        WHERE e.student_id = ? AND e.status = 'active' AND g.grade_value IS NOT NULL
        ORDER BY s.selection_starts_at DESC, c.name");
    $query->execute([$studentId]);
    return $query->fetchAll();
}

function dbCompletedCourseNames(PDO $pdo, int $studentId): array
{
    // 历史中文成绩及 A-D 视为通过，用于先修课程判断。
    $passing = ['优秀', '良好', '中等', '及格', 'A', 'B', 'C', 'D'];
    $marks = implode(',', array_fill(0, count($passing), '?'));
    $query = $pdo->prepare("SELECT DISTINCT c.name FROM grades g
        JOIN enrollments e ON e.id = g.enrollment_id
        JOIN offerings o ON o.id = e.offering_id JOIN courses c ON c.code = o.course_code
        WHERE e.student_id = ? AND e.status = 'active' AND g.grade_value IN ({$marks})");
    $query->execute(array_merge([$studentId], $passing));
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

function dbLockedSelectionPeriod(PDO $pdo, int $semesterId): ?array
{
    $query = $pdo->prepare('SELECT selection_starts_at, selection_ends_at, status FROM semesters WHERE id = ? FOR SHARE');
    $query->execute([$semesterId]);
    $row = $query->fetch();
    return is_array($row)
        ? ['start' => $row['selection_starts_at'], 'end' => $row['selection_ends_at'], 'status' => $row['status']]
        : null;
}

function dbSaveStudentSelection(PDO $pdo, int $studentId, int $semesterId, array $primary, array $backup, array $catalog): array
{
    $all = array_merge($primary, $backup);
    if (count($primary) !== 4 || count($backup) !== 2 || count(array_filter($all, 'is_string')) !== 6) {
        return ['请选择 4 个首选教学班和 2 个备选教学班。'];
    }
    foreach ($all as $id) {
        if ($id === '' || strlen($id) > 32) {
            return ['选课方案中包含无效的教学班编号。'];
        }
    }

    try {
        $pdo->beginTransaction();
        $period = dbLockedSelectionPeriod($pdo, $semesterId);
        if ($period === null || !selectionOpen($period)) {
            $pdo->rollBack();
            return ['当前不在选课时间内。'];
        }
        $studentQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND is_active = 1 FOR UPDATE");
        $studentQuery->execute([$studentId]);
        if (!$studentQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['学生账号不可用。'];
        }

        $oldQuery = $pdo->prepare("SELECT e.offering_id FROM enrollments e JOIN offerings o ON o.id = e.offering_id
            WHERE e.student_id = ? AND o.semester_id = ? AND e.status = 'active'");
        $oldQuery->execute([$studentId, $semesterId]);
        $oldActive = $oldQuery->fetchAll(PDO::FETCH_COLUMN);

        $ids = array_values(array_unique(array_merge($all, $oldActive)));
        sort($ids, SORT_STRING);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $lockedQuery = $pdo->prepare("SELECT id, capacity, status FROM offerings
            WHERE semester_id = ? AND id IN ({$marks}) ORDER BY id FOR UPDATE");
        $lockedQuery->execute(array_merge([$semesterId], $ids));
        $locked = [];
        foreach ($lockedQuery->fetchAll() as $row) {
            $locked[$row['id']] = $row;
        }

        // 锁定教学班后使用当前读，避免 REPEATABLE READ 的旧快照漏掉刚提交的占座。
        $countQuery = $pdo->prepare("SELECT offering_id FROM enrollments
            WHERE status = 'active' AND offering_id IN ({$marks}) FOR UPDATE");
        $countQuery->execute($ids);
        $counts = [];
        foreach ($countQuery->fetchAll() as $row) {
            $counts[$row['offering_id']] = ($counts[$row['offering_id']] ?? 0) + 1;
        }
        $validationCatalog = [];
        foreach ($locked as $id => $row) {
            if (!isset($catalog[$id])) {
                continue;
            }
            $validationCatalog[$id] = $catalog[$id];
            $validationCatalog[$id]['capacity'] = (int) $row['capacity'];
            $validationCatalog[$id]['status'] = $row['status'];
            $validationCatalog[$id]['selected'] = max(0, ($counts[$id] ?? 0) - (in_array($id, $oldActive, true) ? 1 : 0));
        }
        $errors = validateStudentSelection($primary, $backup, $validationCatalog, dbCompletedCourseNames($pdo, $studentId));
        if ($errors !== []) {
            $pdo->rollBack();
            return $errors;
        }

        $deleteChoices = $pdo->prepare('DELETE FROM selection_choices WHERE student_id = ? AND semester_id = ?');
        $deleteChoices->execute([$studentId, $semesterId]);
        $choiceInsert = $pdo->prepare('INSERT INTO selection_choices (student_id, semester_id, offering_id, kind, choice_rank) VALUES (?, ?, ?, ?, ?)');
        foreach (['primary' => $primary, 'backup' => $backup] as $kind => $choices) {
            foreach ($choices as $rank => $id) {
                $choiceInsert->execute([$studentId, $semesterId, $id, $kind, $rank + 1]);
            }
        }
        $drop = $pdo->prepare("UPDATE enrollments SET status = 'dropped' WHERE student_id = ? AND offering_id = ? AND status = 'active'");
        foreach (array_diff($oldActive, $primary) as $id) {
            $drop->execute([$studentId, $id]);
        }
        $enroll = $pdo->prepare("INSERT INTO enrollments (student_id, offering_id, status, source)
            VALUES (?, ?, 'active', 'primary') ON DUPLICATE KEY UPDATE status = 'active', source = 'primary'");
        foreach ($primary as $id) {
            if (!in_array($id, $oldActive, true)) {
                $enroll->execute([$studentId, $id]);
            }
        }
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function dbDropStudentOffering(PDO $pdo, int $studentId, int $semesterId, string $id): array
{
    try {
        $pdo->beginTransaction();
        $period = dbLockedSelectionPeriod($pdo, $semesterId);
        if ($period === null || !selectionOpen($period)) {
            $pdo->rollBack();
            return ['选课已结束，不能退课。'];
        }
        $studentQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'student' AND is_active = 1 FOR UPDATE");
        $studentQuery->execute([$studentId]);
        if (!$studentQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['学生账号不可用。'];
        }
        $offeringQuery = $pdo->prepare('SELECT id FROM offerings WHERE id = ? AND semester_id = ? FOR UPDATE');
        $offeringQuery->execute([$id, $semesterId]);
        if (!$offeringQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['教学班不存在。'];
        }
        $drop = $pdo->prepare("UPDATE enrollments SET status = 'dropped'
            WHERE student_id = ? AND offering_id = ? AND status = 'active'");
        $drop->execute([$studentId, $id]);
        if ($drop->rowCount() !== 1) {
            $pdo->rollBack();
            return ['只能退选自己课表中的教学班。'];
        }
        $delete = $pdo->prepare("DELETE FROM selection_choices
            WHERE student_id = ? AND semester_id = ? AND offering_id = ? AND kind = 'primary'");
        $delete->execute([$studentId, $semesterId, $id]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
