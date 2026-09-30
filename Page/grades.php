<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/student_data.php';
$grades = studentGrades();
$currentGrades = array_values(array_filter($grades, static fn(array $record): bool => $record['semester'] === $semester));
$historicalGrades = array_values(array_filter($grades, static fn(array $record): bool => $record['semester'] !== $semester));
$pageTitle = '我的成绩';
$active = 'grades.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading"><p><?= e($semester) ?></p><h1>我的成绩</h1><p>仅显示当前登录学生的成绩。</p></div>
        <section class="simple-panel"><h2>当前学期</h2>
            <?php if ($currentGrades === []): ?><p>暂无成绩。授课教师录入后，可在这里查看。</p><?php else: ?>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>课程</th><th>成绩等级</th></tr></thead><tbody>
                    <?php foreach ($currentGrades as $record): ?><tr><td><?= e($record['course']) ?></td><td><?= e($record['grade']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <section class="simple-panel"><h2>已完成课程</h2>
            <?php if ($historicalGrades === []): ?><p>暂无已完成课程。</p><?php else: ?>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>学期</th><th>课程</th><th>成绩等级</th></tr></thead><tbody>
                    <?php foreach ($historicalGrades as $record): ?><tr><td><?= e($record['semester']) ?></td><td><?= e($record['course']) ?></td><td><?= e($record['grade']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <p class="page-note"><?= databaseModeEnabled() ? '成绩从 MySQL 读取；教师保存后立即可见。' : '历史成绩为本地演示数据；教师录入需要配置数据库。' ?></p>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
