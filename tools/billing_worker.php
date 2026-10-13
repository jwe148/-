<?php
declare(strict_types=1);

// 后台任务或计划任务入口，网页访问不允许执行。
if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--once', '--watch'], true)) {
    if (PHP_SAPI !== 'cli') http_response_code(404);
    else fwrite(STDERR, "用法：php tools/billing_worker.php --once|--watch\n");
    exit(1);
}
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/billing_db.php';
$watch = $argv[1] === '--watch';
do {
    try {
        $pdo = databaseConnection();
        $admin = (int) $pdo->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
        if (!$admin) throw new RuntimeException('没有可用的教务员账号。');
        $semesters = $pdo->query("SELECT DISTINCT b.semester_id FROM billing_outbox b JOIN semesters s ON s.id=b.semester_id WHERE b.status<>'sent' AND s.status='closed'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($semesters as $semester) {
            $result = dbDispatchBilling($pdo, $admin, (int) $semester, true);
            if ($result['errors']) throw new RuntimeException(implode(' ', $result['errors']));
            if ($result['sent'] || $result['failed']) echo '学期 '.$semester.'：成功 '.$result['sent'].'，失败 '.$result['failed'].PHP_EOL;
        }
    } catch (Throwable $error) {
        fwrite(STDERR, '计费任务未完成：'.$error->getMessage().PHP_EOL);
        if (!$watch) exit(1);
    }
    if ($watch) sleep(1);
} while ($watch);
