<?php
declare(strict_types=1);
require_once __DIR__ . '/billing_db.php';

function dbSetSelectionPeriod(PDO $pdo, int $adminId, int $semesterId, string $start, string $end, string $teachingEnd): array
{
    $dates = [];
    foreach ([$start, $end, $teachingEnd] as $value) {
        $value = str_replace('T', ' ', $value);
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Asia/Shanghai'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $value || $value < '1000-01-01 00:00:00') {
            return ['请输入有效的开始、截止和教学结束时间。'];
        }
        $dates[] = $value;
    }
    if ($dates[0] >= $dates[1] || $dates[2] < $dates[1]) {
        return ['开始时间须早于选课截止，教学结束不能早于选课截止。'];
    }
    try {
        $pdo->beginTransaction();
        $periodQuery = $pdo->prepare('SELECT status, selection_starts_at, selection_ends_at FROM semesters WHERE id = ? FOR UPDATE');
        $periodQuery->execute([$semesterId]);
        $period = $periodQuery->fetch();
        $adminQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE");
        $adminQuery->execute([$adminId]);
        if (!$period || !$adminQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['学期不存在或无权修改。'];
        }
        if ($period['status'] === 'closing') {
            $pdo->rollBack();
            return ['关课正在处理，请稍后修改。'];
        }
        if ($period['status'] === 'closed' && ($dates[0] !== $period['selection_starts_at'] || $dates[1] !== $period['selection_ends_at'])) {
            $pdo->rollBack();
            return ['已关闭选课不能重新开放，只能维护教学结束时间。'];
        }
        $save = $pdo->prepare('UPDATE semesters SET selection_starts_at = ?, selection_ends_at = ?, teaching_ends_at = ? WHERE id = ?');
        $save->execute([$dates[0], $dates[1], $dates[2], $semesterId]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function dbClosureOverview(PDO $pdo, int $semesterId): array
{
    $query = $pdo->prepare("SELECT o.id, o.class_name, c.name AS course_name, o.capacity, o.status,
        COALESCE(u.display_name, '待认领') AS teacher_name,
        (SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = 'active') AS active_count,
        (SELECT COUNT(*) FROM enrollments e WHERE e.offering_id = o.id AND e.status = 'active' AND e.source = 'backup') AS backup_count,
        r.original_active_count, r.outcome
        FROM offerings o JOIN courses c ON c.code = o.course_code
        LEFT JOIN users u ON u.id = o.teacher_id
        LEFT JOIN offering_closure_results r ON r.offering_id = o.id
        WHERE o.semester_id = ? ORDER BY o.id");
    $query->execute([$semesterId]);
    return $query->fetchAll();
}

function dbClosingCompletedCodes(PDO $pdo, int $studentId): array
{
    $passing = ['优秀', '良好', '中等', '及格', 'A', 'B', 'C', 'D'];
    $marks = implode(',', array_fill(0, count($passing), '?'));
    $query = $pdo->prepare("SELECT DISTINCT o.course_code FROM grades g
        JOIN enrollments e ON e.id = g.enrollment_id
        JOIN offerings o ON o.id = e.offering_id JOIN semesters s ON s.id = o.semester_id
        WHERE e.student_id = ? AND e.status = 'active' AND o.status = 'confirmed'
          AND s.status = 'closed' AND s.teaching_ends_at IS NOT NULL
          AND s.teaching_ends_at <= CURRENT_TIMESTAMP AND g.grade_value IN ({$marks})");
    $query->execute(array_merge([$studentId], $passing));
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

function dbClosureTimeConflict(string $candidateId, array $activeIds, array $times): bool
{
    foreach ($activeIds as $activeId) {
        foreach ($times[$candidateId] ?? [] as $candidateTime) {
            foreach ($times[$activeId] ?? [] as $activeTime) {
                if ($candidateTime['weekday'] === $activeTime['weekday']
                    && $candidateTime['starts_at'] < $activeTime['ends_at']
                    && $activeTime['starts_at'] < $candidateTime['ends_at']) {
                    return true;
                }
            }
        }
    }
    return false;
}

function dbReopenSemester(PDO $pdo, int $adminId, int $semesterId, string $newEnd = '', string $teachingEnd = ''): array
{
    $zone = new DateTimeZone('Asia/Shanghai');
    $now = new DateTimeImmutable('now', $zone);
    $newEnd = $newEnd === '' ? $now->modify('+7 days')->format('Y-m-d H:i:s') : $newEnd;
    $automaticTeachingEnd = $teachingEnd === '';
    $teachingEnd = $automaticTeachingEnd ? $newEnd : $teachingEnd;
    $dates = [];
    foreach ([$newEnd, $teachingEnd] as $value) {
        $value = str_replace('T', ' ', $value);
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
        if (!$date || $date->format('Y-m-d H:i:s') !== $value || $value < '1000-01-01 00:00:00') {
            return ['请输入有效的新截止时间和教学结束时间。'];
        }
        $dates[] = $value;
    }
    $now = new DateTimeImmutable('now', $zone);
    if ($dates[0] <= $now->format('Y-m-d H:i:s')) {
        return ['重新开启后的选课截止时间必须晚于当前时间。'];
    }
    if ($dates[1] < $dates[0]) {
        return ['教学结束时间不能早于新的选课截止时间。'];
    }

    try {
        $pdo->beginTransaction();
        $periodQuery = $pdo->prepare('SELECT selection_starts_at, teaching_ends_at, status FROM semesters WHERE id = ? FOR UPDATE');
        $periodQuery->execute([$semesterId]);
        $period = $periodQuery->fetch();
        $adminQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE");
        $adminQuery->execute([$adminId]);
        if (!is_array($period) || !$adminQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['学期不存在或教务员账号不可用。'];
        }
        if (!in_array($period['status'], ['open', 'closed'], true)) {
            $pdo->rollBack();
            return ['选课正在处理中，请稍后重试。'];
        }
        $start = min($period['selection_starts_at'], $now->format('Y-m-d H:i:s'));
        if ($automaticTeachingEnd) {
            $dates[1] = max($period['teaching_ends_at'] ?? $dates[0], $dates[0]);
        }
        if ($period['status'] === 'open') {
            $pdo->prepare('UPDATE semesters SET selection_starts_at=?,selection_ends_at=?,teaching_ends_at=? WHERE id=?')
                ->execute([$start, $dates[0], $dates[1], $semesterId]);
            $pdo->commit();
            return [];
        }

        $gradeQuery = $pdo->prepare('SELECT COUNT(*) FROM grades g
            JOIN enrollments e ON e.id = g.enrollment_id
            JOIN offerings o ON o.id = e.offering_id WHERE o.semester_id = ?');
        $gradeQuery->execute([$semesterId]);
        $submissionQuery = $pdo->prepare('SELECT COUNT(*) FROM grade_submissions gs
            JOIN offerings o ON o.id = gs.offering_id WHERE o.semester_id = ?');
        $submissionQuery->execute([$semesterId]);
        if ((int) $gradeQuery->fetchColumn() > 0 || ((int) ($submissionQuery->fetchColumn())) > 0) {
            $pdo->rollBack();
            return ['本学期已经录入或确认成绩，不能重新开启选课。'];
        }

        $snapshotQuery = $pdo->prepare('SELECT snapshot FROM semester_closure_snapshots WHERE semester_id = ? FOR UPDATE');
        $snapshotQuery->execute([$semesterId]);
        $encoded = $snapshotQuery->fetchColumn();
        if ($encoded !== false) {
            $snapshot = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || !is_array($snapshot['offerings'] ?? null)
                || !is_array($snapshot['choices'] ?? null) || !is_array($snapshot['enrollments'] ?? null)) {
                throw new RuntimeException('关闭前快照格式无效。');
            }

            $pdo->prepare('DELETE FROM selection_choices WHERE semester_id = ?')->execute([$semesterId]);
            $restoreChoice = $pdo->prepare('INSERT INTO selection_choices
                (id, student_id, semester_id, offering_id, kind, choice_rank, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($snapshot['choices'] as $choice) {
                $restoreChoice->execute([$choice['id'], $choice['student_id'], $semesterId,
                    $choice['offering_id'], $choice['kind'], $choice['choice_rank'], $choice['created_at']]);
            }

            $snapshotEnrollmentIds = array_map(static fn(array $row): int => (int) $row['id'], $snapshot['enrollments']);
            $deleteSql = "DELETE e FROM enrollments e JOIN offerings o ON o.id = e.offering_id WHERE o.semester_id = ?";
            $deleteArgs = [$semesterId];
            if ($snapshotEnrollmentIds !== []) {
                $deleteSql .= ' AND e.id NOT IN (' . implode(',', array_fill(0, count($snapshotEnrollmentIds), '?')) . ')';
                $deleteArgs = array_merge($deleteArgs, $snapshotEnrollmentIds);
            }
            $pdo->prepare($deleteSql)->execute($deleteArgs);
            $restoreEnrollment = $pdo->prepare('UPDATE enrollments SET status = ?, source = ?, registered_at = ? WHERE id = ?');
            foreach ($snapshot['enrollments'] as $enrollment) {
                $restoreEnrollment->execute([$enrollment['status'], $enrollment['source'], $enrollment['registered_at'], $enrollment['id']]);
            }
            if ($snapshotEnrollmentIds !== []) {
                $verifyEnrollment = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE id IN ('
                    . implode(',', array_fill(0, count($snapshotEnrollmentIds), '?')) . ')');
                $verifyEnrollment->execute($snapshotEnrollmentIds);
                if ((int) $verifyEnrollment->fetchColumn() !== count($snapshotEnrollmentIds)) {
                    throw new RuntimeException('关闭前占座记录恢复失败。');
                }
            }
            $restoreOffering = $pdo->prepare('UPDATE offerings SET status = ? WHERE id = ? AND semester_id = ?');
            foreach ($snapshot['offerings'] as $offeringId => $status) {
                $restoreOffering->execute([$status, $offeringId, $semesterId]);
                if ($restoreOffering->rowCount() > 1) {
                    throw new RuntimeException('关闭前教学班状态恢复失败。');
                }
            }
        } else {
            // 兼容修复前已关闭的学期：补位记录保存了备选原序，原首选由占座和空缺名次恢复。
            $attemptQuery = $pdo->prepare("SELECT student_id, backup_rank, offering_id FROM closure_backup_attempts
                WHERE semester_id = ? AND outcome = 'promoted' ORDER BY student_id, backup_rank FOR UPDATE");
            $attemptQuery->execute([$semesterId]);
            foreach ($attemptQuery->fetchAll() as $attempt) {
                $choice = $pdo->prepare("UPDATE selection_choices SET kind = 'backup', choice_rank = ?
                    WHERE student_id = ? AND semester_id = ? AND offering_id = ? AND kind = 'primary'");
                $choice->execute([$attempt['backup_rank'], $attempt['student_id'], $semesterId, $attempt['offering_id']]);
            }
            $pdo->prepare("DELETE e FROM enrollments e JOIN offerings o ON o.id=e.offering_id
                WHERE o.semester_id=? AND e.source='backup'")->execute([$semesterId]);
            $pdo->prepare("UPDATE enrollments e JOIN offerings o ON o.id=e.offering_id
                SET e.status='active' WHERE o.semester_id=? AND e.source='primary' AND e.status='cancelled'")->execute([$semesterId]);

            $missingQuery = $pdo->prepare("SELECT e.student_id, e.offering_id FROM enrollments e
                JOIN offerings o ON o.id=e.offering_id
                LEFT JOIN selection_choices c ON c.student_id=e.student_id AND c.semester_id=o.semester_id
                    AND c.offering_id=e.offering_id AND c.kind='primary'
                WHERE o.semester_id=? AND e.status='active' AND e.source='primary' AND c.id IS NULL
                ORDER BY e.student_id,e.id");
            $missingQuery->execute([$semesterId]);
            $insertChoice = $pdo->prepare("INSERT INTO selection_choices
                (student_id,semester_id,offering_id,kind,choice_rank) VALUES (?,?,?,'primary',?)");
            foreach ($missingQuery->fetchAll() as $missing) {
                $rankQuery = $pdo->prepare("SELECT choice_rank FROM selection_choices
                    WHERE student_id=? AND semester_id=? AND kind='primary' ORDER BY choice_rank FOR UPDATE");
                $rankQuery->execute([$missing['student_id'], $semesterId]);
                $used = array_map('intval', $rankQuery->fetchAll(PDO::FETCH_COLUMN));
                $available = array_values(array_diff(range(1, 4), $used));
                if ($available === []) {
                    throw new RuntimeException('无法恢复原首选顺序。');
                }
                $insertChoice->execute([$missing['student_id'], $semesterId, $missing['offering_id'], $available[0]]);
            }
            $pdo->prepare("UPDATE offerings SET status='open' WHERE semester_id=?")->execute([$semesterId]);
        }

        $pdo->prepare('DELETE FROM billing_outbox WHERE semester_id = ?')->execute([$semesterId]);
        $pdo->prepare('DELETE FROM closure_backup_attempts WHERE semester_id = ?')->execute([$semesterId]);
        $pdo->prepare('DELETE r FROM offering_closure_results r JOIN offerings o ON o.id=r.offering_id WHERE o.semester_id = ?')->execute([$semesterId]);
        $pdo->prepare('DELETE FROM semester_closure_snapshots WHERE semester_id = ?')->execute([$semesterId]);
        $pdo->prepare("UPDATE semesters SET status='open', closed_at=NULL, selection_starts_at=?, selection_ends_at=?, teaching_ends_at=? WHERE id=?")
            ->execute([$start, $dates[0], $dates[1], $semesterId]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * 管理员随时关闭指定学期，事务内完成停开、按备选顺序补位及计费记录生成。
 * 先补位再判断最低开班人数；返回结果或错误列表，异常时回滚全部修改。
 */
function dbCloseSemester(PDO $pdo, int $adminId, int $semesterId): array
{
    try {
        $pdo->beginTransaction();
        // 学生与教师写入均先持有此行的共享锁；独占锁会等待这些请求完成。
        $periodQuery = $pdo->prepare('SELECT selection_ends_at, status FROM semesters WHERE id = ? FOR UPDATE');
        $periodQuery->execute([$semesterId]);
        $period = $periodQuery->fetch();
        if (!is_array($period)) {
            $pdo->rollBack();
            return ['changed' => false, 'errors' => ['当前学期不存在。']];
        }
        // 重复关课也必须验证教务身份，不能先返回已关闭结果。
        $adminQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE");
        $adminQuery->execute([$adminId]);
        if (!$adminQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['changed' => false, 'errors' => ['教务员账号不可用。']];
        }
        if ($period['status'] === 'closed') {
            $pdo->rollBack();
            return ['changed' => false, 'errors' => []];
        }
        if ($period['status'] !== 'open') {
            $pdo->rollBack();
            return ['changed' => false, 'errors' => ['选课正在关闭，请稍后查看结果。']];
        }
        $zone = new DateTimeZone('Asia/Shanghai');
        $now = new DateTimeImmutable('now', $zone);
        $pdo->prepare("UPDATE semesters SET status = 'closing' WHERE id = ?")->execute([$semesterId]);

        $offeringQuery = $pdo->prepare('SELECT id, course_code, teacher_id, capacity, status FROM offerings WHERE semester_id = ? ORDER BY id FOR UPDATE');
        $offeringQuery->execute([$semesterId]);
        $offerings = [];
        foreach ($offeringQuery->fetchAll() as $row) {
            if ($row['status'] !== 'open') {
                throw new RuntimeException('选课未关闭，但教学班已存在最终状态。');
            }
            $offerings[$row['id']] = $row;
        }
        if ($offerings === []) {
            throw new RuntimeException('当前学期没有教学班。');
        }

        $seatQuery = $pdo->prepare("SELECT e.id, e.student_id, e.offering_id FROM enrollments e
            JOIN offerings o ON o.id = e.offering_id
            WHERE o.semester_id = ? AND e.status = 'active' ORDER BY e.offering_id, e.id FOR UPDATE");
        $seatQuery->execute([$semesterId]);
        $seats = $seatQuery->fetchAll();
        $counts = array_fill_keys(array_keys($offerings), 0);
        $studentActive = [];
        foreach ($seats as $seat) {
            $id = $seat['offering_id'];
            $counts[$id]++;
            $studentActive[(int) $seat['student_id']][$id] = true;
        }
        $originalCounts = $counts;
        $outcomes = [];
        foreach ($offerings as $id => $offering) {
            // 有教师的班先参与补位；三人下限必须在补位之后判断。
            $outcomes[$id] = $offering['teacher_id'] === null ? 'no_teacher' : 'confirmed';
            if ($counts[$id] > (int) $offering['capacity']) {
                throw new RuntimeException('教学班有效人数超过容量：' . $id);
            }
        }

        // 最早保存方案的学生先补位；时间相同时按学生编号排序。
        $choiceQuery = $pdo->prepare('SELECT id, student_id, offering_id, kind, choice_rank, created_at
            FROM selection_choices WHERE semester_id = ? ORDER BY created_at, student_id, kind, choice_rank FOR UPDATE');
        $choiceQuery->execute([$semesterId]);
        $choiceRows = $choiceQuery->fetchAll();
        $students = [];
        foreach ($choiceRows as $choice) {
            $studentId = (int) $choice['student_id'];
            if (!isset($students[$studentId])) {
                $students[$studentId] = ['primary' => [], 'backup' => []];
            }
            $rank = (int) $choice['choice_rank'];
            if (!isset($offerings[$choice['offering_id']])) {
                throw new RuntimeException('选课记录与当前学期教学班不一致。');
            }
            if ($choice['kind'] === 'primary' && !isset($studentActive[$studentId][$choice['offering_id']])) {
                throw new RuntimeException('首选记录与有效占座不一致。');
            }
            $students[$studentId][$choice['kind']][$rank] = $choice;
        }

        $snapshotEnrollmentQuery = $pdo->prepare('SELECT e.id, e.student_id, e.offering_id, e.status, e.source, e.registered_at
            FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE o.semester_id=? ORDER BY e.id FOR UPDATE');
        $snapshotEnrollmentQuery->execute([$semesterId]);
        $snapshot = [
            'version' => 1,
            'offerings' => array_map(static fn(array $row): string => (string) $row['status'], $offerings),
            'choices' => $choiceRows,
            'enrollments' => $snapshotEnrollmentQuery->fetchAll(),
        ];
        $snapshotSave = $pdo->prepare('INSERT INTO semester_closure_snapshots (semester_id,snapshot) VALUES (?,?)
            ON DUPLICATE KEY UPDATE snapshot=VALUES(snapshot), created_at=CURRENT_TIMESTAMP');
        $snapshotSave->execute([$semesterId, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);

        $timeQuery = $pdo->prepare('SELECT t.offering_id, t.weekday, t.starts_at, t.ends_at
            FROM offering_times t JOIN offerings o ON o.id = t.offering_id WHERE o.semester_id = ?');
        $timeQuery->execute([$semesterId]);
        $times = [];
        foreach ($timeQuery->fetchAll() as $time) {
            $times[$time['offering_id']][] = $time;
        }
        $prerequisites = [];
        foreach ($pdo->query('SELECT course_code, prerequisite_code FROM course_prerequisites')->fetchAll() as $row) {
            $prerequisites[$row['course_code']][] = $row['prerequisite_code'];
        }
        $promoteChoice = $pdo->prepare("UPDATE selection_choices SET kind = 'primary', choice_rank = ? WHERE id = ? AND kind = 'backup'");
        $recordAttempt = $pdo->prepare('INSERT INTO closure_backup_attempts
            (student_id, semester_id, backup_rank, offering_id, outcome) VALUES (?, ?, ?, ?, ?)');
        $enroll = $pdo->prepare("INSERT INTO enrollments (student_id, offering_id, status, source)
            VALUES (?, ?, 'active', 'backup') ON DUPLICATE KEY UPDATE status = 'active', source = 'backup'");
        $backupQueues = [];
        $nextBackups = [];
        foreach ($students as $studentId => $student) {
            ksort($student['backup'], SORT_NUMERIC);
            $backupQueues[$studentId] = array_values($student['backup']);
            $nextBackups[$studentId] = 0;
        }
        $promotions = [];
        // 两轮使用同一套取消和补位步骤，备选按原顺序最多尝试一次。
        for ($round = 1; $round <= 2; $round++) {
            foreach ($offerings as $id => $offering) {
                if ($round === 1) {
                    // 第一轮：取消无教师的班；有教师的班先参与补位。
                    if ($outcomes[$id] !== 'no_teacher') {
                        continue;
                    }
                } else {
                    // 第二轮：首轮补位后仍不足三人的班停开，再填补新增空缺。
                    if ($outcomes[$id] !== 'confirmed' || $counts[$id] >= 3) {
                        continue;
                    }
                    $outcomes[$id] = 'under_minimum';
                }
                // 第一轮补入的班仍可能不足三人。恢复其备选记录与原顺序，
                // 将尝试结果改为停开，避免把最终已取消的课显示为补入成功。
                foreach ($promotions as $studentId => $promoted) {
                    if (!isset($promoted[$id])) {
                        continue;
                    }
                    $backup = $promoted[$id];
                    $restore = $pdo->prepare("UPDATE selection_choices SET kind = 'backup', choice_rank = ? WHERE id = ? AND kind = 'primary'");
                    $restore->execute([$backup['choice_rank'], $backup['id']]);
                    if ($restore->rowCount() !== 1) {
                        throw new RuntimeException('停开教学班的备选记录恢复失败。');
                    }
                    $students[$studentId]['backup'][(int) $backup['choice_rank']] = $backup;
                    $pdo->prepare("UPDATE closure_backup_attempts SET outcome = 'cancelled' WHERE student_id = ? AND semester_id = ? AND backup_rank = ?")
                        ->execute([$studentId, $semesterId, $backup['choice_rank']]);
                    unset($promotions[$studentId][$id]);
                }
                $pdo->prepare("UPDATE offerings SET status = 'cancelled' WHERE id = ?")->execute([$id]);
                $pdo->prepare("UPDATE enrollments SET status = 'cancelled' WHERE offering_id = ? AND status = 'active'")->execute([$id]);
                $pdo->prepare("DELETE FROM selection_choices WHERE semester_id = ? AND offering_id = ? AND kind = 'primary'")->execute([$semesterId, $id]);
                foreach ($studentActive as $studentId => $active) {
                    unset($studentActive[$studentId][$id]);
                }
                foreach ($students as $studentId => $student) {
                    foreach ($student['primary'] as $rank => $choice) {
                        if ($choice['offering_id'] === $id) {
                            unset($students[$studentId]['primary'][$rank]);
                        }
                    }
                }
                $counts[$id] = 0;
            }
            foreach ($students as $studentId => $student) {
                // 包括学生主动退课留下的空位，不局限于本次停开产生的空位。
                $gaps = array_diff(range(1, 4), array_keys($student['primary']));
                if ($gaps === []) {
                    continue;
                }
                $activeIds = array_keys($studentActive[$studentId] ?? []);
                $completedCodes = dbClosingCompletedCodes($pdo, $studentId);
                foreach ($gaps as $gapRank) {
                    while (isset($backupQueues[$studentId][$nextBackups[$studentId]])) {
                        $backup = $backupQueues[$studentId][$nextBackups[$studentId]++];
                        $id = $backup['offering_id'];
                        $courseCode = $offerings[$id]['course_code'];
                        $duplicateCourse = false;
                        foreach ($activeIds as $activeId) {
                            if ($offerings[$activeId]['course_code'] === $courseCode) {
                                $duplicateCourse = true;
                                break;
                            }
                        }
                        $outcome = 'promoted';
                        if ($outcomes[$id] !== 'confirmed') {
                            $outcome = 'cancelled';
                        } elseif ($counts[$id] >= (int) $offerings[$id]['capacity']) {
                            $outcome = 'full';
                        } elseif (($times[$id] ?? []) === []) {
                            $outcome = 'missing_time';
                        } elseif (array_diff($prerequisites[$courseCode] ?? [], $completedCodes) !== []) {
                            $outcome = 'prerequisite';
                        } elseif ($duplicateCourse) {
                            $outcome = 'duplicate_course';
                        } elseif (dbClosureTimeConflict($id, $activeIds, $times)) {
                            $outcome = 'time_conflict';
                        }
                        $recordAttempt->execute([$studentId, $semesterId, $backup['choice_rank'], $id, $outcome]);
                        if ($outcome !== 'promoted') {
                            continue;
                        }
                        $promoteChoice->execute([$gapRank, $backup['id']]);
                        if ($promoteChoice->rowCount() !== 1) {
                            throw new RuntimeException('备选记录补位失败。');
                        }
                        $enroll->execute([$studentId, $id]);
                        $counts[$id]++;
                        $studentActive[$studentId][$id] = true;
                        $promotions[$studentId][$id] = $backup;
                        $primary = $backup;
                        $primary['kind'] = 'primary';
                        $primary['choice_rank'] = $gapRank;
                        $students[$studentId]['primary'][$gapRank] = $primary;
                        unset($students[$studentId]['backup'][(int) $backup['choice_rank']]);
                        $activeIds[] = $id;
                        break;
                    }
                }
            }
        }

        $resultInsert = $pdo->prepare('INSERT INTO offering_closure_results (offering_id, original_active_count, outcome) VALUES (?, ?, ?)');
        $confirm = $pdo->prepare("UPDATE offerings SET status = 'confirmed' WHERE id = ?");
        foreach ($offerings as $id => $offering) {
            if ($outcomes[$id] === 'confirmed') {
                $confirm->execute([$id]);
            }
            $resultInsert->execute([$id, $originalCounts[$id], $outcomes[$id]]);
        }
        $pdo->prepare("UPDATE semesters SET status = 'closed', closed_at = ? WHERE id = ?")
            ->execute([$now->format('Y-m-d H:i:s'), $semesterId]);
        dbQueueBilling($pdo, $semesterId);
        $pdo->commit();
        return ['changed' => true, 'errors' => []];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
