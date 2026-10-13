<?php
declare(strict_types=1);

// 保存会话标识的摘要，不保存可用于登录的原始会话 ID。
function selectionSessionKey(): string
{
    return hash('sha256', session_name() . ':' . session_id());
}

function releaseSelectionSession(): void
{
    if (databaseModeEnabled()) {
        databaseConnection()->prepare('DELETE FROM selection_sessions WHERE session_key=?')->execute([selectionSessionKey()]);
    }
}

function touchSelectionSession(array $user): void
{
    if ($user['role'] !== 'student') {
        return;
    }
    $semester = require __DIR__ . '/data/current_semester.php';
    $pdo = databaseConnection();
    try {
        $pdo->beginTransaction();
        // 与关课的独占学期锁互斥，防止检查无人后又登记选课会话。
        $query = $pdo->prepare('SELECT id,status FROM semesters WHERE name=? FOR SHARE');
        $query->execute([$semester]);
        $period = $query->fetch();
        if ($period && $period['status'] === 'open') {
            $ttl = max(60, (int) ini_get('session.gc_maxlifetime'));
            $expires = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->modify('+' . $ttl . ' seconds');
            $pdo->prepare('INSERT INTO selection_sessions(session_key,student_id,semester_id,auth_stamp,expires_at)
                VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE student_id=VALUES(student_id),semester_id=VALUES(semester_id),
                auth_stamp=VALUES(auth_stamp),expires_at=VALUES(expires_at)')
                ->execute([selectionSessionKey(),$user['id'],$period['id'],$user['auth_stamp'],$expires->format('Y-m-d H:i:s')]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
