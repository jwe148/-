<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
if (currentUser() !== null) {
    header('Location: index.php');
    exit;
}

$accounts = demoAccounts();
$dbMode = databaseModeEnabled();
$roleParam = $_GET['role'] ?? 'student';
$role = is_string($roleParam) && array_key_exists($roleParam, $accounts)
    ? $roleParam
    : 'student';
$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $authenticated = null;
    if ($dbMode) {
        try {
            $statement = databaseConnection()->prepare('SELECT id, username, display_name, role, password_hash FROM users WHERE username = ? AND is_active = 1');
            $statement->execute([$username]);
            $record = $statement->fetch();
            if (is_array($record)
                && hash_equals($role, $record['role'])
                && password_verify($password, $record['password_hash'])) {
                $authenticated = [
                    'id' => (int) $record['id'],
                    'username' => $record['username'],
                    'name' => $record['display_name'],
                    'label' => $accounts[$role]['label'],
                    'role' => $role,
                    'source' => 'database',
                ];
            }
        } catch (Throwable $exception) {
            error_log('Course registration database login failed: ' . $exception->getMessage());
            $error = '数据库暂时无法连接，请检查配置。';
        }
    } else {
        $account = $accounts[$role];
        if (hash_equals($account['username'], $username) && password_verify($password, $account['password_hash'])) {
            $authenticated = [
                'username' => $account['username'],
                'name' => $account['name'],
                'label' => $account['label'],
                'role' => $role,
                'source' => 'demo',
            ];
        }
    }
    if ($authenticated !== null) {
        session_regenerate_id(true);
        $_SESSION['user'] = $authenticated;
        header('Location: index.php');
        exit;
    }
    if ($error === '') {
        $error = '账号、密码或身份不正确，请重试。';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录｜课程注册系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <main class="login-layout">
        <section class="login-panel">
            <div class="login-brand"><small>吉林大学</small><strong>课程注册系统</strong></div>
            <h1>账号登录</h1>
            <p>选择身份后输入账号和密码。</p>
            <div class="role-tabs">
                <?php foreach ($accounts as $roleKey => $account): ?>
                    <a class="<?= $role === $roleKey ? 'active' : '' ?>" href="login.php?role=<?= $roleKey ?>" <?= $role === $roleKey ? 'aria-current="page"' : '' ?>><?= $account['label'] ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($error !== ''): ?><p class="form-error" role="alert"><?= $error ?></p><?php endif; ?>
            <form action="login.php?role=<?= $role ?>" method="post">
                <label>账号<input type="text" name="username" value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="请输入学号或工号" autocomplete="username" required></label>
                <label>密码<input type="password" name="password" placeholder="请输入密码" autocomplete="current-password" required></label>
                <button class="primary-button" type="submit">登录 <span>→</span></button>
            </form>
            <?php if ($dbMode): ?>
                <p class="page-note">当前使用数据库账号登录。</p>
            <?php else: ?>
                <div class="demo-credentials"><strong>演示账号</strong><span><?= $accounts[$role]['username'] ?> / Demo@2026</span><small>仅供本地界面演示；配置 MySQL 后使用数据库账号。</small></div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
