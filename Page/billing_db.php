<?php
declare(strict_types=1);

// 简化收费规则：每门最终课程按同一单价收费，金额使用整数分保存。
function billingCourseFeeCents(): int
{
    $value = getenv('CR_BILLING_COURSE_FEE');
    $value = $value === false || $value === '' ? '300.00' : $value;
    if (!preg_match('/\A(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?\z/', $value, $parts)) {
        throw new RuntimeException('每门课程单价必须为 0 至 999999.99 元，最多两位小数。');
    }
    return (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
}

function billingRetrySeconds(): int
{
    $value = getenv('CR_BILLING_RETRY_SECONDS');
    $value = $value === false || $value === '' ? '60' : $value;
    if (!ctype_digit($value) || (int) $value < 1 || (int) $value > 86400) {
        throw new RuntimeException('计费重试间隔必须为 1 至 86400 秒。');
    }
    return (int) $value;
}

function billingMoney(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function billingScheduleLabel(array $offering): string
{
    $parts=[];
    foreach(['class','teacher','location'] as $key) if(isset($offering[$key]) && $offering[$key]!=='') $parts[]=$offering[$key];
    $days=[1=>'周一',2=>'周二',3=>'周三',4=>'周四',5=>'周五',6=>'周六',7=>'周日'];
    foreach($offering['times']??[] as $time) $parts[]=($days[(int)$time['weekday']]??'').' '.substr($time['starts_at'],0,5).'—'.substr($time['ends_at'],0,5);
    return implode(' · ',$parts);
}

// 在关课事务内建立不可变的最终课表快照，不在事务内调用外部系统。
function dbQueueBilling(PDO $pdo, int $semester): void
{
    $fee = billingCourseFeeCents();
    $times=[];
    $timeQuery=$pdo->prepare('SELECT t.offering_id,t.weekday,t.starts_at,t.ends_at FROM offering_times t JOIN offerings o ON o.id=t.offering_id WHERE o.semester_id=? ORDER BY t.weekday,t.starts_at');
    $timeQuery->execute([$semester]);
    foreach($timeQuery->fetchAll() as $time) { $id=$time['offering_id']; unset($time['offering_id']); $times[$id][]=$time; }
    $q=$pdo->prepare("SELECT u.id,u.username,o.id AS offering_id,o.course_code,o.class_name,o.location,c.name AS course_name,t.display_name AS teacher_name FROM users u JOIN enrollments e ON e.student_id=u.id JOIN offerings o ON o.id=e.offering_id JOIN courses c ON c.code=o.course_code LEFT JOIN users t ON t.id=o.teacher_id WHERE o.semester_id=? AND o.status='confirmed' AND e.status='active' ORDER BY u.id,o.id");
    $q->execute([$semester]); $students=[];
    foreach($q->fetchAll() as $row) {
        $students[$row['id']]['student']=$row['username'];
        $students[$row['id']]['offerings'][]=['id'=>$row['offering_id'],'course'=>$row['course_code'],'name'=>$row['course_name'],'amount_cents'=>$fee,
            'class'=>$row['class_name'],'teacher'=>$row['teacher_name']??'','location'=>$row['location'],'times'=>$times[$row['offering_id']]??[]];
    }
    $insert=$pdo->prepare('INSERT INTO billing_outbox(student_id,semester_id,payload) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=id');
    foreach($students as $id=>$payload) {
        $payload['request_id']='semester-'.$semester.'-student-'.$id;
        $payload['currency']='CNY';
        $payload['course_fee_cents']=$fee;
        $payload['total_cents']=count($payload['offerings'])*$fee;
        $insert->execute([$id,$semester,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    }
}

function dbDispatchBilling(PDO $pdo, int $admin, int $semester, bool $onlyDue = false): array
{
    $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND is_active=1"); $q->execute([$admin]);
    if (!$q->fetchColumn()) return ['errors'=>['无权发送计费记录。'],'sent'=>0,'failed'=>0];
    $interval = billingRetrySeconds();
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
    $due = $onlyDue ? ' AND (next_attempt_at IS NULL OR next_attempt_at<=?)' : '';
    $q=$pdo->prepare("SELECT id FROM billing_outbox WHERE semester_id=? AND status<>'sent'".$due.' ORDER BY id');
    $q->execute($onlyDue ? [$semester,$now->format('Y-m-d H:i:s')] : [$semester]); $ids=$q->fetchAll(PDO::FETCH_COLUMN); $sent=0; $failed=0;
    foreach($ids as $id) {
        try {
            $pdo->beginTransaction();
            $q=$pdo->prepare('SELECT status,payload,next_attempt_at FROM billing_outbox WHERE id=? FOR UPDATE'); $q->execute([$id]); $row=$q->fetch();
            if (!$row || $row['status']==='sent') { $pdo->commit(); continue; }
            if ($onlyDue && $row['next_attempt_at'] !== null && $row['next_attempt_at'] > $now->format('Y-m-d H:i:s')) { $pdo->commit(); continue; }
            // 当前仅模拟接收端。request_id用于未来真实接口的幂等处理。
            // 可选状态文件让常驻重试进程无需重启即可模拟接收端恢复。
            $modeFile = getenv('CR_BILLING_MODE_FILE');
            $useModeFile=$modeFile!==false && $modeFile!=='';
            $mode = $useModeFile ? trim((string) @file_get_contents($modeFile)) : getenv('CR_BILLING_MODE');
            if(!$useModeFile && ($mode===false || $mode==='')) $mode='success';
            $ok=$mode==='success';
            $attemptTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
            $pdo->prepare('UPDATE billing_outbox SET status=?,attempts=attempts+1,last_error=?,sent_at=?,next_attempt_at=? WHERE id=?')->execute([
                $ok?'sent':'failed', $ok?null:'模拟计费接收端不可用', $ok?$attemptTime->format('Y-m-d H:i:s'):null,
                $ok?null:$attemptTime->modify('+'.$interval.' seconds')->format('Y-m-d H:i:s'),$id]);
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
        // 任教资格与登录启用状态分别维护，停用教师仍可由教务授予或撤销资格。
        $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='teacher' FOR UPDATE"); $q->execute([$teacher]);
        if (!$q->fetchColumn()) { $pdo->rollBack(); return ['教师不存在。']; }
        $q=$pdo->prepare('SELECT code FROM courses WHERE code=?'); $q->execute([$course]);
        if(!$q->fetchColumn()) { $pdo->rollBack(); return ['课程不存在。']; }
        if (!$grant) {
            $q=$pdo->prepare("SELECT o.id FROM offerings o JOIN semesters s ON s.id=o.semester_id WHERE o.teacher_id=? AND o.course_code=? AND s.status<>'closed' AND o.status<>'cancelled' FOR SHARE"); $q->execute([$teacher,$course]);
            if($q->fetchColumn()) { $pdo->rollBack(); return ['请先取消该课程的任教安排，再撤销资格。']; }
        }
        $sql=$grant?'INSERT IGNORE INTO teacher_qualifications(teacher_id,course_code) VALUES(?,?)':'DELETE FROM teacher_qualifications WHERE teacher_id=? AND course_code=?';
        $pdo->prepare($sql)->execute([$teacher,$course]); $pdo->commit(); return [];
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
