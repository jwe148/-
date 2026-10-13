<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('teacher');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/teacher_data.php';
require __DIR__ . '/teacher_grades_db.php';
require __DIR__ . '/csv.php';

$errors = [];
$gradeOfferings = [];
$unavailableOfferings = [];
$selected = null;
$statistics = null;
$gradeLogs = [];
$offeringId = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['offering_id'] ?? '') : ($_GET['offering'] ?? '');
$offeringId = is_string($offeringId) ? $offeringId : '';

if (!databaseModeEnabled()) {
    $errors[] = '成绩录入需要先配置数据库。';
} else {
    try {
        $pdo = databaseConnection();
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export' && $offeringId !== '') {
            $export = dbTeacherGradeRoster($pdo, $user['id'], $offeringId);
            if ($export === null) {
                http_response_code(404);
                exit('未找到本人可导出的教学班。');
            }
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="grades-' . rawurlencode($offeringId) . '.csv"');
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            writeCsvRow($output, ['账号', '姓名', '成绩']);
            foreach ($export['students'] as $student) {
                writeCsvRow($output, [$student['username'], $student['display_name'], $student['grade_value'] ?? '']);
            }
            fclose($output);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';
            $expectedSnapshot = $_POST['grade_snapshot'] ?? null;
            if (!validTeacherToken($_POST['token'] ?? null)) {
                $errors[] = '页面已过期，请刷新后重试。';
            } elseif (!is_string($expectedSnapshot) || !preg_match('/\A[a-f0-9]{64}\z/', $expectedSnapshot)) {
                $errors[] = '成绩页面版本无效，请刷新页面后重试。';
            } elseif (!in_array($action, ['save', 'import', 'confirm'], true)) {
                $errors[] = '无效的成绩操作。';
            } elseif ($action === 'import') {
                $file = $_FILES['grade_file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)) {
                    $errors[] = '请选择可读取的CSV文件。';
                } else {
                    $parsed = parseTeacherGradeCsv($file['tmp_name']);
                    $errors = $parsed['errors'];
                    if ($errors === []) {
                        $result = dbImportTeacherGrades($pdo, $user['id'], $offeringId, $parsed['rows'], $expectedSnapshot);
                        $errors = $result['errors'];
                        if ($errors === []) {
                            header('Location: grade_entry.php?offering=' . rawurlencode($offeringId) . '&imported=' . $result['updated']);
                            exit;
                        }
                    }
                }
            } elseif ($action === 'confirm') {
                $errors = dbConfirmTeacherGrades($pdo, $user['id'], $offeringId, $expectedSnapshot);
                if ($errors === []) {
                    header('Location: grade_entry.php?offering=' . rawurlencode($offeringId) . '&confirmed=1');
                    exit;
                }
            } else {
                $enrollmentId = filter_var($_POST['enrollment_id'] ?? null, FILTER_VALIDATE_INT);
                $grade = $_POST['grade'] ?? null;
                if (!is_int($enrollmentId) || $enrollmentId < 1 || !is_string($grade) || !in_array($grade, ['clear', 'A', 'B', 'C', 'D', 'F', 'I'], true)) {
                    $errors[] = '请选择有效的学生和成绩等级。';
                } else {
                    $errors = dbSaveTeacherGrade($pdo, $user['id'], $offeringId, $enrollmentId, $grade === 'clear' ? '' : $grade, $expectedSnapshot);
                    if ($errors === []) {
                        header('Location: grade_entry.php?offering=' . rawurlencode($offeringId) . '&saved=1');
                        exit;
                    }
                }
            }
        }

        $gradeOfferings = dbTeacherGradeOfferings($pdo, $user['id']);
        $unavailableOfferings = dbTeacherUnavailableGradeOfferings($pdo, $user['id']);
        if ($offeringId !== '') {
            $selected = dbTeacherGradeRoster($pdo, $user['id'], $offeringId);
            if ($selected === null) {
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    http_response_code(404);
                }
                if ($errors === []) {
                    $errors[] = '未找到本人可录入成绩的教学班。';
                }
            } else {
                $statistics = teacherGradeStatistics($selected['students']);
                $gradeLogs = dbTeacherGradeLogs($pdo, $user['id'], $offeringId);
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
        <div class="page-heading"><h1>成绩录入</h1><p>仅选课已关闭且教学已结束的本人教学班可以录入成绩。可暂时跳过学生，之后补录或更正。</p></div>
        <?php if (isset($_GET['saved'])): ?><p class="message success" role="status">成绩已保存。<?= $selected !== null && $selected['submitted_at'] !== null ? '当前仍为已确认提交。' : '当前尚未确认提交。' ?></p><?php endif; ?>
        <?php if (isset($_GET['imported'])): ?><p class="message success" role="status">CSV导入完成，共更新 <?= (int) $_GET['imported'] ?> 条成绩。</p><?php endif; ?>
        <?php if (isset($_GET['confirmed'])): ?><p class="message success" role="status">成绩已确认提交。</p><?php endif; ?>
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
            <?php if ($unavailableOfferings !== []): ?>
                <details class="simple-panel disclosure-panel"><summary>尚不可录入的本人教学班（<?= count($unavailableOfferings) ?>）</summary><div class="disclosure-content">
                    <p>这些课程属于你的任教范围，但尚未满足成绩录入条件。</p>
                    <div class="table-scroll"><table class="data-table"><thead><tr><th>学期</th><th>教学班</th><th>原因</th></tr></thead><tbody>
                        <?php foreach ($unavailableOfferings as $offering): ?><tr>
                            <td><?= e($offering['semester_name']) ?></td>
                            <td><?= e($offering['course_name'] . ' · ' . $offering['class_name']) ?></td>
                            <td><?= e(teacherGradeUnavailableReason($offering)) ?></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div>
                </div></details>
            <?php endif; ?>
            <?php if ($selected !== null): ?>
                <section class="simple-panel">
                    <h2><?= e($selected['course_name'] . ' · ' . $selected['class_name']) ?></h2>
                    <p><?= e($selected['semester_name']) ?></p>
                    <div class="grade-toolbar">
                        <a class="primary-button" href="grade_entry.php?offering=<?= rawurlencode($selected['id']) ?>&amp;action=export">导出CSV</a>
                        <form method="post" action="grade_entry.php" enctype="multipart/form-data" class="inline-upload">
                            <input type="hidden" name="token" value="<?= e(teacherToken()) ?>">
                            <input type="hidden" name="action" value="import">
                            <input type="hidden" name="grade_snapshot" value="<?= e(teacherGradeSnapshot($selected['students'])) ?>">
                            <input type="hidden" name="offering_id" value="<?= e($selected['id']) ?>">
                            <label>导入CSV <input type="file" name="grade_file" accept=".csv,text/csv" required></label>
                            <button class="primary-button" type="submit">批量导入</button>
                        </form>
                    </div>
                    <?php if ($statistics !== null): ?>
                        <div class="stat-grid" aria-label="班级成绩统计">
                            <div><strong><?= $statistics['total'] ?></strong><span>学生总数</span></div>
                            <div><strong><?= $statistics['missing'] ?></strong><span>未录入</span></div>
                            <div><strong><?= $statistics['average'] === null ? '—' : e((string) $statistics['average']) ?></strong><span>折算平均分</span></div>
                            <div><strong><?= $statistics['pass_rate'] === null ? '—' : e((string) $statistics['pass_rate']) . '%' ?></strong><span>通过率</span></div>
                        </div>
                        <p class="grade-distribution">等级分布：<?php foreach ($statistics['distribution'] as $grade => $count): ?><span><?= $grade ?>：<?= $count ?></span><?php endforeach; ?></p>
                    <?php endif; ?>
                    <?php if ($selected['students'] === []): ?><p>暂无有效选课学生。</p><?php else: ?>
                        <div class="table-scroll"><table class="data-table"><thead><tr><th>学生</th><th>当前成绩</th><th>录入或修改</th></tr></thead><tbody>
                            <?php foreach ($selected['students'] as $student): ?><tr>
                                <td><?= e($student['display_name'] . ' · ' . $student['username']) ?></td>
                                <td><?= e($student['grade_value'] ?? '未录入') ?></td>
                                <td><form class="grade-form" method="post" action="grade_entry.php">
                                    <input type="hidden" name="token" value="<?= e(teacherToken()) ?>">
                                    <input type="hidden" name="action" value="save">
                                    <input type="hidden" name="grade_snapshot" value="<?= e(teacherGradeSnapshot([$student])) ?>">
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
                    <div class="submission-box">
                        <?php if ($selected['submitted_at'] !== null): ?>
                            <p><strong>已确认提交</strong>：<?= e($selected['submitted_at']) ?>。成绩发生实际变化时会自动撤销确认，重复保存或原样导入保留确认。</p>
                        <?php else: ?>
                            <p>当前尚未确认提交。全部学生录入成绩后可确认；确认后仍可纠错，但需要重新确认。</p>
                        <?php endif; ?>
                        <form method="post" action="grade_entry.php">
                            <input type="hidden" name="token" value="<?= e(teacherToken()) ?>">
                            <input type="hidden" name="action" value="confirm">
                            <input type="hidden" name="grade_snapshot" value="<?= e(teacherGradeSnapshot($selected['students'])) ?>">
                            <input type="hidden" name="offering_id" value="<?= e($selected['id']) ?>">
                            <button class="primary-button" type="submit" <?= ($statistics['missing'] ?? 1) > 0 || ($statistics['total'] ?? 0) === 0 ? 'disabled' : '' ?>>确认提交成绩</button>
                        </form>
                    </div>
                </section>
                <details class="simple-panel disclosure-panel"><summary>成绩修改记录（<?= count($gradeLogs) ?>）</summary><div class="disclosure-content">
                    <?php if ($gradeLogs === []): ?><p>暂无修改记录。</p><?php else: ?>
                        <div class="table-scroll"><table class="data-table"><thead><tr><th>时间</th><th>学生</th><th>变化</th><th>方式</th></tr></thead><tbody>
                            <?php foreach ($gradeLogs as $log): ?><tr>
                                <td><?= e($log['changed_at']) ?></td>
                                <td><?= e($log['display_name'] . ' · ' . $log['username']) ?></td>
                                <td><?= e($log['old_grade'] ?? '未录入') ?> → <?= e($log['new_grade'] ?? '未录入') ?></td>
                                <td><?= $log['change_source'] === 'csv' ? 'CSV导入' : '页面修改' ?></td>
                            </tr><?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </div></details>
            <?php endif; ?>
            <details class="page-help"><summary>成绩与CSV说明</summary><p>使用 A、B、C、D、F、I，支持更正和撤销。CSV表头应包含唯一的“账号”和“成绩”列，每行列数须与表头一致；保留成绩单元格并留空表示清空，缺列会拒绝整批导入。历史中文等级可原样导入保留，修改时请使用字母等级。折算平均分按 A=95、B=85、C=75、D=65、F=50 计算，I和未录入不计入平均分。</p></details>
        <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
