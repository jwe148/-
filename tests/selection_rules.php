<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../Page/data/catalog.php';
require __DIR__ . '/../Page/student_data.php';

$primary = ['SE301-01', 'DB305-01', 'CN309-01', 'AI320-01'];
$backup = ['WEB337-01', 'DSP342-01'];
$checks = [
    'valid' => validateStudentSelection($primary, $backup, $offerings) === [],
    'count' => validateStudentSelection(array_slice($primary, 0, 3), $backup, $offerings) !== [],
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

foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);

function containsError(array $errors, string $part): bool
{
    foreach ($errors as $error) {
        if (strpos($error, $part) !== false) {
            return true;
        }
    }
    return false;
}
