<?php
declare(strict_types=1);

/** 空下拉项不算选课；保留其他类型，让输入校验拒绝无效数据。 */
function normalizeStudentChoices(array $choices): array
{
    return array_values(array_filter($choices, static fn($id): bool => $id !== ''));
}

function studentSelectionInputErrors(array $primary, array $backup): array
{
    if (count($primary) > 4 || count($backup) > 2) {
        return ['首选最多选择 4 个教学班，备选最多选择 2 个教学班，可以留空。'];
    }
    $all = array_merge($primary, $backup);
    foreach ($all as $id) {
        if (!is_string($id) || $id === '' || strlen($id) > 32) {
            return ['选课方案中包含无效的教学班编号。'];
        }
    }
    if (count(array_unique($all)) !== count($all)) {
        return ['所选教学班必须各不相同。'];
    }
    return [];
}

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
        FROM enrollments e LEFT JOIN grades g ON g.enrollment_id = e.id
        JOIN offerings o ON o.id = e.offering_id JOIN courses c ON c.code = o.course_code
        JOIN semesters s ON s.id = o.semester_id
        WHERE e.student_id = ? AND e.status = 'active' AND o.status = 'confirmed'
          AND s.status = 'closed' AND s.teaching_ends_at IS NOT NULL
          AND s.teaching_ends_at <= CURRENT_TIMESTAMP
        ORDER BY s.selection_starts_at DESC, c.name");
    $query->execute([$studentId]);
    return $query->fetchAll();
}

function dbCompletedCourseCodes(PDO $pdo, int $studentId): array
{
    // 历史中文成绩及 A-D 视为通过，用于先修课程判断。
    $passing = ['优秀', '良好', '中等', '及格', 'A', 'B', 'C', 'D'];
    $marks = implode(',', array_fill(0, count($passing), '?'));
    $query = $pdo->prepare("SELECT DISTINCT c.code FROM grades g
        JOIN enrollments e ON e.id = g.enrollment_id
        JOIN offerings o ON o.id = e.offering_id JOIN courses c ON c.code = o.course_code
        JOIN semesters s ON s.id = o.semester_id
        WHERE e.student_id = ? AND e.status = 'active' AND o.status = 'confirmed'
          AND s.status = 'closed' AND s.teaching_ends_at IS NOT NULL
          AND s.teaching_ends_at <= CURRENT_TIMESTAMP AND g.grade_value IN ({$marks})");
    $query->execute(array_merge([$studentId], $passing));
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

// 名称仅供展示和旧演示校验使用，数据库提交必须比较课程代码。
function dbCompletedCourseNames(PDO $pdo, int $studentId): array
{
    $codes = dbCompletedCourseCodes($pdo, $studentId);
    require_once __DIR__.'/data/catalog_provider.php';
    $definitions=readCourseDefinitions($pdo);
    return array_map(static fn($code)=>$definitions[$code]['name'] ?? $code,$codes);
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

/**
 * 保存学生的首选、备选教学班编号；成功返回空数组，拒绝时返回错误列表。
 * 同一事务内校验并替换原方案，首选占座、备选不占座，重提不会重复占座。
 */
function dbSaveStudentSelection(PDO $pdo, int $studentId, int $semesterId, array $primary, array $backup, array $catalog): array
{
    $primary = normalizeStudentChoices($primary);
    $backup = normalizeStudentChoices($backup);
    $errors = studentSelectionInputErrors($primary, $backup);
    if ($errors !== []) {
        return $errors;
    }
    $all = array_merge($primary, $backup);

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
        // 空方案且没有原占座时仍可提交，避免生成 IN ()。
        $marks = $ids === [] ? 'NULL' : implode(',', array_fill(0, count($ids), '?'));
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
        // 从数据库读取先修关系，不能把同名课程或旧页面目录当作通过凭证。
        $prerequisiteQuery = $pdo->prepare("SELECT o.id, cp.prerequisite_code
            FROM offerings o JOIN course_prerequisites cp ON cp.course_code = o.course_code
            WHERE o.semester_id = ? AND o.id IN ({$marks}) ORDER BY o.id, cp.prerequisite_code");
        $prerequisiteQuery->execute(array_merge([$semesterId], $ids));
        $required = [];
        foreach ($prerequisiteQuery->fetchAll() as $row) {
            $required[$row['id']][] = $row['prerequisite_code'];
        }
        require_once __DIR__ . '/data/catalog_provider.php';
        $definitions = readCourseDefinitions($pdo);
        $validationCatalog = [];
        foreach ($locked as $id => $row) {
            if (!isset($catalog[$id])) {
                continue;
            }
            $validationCatalog[$id] = $catalog[$id];
            $validationCatalog[$id]['prerequisite_codes'] = $required[$id] ?? [];
            $validationCatalog[$id]['prerequisites'] = array_map(
                static fn(string $code): string => $definitions[$code]['name'] ?? $code, $required[$id] ?? []
            );
            $validationCatalog[$id]['capacity'] = (int) $row['capacity'];
            $validationCatalog[$id]['status'] = $row['status'];
            $validationCatalog[$id]['selected'] = max(0, ($counts[$id] ?? 0) - (in_array($id, $oldActive, true) ? 1 : 0));
        }
        $errors = validateStudentSelection($primary, $backup, $validationCatalog, dbCompletedCourseCodes($pdo, $studentId), $oldActive);
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
        $pdo->prepare('DELETE FROM selection_drafts WHERE student_id=? AND semester_id=?')->execute([$studentId,$semesterId]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** 退掉本人的一个教学班并释放名额；失败回滚，返回错误列表。 */
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
