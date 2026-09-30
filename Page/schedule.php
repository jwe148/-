<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog_runtime.php';
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
        try {
            if (databaseModeEnabled()) {
                $errors = dbDropStudentOffering(databaseConnection(), $user['id'], $selectionPeriod['semester_id'], $id);
            } else {
                $selection['primary'] = array_values(array_diff($selection['primary'], [$id]));
                $_SESSION['student_selection'] = $selection;
            }
            if ($errors === []) {
                header('Location: schedule.php?removed=1');
                exit;
            }
        } catch (Throwable $error) {
            error_log('Course registration drop failed: ' . $error->getMessage());
            $errors[] = '退课未完成，请稍后重试。';
        }
    }
}

$selection = studentSelection();
$closureChanges = databaseModeEnabled() && ($selectionPeriod['status'] ?? 'open') === 'closed'
    ? dbStudentClosureChanges(databaseConnection(), $user['id'], $selectionPeriod['semester_id'])
    : [];
$backupAttempts = databaseModeEnabled() && ($selectionPeriod['status'] ?? 'open') === 'closed'
    ? dbStudentBackupAttempts(databaseConnection(), $user['id'], $selectionPeriod['semester_id'])
    : [];
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
            <div class="empty-state"><h2>课表暂无课程</h2><p><?= $open ? '先提交选课方案，成功选中的首选教学班会显示在这里。' : '当前没有保留的教学班。' ?></p><?php if ($open): ?><a href="selection.php">前往选课 →</a><?php endif; ?></div>
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
            <h2><?= ($selectionPeriod['status'] ?? 'open') === 'closed' ? '未补入的备选' : '备选顺序' ?></h2>
            <?php if ($selection['backup'] === []): ?><p>尚未提交备选教学班。</p><?php else: ?>
                <ol><?php foreach ($selection['backup'] as $id): ?><?php if (isset($offerings[$id])): ?><li><?= e($offerings[$id]['name'] . ' · ' . $offerings[$id]['day'] . ' ' . $offerings[$id]['time']) ?></li><?php endif; ?><?php endforeach; ?></ol>
            <?php endif; ?>
            <?php if ($open): ?><a href="selection.php">调整选课方案 →</a><?php endif; ?>
        </div>
        <?php if (databaseModeEnabled() && ($selectionPeriod['status'] ?? 'open') === 'closed'): ?>
            <div class="simple-panel"><h2>关闭结果</h2>
                <?php if ($closureChanges === []): ?><p>课表无需调整。</p><?php else: ?><ol>
                    <?php foreach ($closureChanges as $change): ?><li><?= e($change['course_name']) ?>：<?= $change['status'] === 'cancelled' ? e($change['outcome'] === 'no_teacher' ? '无教师，已停开' : '人数不足，已停开') : '由备选补入' ?></li><?php endforeach; ?>
                </ol><?php endif; ?>
            </div>
            <?php if ($backupAttempts !== []): ?>
                <?php $attemptLabels = ['promoted' => '已补入', 'cancelled' => '教学班停开，未补入', 'full' => '名额已满，未补入', 'missing_time' => '缺少上课时间，未补入', 'prerequisite' => '先修条件不满足，未补入', 'duplicate_course' => '与课表课程重复，未补入', 'time_conflict' => '与课表时间冲突，未补入']; ?>
                <div class="simple-panel"><h2>备选处理</h2><ol>
                    <?php foreach ($backupAttempts as $attempt): ?><li>备选 <?= (int) $attempt['backup_rank'] ?>：<?= e($attempt['course_name']) ?> · <?= e($attemptLabels[$attempt['outcome']] ?? '未补入') ?></li><?php endforeach; ?>
                </ol></div>
            <?php endif; ?>
            <p class="page-note">选课已关闭，课表显示最终占座结果，不能再调整。</p>
        <?php else: ?>
            <p class="page-note">备选教学班当前不占名额；关闭选课时才会按顺序尝试补位。</p>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
