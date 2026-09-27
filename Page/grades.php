<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/student_data.php';
$grades = studentGrades();
$pageTitle = '我的成绩';
$active = 'grades.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading"><p><?= e($semester) ?></p><h1>我的成绩</h1><p>仅显示当前登录学生的演示成绩。</p></div>
        <section class="simple-panel"><h2>当前学期</h2><p>暂无成绩。授课教师录入并公布后，可在这里查看。</p></section>
        <section class="simple-panel"><h2>已完成课程</h2>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>学期</th><th>课程</th><th>成绩等级</th></tr></thead><tbody>
                <?php foreach ($grades as $record): ?><tr><td><?= e($record['semester']) ?></td><td><?= e($record['course']) ?></td><td><?= e($record['grade']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <p class="page-note">历史成绩仅用于展示先修课校验；成绩录入与数据库将在后续阶段接入。</p>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
