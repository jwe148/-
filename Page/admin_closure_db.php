<?php
declare(strict_types=1);

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
          AND s.status = 'closed' AND g.grade_value IN ({$marks})");
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
        if ($now <= new DateTimeImmutable($period['selection_ends_at'], $zone)) {
            $pdo->rollBack();
            return ['changed' => false, 'errors' => ['选课截止后才能关闭。']];
        }
        $adminQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 FOR UPDATE");
        $adminQuery->execute([$adminId]);
        if (!$adminQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['changed' => false, 'errors' => ['教务员账号不可用。']];
        }
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
            $outcomes[$id] = $offering['teacher_id'] === null ? 'no_teacher'
                : ($counts[$id] < 3 ? 'under_minimum' : 'confirmed');
            if ($counts[$id] > (int) $offering['capacity']) {
                throw new RuntimeException('教学班有效人数超过容量：' . $id);
            }
        }

        $choiceQuery = $pdo->prepare('SELECT id, student_id, offering_id, kind, choice_rank, created_at
            FROM selection_choices WHERE semester_id = ? ORDER BY student_id, kind, choice_rank FOR UPDATE');
        $choiceQuery->execute([$semesterId]);
        $students = [];
        foreach ($choiceQuery->fetchAll() as $choice) {
            $studentId = (int) $choice['student_id'];
            if (!isset($students[$studentId])) {
                $students[$studentId] = ['created_at' => $choice['created_at'], 'primary' => [], 'backup' => [], 'gaps' => []];
            }
            if ($choice['created_at'] < $students[$studentId]['created_at']) {
                $students[$studentId]['created_at'] = $choice['created_at'];
            }
            $rank = (int) $choice['choice_rank'];
            if (!isset($offerings[$choice['offering_id']])) {
                throw new RuntimeException('选课记录与当前学期教学班不一致。');
            }
            if ($choice['kind'] === 'primary' && !isset($studentActive[$studentId][$choice['offering_id']])) {
                throw new RuntimeException('首选记录与有效占座不一致。');
            }
            $students[$studentId][$choice['kind']][$rank] = $choice;
            if ($choice['kind'] === 'primary' && $outcomes[$choice['offering_id']] !== 'confirmed') {
                $students[$studentId]['gaps'][] = $rank;
            }
        }

        foreach ($offerings as $id => $offering) {
            if ($outcomes[$id] === 'confirmed') {
                continue;
            }
            $pdo->prepare("UPDATE offerings SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE enrollments SET status = 'cancelled' WHERE offering_id = ? AND status = 'active'")->execute([$id]);
            $pdo->prepare("DELETE FROM selection_choices WHERE semester_id = ? AND offering_id = ? AND kind = 'primary'")->execute([$semesterId, $id]);
            foreach ($studentActive as &$active) {
                unset($active[$id]);
            }
            unset($active);
            $counts[$id] = 0;
        }

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
        uksort($students, static function (int $first, int $second) use (&$students): int {
            return strcmp($students[$first]['created_at'], $students[$second]['created_at']) ?: ($first <=> $second);
        });
        $promoteChoice = $pdo->prepare("UPDATE selection_choices SET kind = 'primary', choice_rank = ? WHERE id = ? AND kind = 'backup'");
        $recordAttempt = $pdo->prepare('INSERT INTO closure_backup_attempts
            (student_id, semester_id, backup_rank, offering_id, outcome) VALUES (?, ?, ?, ?, ?)');
        $enroll = $pdo->prepare("INSERT INTO enrollments (student_id, offering_id, status, source)
            VALUES (?, ?, 'active', 'backup') ON DUPLICATE KEY UPDATE status = 'active', source = 'backup'");
        foreach ($students as $studentId => $student) {
            if ($student['gaps'] === []) {
                continue;
            }
            sort($student['gaps'], SORT_NUMERIC);
            ksort($student['backup'], SORT_NUMERIC);
            $backupChoices = array_values($student['backup']);
            $nextBackup = 0;
            $activeIds = array_keys($studentActive[$studentId] ?? []);
            $completedCodes = dbClosingCompletedCodes($pdo, $studentId);
            foreach ($student['gaps'] as $gapRank) {
                while (isset($backupChoices[$nextBackup])) {
                    $backup = $backupChoices[$nextBackup++];
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
                    $activeIds[] = $id;
                    break;
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
        $pdo->commit();
        return ['changed' => true, 'errors' => []];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
