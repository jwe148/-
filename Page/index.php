<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireLogin();
require __DIR__ . '/data/catalog_runtime.php';
$pageTitle = '首页';
$active = 'index.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container">
        <div class="page-heading">
            <p><?= e($semester) ?></p>
            <h1>常用功能</h1>
        </div>
        <div class="dashboard-links">
            <a class="dashboard-link" href="courses.php"><strong>课程查询</strong><span>查询教学班和剩余名额</span></a>
            <?php if ($user['role'] === 'student'): ?>
                <a class="dashboard-link" href="selection.php"><strong>我的选课</strong><span>提交或调整选课方案</span></a>
                <a class="dashboard-link" href="schedule.php"><strong>我的课表</strong><span>查看已选课程</span></a>
                <a class="dashboard-link" href="grades.php"><strong>我的成绩</strong><span>查看课程成绩</span></a>
            <?php elseif ($user['role'] === 'teacher'): ?>
                <a class="dashboard-link" href="teaching.php"><strong>我的授课</strong><span>认领或取消认领教学班</span></a>
                <?php if (databaseModeEnabled()): ?><a class="dashboard-link" href="grade_entry.php"><strong>成绩录入</strong><span>录入已结课教学班成绩</span></a><?php endif; ?>
            <?php else: ?>
                <?php if (databaseModeEnabled()): ?><a class="dashboard-link" href="admin_closure.php"><strong>关闭选课</strong><span>处理停开和备选补位</span></a><?php else: ?><div class="dashboard-link muted-link"><strong>关闭选课</strong><span>演示模式暂不可用</span></div><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
