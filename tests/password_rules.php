<?php
declare(strict_types=1);
require __DIR__ . '/../Page/password.php';
$hash = password_hash('Hashed@2026', PASSWORD_BCRYPT);
$checks = [
    'plaintext accepted exactly' => passwordMatches('Demo@2026', 'Demo@2026'),
    'wrong plaintext rejected' => !passwordMatches('demo@2026', 'Demo@2026'),
    'empty stored value rejected' => !passwordMatches('', ''),
    'bcrypt identified as hash' => passwordIsHash($hash),
    'plaintext identified as plaintext' => !passwordIsHash('Demo@2026'),
    'bcrypt password accepted' => passwordMatches('Hashed@2026', $hash),
    'wrong bcrypt password rejected' => !passwordMatches('Wrong@2026', $hash),
    'stored hash cannot be used as login password' => !passwordMatches($hash, $hash),
    'hash concealed with accurate display status' => passwordForDisplay($hash) === '哈希密码（不可查看原文，可重置）',
    'plaintext available for display' => passwordForDisplay('Demo@2026') === 'Demo@2026',
    'plaintext comparison is case sensitive' => !passwordMatches('Demo@2026', 'demo@2026'),
];
foreach (password_algos() as $algorithm) {
    $stored = password_hash('Algorithm@2026', $algorithm);
    $checks[$algorithm . ' supported hash accepted'] = passwordIsHash($stored) && passwordMatches('Algorithm@2026', $stored);
    $checks[$algorithm . ' wrong password rejected'] = !passwordMatches('Wrong@2026', $stored);
}
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
