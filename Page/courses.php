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

$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$day = isset($_GET['day']) && is_string($_GET['day']) ? $_GET['day'] : '';
$days = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];
if (!in_array($day, $days, true)) {
    $day = '';
}
$filtered = array_filter($offerings, static function (array $course) use ($query, $day): bool {
    $matchesQuery = $query === ''
        || stripos($course['name'], $query) !== false
        || stripos($course['teacher'], $query) !== false
        || stripos($course['code'], $query) !== false;
    return $matchesQuery && ($day === '' || offeringHasDay($course, $day));
});
$pageTitle = '课程查询';
$active = 'courses.php';
require __DIR__ . '/partials/header.php';
?>
    <main>
        <section class="page-banner">
            <div class="container">
                <h1>课程查询</h1>
                <p><?= e($semester) ?></p>
            </div>
        </section>
        <section class="catalog-section">
            <div class="container">
                <form class="filter-panel" method="get" action="courses.php" role="search">
                    <label class="filter-search">课程或教师
                        <input type="search" name="q" value="<?= e($query) ?>" placeholder="例如：软件工程、王老师、SE301">
                    </label>
                    <label>上课星期
                        <select name="day">
                            <option value="">全部星期</option>
                            <?php foreach ($days as $option): ?>
                                <option value="<?= e($option) ?>" <?= $day === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="primary-button" type="submit">筛选</button>
                    <?php if ($query !== '' || $day !== ''): ?><a class="clear-filter" href="courses.php">清除</a><?php endif; ?>
                </form>

                <div class="catalog-meta">
                    <h2>教学班列表 <small>共 <?= count($filtered) ?> 个</small></h2>
                </div>

                <?php if ($filtered === []): ?>
                    <div class="empty-state"><h3>没有找到教学班</h3><p>请调整关键词或上课星期。</p><a href="courses.php">查看全部教学班</a></div>
                <?php else: ?>
                    <div class="course-grid">
                        <?php foreach ($filtered as $id => $course): ?>
                            <?php $remaining = seatsRemaining($id, $offerings); $selected = $course['capacity'] - $remaining; ?>
                            <article class="course-card">
                                <div class="course-top">
                                    <span><?= e($course['code']) ?> · <?= e($course['class']) ?></span>
                                    <span><?= ($course['status'] ?? 'open') === 'cancelled' ? '已停开' : (($course['status'] ?? 'open') === 'confirmed' ? '已开设 · ' : '') . ($remaining > 0 ? '剩余 ' . $remaining . ' 席' : '已满') ?></span>
                                </div>
                                <div class="course-body">
                                    <h3><?= e($course['name']) ?></h3>
                                    <p>教师：<?= e($course['teacher']) ?></p>
                                    <p>时间：<?= e(offeringTimeLabel($course)) ?></p>
                                    <p>地点：<?= e($course['location']) ?></p>
                                    <div class="capacity" aria-label="已选 <?= $selected ?> 人，容量 <?= $course['capacity'] ?> 人">
                                        <div><span>已选人数</span><b><?= $selected ?>/<?= $course['capacity'] ?></b></div>
                                        <progress value="<?= $selected ?>" max="<?= $course['capacity'] ?>"></progress>
                                    </div>
                                    <a class="course-detail-link" href="course.php?id=<?= rawurlencode($id) ?>">查看详情</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
<?php require __DIR__ . '/partials/footer.php'; ?>
