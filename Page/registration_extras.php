<?php
declare(strict_types=1);

function dbSelectionDraft(PDO $pdo, int $student, int $semester): ?array
{
    $q = $pdo->prepare('SELECT choices FROM selection_drafts WHERE student_id=? AND semester_id=?');
    $q->execute([$student, $semester]);
    $json = $q->fetchColumn();
    return $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

function draftErrors(array $primary, array $backup, array $catalog): array
{
    if (count($primary)>4 || count($backup)>2) return ['草稿最多保存4个首选、2个备选。'];
    foreach (array_merge($primary, $backup) as $id) {
        if (!is_string($id) || ($id !== '' && !isset($catalog[$id]))) return ['草稿包含无效教学班。'];
    }
    // 草稿可未完成或有冲突，正式提交时才校验全部业务规则。
    return [];
}

function dbChangeStudentPlan(PDO $pdo, int $student, int $semester, string $action, array $primary=[], array $backup=[], array $catalog=[]): array
{
    if (!in_array($action, ['draft','delete'], true)) return ['无效操作。'];
    $errors = $action === 'draft' ? draftErrors($primary, $backup, $catalog) : [];
    if ($errors) return $errors;
    try {
        $pdo->beginTransaction();
        $period = dbLockedSelectionPeriod($pdo, $semester);
        if ($period === null || !selectionOpen($period)) {
            $pdo->rollBack(); return ['选课已结束，不能修改课表。'];
        }
        $q = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='student' AND is_active=1 FOR UPDATE");
        $q->execute([$student]);
        if (!$q->fetchColumn()) { $pdo->rollBack(); return ['学生账号不可用。']; }
        if ($action === 'draft') {
            $q = $pdo->prepare('INSERT INTO selection_drafts(student_id,semester_id,choices) VALUES(?,?,?) ON DUPLICATE KEY UPDATE choices=VALUES(choices)');
            $q->execute([$student,$semester,json_encode(['primary'=>$primary,'backup'=>$backup],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        } else {
            $q = $pdo->prepare('SELECT id FROM offerings WHERE semester_id=? ORDER BY id FOR UPDATE');
            $q->execute([$semester]); $q->fetchAll();
            $pdo->prepare("UPDATE enrollments e JOIN offerings o ON o.id=e.offering_id SET e.status='dropped' WHERE e.student_id=? AND o.semester_id=? AND e.status='active'")->execute([$student,$semester]);
            $pdo->prepare('DELETE FROM selection_choices WHERE student_id=? AND semester_id=?')->execute([$student,$semester]);
            $pdo->prepare('DELETE FROM selection_drafts WHERE student_id=? AND semester_id=?')->execute([$student,$semester]);
        }
        $pdo->commit(); return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function dbTeacherRoster(PDO $pdo, int $teacher, int $semester, string $offering): ?array
{
    $q=$pdo->prepare("SELECT o.id FROM offerings o JOIN users u ON u.id=o.teacher_id WHERE o.id=? AND o.semester_id=? AND u.id=? AND u.role='teacher' AND u.is_active=1");
    $q->execute([$offering,$semester,$teacher]);
    if (!$q->fetchColumn()) return null;
    $q=$pdo->prepare("SELECT u.username,u.display_name FROM enrollments e JOIN users u ON u.id=e.student_id WHERE e.offering_id=? AND e.status='active' ORDER BY u.username");
    $q->execute([$offering]); return $q->fetchAll();
}
