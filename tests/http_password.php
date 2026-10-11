<?php
declare(strict_types=1);

require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/password.php';

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--disposable-demo') {
    exit(1);
}
$base = $argv[2] ?? '';
if (!preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~', $base)) {
    throw new RuntimeException('A local isolated HTTP server is required');
}
$pdo = databaseConnection();
$testDirectory = getenv('CR_TEST_DATADIR');
if (!$testDirectory || realpath($testDirectory) !== realpath($pdo->query('SELECT @@datadir')->fetchColumn())) {
    throw new RuntimeException('Refusing password tests outside isolated MySQL');
}
function checkPasswordHttp(bool $ok, string $name): void
{
    if (!$ok) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . PHP_EOL;
}
function passwordRequest(string $base, string $path, string &$cookie, ?array $form = null): array
{
    $headers = 'Cookie: ' . $cookie . "\r\n";
    if ($form !== null) { $headers .= "Content-Type: application/x-www-form-urlencoded\r\n"; }
    $context = stream_context_create(['http' => [
        'method' => $form === null ? 'GET' : 'POST', 'header' => $headers,
        'content' => $form === null ? '' : http_build_query($form),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $body = file_get_contents($base . '/' . $path, false, $context);
    if ($body === false) { throw new RuntimeException('HTTP password request failed'); }
    $status = 0;
    foreach ($http_response_header as $header) {
        if (preg_match('~^HTTP/\S+ ([0-9]+)~', $header, $match)) { $status = (int) $match[1]; }
        if (preg_match('/^Set-Cookie: (course_registration_demo=[^;]+)/i', $header, $match)) { $cookie = $match[1]; }
    }
    return ['status' => $status, 'body' => $body];
}
$ids = [];
$prefix = 'password_test_' . bin2hex(random_bytes(4));
try {
    foreach (['student', 'teacher', 'admin'] as $role) {
        foreach (['plain', 'hash'] as $format) {
            $name = $prefix . '_' . $role . '_' . $format;
            $password = 'MixedPassword@2026';
            $stored = $format === 'hash' ? password_hash($password, PASSWORD_BCRYPT) : $password;
            $pdo->prepare('INSERT INTO users (username, display_name, role, password_hash, is_active) VALUES (?, ?, ?, ?, 1)')
                ->execute([$name, 'Password test', $role, $stored]);
            $id = (int) $pdo->lastInsertId();
            $ids[] = $id;
            $label = $role . ' ' . $format;
            $cookie = '';
            $login = passwordRequest($base, 'login.php?role=' . $role, $cookie, ['username' => $name, 'password' => $password]);
            checkPasswordHttp($login['status'] === 302, $label . ' login succeeds');
            $page = passwordRequest($base, 'index.php', $cookie);
            checkPasswordHttp($page['status'] === 200 && str_contains($page['body'], 'action="logout.php"'), $label . ' session works');
            $query = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
            $query->execute([$id]);
            checkPasswordHttp($query->fetchColumn() === $stored, $label . ' login preserves stored value');
            $otherCookie = '';
            $wrong = passwordRequest($base, 'login.php?role=' . $role, $otherCookie, ['username' => $name, 'password' => 'Wrong@2026']);
            checkPasswordHttp($wrong['status'] === 200 && str_contains($wrong['body'], '账号、密码或身份不正确'), $label . ' wrong password rejected');
            if ($format === 'hash') {
                $otherCookie = '';
                $wrong = passwordRequest($base, 'login.php?role=' . $role, $otherCookie, ['username' => $name, 'password' => $stored]);
                checkPasswordHttp($wrong['status'] === 200 && str_contains($wrong['body'], '账号、密码或身份不正确'), $label . ' raw hash rejected');
                if ($role === 'student') { $hashedStudent = ['id' => $id, 'name' => $name, 'stored' => $stored]; }
            }
            $otherCookie = '';
            $wrongRole = $role === 'student' ? 'teacher' : 'student';
            $wrong = passwordRequest($base, 'login.php?role=' . $wrongRole, $otherCookie, ['username' => $name, 'password' => $password]);
            checkPasswordHttp($wrong['status'] === 200 && str_contains($wrong['body'], '账号、密码或身份不正确'), $label . ' wrong role rejected');
            if ($role === 'admin' && $format === 'hash') {
                $list = passwordRequest($base, 'admin_people.php?q=' . $hashedStudent['name'], $cookie);
                checkPasswordHttp(str_contains($list['body'], '哈希密码（不可查看原文，可重置）') && !str_contains($list['body'], $hashedStudent['stored']), 'admin list identifies hash without showing it');
                $edit = passwordRequest($base, 'admin_people.php?view=edit&id=' . $hashedStudent['id'], $cookie);
                checkPasswordHttp(str_contains($edit['body'], 'name="password" value=""') && !str_contains($edit['body'], $hashedStudent['stored']), 'admin edit leaves hashed password blank');
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute(['ResetPassword@2026', $id]);
            checkPasswordHttp(passwordRequest($base, 'index.php', $cookie)['status'] === 302, $label . ' reset revokes session');
            $cookie = '';
            checkPasswordHttp(passwordRequest($base, 'login.php?role=' . $role, $cookie, ['username' => $name, 'password' => 'ResetPassword@2026'])['status'] === 302, $label . ' reset password authenticates');
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
            checkPasswordHttp(passwordRequest($base, 'index.php', $cookie)['status'] === 302, $label . ' disable revokes session');
            $cookie = '';
            $disabled = passwordRequest($base, 'login.php?role=' . $role, $cookie, ['username' => $name, 'password' => 'ResetPassword@2026']);
            checkPasswordHttp($disabled['status'] === 200 && str_contains($disabled['body'], '账号、密码或身份不正确'), $label . ' disabled login rejected');
            $pdo->prepare('UPDATE users SET password_hash = ?, is_active = 1 WHERE id = ?')->execute([$stored, $id]);
        }
    }
} finally {
    $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
    foreach ($ids as $id) { $delete->execute([$id]); }
}
