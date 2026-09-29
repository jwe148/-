<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireLogin();
require __DIR__ . '/data/catalog.php';
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
                <a class="dashboard-link" href="grades.php"><strong>我的成绩</strong><span>查看已公布的成绩</span><b aria-hidden="true">→</b></a>
            <?php elseif ($user['role'] === 'teacher'): ?>
                <a class="dashboard-link" href="teaching.php"><strong>我的授课</strong><span>认领或取消认领教学班</span><b aria-hidden="true">→</b></a>
            <?php else: ?>
                <div class="dashboard-link muted-link"><strong>教务服务</strong><span>相关业务页面将在下一阶段接入</span></div>
            <?php endif; ?>
        </div>
        <p class="page-note">当前账号和课程为本地演示数据，选课和教师认领结果只保存在本次登录会话中。</p>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
