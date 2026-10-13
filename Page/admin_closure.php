<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('admin');
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/student_data.php';
require __DIR__ . '/admin_closure_db.php';

if (!isset($_SESSION['admin_close_token'])) {
    $_SESSION['admin_close_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['admin_close_token'];
$errors = [];
$overview = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['token'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif (!databaseModeEnabled()) {
        $errors[] = '关闭选课需要先配置数据库。';
    } else {
        try {
            $action = $_POST['action'] ?? 'close';
            if ($action === 'period') {
                $values = [];
                foreach (['start', 'end', 'teaching_end'] as $key) {
                    $values[] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
                }
                $errors = dbSetSelectionPeriod(databaseConnection(), $user['id'], $selectionPeriod['semester_id'], ...$values);
                if ($errors === []) {
                    header('Location: admin_closure.php?period_saved=1');
                    exit;
                }
            } elseif ($action === 'close') {
                $result = dbCloseSemester(databaseConnection(), $user['id'], $selectionPeriod['semester_id']);
                $errors = $result['errors'];
                if ($errors === []) {
                    try {
                        dbDispatchBilling(databaseConnection(), $user['id'], $selectionPeriod['semester_id']);
                    } catch (Throwable $sendError) {
                        error_log('Billing dispatch failed: ' . $sendError->getMessage());
                    }
                    header('Location: admin_closure.php?' . ($result['changed'] ? 'closed=1' : 'already=1'));
                    exit;
                }
            } elseif ($action === 'reopen') {
                $newEnd = is_string($_POST['reopen_end'] ?? null) ? $_POST['reopen_end'] : '';
                $teachingEnd = is_string($_POST['reopen_teaching_end'] ?? null) ? $_POST['reopen_teaching_end'] : '';
                $errors = dbReopenSemester(databaseConnection(), $user['id'], $selectionPeriod['semester_id'], $newEnd, $teachingEnd);
                if ($errors === []) {
                    header('Location: admin_closure.php?reopened=1');
                    exit;
                }
            } else {
                $errors[] = '无效操作。';
            }
        } catch (Throwable $error) {
            error_log('Course registration close failed: ' . $error->getMessage());
            $errors[] = '操作未完成，数据未修改，请稍后重试。';
        }
    }
}
if (databaseModeEnabled()) {
    try {
        $overview = dbClosureOverview(databaseConnection(), $selectionPeriod['semester_id']);
    } catch (Throwable $error) {
        error_log('Course registration close overview failed: ' . $error->getMessage());
        http_response_code(503);
        $errors[] = '关闭结果暂不可用，请检查数据库。';
    }
}
$canClose = databaseModeEnabled() && $overview !== [] && ($selectionPeriod['status'] ?? 'open') === 'open';
$statusLabels = ['open' => '选课中', 'closing' => '处理中', 'closed' => '已关闭'];
$outcomeLabels = ['confirmed' => '开设', 'no_teacher' => '停开：无教师', 'under_minimum' => '停开：不足 3 人'];
$pageTitle = '关闭选课';
$active = 'admin_closure.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading"><p><?= e($semester) ?></p><h1>关闭选课</h1></div>
        <div class="info-line"><strong><?= e($statusLabels[$selectionPeriod['status'] ?? 'open'] ?? '未知状态') ?></strong><span>选课截止：<?= e(selectionTimeLabel($selectionPeriod['end'])) ?></span></div>
        <?php if (isset($_GET['closed'])): ?><p class="message success" role="status">选课已关闭，最终课表与学费账单已生成。计费发送结果可在“任教与计费”查看。</p><?php endif; ?>
        <?php if (isset($_GET['already'])): ?><p class="message success" role="status">此前已关闭，本次未重复处理。</p><?php endif; ?>
        <?php if (isset($_GET['period_saved'])): ?><p class="message success" role="status">学期时间已保存。</p><?php endif; ?>
        <?php if (isset($_GET['reopened'])): ?><p class="message success" role="status">选课已重新开启，现在可以选课。若此前已执行关课，其关闭结果和本次计费记录已撤销。</p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
        <?php if (databaseModeEnabled()): ?>
            <details class="simple-panel"><summary>维护本学期时间</summary>
                <p>选课关闭与教学结束是两个阶段；只有教学结束后才能录入成绩。已关闭时可在下方设置新的截止时间并重新开启。</p>
                <form method="post">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <input type="hidden" name="action" value="period">
                    <?php $periodClosed = ($selectionPeriod['status'] ?? 'open') === 'closed'; ?>
                    <div class="people-fields">
                        <label>选课开始<input type="datetime-local" name="start" step="1" value="<?= e(str_replace(' ', 'T', $selectionPeriod['start'])) ?>" <?= $periodClosed ? 'readonly' : '' ?> required></label>
                        <label>选课截止<input type="datetime-local" name="end" step="1" value="<?= e(str_replace(' ', 'T', $selectionPeriod['end'])) ?>" <?= $periodClosed ? 'readonly' : '' ?> required></label>
                        <label>教学结束<input type="datetime-local" name="teaching_end" step="1" value="<?= e(str_replace(' ', 'T', $selectionPeriod['teaching_end'] ?? '')) ?>" required></label>
                    </div>
                    <div class="form-actions"><button class="primary-button" type="submit">保存时间</button></div>
                </form>
            </details>
            <section class="simple-panel">
                <h2>教学班处理结果</h2><p><a href="admin_extras.php">查看计费发送记录 / 重试</a></p>
                <?php if ($overview === []): ?><p>暂无教学班数据。</p><?php else: ?>
                    <div class="table-scroll"><table class="data-table"><thead><tr><th>教学班</th><th>教师</th><th>关闭前人数</th><th>当前人数</th><th>结果</th></tr></thead><tbody>
                        <?php foreach ($overview as $row): ?><tr>
                            <td><?= e($row['course_name'] . ' · ' . $row['class_name']) ?></td>
                            <td><?= e($row['teacher_name']) ?></td>
                            <td><?= $row['original_active_count'] === null ? '—' : (int) $row['original_active_count'] ?></td>
                            <td><?= (int) $row['active_count'] ?>/<?= (int) $row['capacity'] ?><?php if ((int) $row['backup_count'] > 0): ?>（备选补入 <?= (int) $row['backup_count'] ?>）<?php endif; ?></td>
                            <td><?= e($row['outcome'] === null ? '待处理' : ($outcomeLabels[$row['outcome']] ?? '未知')) ?></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>
            <?php if (($selectionPeriod['status'] ?? 'open') === 'open'): ?>
                <section class="simple-panel">
                    <h2>执行关闭</h2>
                    <p>停开不符合条件的教学班，按备选顺序补位并生成最终课表。</p><details class="page-help"><summary>处理规则</summary><p>先停开无教师班并补位，再停开仍不足 3 人的班，用剩余备选补位。每次检查名额、先修和时间冲突。</p></details>
                    <form method="post" action="admin_closure.php" class="form-actions">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <button class="primary-button" type="submit" <?= $canClose ? '' : 'disabled' ?>>关闭选课</button>
                        <span>管理员可随时关闭，立即停止选课并执行关课处理。</span>
                    </form>
                </section>
            <?php endif; ?>
            <?php if (in_array($selectionPeriod['status'] ?? '', ['open', 'closed'], true)): ?>
                <section class="simple-panel">
                    <h2>重新开启选课</h2>
                    <p>开启后立即允许选课，默认开放 7 天。此前已关闭时会恢复关闭前的教学班、学生志愿与占座，并撤销本次关闭结果和计费记录；已有成绩时不能撤销关课。</p>
                    <form method="post" action="admin_closure.php">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <input type="hidden" name="action" value="reopen">
                        <details><summary>自定义开放时间（不填写则使用默认时间）</summary><div class="people-fields">
                            <label>新的选课截止<input type="datetime-local" name="reopen_end" step="1"></label>
                            <label>教学结束<input type="datetime-local" name="reopen_teaching_end" step="1"></label>
                        </div></details>
                        <div class="form-actions"><button class="primary-button" type="submit">重新开启选课</button></div>
                    </form>
                </section>
            <?php endif; ?>
        <?php else: ?>
            <p class="message error">关闭选课需要配置 MySQL 并导入学期数据。</p>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
