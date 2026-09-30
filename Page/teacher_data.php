<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/teacher_claim_db.php';

// 无数据库配置时仍使用本地会话演示。
function teacherClaims(): array
{
    if (databaseModeEnabled()) {
        $user = function_exists('currentUser') ? currentUser() : null;
        $semesterId = $GLOBALS['selectionPeriod']['semester_id'] ?? null;
        return $user !== null && $user['role'] === 'teacher' && is_int($semesterId)
            ? dbTeacherClaims(databaseConnection(), $user['id'], $semesterId)
            : [];
    }
    $claims = $_SESSION['teacher_claims'] ?? [];
    return is_array($claims) ? array_values(array_filter($claims, 'is_string')) : [];
}

function teacherToken(): string
{
    if (!isset($_SESSION['teacher_token'])) {
        $_SESSION['teacher_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['teacher_token'];
}

function validTeacherToken($token): bool
{
    return is_string($token) && hash_equals(teacherToken(), $token);
}

function teacherTimeConflict(array $first, array $second): bool
{
    if ($first['day'] !== $second['day']) {
        return false;
    }
    [$firstStart, $firstEnd] = explode('—', $first['time']);
    [$secondStart, $secondEnd] = explode('—', $second['time']);
    return $firstStart < $secondEnd && $secondStart < $firstEnd;
}

function validateTeacherClaim(string $id, array $claims, array $offerings, bool $open): array
{
    if (!$open) {
        return ['选课已结束，不能调整授课教学班。'];
    }
    if (!isset($offerings[$id])) {
        return ['教学班不存在。'];
    }
    if (in_array($id, $claims, true)) {
        return ['你已认领该教学班。'];
    }
    if ($offerings[$id]['teacher'] !== '待认领') {
        return ['该教学班已有授课教师。'];
    }
    foreach ($claims as $claimedId) {
        if (isset($offerings[$claimedId]) && teacherTimeConflict($offerings[$id], $offerings[$claimedId])) {
            return ['与已认领的“' . $offerings[$claimedId]['name'] . '”上课时间冲突。'];
        }
    }
    return [];
}

function showTeacherClaims(array &$offerings, array $claims, string $teacherName): void
{
    if (databaseModeEnabled()) {
        return;
    }
    foreach ($claims as $id) {
        if (isset($offerings[$id]) && $offerings[$id]['teacher'] === '待认领') {
            $offerings[$id]['teacher'] = $teacherName;
        }
    }
}
