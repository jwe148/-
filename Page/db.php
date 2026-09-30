<?php
declare(strict_types=1);

// 设置 CR_DB_USER 后启用 MySQL 模式。
function databaseModeEnabled(): bool
{
    $user = getenv('CR_DB_USER');
    return $user !== false && $user !== '';
}

function databaseConnection(): PDO
{
    $user = getenv('CR_DB_USER');
    if ($user === false || $user === '') {
        throw new RuntimeException('请先设置 CR_DB_USER 环境变量。');
    }
    $host = getenv('CR_DB_HOST') ?: '127.0.0.1';
    $port = getenv('CR_DB_PORT') ?: '3306';
    $name = getenv('CR_DB_NAME') ?: 'course_registration';
    $password = getenv('CR_DB_PASSWORD') ?: '';
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
