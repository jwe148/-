<?php
declare(strict_types=1);

// 无数据库写入的规则检查：php tests/rules.php [selection|teacher|closure|all]
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$group = $argv[1] ?? 'all';
if (!in_array($group, ['selection', 'teacher', 'closure', 'all'], true)) {
    fwrite(STDERR, "用法：php tests/rules.php [selection|teacher|closure|all]\n");
    exit(1);
}
putenv('CR_DEMO_MODE=1');
session_start();
require __DIR__ . '/../Page/data/catalog.php';
require_once __DIR__ . '/../Page/student_data.php';
require_once __DIR__ . '/../Page/teacher_data.php';
require_once __DIR__ . '/../Page/admin_closure_db.php';

$failed = false;
foreach ($group === 'all' ? ['selection', 'teacher', 'closure'] : [$group] as $name) {
    $_SESSION = [];
    $checks = $name === 'closure' ? closureChecks() : ($name . 'Checks')($offerings);
    foreach ($checks as $label => $passed) {
        echo ($passed ? 'PASS ' : 'FAIL ') . $name . ': ' . $label . PHP_EOL;
        $failed = $failed || !$passed;
    }
}
exit($failed ? 1 : 0);

function containsError(array $errors, string $part): bool
{
    foreach ($errors as $error) {
        if (str_contains($error, $part)) {
            return true;
        }
    }
    return false;
}

function selectionChecks(array $offerings): array
{
    $primary = ['SE301-01', 'DB305-01', 'CN309-01', 'AI320-01'];
    $backup = ['WEB337-01', 'DSP342-01'];
    $checks = [
        'valid' => validateStudentSelection($primary, $backup, $offerings) === [],
        'zero_primary' => validateStudentSelection([], [], $offerings) === [],
        'one_primary_no_backup' => validateStudentSelection(array_slice($primary, 0, 1), [], $offerings) === [],
        'two_primary_one_backup' => validateStudentSelection(array_slice($primary, 0, 2), array_slice($backup, 0, 1), $offerings) === [],
        'three_primary' => validateStudentSelection(array_slice($primary, 0, 3), $backup, $offerings) === [],
        'four_primary_no_backup' => validateStudentSelection($primary, [], $offerings) === [],
        'five_primary_rejected' => validateStudentSelection(array_merge($primary, [$backup[0]]), [], $offerings) !== [],
        'three_backup_rejected' => validateStudentSelection([], array_merge($backup, [$primary[0]]), $offerings) !== [],
        'blank_slots_allowed' => validateStudentSelection(['', $primary[0], '', $primary[1]], ['', $backup[0]], $offerings) === [],
        'sparse_choices_allowed' => validateStudentSelection([2 => $primary[0], 7 => $primary[1]], [], $offerings) === [],
        'malformed_id_rejected' => validateStudentSelection([[]], [], $offerings) !== [],
        'partial_conflict_rejected' => containsError(validateStudentSelection(['SE301-01', 'SEC335-01'], [], $offerings), '时间冲突'),
        'partial_prerequisite_rejected' => containsError(validateStudentSelection(['ML340-01'], [], $offerings), '要求先修'),
        'duplicate' => validateStudentSelection($primary, ['SE301-01', 'DSP342-01'], $offerings) !== [],
        'full' => containsError(validateStudentSelection(['OS312-01', 'DB305-01', 'CN309-01', 'AI320-01'], $backup, $offerings), '已满额'),
        'full_backup_allowed' => validateStudentSelection($primary, ['OS312-01', 'DSP342-01'], $offerings) === [],
        'prerequisite' => containsError(validateStudentSelection($primary, ['ML340-01', 'DSP342-01'], $offerings), '要求先修'),
        'conflict' => containsError(validateStudentSelection(['SE301-01', 'DB305-01', 'CN309-01', 'SEC335-01'], $backup, $offerings), '时间冲突'),
    ];
    $_SESSION['student_selection'] = ['primary' => $primary, 'backup' => $backup];
    $checks['seat_taken'] = seatsRemaining('SE301-01', $offerings) === 2;
    $_SESSION['student_selection']['primary'] = array_slice($primary, 1);
    $checks['seat_restored'] = seatsRemaining('SE301-01', $offerings) === 3;

    $multipleTimes = $offerings;
    $multipleTimes['DB305-01']['times'] = [
        ['day' => '周三', 'time' => '10:00—11:40'],
        ['day' => '周一', 'time' => '09:00—10:00'],
    ];
    $checks['second_meeting_conflict'] = containsError(
        validateStudentSelection($primary, $backup, $multipleTimes), '时间冲突'
    );
    $checks['second_meeting_filter'] = offeringHasDay($multipleTimes['DB305-01'], '周一');
    $multiplePrerequisites = $offerings;
    $multiplePrerequisites['AI320-01']['prerequisites'] = ['程序设计基础', '线性代数'];
    $checks['multiple_prerequisites'] = containsError(
        validateStudentSelection($primary, $backup, $multiplePrerequisites), '线性代数'
    );
    return $checks;
}

function teacherChecks(array $offerings): array
{
    $checks = [
        'claim_available' => validateTeacherClaim('HCI326-01', [], $offerings, true) === [],
        'time_conflict' => containsError(validateTeacherClaim('UX328-01', ['HCI326-01'], $offerings, true), '时间冲突'),
        'already_assigned' => containsError(validateTeacherClaim('SE301-01', [], $offerings, true), '已有授课教师'),
        'already_claimed' => containsError(validateTeacherClaim('HCI326-01', ['HCI326-01'], $offerings, true), '已认领'),
        'closed' => containsError(validateTeacherClaim('HCI326-01', [], $offerings, false), '已结束'),
        'missing' => containsError(validateTeacherClaim('MISSING', [], $offerings, true), '不存在'),
    ];
    $multipleTimes = $offerings;
    $multipleTimes['UX328-01']['times'] = [
        ['day' => '周六', 'time' => '10:00—11:40'],
        ['day' => '周四', 'time' => '14:00—15:40'],
    ];
    $checks['second_meeting_conflict'] = containsError(
        validateTeacherClaim('UX328-01', ['HCI326-01'], $multipleTimes, true), '时间冲突'
    );
    return $checks;
}

function closureChecks(): array
{
    $times = [
        'candidate' => [['weekday' => 3, 'starts_at' => '10:00:00', 'ends_at' => '11:40:00']],
        'overlap' => [['weekday' => 3, 'starts_at' => '11:00:00', 'ends_at' => '12:00:00']],
        'adjacent' => [['weekday' => 3, 'starts_at' => '11:40:00', 'ends_at' => '12:30:00']],
        'other_day' => [['weekday' => 4, 'starts_at' => '10:30:00', 'ends_at' => '11:30:00']],
        'two_segments' => [
            ['weekday' => 1, 'starts_at' => '08:00:00', 'ends_at' => '09:40:00'],
            ['weekday' => 3, 'starts_at' => '11:30:00', 'ends_at' => '12:20:00'],
        ],
    ];
    $checks = [
        'overlap' => dbClosureTimeConflict('candidate', ['overlap'], $times),
        'adjacent_allowed' => !dbClosureTimeConflict('candidate', ['adjacent'], $times),
        'other_day_allowed' => !dbClosureTimeConflict('candidate', ['other_day'], $times),
        'second_segment_checked' => dbClosureTimeConflict('candidate', ['two_segments'], $times),
    ];
    return $checks;
}
