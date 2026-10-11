<?php
declare(strict_types=1);

// 按存储格式验证明文或 PHP 密码哈希，不在登录时改变存储格式。
function passwordIsHash(string $stored): bool
{
    return (password_get_info($stored)['algoName'] ?? 'unknown') !== 'unknown';
}

function passwordMatches(string $password, string $stored): bool
{
    return $stored !== '' && (passwordIsHash($stored)
        ? password_verify($password, $stored)
        : hash_equals($stored, $password));
}

function passwordForDisplay(string $stored): string
{
    return passwordIsHash($stored) ? '哈希密码（不可查看原文，可重置）' : $stored;
}
