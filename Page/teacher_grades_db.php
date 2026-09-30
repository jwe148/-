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
    return $offering;
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
        $teacherQuery = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'teacher' AND is_active = 1 FOR UPDATE");
        $teacherQuery->execute([$teacherId]);
        if (!$teacherQuery->fetchColumn()) {
            $pdo->rollBack();
            return ['教师账号不可用。'];
        }
        $offeringQuery = $pdo->prepare("SELECT o.id, o.status, o.teacher_id, s.status AS semester_status
            FROM offerings o JOIN semesters s ON s.id = o.semester_id
            WHERE o.id = ? FOR UPDATE");
        $offeringQuery->execute([$offeringId]);
        $offering = $offeringQuery->fetch();
        if (!is_array($offering) || (int) $offering['teacher_id'] !== $teacherId
            || $offering['status'] !== 'confirmed' || $offering['semester_status'] !== 'closed') {
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
        $save = $pdo->prepare('INSERT INTO grades (enrollment_id, grade_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE grade_value = VALUES(grade_value)');
        $save->execute([$enrollmentId, $grade === '' ? null : $grade]);
        $pdo->commit();
        return [];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
