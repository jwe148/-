<?php
declare(strict_types=1);
require __DIR__ . '/../Page/db.php';

// 仅验证配置读取，不连接数据库，不读写本机配置文件。
$names = ['CR_DEMO_MODE', 'CR_DB_USER', 'CR_DB_PASSWORD', 'CR_DB_HOST', 'CR_DB_PORT', 'CR_DB_NAME'];
$original = [];
foreach ($names as $name) {
    $original[$name] = getenv($name);
}
$checks = [];
try {
    putenv('CR_DEMO_MODE=1');
    putenv('CR_DB_USER=must_not_connect');
    putenv('CR_DB_PORT=invalid');
    $checks['forced demo ignores database configuration'] = !databaseModeEnabled();
    putenv('CR_DEMO_MODE=0');
    putenv('CR_DB_HOST=127.0.0.1');
    putenv('CR_DB_PORT=3306');
    putenv('CR_DB_NAME=config_test');
    putenv('CR_DB_PASSWORD=0');
    $settings = databaseSettings();
    $checks['environment overrides local settings as a group'] = $settings['user'] === 'must_not_connect' && $settings['name'] === 'config_test';
    $checks['zero password preserved'] = $settings['password'] === '0';
    putenv('CR_DB_PASSWORD');
    $checks['missing environment password defaults to empty'] = databaseSettings()['password'] === '';
    $checks['integer port normalized'] = validateDatabaseSettings(['port' => 3306])['port'] === '3306';
    $checks['empty settings select demo'] = validateDatabaseSettings([])['user'] === '';
    foreach (['0', '65536', 'abc', '3306;dbname=other', [], null] as $i => $port) {
        try {
            validateDatabaseSettings(['port' => $port]);
            $checks['invalid port rejected ' . $i] = false;
        } catch (RuntimeException $error) {
            $checks['invalid port rejected ' . $i] = true;
        }
    }
    foreach (['host', 'name', 'user', 'password'] as $field) {
        try {
            validateDatabaseSettings([$field => []]);
            $checks['invalid type rejected ' . $field] = false;
        } catch (RuntimeException $error) {
            $checks['invalid type rejected ' . $field] = true;
        }
    }
    foreach (['host', 'name'] as $field) {
        try {
            validateDatabaseSettings([$field => 'localhost;dbname=other']);
            $checks['DSN separator rejected ' . $field] = false;
        } catch (RuntimeException $error) {
            $checks['DSN separator rejected ' . $field] = true;
        }
    }
} finally {
    foreach ($original as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
foreach ($checks as $name => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
