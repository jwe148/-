<?php
declare(strict_types=1);

// 只检查配置格式，不建立连接；错误信息不包含用户名或密码。
function validateDatabaseSettings(array $settings): array
{
    $settings += ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'course_registration', 'user' => '', 'password' => ''];
    foreach (['host', 'name', 'user', 'password'] as $field) {
        if (!is_string($settings[$field])) {
            throw new RuntimeException('数据库配置 ' . $field . ' 必须是字符串。');
        }
    }
    $port = $settings['port'];
    if ((!is_string($port) && !is_int($port))
        || !preg_match('/\A[0-9]{1,5}\z/', (string) $port)
        || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('数据库端口必须是 1—65535 的整数。');
    }
    $settings['port'] = (string) $port;
    foreach (['host', 'name'] as $field) {
        if (trim($settings[$field]) === '' || preg_match('/[;\x00-\x1f\x7f]/', $settings[$field])) {
            throw new RuntimeException('数据库配置 ' . $field . ' 不能为空或含分号、控制字符。');
        }
    }
    return $settings;
}

// 强制演示模式优先，其次整组环境变量，最后读取本机配置。
function databaseSettings(): array
{
    if (getenv('CR_DEMO_MODE') === '1') {
        return validateDatabaseSettings([]);
    }
    $user = getenv('CR_DB_USER');
    if ($user !== false) {
        $password = getenv('CR_DB_PASSWORD');
        $port = getenv('CR_DB_PORT');
        return validateDatabaseSettings([
            'host' => getenv('CR_DB_HOST') ?: '127.0.0.1',
            'port' => $port === false || $port === '' ? '3306' : $port,
            'name' => getenv('CR_DB_NAME') ?: 'course_registration',
            'user' => $user,
            'password' => $password === false ? '' : $password,
        ]);
    }
    static $local = null;
    if ($local === null) {
        $path = __DIR__ . '/../config.local.php';
        $loaded = is_file($path) ? require $path : [];
        if (!is_array($loaded)) {
            throw new RuntimeException('config.local.php 必须返回配置数组。');
        }
        $local = validateDatabaseSettings($loaded);
    }
    return $local;
}

function databaseModeEnabled(): bool
{
    return databaseSettings()['user'] !== '';
}

function databaseConnection(): PDO
{
    // Web 请求内复用连接，避免一个页面的权限、会话、课表查询反复建立 TCP 连接。
    // CLI 调用仍返回新连接，供并发测试及常驻计费任务在下一轮断线重连。
    static $requestConnection = null;
    if (PHP_SAPI !== 'cli' && $requestConnection instanceof PDO) {
        return $requestConnection;
    }
    $settings = databaseSettings();
    $user = $settings['user'];
    if ($user === '') {
        throw new RuntimeException('请填写 config.local.php 或设置 CR_DB_USER 环境变量。');
    }
    $host = $settings['host'];
    $port = $settings['port'];
    $name = $settings['name'];
    $password = $settings['password'];
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    if (PHP_SAPI !== 'cli') {
        $requestConnection = $pdo;
    }
    return $pdo;
}
