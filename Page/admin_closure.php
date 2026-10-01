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
            $result = dbCloseSemester(databaseConnection(), $user['id'], $selectionPeriod['semester_id']);
            $errors = $result['errors'];
            if ($errors === []) {
                header('Location: admin_closure.php?' . ($result['changed'] ? 'closed=1' : 'already=1'));
                exit;
            }
        } catch (Throwable $error) {
            error_log('Course registration close failed: ' . $error->getMessage());
            $errors[] = '关闭未完成，数据未修改，请稍后重试。';
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
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
$afterDeadline = $now > new DateTimeImmutable($selectionPeriod['end'], new DateTimeZone('Asia/Shanghai'));
$canClose = databaseModeEnabled() && $overview !== [] && ($selectionPeriod['status'] ?? 'open') === 'open' && $afterDeadline;
$statusLabels = ['open' => '选课中', 'closing' => '处理中', 'closed' => '已关闭'];
$outcomeLabels = ['confirmed' => '开设', 'no_teacher' => '停开：无教师', 'under_minimum' => '停开：不足 3 人'];
$pageTitle = '关闭选课';
$active = 'admin_closure.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading"><p><?= e($semester) ?></p><h1>关闭选课</h1><p>选课截止后处理教学班和备选补位。</p></div>
        <div class="info-line"><strong><?= e($statusLabels[$selectionPeriod['status'] ?? 'open'] ?? '未知状态') ?></strong><span>选课截止：<?= e(selectionTimeLabel($selectionPeriod['end'])) ?></span></div>
        <?php if (isset($_GET['closed'])): ?><p class="message success" role="status">选课已关闭，最终课表已生成。</p><?php endif; ?>
        <?php if (isset($_GET['already'])): ?><p class="message success" role="status">此前已关闭，本次未重复处理。</p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
        <?php if (databaseModeEnabled()): ?>
            <section class="simple-panel">
                <h2>教学班处理结果</h2>
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
                    <p>无教师或关闭前不足 3 人的教学班将停开。系统先停开，再按学生备选顺序补位；名额和时间冲突会重新检查。</p>
                    <form method="post" action="admin_closure.php" class="form-actions">
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <button class="primary-button" type="submit" <?= $canClose ? '' : 'disabled' ?>>关闭选课并生成最终课表</button>
                        <?php if (!$afterDeadline): ?><span>截止后才能操作。</span><?php endif; ?>
                    </form>
                </section>
            <?php endif; ?>
            <p class="page-note">关闭操作只执行一次，重复进入可查看已有结果。</p>
        <?php else: ?>
            <p class="message error">关闭选课需要配置 MySQL 并导入学期数据。</p>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
