<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
// 成绩来自注册库，不依赖外部课程目录服务的实时可用性。
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/student_data.php';
$grades = studentGrades();
$reportCards = [];
$hasGrades = false;
foreach ($grades as $record) {
    $reportCards[$record['semester']][] = $record;
    if (is_string($record['grade']) && trim($record['grade']) !== '') {
        $hasGrades = true;
    }
}
$pageTitle = '我的成绩';
$active = 'grades.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading"><h1>我的成绩</h1><p>已完成学期</p></div>
        <?php if (!$hasGrades): ?><p class="message" role="status"><?= $grades === [] ? '暂无已完成学期的成绩信息。' : '暂无成绩信息，已完成课程的成绩尚未录入。' ?></p><?php endif; ?>
        <?php foreach ($reportCards as $semesterName => $records): ?>
            <section class="simple-panel"><h2><?= e((string) $semesterName) ?></h2>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>课程</th><th>成绩等级</th></tr></thead><tbody>
                    <?php foreach ($records as $record): ?><tr><td><?= e($record['course']) ?></td><td><?= e(is_string($record['grade']) && trim($record['grade']) !== '' ? $record['grade'] : '未录入') ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section>
        <?php endforeach; ?>
        <?php if (!databaseModeEnabled()): ?><p class="page-note">当前显示演示成绩。</p><?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
