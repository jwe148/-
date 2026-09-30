<?php
declare(strict_types=1);

require __DIR__ . '/../Page/admin_closure_db.php';

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
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
