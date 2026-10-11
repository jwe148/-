<?php
declare(strict_types=1);
require __DIR__ . '/../Page/password.php';
$legacy = password_hash('Legacy@2026', PASSWORD_DEFAULT);
$checks = [
    'plaintext accepted exactly' => passwordMatches('Demo@2026', 'Demo@2026'),
    'wrong plaintext rejected' => !passwordMatches('demo@2026', 'Demo@2026'),
    'empty stored value rejected' => !passwordMatches('', ''),
    'legacy hash accepted' => passwordMatches('Legacy@2026', $legacy),
    'wrong legacy password rejected' => !passwordMatches('Wrong@2026', $legacy),
    'legacy hash concealed in display' => passwordForDisplay($legacy) !== $legacy,
    'plaintext available for display' => passwordForDisplay('Demo@2026') === 'Demo@2026',
];
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
