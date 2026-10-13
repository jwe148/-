<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('teacher');
require __DIR__ . '/data/catalog_runtime.php';
require __DIR__ . '/registration_extras.php';
require __DIR__ . '/csv.php';

$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$query = function_exists('mb_substr') ? mb_substr($query, 0, 100, 'UTF-8') : substr($query, 0, 100);
$rows = databaseModeEnabled() ? dbTeacherRoster(databaseConnection(), $user['id'], $selectionPeriod['semester_id'], $id) : null;
if ($rows === null || !isset($offerings[$id])) {
    http_response_code(403);
    exit('只能查看本人教学班名单，且需要数据库模式。');
}
if ($query !== '') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($query): bool {
        $usernameMatches = stripos($row['username'], $query) !== false;
        $nameMatches = function_exists('mb_stripos')
            ? mb_stripos($row['display_name'], $query, 0, 'UTF-8') !== false
            : stripos($row['display_name'], $query) !== false;
        return $usernameMatches || $nameMatches;
    }));
}
if (($_GET['action'] ?? '') === 'export') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="roster-' . rawurlencode($id) . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    writeCsvRow($output, ['账号', '姓名']);
    foreach ($rows as $row) {
        writeCsvRow($output, [$row['username'], $row['display_name']]);
    }
    fclose($output);
    exit;
}

$pageTitle = '班级名单';
$active = 'teaching.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page"><div class="container narrow-content">
    <div class="page-heading"><h1><?= e($offerings[$id]['name'] . ' · ' . $offerings[$id]['class']) ?></h1><p><?= $query === '' ? '在选学生 ' : '找到 ' ?><?= count($rows) ?> 人</p></div>
    <form class="filter-panel" method="get" action="roster.php">
        <input type="hidden" name="id" value="<?= e($id) ?>">
        <label class="filter-search">搜索账号或姓名<input type="search" name="q" value="<?= e($query) ?>" maxlength="100" placeholder="输入学生账号或姓名"></label>
        <button class="primary-button" type="submit">搜索</button>
        <?php if ($query !== ''): ?><a class="clear-filter" href="roster.php?id=<?= rawurlencode($id) ?>">清除</a><?php endif; ?>
        <a class="clear-filter" href="roster.php?id=<?= rawurlencode($id) ?>&amp;q=<?= rawurlencode($query) ?>&amp;action=export">导出CSV</a>
    </form>
    <section class="simple-panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>学号 / 账号</th><th>姓名</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr><td><?= e($row['username']) ?></td><td><?= e($row['display_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php if ($rows === []): ?><p>没有匹配的学生。</p><?php endif; ?></section>
    <a href="teaching.php">返回我的授课</a>
</div></main>
<?php require __DIR__ . '/partials/footer.php'; ?>
