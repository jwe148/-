<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
if (currentUser() !== null) {
    header('Location: index.php');
    exit;
}

$accounts = demoAccounts();
$roleParam = $_GET['role'] ?? 'student';
$role = is_string($roleParam) && array_key_exists($roleParam, $accounts)
    ? $roleParam
    : 'student';
$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $account = $accounts[$role];
    if (hash_equals($account['username'], $username) && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'username' => $account['username'],
            'name' => $account['name'],
            'label' => $account['label'],
            'role' => $role,
        ];
        header('Location: index.php');
        exit;
    }
    $error = '账号、密码或身份不正确，请重试。';
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
            <div class="login-brand"><span class="brand-mark">JLU</span><strong>课程注册系统</strong></div>
            <span class="section-kicker">WELCOME BACK</span>
            <h1>登录系统</h1>
            <p>请选择身份并登录，进入课程注册系统。</p>
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
            <div class="demo-credentials"><strong>演示账号</strong><span><?= $accounts[$role]['username'] ?> / Demo@2026</span><small>仅供本地界面演示，正式账号将在连接 MySQL 后启用。</small></div>
        </section>
    </main>
</body>
</html>
