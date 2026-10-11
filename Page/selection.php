<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('student');
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/student_data.php';

$selection = studentSelection();
$draft = databaseModeEnabled() ? dbSelectionDraft(databaseConnection(), $user['id'], $selectionPeriod['semester_id']) : ($_SESSION['selection_draft'] ?? null);
if (is_array($draft) && selectionOpen($selectionPeriod)) $selection = $draft;
$primary = $selection['primary'];
$backup = $selection['backup'];
$open = selectionOpen($selectionPeriod);
$errors = [];
$prefillNotice = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $open) {
    $suggested = $_GET['offering'] ?? null;
    if (is_string($suggested) && isset($offerings[$suggested])) {
        if (in_array($suggested, array_merge($primary, $backup), true)) {
            $prefillNotice = '该教学班已在当前选课方案中。';
        } elseif (seatsRemaining($suggested, $offerings) === 0) {
            $prefillNotice = '该教学班当前已满，可手动选为备选。';
        } else {
            for ($i = 0; $i < 4; $i++) {
                if (!isset($primary[$i]) || $primary[$i] === '') {
                    $primary[$i] = $suggested;
                    $prefillNotice = '已将' . $offerings[$suggested]['name'] . '填入首选 ' . ($i + 1) . '，保存后才会生效。';
                    break;
                }
            }
            if ($prefillNotice === '') {
                $prefillNotice = '首选四项已有选择，请手动替换一项并保存。';
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $primary = isset($_POST['primary']) && is_array($_POST['primary']) ? array_values($_POST['primary']) : [];
    $backup = isset($_POST['backup']) && is_array($_POST['backup']) ? array_values($_POST['backup']) : [];
    if (!validSelectionToken($_POST['token'] ?? null)) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif (!$open) {
        $errors[] = '当前不在选课时间内。';
    } else {
        try {
            $saveDraft = ($_POST['action'] ?? '') === 'draft';
            if ($saveDraft) {
                $errors = draftErrors($primary, $backup, $offerings);
                if ($errors === []) {
                    if (databaseModeEnabled()) $errors = dbChangeStudentPlan(databaseConnection(), $user['id'], $selectionPeriod['semester_id'], 'draft', $primary, $backup, $offerings);
                    else $_SESSION['selection_draft'] = ['primary'=>$primary, 'backup'=>$backup];
                }
            } elseif (databaseModeEnabled()) {
                $errors = dbSaveStudentSelection(
                    databaseConnection(), $user['id'], $selectionPeriod['semester_id'], $primary, $backup, $offerings
                );
            } else {
                $errors = validateStudentSelection($primary, $backup, $offerings);
                if ($errors === []) {
                    $_SESSION['student_selection'] = ['primary' => $primary, 'backup' => $backup];
                    unset($_SESSION['selection_draft']);
                }
            }
        } catch (Throwable $error) {
            error_log('Course registration save failed: ' . $error->getMessage());
            $errors[] = '提交未完成，请稍后重试。';
        }
    }
    if ($errors === []) {
        header('Location: selection.php?' . (($_POST['action'] ?? '') === 'draft' ? 'draft=1' : 'saved=1'));
        exit;
    }
}
$primaryCount = count(array_filter($primary, static fn($id): bool => is_string($id) && $id !== ''));
$backupCount = count(array_filter($backup, static fn($id): bool => is_string($id) && $id !== ''));

$pageTitle = '我的选课';
$active = 'selection.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading">
            <p><?= e($semester) ?></p>
            <h1>我的选课</h1>
        </div>
        <div class="info-line"><strong><?= $open ? '选课开放中' : '选课已结束' ?></strong><span>截止：<?= e(selectionTimeLabel($selectionPeriod['end'])) ?></span></div>
        <?php if ($prefillNotice !== ''): ?><p class="message" role="status"><?= e($prefillNotice) ?></p><?php endif; ?>
        <?php if (isset($_GET['saved'])): ?><p class="message success" role="status">选课方案已保存。<a href="schedule.php">查看课表</a></p><?php endif; ?>
        <?php if (isset($_GET['draft'])): ?><p class="message success" role="status">草稿已保存，未占用名额。</p><?php elseif (is_array($draft) && $open): ?><p class="message" role="status">已载入未提交草稿，正式提交时检查名额与冲突。</p><?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="message error" role="alert"><strong>提交失败</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" action="selection.php" class="form-panel" id="selection-form">
            <input type="hidden" name="token" value="<?= e(selectionToken()) ?>">
            <section class="form-section">
                <div class="section-heading"><h2 id="primary-count" aria-live="polite">首选 <?= $primaryCount ?>/4</h2><span>提交后占座</span></div>
                <div class="selection-fields">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <?php $chosen = isset($primary[$i]) && is_string($primary[$i]) ? $primary[$i] : ''; ?>
                        <label>首选 <?= $i + 1 ?>
                            <select name="primary[]" required <?= $open ? '' : 'disabled' ?>>
                                <option value="">请选择教学班</option>
                                <?php foreach ($offerings as $id => $course): ?>
                                    <?php $remaining = seatsRemaining($id, $offerings); ?>
                                    <option value="<?= e($id) ?>" data-course-code="<?= e($course['code']) ?>" <?= $chosen === $id ? 'selected' : '' ?> <?= $remaining === 0 && $chosen !== $id ? 'disabled' : '' ?>><?= e($course['name'] . ' · ' . $course['teacher'] . ' · ' . offeringTimeLabel($course)) ?><?= $remaining === 0 ? '（已满）' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endfor; ?>
                </div>
            </section>
            <section class="form-section">
                <div class="section-heading"><h2 id="backup-count" aria-live="polite">备选 <?= $backupCount ?>/2</h2><span>按顺序补位，不占座</span></div>
                <div class="selection-fields">
                    <?php for ($i = 0; $i < 2; $i++): ?>
                        <?php $chosen = isset($backup[$i]) && is_string($backup[$i]) ? $backup[$i] : ''; ?>
                        <label>备选 <?= $i + 1 ?>
                            <select name="backup[]" required <?= $open ? '' : 'disabled' ?>>
                                <option value="">请选择教学班</option>
                                <?php foreach ($offerings as $id => $course): ?>
                                    <?php $remaining = seatsRemaining($id, $offerings); ?>
                                    <option value="<?= e($id) ?>" data-course-code="<?= e($course['code']) ?>" <?= $chosen === $id ? 'selected' : '' ?>><?= e($course['name'] . ' · ' . $course['teacher'] . ' · ' . offeringTimeLabel($course)) ?><?= $remaining === 0 ? '（当前已满）' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endfor; ?>
                </div>
            </section>
            <p class="selection-warning" id="selection-duplicate" role="alert" hidden></p>
            <div class="form-actions"><button class="primary-button" type="submit" <?= $open ? '' : 'disabled' ?>>保存选课</button><button class="text-button" type="submit" name="action" value="draft" formnovalidate <?= $open ? '' : 'disabled' ?>>保存草稿</button><a href="schedule.php">查看课表</a></div>
        </form>
        <details class="page-help"><summary>选课规则</summary><p>提交 4 个首选、2 个备选。同一课程不能重复选择；提交时检查名额、先修与时间冲突。备选可以登记已满的班级，补位时重新检查。</p><p>选课时间：<?= e(selectionTimeLabel($selectionPeriod['start'])) ?> 至 <?= e(selectionTimeLabel($selectionPeriod['end'])) ?></p><?php if (!databaseModeEnabled()): ?><p>演示记录仅在本次登录中有效。</p><?php endif; ?></details>
    </div>
</main>
<script src="assets/js/selection.js" defer></script>
<?php require __DIR__ . '/partials/footer.php'; ?>
