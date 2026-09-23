<?php
declare(strict_types=1);

$allowedRoles = ['student' => '学生', 'teacher' => '教师', 'admin' => '教务员'];
$roleParam = $_GET['role'] ?? 'student';
$role = is_string($roleParam) && array_key_exists($roleParam, $allowedRoles)
    ? $roleParam
    : 'student';
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
        <a class="back-link" href="index.php">← 返回首页</a>
        <section class="login-panel">
            <div class="login-brand"><span class="brand-mark">JLU</span><strong>课程注册系统</strong></div>
            <span class="section-kicker">WELCOME BACK</span>
            <h1>登录系统</h1>
            <p>请选择身份并输入账号信息</p>
            <div class="role-tabs">
                <?php foreach ($allowedRoles as $roleKey => $roleName): ?>
                    <a class="<?= $role === $roleKey ? 'active' : '' ?>" href="login.php?role=<?= $roleKey ?>"><?= $roleName ?></a>
                <?php endforeach; ?>
            </div>
            <form action="#" method="post">
                <label>账号<input type="text" name="username" placeholder="请输入学号或工号" autocomplete="username" required></label>
                <label>密码<input type="password" name="password" placeholder="请输入密码" autocomplete="current-password" required></label>
                <button class="primary-button" type="submit">登录 <span>→</span></button>
            </form>
            <small class="demo-note">当前为首页界面演示，登录逻辑将在连接 MySQL 后实现。</small>
        </section>
    </main>
</body>
</html>
