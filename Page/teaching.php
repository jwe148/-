<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('teacher');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/student_data.php';
require __DIR__ . '/teacher_data.php';

$open = selectionOpen($selectionPeriod);
$claims = teacherClaims();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : '';
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    if (!validTeacherToken($_POST['token'] ?? null)) {
        $errors[] = '页面已过期，请刷新后重试。';
    } elseif ($action === 'claim') {
        $errors = validateTeacherClaim($id, $claims, $offerings, $open);
        if ($errors === []) {
            $claims[] = $id;
        }
    } elseif ($action === 'release') {
        if (!$open) {
            $errors[] = '选课已结束，不能调整授课教学班。';
        } elseif (!in_array($id, $claims, true)) {
            $errors[] = '只能取消自己认领的教学班。';
        } else {
            $claims = array_values(array_diff($claims, [$id]));
        }
    } else {
        $errors[] = '无效的操作。';
    }
    if ($errors === []) {
        $_SESSION['teacher_claims'] = $claims;
        header('Location: teaching.php?' . ($action === 'claim' ? 'claimed=1' : 'released=1'));
        exit;
    }
}

$available = array_filter($offerings, static fn(array $course, string $id): bool => $course['teacher'] === '待认领' && !in_array($id, $claims, true), ARRAY_FILTER_USE_BOTH);
$mine = array_intersect_key($offerings, array_fill_keys($claims, true));
$pageTitle = '我的授课';
$active = 'teaching.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading">
            <p><?= e($semester) ?></p>
            <h1>我的授课</h1>
            <p>认领尚未安排教师的教学班。系统会检查与已认领教学班的上课时间是否冲突。</p>
        </div>
        <div class="info-line"><strong><?= $open ? '认领开放中' : '认领已结束' ?></strong><span>截止时间：<?= e(selectionTimeLabel($selectionPeriod['end'])) ?></span></div>
        <?php if (isset($_GET['claimed'])): ?><p class="message success" role="status">已认领教学班。</p><?php endif; ?>
        <?php if (isset($_GET['released'])): ?><p class="message success" role="status">已取消认领。</p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>

        <section class="simple-panel">
            <h2>我已认领的教学班</h2>
            <?php if ($mine === []): ?><p>暂无教学班。</p><?php else: ?>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>教学班</th><th>上课时间</th><th>操作</th></tr></thead><tbody>
                    <?php foreach ($mine as $id => $course): ?><tr>
                        <td><?= e($course['name'] . ' · ' . $course['class']) ?></td>
                        <td><?= e($course['day'] . ' ' . $course['time']) ?></td>
                        <td><?php if ($open): ?><form method="post" action="teaching.php"><input type="hidden" name="token" value="<?= e(teacherToken()) ?>"><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?= e($id) ?>"><button class="text-button" type="submit">取消认领</button></form><?php else: ?>已结束<?php endif; ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

        <section class="simple-panel">
            <h2>待认领教学班</h2>
            <?php if ($available === []): ?><p>暂无待认领教学班。</p><?php else: ?>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>教学班</th><th>上课时间</th><th>操作</th></tr></thead><tbody>
                    <?php foreach ($available as $id => $course): ?><tr>
                        <td><a href="course.php?id=<?= rawurlencode($id) ?>"><?= e($course['name'] . ' · ' . $course['class']) ?></a></td>
                        <td><?= e($course['day'] . ' ' . $course['time']) ?></td>
                        <td><?php if ($open): ?><form method="post" action="teaching.php"><input type="hidden" name="token" value="<?= e(teacherToken()) ?>"><input type="hidden" name="action" value="claim"><input type="hidden" name="id" value="<?= e($id) ?>"><button class="primary-button" type="submit">认领</button></form><?php else: ?>已结束<?php endif; ?></td>
                    </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>
        <p class="page-note">当前认领结果仅保存在本次教师登录会话中。正式版需接入 MySQL，供学生和教务员共同查看。</p>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
