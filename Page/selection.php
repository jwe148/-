<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/student_data.php';

$selection = studentSelection();
$primary = $selection['primary'];
$backup = $selection['backup'];
$open = selectionOpen($selectionPeriod);
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $primary = isset($_POST['primary']) && is_array($_POST['primary']) ? array_values($_POST['primary']) : [];
    $backup = isset($_POST['backup']) && is_array($_POST['backup']) ? array_values($_POST['backup']) : [];
    if (!validSelectionToken($_POST['token'] ?? null)) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif (!$open) {
        $errors[] = '当前不在选课时间内。';
    } else {
        $errors = validateStudentSelection($primary, $backup, $offerings);
    }
    if ($errors === []) {
        $_SESSION['student_selection'] = ['primary' => $primary, 'backup' => $backup];
        header('Location: selection.php?saved=1');
        exit;
    }
}

$pageTitle = '我的选课';
$active = 'selection.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading">
            <p><?= e($semester) ?></p>
            <h1>我的选课</h1>
            <p>选择 4 个首选教学班和 2 个备选教学班。重新提交可调整选课方案。</p>
        </div>
        <div class="info-line"><strong><?= $open ? '选课开放中' : '选课已结束' ?></strong><span>选课时间：<?= e(selectionTimeLabel($selectionPeriod['start'])) ?> 至 <?= e(selectionTimeLabel($selectionPeriod['end'])) ?></span></div>
        <?php if (isset($_GET['saved'])): ?><p class="message success" role="status">选课方案已保存。<a href="schedule.php">查看课表</a></p><?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="message error" role="alert"><strong>提交失败</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" action="selection.php" class="form-panel">
            <input type="hidden" name="token" value="<?= e(selectionToken()) ?>">
            <section class="form-section">
                <h2>首选教学班 <small>成功提交后进入课表并占用名额</small></h2>
                <div class="selection-fields">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <?php $chosen = isset($primary[$i]) && is_string($primary[$i]) ? $primary[$i] : ''; ?>
                        <label>首选 <?= $i + 1 ?>
                            <select name="primary[]" required <?= $open ? '' : 'disabled' ?>>
                                <option value="">请选择教学班</option>
                                <?php foreach ($offerings as $id => $course): ?>
                                    <?php $remaining = seatsRemaining($id, $offerings); ?>
                                    <option value="<?= e($id) ?>" <?= $chosen === $id ? 'selected' : '' ?> <?= $remaining === 0 && $chosen !== $id ? 'disabled' : '' ?>><?= e($course['name'] . ' · ' . $course['teacher'] . ' · ' . $course['day'] . ' ' . $course['time']) ?><?= $remaining === 0 ? '（已满）' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endfor; ?>
                </div>
            </section>
            <section class="form-section">
                <h2>备选教学班 <small>按顺序记录，当前不占名额；补位时再检查是否有空位</small></h2>
                <div class="selection-fields">
                    <?php for ($i = 0; $i < 2; $i++): ?>
                        <?php $chosen = isset($backup[$i]) && is_string($backup[$i]) ? $backup[$i] : ''; ?>
                        <label>备选 <?= $i + 1 ?>
                            <select name="backup[]" required <?= $open ? '' : 'disabled' ?>>
                                <option value="">请选择教学班</option>
                                <?php foreach ($offerings as $id => $course): ?>
                                    <?php $remaining = seatsRemaining($id, $offerings); ?>
                                    <option value="<?= e($id) ?>" <?= $chosen === $id ? 'selected' : '' ?>><?= e($course['name'] . ' · ' . $course['teacher'] . ' · ' . $course['day'] . ' ' . $course['time']) ?><?= $remaining === 0 ? '（当前已满）' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endfor; ?>
                </div>
            </section>
            <div class="form-actions"><button class="primary-button" type="submit" <?= $open ? '' : 'disabled' ?>>保存选课方案</button><a href="schedule.php">查看我的课表</a></div>
        </form>
        <p class="page-note">提交时检查首选名额、先修课和首选课程时间冲突。备选即使当前满额也可登记，补位时须重新检查。演示数据仅保存在当前登录会话中。</p>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
