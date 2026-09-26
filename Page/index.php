<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireLogin();
require __DIR__ . '/data/catalog.php';
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="吉林大学课程注册系统首页">
    <title>课程注册系统｜吉林大学</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="课程注册系统首页">
                <span class="brand-mark" aria-hidden="true">JLU</span>
                <span>
                    <strong>课程注册系统</strong>
                    <small>COURSE REGISTRATION</small>
                </span>
            </a>

            <nav class="main-nav" aria-label="主导航">
                <a class="active" href="index.php">首页</a>
                <a href="courses.php">课程查询</a>
            </nav>

            <div class="account-actions"><span class="user-chip"><?= e($user['name']) ?> · <?= e($user['label']) ?></span><form method="post" action="logout.php"><button class="logout-button" type="submit">退出登录</button></form></div>
        </div>
    </header>

    <main class="dashboard-home">
        <section class="hero">
            <div class="container hero-inner">
                <div class="hero-copy">
                    <div class="eyebrow"><span></span><?= e($semester) ?></div>
                    <h1><em><?= e($user['name']) ?></em>，欢迎回来</h1>
                    <p>在课程查询中查看本学期教学班、授课教师、上课时间和剩余名额。</p>
                    <div class="hero-actions">
                        <a class="primary-button" href="courses.php">查询课程 <span>→</span></a>
                    </div>
                    <p class="dashboard-note">当前为界面演示；选课、成绩和教务操作将在后续阶段接入。</p>
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="container footer-wrap">
            <div><strong>吉林大学 · 课程注册系统</strong><span>界面演示版本</span></div>
            <p>© <?= date('Y') ?> 吉林大学计算机科学与技术学院</p>
        </div>
    </footer>
</body>
</html>
