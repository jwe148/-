<?php
declare(strict_types=1);

// 本地课程演示使用明文密码，同时兼容旧版已有的密码哈希。
function passwordIsLegacyHash(string $stored): bool
{
    return (password_get_info($stored)['algoName'] ?? 'unknown') !== 'unknown';
}

function passwordMatches(string $password, string $stored): bool
{
    return $stored !== '' && (passwordIsLegacyHash($stored)
        ? password_verify($password, $stored)
        : hash_equals($stored, $password));
}

function passwordForDisplay(string $stored): string
{
    return passwordIsLegacyHash($stored) ? '旧版哈希：登录或重置后可查看明文' : $stored;
}
