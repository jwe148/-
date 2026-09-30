<?php
declare(strict_types=1);

function dbTeacherClaims(PDO $pdo, int $teacherId, int $semesterId): array
{
    $query = $pdo->prepare('SELECT id FROM offerings WHERE teacher_id = ? AND semester_id = ? ORDER BY id');
    $query->execute([$teacherId, $semesterId]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
}

function dbChangeTeacherClaim(PDO $pdo, int $teacherId, int $semesterId, string $offeringId, string $action): array
{
    if (!in_array($action, ['claim', 'release'], true) || $offeringId === '' || strlen($offeringId) > 32) {
        return ['无效的操作。'];
    }

    try {
        $pdo->beginTransaction();
        $period = dbLockedSelectionPeriod($pdo, $semesterId);
        if ($period === null || !selectionOpen($period)) {
            $pdo->rollBack();
            return ['选课已结束，不能调整授课教学班。'];
        }

        // 同一教师的认领串行执行；教学班行锁阻止不同教师认领同一班。
        $teacherQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1 FOR UPDATE");
        $teacherQuery->execute([$teacherId]);
        if (!$teacherQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['教师账号不可用。'];
        }
        $offeringQuery = $pdo->prepare('SELECT id, teacher_id, status FROM offerings WHERE id = ? AND semester_id = ? FOR UPDATE');
        $offeringQuery->execute([$offeringId, $semesterId]);
        $offering = $offeringQuery->fetch();
        if (!is_array($offering)) {
            $pdo->rollBack();
            return ['教学班不存在。'];
        }

        if ($action === 'release') {
            if ($offering['teacher_id'] === null || (int) $offering['teacher_id'] !== $teacherId) {
                $pdo->rollBack();
                return ['只能取消自己认领的教学班。'];
            }
            $claimQuery = $pdo->prepare('SELECT teacher_id FROM teacher_claims WHERE offering_id = ? FOR UPDATE');
            $claimQuery->execute([$offeringId]);
            if ((int) $claimQuery->fetchColumn() !== $teacherId) {
                $pdo->rollBack();
                return ['只能取消自己认领的教学班。'];
            }
            $delete = $pdo->prepare('DELETE FROM teacher_claims WHERE offering_id = ? AND teacher_id = ?');
            $delete->execute([$offeringId, $teacherId]);
            $update = $pdo->prepare('UPDATE offerings SET teacher_id = NULL WHERE id = ? AND teacher_id = ?');
            $update->execute([$offeringId, $teacherId]);
        } else {
            if ($offering['status'] !== 'open') {
                $pdo->rollBack();
                return ['该教学班当前不可认领。'];
            }
            if ($offering['teacher_id'] !== null) {
                $pdo->rollBack();
                return [(int) $offering['teacher_id'] === $teacherId ? '你已认领该教学班。' : '该教学班已有授课教师。'];
            }
            // 先锁定教师和目标班，再以当前读检查数据库中的全部上课时段。
            $conflictQuery = $pdo->prepare("SELECT EXISTS(
                SELECT 1 FROM offering_times target_time
                JOIN offering_times assigned_time
                  ON assigned_time.weekday = target_time.weekday
                 AND target_time.starts_at < assigned_time.ends_at
                 AND assigned_time.starts_at < target_time.ends_at
                JOIN offerings assigned ON assigned.id = assigned_time.offering_id
                WHERE target_time.offering_id = ? AND assigned.teacher_id = ?
                  AND assigned.semester_id = ? AND assigned.status <> 'cancelled'
            )");
            $conflictQuery->execute([$offeringId, $teacherId, $semesterId]);
            if ((int) $conflictQuery->fetchColumn() === 1) {
                $pdo->rollBack();
                return ['与已任教的教学班上课时间冲突。'];
            }
            $update = $pdo->prepare('UPDATE offerings SET teacher_id = ? WHERE id = ? AND teacher_id IS NULL');
            $update->execute([$teacherId, $offeringId]);
            $insert = $pdo->prepare('INSERT INTO teacher_claims (offering_id, teacher_id) VALUES (?, ?)');
            $insert->execute([$offeringId, $teacherId]);
        }

        if ($update->rowCount() !== 1) {
            throw new RuntimeException('教师认领状态已变化。');
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
