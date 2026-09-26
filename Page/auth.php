<?php
declare(strict_types=1);

// 仅供界面联调。接入 MySQL 后应从导入的用户表读取账号和密码哈希。
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('course_registration_demo');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function demoAccounts(): array
{
    $passwordHash = '$2y$10$LuZNyh/KZux4YIBS0l8R/eIiLtJgjmSKqtmD7Q6hHZCrb7PcXW1ti';
    return [
        'student' => ['username' => 'student01', 'name' => '演示学生', 'label' => '学生', 'password_hash' => $passwordHash],
        'teacher' => ['username' => 'teacher01', 'name' => '演示教师', 'label' => '教师', 'password_hash' => $passwordHash],
        'admin' => ['username' => 'admin01', 'name' => '演示教务员', 'label' => '教务员', 'password_hash' => $passwordHash],
    ];
}

function currentUser(): ?array
{
    $user = $_SESSION['user'] ?? null;
    return is_array($user) && isset($user['username'], $user['name'], $user['role'], $user['label'])
        ? $user
        : null;
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: login.php');
        exit;
    }
    header('Cache-Control: no-store, private');
    return $user;
}
