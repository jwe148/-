<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/password.php';
require_once __DIR__ . '/selection_sessions.php';

// 未配置 MySQL 时使用内置演示账号；数据库模式从 users 表验证身份。
if (session_status() !== PHP_SESSION_ACTIVE) {
    // 可配置 cookie 名称，避免同一主机上的不同项目互相覆盖登录。
    $sessionName = getenv('CR_SESSION_NAME') ?: 'course_registration_demo';
    if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $sessionName)) throw new RuntimeException('会话名称配置无效。');
    session_name($sessionName);
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function demoAccounts(): array
{
    $passwordValue = 'Demo@2026';
    return [
        'student' => ['username' => 'student01', 'name' => '演示学生', 'label' => '学生', 'password_hash' => $passwordValue],
        'teacher' => ['username' => 'teacher01', 'name' => '演示教师', 'label' => '教师', 'password_hash' => $passwordValue],
        'admin' => ['username' => 'admin01', 'name' => '演示教务员', 'label' => '教务员', 'password_hash' => $passwordValue],
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
            releaseSelectionSession();
            $_SESSION = [];
            session_regenerate_id(true);
            return null;
        }
        $user['username'] = $record['username'];
        $user['name'] = $record['display_name'];
        $_SESSION['user'] = $user;
        try {
            touchSelectionSession($user);
        } catch (Throwable $error) {
            error_log('Selection session update failed: ' . $error->getMessage());
            http_response_code(503);
            exit('选课会话暂不可用，请稍后重试。');
        }
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
