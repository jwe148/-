<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../Page/data/catalog.php';
require __DIR__ . '/../Page/teacher_data.php';

$checks = [
    'claim_available' => validateTeacherClaim('HCI326-01', [], $offerings, true) === [],
    'time_conflict' => containsTeacherError(validateTeacherClaim('UX328-01', ['HCI326-01'], $offerings, true), '时间冲突'),
    'already_assigned' => containsTeacherError(validateTeacherClaim('SE301-01', [], $offerings, true), '已有授课教师'),
    'already_claimed' => containsTeacherError(validateTeacherClaim('HCI326-01', ['HCI326-01'], $offerings, true), '已认领'),
    'closed' => containsTeacherError(validateTeacherClaim('HCI326-01', [], $offerings, false), '已结束'),
    'missing' => containsTeacherError(validateTeacherClaim('MISSING', [], $offerings, true), '不存在'),
];

foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);

function containsTeacherError(array $errors, string $part): bool
{
    foreach ($errors as $error) {
        if (strpos($error, $part) !== false) {
            return true;
        }
    }
    return false;
}
