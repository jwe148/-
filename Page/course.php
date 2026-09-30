<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireLogin();
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/student_data.php';
require __DIR__ . '/teacher_data.php';
if ($user['role'] === 'teacher') {
    showTeacherClaims($offerings, teacherClaims(), $user['name']);
}

$id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
$course = $offerings[$id] ?? null;
if ($course === null) {
    http_response_code(404);
}
$remaining = $course === null ? 0 : seatsRemaining($id, $offerings);
$selected = $course === null ? 0 : $course['capacity'] - $remaining;
$pageTitle = $course === null ? '未找到教学班' : $course['name'] . ' · ' . $course['class'];
$active = 'courses.php';
require __DIR__ . '/partials/header.php';
?>
    <main>
        <section class="page-banner">
            <div class="container">
                <a class="breadcrumb" href="courses.php">← 返回教学班列表</a>
                <h1><?= $course === null ? '未找到教学班' : e($course['name']) ?></h1>
                <p><?= $course === null ? '请返回列表重新选择。' : e($semester . ' · ' . $course['code'] . ' · ' . $course['class']) ?></p>
            </div>
        </section>

        <?php if ($course !== null): ?>
            <section class="catalog-section">
                <div class="container detail-layout">
                    <article class="detail-card">
                        <div class="detail-title"><h2>基本信息</h2><?php if (!databaseModeEnabled()): ?><span class="demo-badge">演示数据</span><?php endif; ?></div>
                        <dl class="detail-list">
                            <div><dt>教学班</dt><dd><?= e($course['code'] . ' · ' . $course['class']) ?></dd></div>
                            <div><dt>授课教师</dt><dd><?= e($course['teacher']) ?></dd></div>
                            <div><dt>状态</dt><dd><?= e(($course['status'] ?? 'open') === 'cancelled' ? '已停开' : ((($course['status'] ?? 'open') === 'confirmed') ? '已开设' : '选课中')) ?></dd></div>
                            <div><dt>上课时间</dt><dd><?= e(offeringTimeLabel($course)) ?></dd></div>
                            <div><dt>上课地点</dt><dd><?= e($course['location']) ?></dd></div>
                            <div><dt>先修课程</dt><dd><?= e($course['prerequisite']) ?></dd></div>
                            <div><dt>人数上限</dt><dd><?= $course['capacity'] ?> 人</dd></div>
                        </dl>
                        <h3>课程简介</h3>
                        <p class="detail-description"><?= e($course['description']) ?></p>
                    </article>
                    <aside class="detail-side">
                        <h2>教学班名额</h2>
                        <p class="remaining-number"><?= $remaining ?><span> / <?= $course['capacity'] ?> 席剩余</span></p>
                        <progress value="<?= $selected ?>" max="<?= $course['capacity'] ?>"></progress>
                        <p class="side-note">已选 <?= $selected ?> 人。<?= databaseModeEnabled() ? '人数来自数据库，提交选课时会重新校验。' : '当前人数仅用于本地演示。' ?></p>
                        <?php if ($user['role'] === 'student'): ?>
                            <a class="primary-button" href="selection.php?offering=<?= rawurlencode($id) ?>">去选课 <span>→</span></a>
                        <?php else: ?>
                            <a class="primary-button" href="courses.php">返回课程列表 <span>→</span></a>
                        <?php endif; ?>
                    </aside>
                </div>
            </section>
        <?php endif; ?>
    </main>
<?php require __DIR__ . '/partials/footer.php'; ?>
