<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('teacher');
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/student_data.php';
require __DIR__ . '/teacher_data.php';

$claims = teacherClaims();
$mine = array_intersect_key($offerings, array_fill_keys($claims, true));
$days = ['周一', '周二', '周三', '周四', '周五', '周六', '周日'];
$schedule = array_fill_keys($days, []);

foreach ($mine as $id => $course) {
    foreach (offeringTimes($course) as $time) {
        if (!isset($schedule[$time['day']])) {
            continue;
        }
        [$startsAt] = explode('—', $time['time']);
        $schedule[$time['day']][] = [
            'id' => $id,
            'starts_at' => $startsAt,
            'time' => $time['time'],
            'course' => $course,
        ];
    }
}
foreach ($schedule as &$items) {
    usort($items, static fn(array $left, array $right): int => [$left['starts_at'], $left['id']] <=> [$right['starts_at'], $right['id']]);
}
unset($items);

$pageTitle = '我的课表';
$active = 'teacher_schedule.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container">
        <div class="page-heading">
            <p><?= e($semester) ?></p>
            <h1>教师课表</h1>
            <p>共 <?= count($mine) ?> 个教学班</p>
        </div>
        <?php if ($mine === []): ?>
            <div class="empty-state"><h2>本学期暂无授课安排</h2><a href="teaching.php">查看我的授课</a></div>
        <?php else: ?>
            <div class="timetable">
                <?php foreach ($schedule as $day => $items): ?>
                    <section class="day-column">
                        <h2><?= e($day) ?></h2>
                        <?php if ($items === []): ?><span class="no-class">无课程</span><?php else: ?>
                            <?php foreach ($items as $item): $course = $item['course']; ?>
                                <article class="class-item">
                                    <time><?= e($item['time']) ?></time>
                                    <h3><?= e($course['name']) ?></h3>
                                    <p><?= e($course['class'] . ' · ' . $course['location']) ?></p>
                                    <?php if (databaseModeEnabled()): ?><a href="roster.php?id=<?= rawurlencode($item['id']) ?>">查看学生名单</a><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
