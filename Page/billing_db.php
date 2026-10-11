<?php
declare(strict_types=1);

// 在关课事务内建立不可变的最终课表快照，不在事务内调用外部系统。
function dbQueueBilling(PDO $pdo, int $semester): void
{
    $q=$pdo->prepare("SELECT u.id,u.username,o.id AS offering_id,o.course_code FROM users u JOIN enrollments e ON e.student_id=u.id JOIN offerings o ON o.id=e.offering_id WHERE o.semester_id=? AND o.status='confirmed' AND e.status='active' ORDER BY u.id,o.id");
    $q->execute([$semester]); $students=[];
    foreach($q->fetchAll() as $row) {
        $students[$row['id']]['student']=$row['username'];
        $students[$row['id']]['offerings'][]=['id'=>$row['offering_id'],'course'=>$row['course_code']];
    }
    $insert=$pdo->prepare('INSERT INTO billing_outbox(student_id,semester_id,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=id');
    foreach($students as $id=>$payload) {
        $payload['request_id']='semester-'.$semester.'-student-'.$id;
        $insert->execute([$id,$semester,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}

function dbDispatchBilling(PDO $pdo, int $admin, int $semester): array
{
    $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1"); $q->execute([$admin]);
    if (!$q->fetchColumn()) return ['errors'=>['无权发送计费记录。'],'sent'=>0,'failed'=>0];
    $q=$pdo->prepare("SELECT id FROM billing_outbox WHERE semester_id=? AND status<>'sent' ORDER BY id");
    $q->execute([$semester]); $ids=$q->fetchAll(PDO::FETCH_COLUMN); $sent=0; $failed=0;
    foreach($ids as $id) {
        try {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT status,payload FROM billing_outbox WHERE id=? FOR UPDATE'); $q->execute([$id]); $row=$q->fetch();
            if (!$row || $row['status']==='sent') { $pdo->commit(); continue; }
            // 当前仅模拟接收端。request_id用于未来真实接口的幂等处理。
            $mode=getenv('CR_BILLING_MODE') ?: 'success';
            $ok=$mode==='success';
            $pdo->prepare('UPDATE billing_outbox SET status=?,attempts=attempts+1,last_error=?,sent_at=? WHERE id=?')->execute([
                $ok?'sent':'failed', $ok?null:'模拟计费接收端不可用', $ok?(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->format('Y-m-d H:i:s'):null,$id]);
            $pdo->commit(); $ok ? $sent++ : $failed++;
        } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
    return ['errors'=>[],'sent'=>$sent,'failed'=>$failed];
}

function dbSetTeacherQualification(PDO $pdo,int $admin,int $teacher,string $course,bool $grant): array
{
    try {
        $pdo->beginTransaction();
        $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1 FOR SHARE"); $q->execute([$admin]);
        if (!$q->fetchColumn()) { $pdo->rollBack(); return ['无权维护任教资格。']; }
        $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='teacher' AND is_active=1 FOR UPDATE"); $q->execute([$teacher]);
        if (!$q->fetchColumn()) { $pdo->rollBack(); return ['教师账号不可用。']; }
        $q=$pdo->prepare('SELECT code FROM courses WHERE code=?'); $q->execute([$course]);
        if(!$q->fetchColumn()) { $pdo->rollBack(); return ['课程不存在。']; }
        if (!$grant) {
            $q=$pdo->prepare("SELECT o.id FROM offerings o JOIN semesters s ON s.id=o.semester_id WHERE o.teacher_id=? AND o.course_code=? AND s.status<>'closed' FOR SHARE"); $q->execute([$teacher,$course]);
            if($q->fetchColumn()) { $pdo->rollBack(); return ['请先取消该课程的任教安排，再撤销资格。']; }
        }
        $sql=$grant?'INSERT IGNORE INTO teacher_qualifications(teacher_id,course_code) VALUES(?,?)':'DELETE FROM teacher_qualifications WHERE teacher_id=? AND course_code=?';
        $pdo->prepare($sql)->execute([$teacher,$course]); $pdo->commit(); return [];
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
