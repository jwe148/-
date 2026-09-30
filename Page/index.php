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
            <h1>首页</h1>
            <p><?= e($user['name']) ?>，你好。请选择需要办理的事项。</p>
        </div>
        <div class="dashboard-links">
            <a class="dashboard-link" href="courses.php"><strong>课程查询</strong><span>查看教学班、教师、时间和剩余名额</span><b aria-hidden="true">→</b></a>
            <?php if ($user['role'] === 'student'): ?>
                <a class="dashboard-link" href="selection.php"><strong>我的选课</strong><span>提交或调整 4 个首选、2 个备选</span><b aria-hidden="true">→</b></a>
                <a class="dashboard-link" href="schedule.php"><strong>我的课表</strong><span>按星期查看已选教学班</span><b aria-hidden="true">→</b></a>
                <a class="dashboard-link" href="grades.php"><strong>我的成绩</strong><span>查看教师已录入的成绩</span><b aria-hidden="true">→</b></a>
            <?php elseif ($user['role'] === 'teacher'): ?>
                <a class="dashboard-link" href="teaching.php"><strong>我的授课</strong><span>认领或取消认领教学班</span><b aria-hidden="true">→</b></a>
                <?php if (databaseModeEnabled()): ?><a class="dashboard-link" href="grade_entry.php"><strong>成绩录入</strong><span>维护已结课教学班的学生成绩</span><b aria-hidden="true">→</b></a><?php endif; ?>
            <?php else: ?>
                <?php if (databaseModeEnabled()): ?><a class="dashboard-link" href="admin_closure.php"><strong>关闭选课</strong><span>确定开设教学班并处理备选补位</span><b aria-hidden="true">→</b></a><?php else: ?><div class="dashboard-link muted-link"><strong>教务服务</strong><span>配置数据库后可处理关闭选课</span></div><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
