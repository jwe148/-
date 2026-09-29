<?php
declare(strict_types=1);

require __DIR__ . '/../Page/db.php';

try {
    $version = databaseConnection()->query('SELECT VERSION()')->fetchColumn();
    echo 'MySQL 连接成功：' . $version . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'MySQL 连接失败：' . $error->getMessage() . PHP_EOL);
    exit(1);
}
