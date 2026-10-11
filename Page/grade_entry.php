<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('teacher');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/teacher_data.php';
require __DIR__ . '/teacher_grades_db.php';

$errors = [];
$gradeOfferings = [];
$selected = null;
$offeringId = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['offering_id'] ?? '') : ($_GET['offering'] ?? '');
$offeringId = is_string($offeringId) ? $offeringId : '';

if (!databaseModeEnabled()) {
    $errors[] = '成绩录入需要先配置数据库。';
} else {
    try {
        $pdo = databaseConnection();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT);
            $grade = $_POST['grade'] ?? null;
            if (!validTeacherToken($_POST['token'] ?? null)) {
                $errors[] = '页面已过期，请刷新后重试。';
            } elseif (!is_int($enrollmentId) || $enrollmentId < 1 || !is_string($grade) || !in_array($grade, ['clear', 'A', 'B', 'C', 'D', 'F', 'I'], true)) {
                $errors[] = '请选择有效的学生和成绩等级。';
            } else {
                $errors = dbSaveTeacherGrade($pdo, $user['id'], $offeringId, $enrollmentId, $grade === 'clear' ? '' : $grade);
                if ($errors === []) {
                    header('Location: grade_entry.php?offering=' . rawurlencode($offeringId) . '&saved=1');
                    exit;
                }
            }
        }
        $gradeOfferings = dbTeacherGradeOfferings($pdo, $user['id']);
        if ($offeringId !== '') {
            $selected = dbTeacherGradeRoster($pdo, $user['id'], $offeringId);
            if ($selected === null) {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(404);
                }
                if ($errors === []) {
                    $errors[] = '未找到本人可录入成绩的教学班。';
                }
            }
        }
    } catch (Throwable $error) {
        error_log('Course registration grade entry failed: ' . $error->getMessage());
        http_response_code(503);
        $errors[] = '成绩数据暂不可用，请稍后重试。';
    }
}

$pageTitle = '成绩录入';
$active = 'grade_entry.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page">
    <div class="container narrow-content">
        <div class="page-heading"><h1>成绩录入</h1></div>
        <?php if (isset($_GET['saved'])): ?><p class="message success" role="status">成绩已保存。</p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
        <?php if (databaseModeEnabled()): ?>
            <details class="simple-panel disclosure-panel" <?= $selected === null ? 'open' : '' ?>><summary>选择教学班</summary><div class="disclosure-content">
                <?php if ($gradeOfferings === []): ?><p>暂无已结课的教学班。</p><?php else: ?>
                    <div class="table-scroll"><table class="data-table"><thead><tr><th>学期</th><th>教学班</th><th>操作</th></tr></thead><tbody>
                        <?php foreach ($gradeOfferings as $offering): ?><tr>
                            <td><?= e($offering['semester_name']) ?></td>
                            <td><?= e($offering['course_name'] . ' · ' . $offering['class_name']) ?></td>
                            <td><a href="grade_entry.php?offering=<?= rawurlencode($offering['id']) ?>">查看名单</a></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </div></details>
            <?php if ($selected !== null): ?>
                <section class="simple-panel">
                    <h2><?= e($selected['course_name'] . ' · ' . $selected['class_name']) ?></h2>
                    <p><?= e($selected['semester_name']) ?></p>
                    <?php if ($selected['students'] === []): ?><p>暂无有效选课学生。</p><?php else: ?>
                        <div class="table-scroll"><table class="data-table"><thead><tr><th>学生</th><th>当前成绩</th><th>录入或修改</th></tr></thead><tbody>
                            <?php foreach ($selected['students'] as $student): ?><tr>
                                <td><?= e($student['display_name'] . ' · ' . $student['username']) ?></td>
                                <td><?= e($student['grade_value'] ?? '未录入') ?></td>
                                <td><form class="grade-form" method="post" action="grade_entry.php">
                                    <input type="hidden" name="token" value="<?= e(teacherToken()) ?>">
                                    <input type="hidden" name="offering_id" value="<?= e($selected['id']) ?>">
                                    <input type="hidden" name="enrollment_id" value="<?= (int) $student['enrollment_id'] ?>">
                                    <select name="grade" aria-label="<?= e($student['username']) ?> 的成绩" required>
                                        <option value="" selected disabled>选择等级</option>
                                        <?php foreach (['A', 'B', 'C', 'D', 'F', 'I'] as $option): ?><option value="<?= $option ?>"><?= $option ?></option><?php endforeach; ?>
                                        <option value="clear">撤销成绩</option>
                                    </select>
                                    <button class="primary-button" type="submit">保存</button>
                                </form></td>
                            </tr><?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <details class="page-help"><summary>成绩说明</summary><p>使用 A、B、C、D、F、I，支持更正和撤销。旧中文等级保留原值；撤销后学生不再看到该成绩。</p></details>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
