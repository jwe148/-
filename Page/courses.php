<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireLogin();
require __DIR__ . '/data/catalog.php';

$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$day = isset($_GET['day']) && is_string($_GET['day']) ? $_GET['day'] : '';
$days = ['周一', '周二', '周三', '周四', '周五'];
if (!in_array($day, $days, true)) {
    $day = '';
}
$filtered = array_filter($offerings, static function (array $course) use ($query, $day): bool {
    $matchesQuery = $query === ''
        || stripos($course['name'], $query) !== false
        || stripos($course['teacher'], $query) !== false
        || stripos($course['code'], $query) !== false;
    return $matchesQuery && ($day === '' || $course['day'] === $day);
});
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="查询本学期课程和教学班剩余名额">
    <title>课程查询｜课程注册系统</title>
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
                <a class="active" aria-current="page" href="courses.php">课程查询</a>
            </nav>
            <div class="account-actions"><span class="user-chip"><?= e($user['name']) ?> · <?= e($user['label']) ?></span><form method="post" action="logout.php"><button class="logout-button" type="submit">退出登录</button></form></div>
        </div>
    </header>

    <main>
        <section class="page-banner">
            <div class="container">
                <span class="section-kicker">COURSE CATALOG</span>
                <h1>查询本学期教学班</h1>
                <p><?= e($semester) ?> · 按课程名称、课程代码或教师筛选，查看时间与剩余名额。</p>
            </div>
        </section>
        <section class="catalog-section">
            <div class="container">
                <form class="filter-panel" method="get" action="courses.php" role="search">
                    <label class="filter-search">课程或教师
                        <input type="search" name="q" value="<?= e($query) ?>" placeholder="例如：软件工程、王老师、SE301">
                    </label>
                    <label>上课星期
                        <select name="day">
                            <option value="">全部星期</option>
                            <?php foreach ($days as $option): ?>
                                <option value="<?= e($option) ?>" <?= $day === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="primary-button" type="submit">筛选 <span>→</span></button>
                    <a class="clear-filter" href="courses.php">清除</a>
                </form>

                <div class="catalog-meta">
                    <div><span class="section-kicker">RESULTS</span><h2>教学班列表 <small>共 <?= count($filtered) ?> 个</small></h2></div>
                    <span class="demo-badge">界面演示数据</span>
                </div>

                <?php if ($filtered === []): ?>
                    <div class="empty-state"><h3>没有找到符合条件的教学班</h3><p>试试其他课程名称、教师或星期。</p><a href="courses.php">查看全部教学班 →</a></div>
                <?php else: ?>
                    <div class="course-grid">
                        <?php foreach ($filtered as $id => $course): ?>
                            <?php $remaining = $course['capacity'] - $course['selected']; ?>
                            <article class="course-card">
                                <div class="course-top <?= e($course['color']) ?>">
                                    <span><?= e($course['code']) ?> · <?= e($course['class']) ?></span>
                                    <span><?= $remaining > 0 ? '剩余 ' . $remaining . ' 席' : '已满' ?></span>
                                </div>
                                <div class="course-body">
                                    <h3><?= e($course['name']) ?></h3>
                                    <p><span aria-hidden="true">👤</span><?= e($course['teacher']) ?></p>
                                    <p><span aria-hidden="true">◷</span><?= e($course['day'] . ' ' . $course['time']) ?></p>
                                    <p><span aria-hidden="true">⌖</span><?= e($course['location']) ?></p>
                                    <div class="capacity" aria-label="已选 <?= $course['selected'] ?> 人，容量 <?= $course['capacity'] ?> 人">
                                        <div><span>选课进度</span><b><?= $course['selected'] ?>/<?= $course['capacity'] ?></b></div>
                                        <progress value="<?= $course['selected'] ?>" max="<?= $course['capacity'] ?>"></progress>
                                    </div>
                                    <a class="course-detail-link" href="course.php?id=<?= rawurlencode($id) ?>">查看教学班详情 →</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <footer><div class="container footer-wrap"><div><strong>吉林大学 · 课程注册系统</strong><span>课程信息仅供界面演示</span></div><p>© <?= date('Y') ?> 吉林大学计算机科学与技术学院</p></div></footer>
</body>
</html>
