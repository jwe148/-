<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

// 未配置 MySQL 时使用内置演示账号；数据库模式从 users 表验证身份。
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
    $source = databaseModeEnabled() ? 'database' : 'demo';
    if (!is_array($user)
        || !isset($user['username'], $user['name'], $user['role'], $user['label'])
        || ($user['source'] ?? null) !== $source
        || ($source === 'database' && (!isset($user['id']) || !is_int($user['id']) || $user['id'] < 1))) {
        return null;
    }
    if ($source === 'database') {
        try {
            $query = databaseConnection()->prepare('SELECT username, display_name, role, password_hash, is_active FROM users WHERE id = ?');
            $query->execute([$user['id']]);
            $record = $query->fetch();
        } catch (Throwable $error) {
            error_log('Course registration session check failed: ' . $error->getMessage());
            http_response_code(503);
            exit('课程数据暂不可用，请稍后重试。');
        }
        if (!is_array($record) || (int) $record['is_active'] !== 1 || $record['role'] !== $user['role']
            || !is_string($user['auth_stamp'] ?? null)
            || !hash_equals(hash('sha256', $record['password_hash']), $user['auth_stamp'])) {
            $_SESSION = [];
            session_regenerate_id(true);
            return null;
        }
        $user['username'] = $record['username'];
        $user['name'] = $record['display_name'];
        $_SESSION['user'] = $user;
    }
    return $user;
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

function requireRole(string $role): array
{
    $user = requireLogin();
    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('无权访问此页面。');
    }
    return $user;
}
