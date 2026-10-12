<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
$user = requireRole('admin');
require __DIR__ . '/data/catalog.php';
require __DIR__ . '/admin_people_db.php';

function personNumber($value, bool $allowZero = false): ?int
{
    if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/', $value)) {
        return null;
    }
    $number = (int) $value;
    return $number > 0 || ($allowZero && $number === 0) ? $number : null;
}

function personRedirect(string $url, string $message): void
{
    unset($_SESSION['person_delete']);
    $_SESSION['person_flash'] = $message;
    header('Location: ' . $url);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('不支持此请求方式。');
}
$role = $_GET['role'] ?? 'student';
if (!is_string($role) || !personRoleValid($role)) {
    http_response_code(400);
    exit('无效的人员类型。');
}
$roleLabel = $role === 'student' ? '学生' : '教师';
$base = 'admin_people.php?role=' . $role;
$viewParam = $_GET['view'] ?? 'list';
$view = is_string($viewParam) && in_array($viewParam, ['list', 'new', 'edit', 'confirm'], true) ? $viewParam : 'list';
$id = personNumber($_GET['id'] ?? null);
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
if (strlen($search) > 400 || !preg_match('//u', $search)) {
    http_response_code(400);
    exit('查询条件无效或过长。');
}
$page = personNumber($_GET['page'] ?? '1') ?? 1;
if (!isset($_SESSION['person_token'])) {
    $_SESSION['person_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['person_token'];
$values = array_fill_keys(PERSON_FIELDS, '');
$values['is_active'] = '1';
$revision = 0;
$record = null;
$listing = ['rows'=>[], 'total'=>0, 'page'=>1, 'pages'=>1];
$errors = [];
$flash = $_SESSION['person_flash'] ?? '';
unset($_SESSION['person_flash']);
$available = databaseModeEnabled();
$pending = $_SESSION['person_delete'] ?? null;
if (!is_array($pending) || ($pending['created_at'] ?? 0) < time() - 600) {
    unset($_SESSION['person_delete']);
    $pending = null;
}
if (!$available) {
    http_response_code(503);
    $errors[] = '人员资料维护需要先配置数据库。';
} else {
    try {
        $pdo = databaseConnection();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 所有写入从列表开始；仅保存失败或准备删除时显示对应表单。
            $view = 'list';
            $submittedToken = $_POST['token'] ?? null;
            $action = $_POST['action'] ?? null;
            if (!is_string($submittedToken) || !hash_equals($token, $submittedToken)) {
                $errors[] = '页面已过期，请刷新后重试。';
            } elseif (!is_string($action)) {
                $errors[] = '无效的操作。';
            } else {
                switch ($action) {
                    case 'create':
                    case 'update':
                        $view = $action === 'create' ? 'new' : 'edit';
                        $id = $action === 'create' ? null : personNumber($_POST['id'] ?? null);
                        $postedRevision = personNumber($_POST['revision'] ?? '0', true);
                        $revision = $postedRevision ?? 0;
                        $input = [];
                        foreach (PERSON_FIELDS as $field) {
                            $input[$field] = $_POST[$field] ?? '';
                            $values[$field] = is_string($input[$field]) ? $input[$field] : '';
                        }
                        $values['password'] = ''; // 保存失败也不回显密码。
                        if (($action === 'update' && $id === null) || $postedRevision === null) {
                            $errors[] = '人员编号或版本无效。';
                            break;
                        }
                        $result = dbSavePerson($pdo, $user['id'], $role, $id, $input, $revision);
                        $errors = $result['errors'];
                        if ($errors === []) {
                            personRedirect($base . '&view=edit&id=' . $result['id'], ($action === 'create' ? '已新增' : '已保存') . $roleLabel . '，系统编号：' . $result['id'] . '。');
                        }
                        break;
                    case 'prepare_delete':
                        $id = personNumber($_POST['id'] ?? null);
                        $view = 'confirm';
                        break;
                    case 'confirm_delete':
                        $id = personNumber($_POST['id'] ?? null);
                        $nonce = $_POST['confirmation'] ?? null;
                        if ($pending === null || $pending['id'] !== $id || $pending['role'] !== $role || !is_string($nonce) || !hash_equals($pending['nonce'], $nonce)) {
                            $errors[] = '删除确认已失效，请重新选择人员。';
                            break;
                        }
                        unset($_SESSION['person_delete']);
                        $errors = dbDeletePerson($pdo, $user['id'], $role, $id, $pending['revision']);
                        if ($errors === []) {
                            personRedirect($base, '已删除' . $roleLabel . '，系统编号：' . $id . '。');
                        }
                        break;
                    case 'cancel_delete':
                        personRedirect($base, '删除已取消，资料未修改。');
                        break;
                    default:
                        $errors[] = '无效的操作。';
                }
            }
        }
        // 编辑页和新删除确认共用一次查找；保存失败保留用户填写的值。
        $preparingDelete = $view === 'confirm' && $_SERVER['REQUEST_METHOD'] === 'POST';
        if (($view === 'edit' && $_SERVER['REQUEST_METHOD'] === 'GET') || $preparingDelete) {
            $record = $id === null ? null : dbPersonRecord($pdo, $id, $role);
            if ($record === null) {
                http_response_code(404);
                $errors[] = '该人员不存在，请重新查找。';
                $view = 'list';
            } elseif ($preparingDelete) {
                $pending = ['id'=>$id, 'role'=>$role, 'revision'=>(int)$record['revision'], 'nonce'=>bin2hex(random_bytes(16)), 'created_at'=>time()];
                $_SESSION['person_delete'] = $pending;
            } else {
                foreach (PERSON_FIELDS as $field) {
                    $values[$field] = (string) ($record[$field] ?? '');
                    if ($field === 'password') {
                        $values[$field] = '';
                    }
                }
                $revision = (int) $record['revision'];
            }
        }
        if ($view === 'confirm' && $record === null) {
            if ($pending === null || $pending['role'] !== $role) {
                $errors[] = '删除确认已失效，请重新选择人员。';
                $view = 'list';
            } else {
                $record = dbPersonRecord($pdo, $pending['id'], $role);
                if ($record === null || (int)$record['revision'] !== $pending['revision']) {
                    unset($_SESSION['person_delete']);
                    $errors[] = '资料已变化，请重新确认删除。';
                    $view = 'list';
                }
            }
        }
        if ($view === 'list') {
            $listing = dbPersonList($pdo, $role, $search, $page);
        }
    } catch (Throwable $error) {
        error_log('Course registration person maintenance failed: ' . $error->getMessage());
        http_response_code(503);
        $errors[] = '人员资料暂不可用，操作未完成，请稍后重试。';
        $available = false;
    }
}
$pageTitle = '人员资料';
$active = 'admin_people.php';
require __DIR__ . '/partials/header.php';
?>
<main class="content-page"><div class="container narrow-content">
    <div class="page-heading"><h1>人员资料</h1></div>
    <div class="form-actions"><a href="admin_people.php?role=student" <?= $role === 'student' ? 'aria-current="page"' : '' ?>>学生资料</a><a href="admin_people.php?role=teacher" <?= $role === 'teacher' ? 'aria-current="page"' : '' ?>>教师资料</a></div>
    <?php if (is_string($flash) && $flash !== ''): ?><p class="message success" role="status"><?= e($flash) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="message error" role="alert"><?= e($error) ?></p><?php endforeach; ?>
    <?php if ($available && $view === 'list'): ?>
        <section class="simple-panel"><h2><?= e($roleLabel) ?>列表</h2>
            <form class="filter-panel" method="get" action="admin_people.php">
                <input type="hidden" name="role" value="<?= e($role) ?>">
                <label class="filter-search">系统编号、账号或姓名<input type="search" name="q" value="<?= e($search) ?>" maxlength="100"></label>
                <button class="primary-button" type="submit">查找</button><a href="<?= e($base . '&view=new') ?>">新增<?= e($roleLabel) ?></a>
            </form>
            <p>共 <?= $listing['total'] ?> 人，第 <?= $listing['page'] ?>/<?= $listing['pages'] ?> 页。</p>
            <?php if ($listing['rows'] === []): ?><p>没有找到人员，请调整查询条件或新增资料。</p><?php else: ?>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>系统编号</th><th>账号</th><th>姓名</th><th>密码 / 状态</th><th>人员状态</th><th>启用状态</th><th>操作</th></tr></thead><tbody>
                <?php foreach ($listing['rows'] as $row): ?><tr>
                    <td><?= (int)$row['id'] ?></td><td><?= e($row['username']) ?></td><td><?= e($row['display_name']) ?></td>
                    <td><?= e(passwordForDisplay($row['password'])) ?></td>
                    <td><?= e($row['person_status'] ?? '资料待补全') ?></td><td><?= (int)$row['is_active'] === 1 ? '启用' : '停用' ?></td>
                    <td><a href="<?= e($base . '&view=edit&id=' . $row['id']) ?>">编辑</a>
                        <form method="post" action="<?= e($base) ?>" class="people-inline"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="prepare_delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="logout-button" type="submit">删除</button></form></td>
                </tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
            <div class="form-actions">
                <?php if ($listing['page'] > 1): ?><a href="<?= e($base . '&q=' . rawurlencode($search) . '&page=' . ($listing['page'] - 1)) ?>">上一页</a><?php endif; ?>
                <?php if ($listing['page'] < $listing['pages']): ?><a href="<?= e($base . '&q=' . rawurlencode($search) . '&page=' . ($listing['page'] + 1)) ?>">下一页</a><?php endif; ?>
            </div>
        </section>
    <?php elseif ($available && in_array($view, ['new','edit'], true)): ?>
        <section class="simple-panel"><h2><?= $view === 'new' ? '新增' : '编辑' ?><?= e($roleLabel) ?><?= $id === null ? '' : ' · 系统编号 ' . $id ?></h2>
            <form method="post" action="<?= e($base) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="<?= $view === 'new' ? 'create' : 'update' ?>">
                <input type="hidden" name="id" value="<?= $id ?? '' ?>"><input type="hidden" name="revision" value="<?= $revision ?>">
                <div class="people-fields">
                    <label>账号<input name="username" value="<?= e($values['username']) ?>" minlength="3" maxlength="64" required <?= $view === 'edit' ? 'readonly' : '' ?> autocomplete="off"></label>
                    <label>姓名<input name="display_name" value="<?= e($values['display_name']) ?>" maxlength="100" required></label>
                    <label>出生日期<input type="date" name="date_of_birth" value="<?= e($values['date_of_birth']) ?>" max="<?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))->format('Y-m-d') ?>" required></label>
                    <label>证件号<input name="social_security_number" value="<?= e($values['social_security_number']) ?>" maxlength="32" required autocomplete="off"></label>
                    <label>人员状态<input name="person_status" value="<?= e($values['person_status']) ?>" placeholder="<?= $role === 'student' ? '例如：在读、休学、毕业' : '例如：在职、休假、离职' ?>" maxlength="32" required></label>
                    <?php if ($role === 'teacher'): ?><label>院系<input name="department" value="<?= e($values['department']) ?>" maxlength="100" required></label><input type="hidden" name="graduation_date" value="">
                    <?php else: ?><label>毕业日期<input type="date" name="graduation_date" value="<?= e($values['graduation_date']) ?>" required></label><input type="hidden" name="department" value=""><?php endif; ?>
                    <label>账号状态<select name="is_active"><option value="1" <?= $values['is_active'] === '1' ? 'selected' : '' ?>>启用</option><option value="0" <?= $values['is_active'] === '0' ? 'selected' : '' ?>>停用</option></select></label>
                    <label><?= $view === 'new' ? '初始密码' : '新密码（留空保持原密码）' ?><input type="password" name="password" value="<?= e($values['password']) ?>" minlength="8" maxlength="72" autocomplete="new-password" <?= $view === 'new' ? 'required' : '' ?>></label>
                </div>
                <div class="form-actions"><button class="primary-button" type="submit">保存资料</button><a href="<?= e($base) ?>">返回列表</a></div>
            </form>
        </section>
    <?php elseif ($available && $view === 'confirm' && $record !== null && $pending !== null): ?>
        <section class="simple-panel"><h2>确认删除<?= e($roleLabel) ?></h2>
            <p>系统编号：<?= (int)$record['id'] ?>；账号：<?= e($record['username']) ?>；姓名：<?= e($record['display_name']) ?>。</p>
            <p>删除后不能登录；已有选课、成绩或授课记录的人员请改为停用。</p>
            <form method="post" action="<?= e($base) ?>" class="form-actions">
                <input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><input type="hidden" name="confirmation" value="<?= e($pending['nonce']) ?>">
                <button class="primary-button" name="action" value="confirm_delete" type="submit">确认删除</button><button class="logout-button" name="action" value="cancel_delete" type="submit">取消删除</button>
            </form>
        </section>
    <?php endif; ?>
</div></main>
<?php require __DIR__ . '/partials/footer.php'; ?>
