<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/student_data.php';

$open = selectionOpen($selectionPeriod);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : '';
    $selection = studentSelection();
    if (!validSelectionToken($_POST['token'] ?? null)) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif (!$open) {
        $errors[] = '选课已结束，不能退课。';
    } elseif (!in_array($id, $selection['primary'], true)) {
        $errors[] = '只能退选自己课表中的教学班。';
    } else {
        $selection['primary'] = array_values(array_diff($selection['primary'], [$id]));
        $_SESSION['student_selection'] = $selection;
        header('Location: schedule.php?removed=1');
        exit;
    }
}

$selection = studentSelection();
$days = ['周一', '周二', '周三', '周四', '周五'];
$byDay = array_fill_keys($days, []);
foreach ($selection['primary'] as $id) {
    if (isset($offerings[$id])) {
        $byDay[$offerings[$id]['day']][] = ['id' => $id, 'course' => $offerings[$id]];
    }
}
foreach ($byDay as &$dayCourses) {
    usort($dayCourses, static fn(array $a, array $b): int => strcmp($a['course']['time'], $b['course']['time']));
}
unset($dayCourses);

$pageTitle = '我的课表';
$active = 'schedule.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container">
        <div class="page-heading"><p><?= e($semester) ?></p><h1>我的课表</h1><p>当前首选教学班，共 <?= count($selection['primary']) ?> 门。</p></div>
        <?php if (isset($_GET['removed'])): ?><p class="message success" role="status">已退课，名额已恢复。<a href="selection.php">前往补选</a></p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
        <?php if ($selection['primary'] === []): ?>
            <div class="empty-state"><h2>课表暂无课程</h2><p>先提交选课方案，成功选中的首选教学班会显示在这里。</p><a href="selection.php">前往选课 →</a></div>
        <?php else: ?>
            <div class="timetable">
                <?php foreach ($byDay as $day => $dayCourses): ?>
                    <section class="day-column"><h2><?= e($day) ?></h2>
                        <?php if ($dayCourses === []): ?><p class="no-class">无课程</p><?php endif; ?>
                        <?php foreach ($dayCourses as $item): ?>
                            <article class="class-item">
                                <time><?= e($item['course']['time']) ?></time>
                                <h3><?= e($item['course']['name']) ?></h3>
                                <p><?= e($item['course']['teacher'] . ' · ' . $item['course']['location']) ?></p>
                                <?php if ($open): ?><form method="post" action="schedule.php"><input type="hidden" name="token" value="<?= e(selectionToken()) ?>"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button class="text-button" type="submit">退课</button></form><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="simple-panel">
            <h2>备选顺序</h2>
            <?php if ($selection['backup'] === []): ?><p>尚未提交备选教学班。</p><?php else: ?>
                <ol><?php foreach ($selection['backup'] as $id): ?><?php if (isset($offerings[$id])): ?><li><?= e($offerings[$id]['name'] . ' · ' . $offerings[$id]['day'] . ' ' . $offerings[$id]['time']) ?></li><?php endif; ?><?php endforeach; ?></ol>
            <?php endif; ?>
            <a href="selection.php">调整选课方案 →</a>
        </div>
        <p class="page-note">备选教学班不在当前课表中。选课关闭后的补位流程将在教务功能接入后实现。</p>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
