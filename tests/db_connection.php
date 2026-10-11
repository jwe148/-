<?php
declare(strict_types=1);

require __DIR__ . '/../Page/db.php';

try {
    $info = databaseConnection()->query('SELECT DATABASE() AS db_name, @@hostname AS host_name, @@port AS port, VERSION() AS version')->fetch();
    $version = $info['version'];
    echo '当前数据库：' . $info['db_name'] . '；MySQL 服务：' . $info['host_name'] . ':' . $info['port'] . PHP_EOL;
    echo 'MySQL 连接成功：' . $version . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'MySQL 连接失败：' . $error->getMessage() . PHP_EOL);
    exit(1);
}
