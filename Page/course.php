<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireLogin();
require __DIR__ . '/data/catalog.php';

$id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
$course = $offerings[$id] ?? null;
if ($course === null) {
    http_response_code(404);
}
$remaining = $course === null ? 0 : $course['capacity'] - $course['selected'];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $course === null ? '未找到教学班' : e($course['name'] . ' · ' . $course['class']) ?>｜课程注册系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="课程注册系统首页">
                <span class="brand-mark" aria-hidden="true">JLU</span>
                <span><strong>课程注册系统</strong><small>COURSE REGISTRATION</small></span>
            </a>
            <nav class="main-nav" aria-label="主导航">
                <a href="index.php">首页</a>
                <a class="active" href="courses.php">课程查询</a>
            </nav>
            <div class="account-actions"><span class="user-chip"><?= e($user['name']) ?> · <?= e($user['label']) ?></span><form method="post" action="logout.php"><button class="logout-button" type="submit">退出登录</button></form></div>
        </div>
    </header>

    <main>
        <section class="page-banner">
            <div class="container">
                <a class="breadcrumb" href="courses.php">← 返回教学班列表</a>
                <span class="section-kicker">COURSE DETAIL</span>
                <h1><?= $course === null ? '未找到教学班' : e($course['name']) ?></h1>
                <p><?= $course === null ? '请返回列表重新选择。' : e($semester . ' · ' . $course['code'] . ' · ' . $course['class']) ?></p>
            </div>
        </section>

        <?php if ($course !== null): ?>
            <section class="catalog-section">
                <div class="container detail-layout">
                    <article class="detail-card">
                        <div class="detail-title"><div><span class="section-kicker">基本信息</span><h2><?= e($course['name']) ?></h2></div><span class="demo-badge">界面演示数据</span></div>
                        <dl class="detail-list">
                            <div><dt>教学班</dt><dd><?= e($course['code'] . ' · ' . $course['class']) ?></dd></div>
                            <div><dt>授课教师</dt><dd><?= e($course['teacher']) ?></dd></div>
                            <div><dt>上课时间</dt><dd><?= e($course['day'] . ' ' . $course['time']) ?></dd></div>
                            <div><dt>上课地点</dt><dd><?= e($course['location']) ?></dd></div>
                            <div><dt>先修课程</dt><dd><?= e($course['prerequisite']) ?></dd></div>
                            <div><dt>人数上限</dt><dd><?= $course['capacity'] ?> 人</dd></div>
                        </dl>
                        <h3>课程简介</h3>
                        <p class="detail-description"><?= e($course['description']) ?></p>
                    </article>
                    <aside class="detail-side">
                        <span class="section-kicker">AVAILABILITY</span>
                        <h2>教学班名额</h2>
                        <p class="remaining-number"><?= $remaining ?><span> / <?= $course['capacity'] ?> 席剩余</span></p>
                        <progress value="<?= $course['selected'] ?>" max="<?= $course['capacity'] ?>"></progress>
                        <p class="side-note">已选 <?= $course['selected'] ?> 人。实际名额将在数据库接入后实时更新。</p>
                        <a class="primary-button" href="courses.php">返回课程列表 <span>→</span></a>
                        <p class="side-note">正式选课功能将在后续阶段接入。</p>
                    </aside>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <footer><div class="container footer-wrap"><div><strong>吉林大学 · 课程注册系统</strong><span>课程信息仅供界面演示</span></div><p>© <?= date('Y') ?> 吉林大学计算机科学与技术学院</p></div></footer>
</body>
</html>
