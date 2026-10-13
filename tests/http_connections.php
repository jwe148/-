<?php
declare(strict_types=1);

require __DIR__ . '/../Page/db.php';
if (PHP_SAPI !== 'cli' || !preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+\z~', $argv[1] ?? '')) {
    exit(1);
}
$base = $argv[1];
$pdo = databaseConnection();
$directory = getenv('CR_TEST_DATADIR');
if (!$directory || realpath($directory) !== realpath($pdo->query('SELECT @@datadir')->fetchColumn())) {
    throw new RuntimeException('An isolated database is required.');
}
function checkConnection(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('FAIL ' . $message);
    echo 'PASS ' . $message . PHP_EOL;
}
$second = databaseConnection();
checkConnection($pdo !== $second && $pdo->query('SELECT CONNECTION_ID()')->fetchColumn() !== $second->query('SELECT CONNECTION_ID()')->fetchColumn(), 'CLI callers retain independent database connections');
$second = null;
$cookie = '';
function connectionRequest(string $path, ?array $form = null, int $expectedStatus = 200): void
{
    global $pdo, $base, $cookie;
    $before = (int)$pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch()['Value'];
    $context = stream_context_create(['http' => [
        'method' => $form === null ? 'GET' : 'POST',
        'header' => 'Cookie: ' . $cookie . "\r\nContent-Type: application/x-www-form-urlencoded\r\n",
        'content' => $form === null ? '' : http_build_query($form),
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 15,
    ]]);
    $body = file_get_contents($base . '/' . $path, false, $context);
    $status = 0;
    foreach ($http_response_header as $header) {
        if (preg_match('~^HTTP/\S+ ([0-9]+)~', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie: (course_registration_demo=[^;]+)/i', $header, $match)) $cookie = $match[1];
    }
    $after = (int)$pdo->query("SHOW GLOBAL STATUS LIKE 'Connections'")->fetch()['Value'];
    checkConnection($body !== false && $status === $expectedStatus, 'HTTP request succeeds: ' . $path);
    checkConnection($after - $before === 1, 'one database connection per request: ' . $path . ' (actual ' . ($after - $before) . ')');
}
try {
    connectionRequest('login.php?role=student', ['username'=>'student01', 'password'=>'Demo@2026'], 302);
    foreach (['index.php','selection.php','schedule.php','grades.php','selection_availability.php'] as $path) {
        connectionRequest($path);
    }
} finally {
    if ($cookie !== '') connectionRequest('logout.php', [], 302);
}
