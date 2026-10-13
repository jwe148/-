<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/student_data.php';
require_once __DIR__ . '/billing_db.php';

$open = selectionOpen($selectionPeriod);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : '';
    $selection = studentSelection();
    if (!validSelectionToken($_POST['token'] ?? null)) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif (!$open) {
        $errors[] = '选课已结束，不能退课。';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        if (($_POST['confirm'] ?? '') !== 'yes') $errors[] = '请确认删除整份课表。';
        else {
            try {
                if (databaseModeEnabled()) $errors = dbChangeStudentPlan(databaseConnection(), $user['id'], $selectionPeriod['semester_id'], 'delete');
                else { $_SESSION['student_selection']=['primary'=>[], 'backup'=>[]]; unset($_SESSION['selection_draft']); }
                if ($errors === []) { header('Location: schedule.php?deleted=1'); exit; }
            } catch (Throwable $error) { error_log('Delete schedule failed: '.$error->getMessage()); $errors[]='删除未完成，请稍后重试。'; }
        }
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
$bill = null;
if (databaseModeEnabled() && ($selectionPeriod['status'] ?? 'open') === 'closed') {
    $query = databaseConnection()->prepare('SELECT payload,status FROM billing_outbox WHERE student_id=? AND semester_id=?');
    $query->execute([$user['id'], $selectionPeriod['semester_id']]);
    $row = $query->fetch();
    if ($row) $bill = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) + ['status'=>$row['status']];
}
$days = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];
$byDay = array_fill_keys($days, []);
foreach ($selection['primary'] as $id) {
    if (isset($offerings[$id])) {
        foreach (offeringTimes($offerings[$id]) as $time) {
            $byDay[$time['day']][] = ['id' => $id, 'course' => $offerings[$id], 'time' => $time['time']];
        }
    }
}
foreach ($byDay as &$dayCourses) {
    usort($dayCourses, static fn(array $a, array $b): int => strcmp($a['time'], $b['time']));
}
unset($dayCourses);

$pageTitle = '我的课表';
$active = 'schedule.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container">
        <div class="page-heading"><p><?= e($semester) ?></p><h1>我的课表</h1><p>已选 <?= count($selection['primary']) ?> 门课程</p></div>
        <?php if (isset($_GET['removed'])): ?><p class="message success" role="status">已退课，名额已恢复。<a href="selection.php">前往补选</a></p><?php endif; ?>
        <?php if(isset($_GET['deleted'])): ?><p class="message success">课表、备选和草稿已清空，名额已释放。</p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
        <?php if ($selection['primary'] === []): ?>
            <div class="empty-state"><h2>课表暂无课程</h2><p><?= $open ? '提交选课方案后，首选课程会显示在这里。' : '没有保留的课程。' ?></p><?php if ($open): ?><a href="selection.php">去选课</a><?php endif; ?></div>
        <?php else: ?>
            <div class="timetable">
                <?php foreach ($byDay as $day => $dayCourses): ?>
                    <section class="day-column"><h2><?= e($day) ?></h2>
                        <?php if ($dayCourses === []): ?><p class="no-class">无课程</p><?php endif; ?>
                        <?php foreach ($dayCourses as $item): ?>
                            <article class="class-item">
                                <time><?= e($item['time']) ?></time>
                                <h3><?= e($item['course']['name']) ?></h3>
                                <p><?= e($item['course']['teacher'] . ' · ' . $item['course']['location']) ?></p>
                                <?php if ($open): ?><form method="post" action="schedule.php"><input type="hidden" name="token" value="<?= e(selectionToken()) ?>"><input type="hidden" name="id" value="<?= e($item['id']) ?>"><button class="text-button" type="submit">退课</button></form><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($open): ?><details class="page-help"><summary>删除整份课表</summary><form method="post" action="schedule.php"><input type="hidden" name="token" value="<?= e(selectionToken()) ?>"><input type="hidden" name="action" value="delete"><label><input type="checkbox" name="confirm" value="yes" required>确认清空本学期课表、备选和草稿</label><button class="text-button" type="submit">确认删除</button></form></details><?php endif; ?>
        <details class="simple-panel disclosure-panel"><summary><?= ($selectionPeriod['status'] ?? 'open') === 'closed' ? '未补入的备选' : '备选顺序' ?> · <?= count($selection['backup']) ?> 门</summary><div class="disclosure-content">
            <?php if ($selection['backup'] === []): ?><p>尚未提交备选教学班。</p><?php else: ?>
                <ol><?php foreach ($selection['backup'] as $id): ?><?php if (isset($offerings[$id])): ?><li><?= e($offerings[$id]['name'] . ' · ' . offeringTimeLabel($offerings[$id])) ?></li><?php endif; ?><?php endforeach; ?></ol>
            <?php endif; ?>
            <?php if ($open): ?><a href="selection.php">调整选课方案</a><?php endif; ?>
        </div></details>
        <?php if (databaseModeEnabled() && ($selectionPeriod['status'] ?? 'open') === 'closed'): ?>
            <section class="simple-panel"><h2>学费账单</h2>
                <?php if ($bill && isset($bill['total_cents'])): ?>
                    <p>学费合计：<strong>¥<?= e(billingMoney((int)$bill['total_cents'])) ?></strong>（<?= count($bill['offerings']) ?> 门 × ¥<?= e(billingMoney((int)$bill['course_fee_cents'])) ?>）</p>
                    <ul><?php foreach ($bill['offerings'] as $item): ?><li><?= e($item['name'].' · '.$item['id']) ?>：¥<?= e(billingMoney((int)$item['amount_cents'])) ?><br><small><?= e(billingScheduleLabel($item)) ?></small></li><?php endforeach; ?></ul>
                    <p><?= $bill['status'] === 'sent' ? '计费系统已模拟接收账单。' : '账单已保存，等待计费系统接收。' ?>当前为模拟计费，不产生真实扣款。</p>
                <?php elseif ($bill): ?><p>历史课表记录未计算学费。</p>
                <?php else: ?><p>没有最终课程，无需计费。</p><?php endif; ?>
            </section>
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
            <p class="page-note">选课已关闭，课表不能再调整。</p>
        <?php else: ?>

        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
