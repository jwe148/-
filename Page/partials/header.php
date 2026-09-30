<?php
declare(strict_types=1);

if (!isset($user, $pageTitle, $active) || !function_exists('e')) {
    http_response_code(404);
    exit;
}

$links = ['index.php' => '首页', 'courses.php' => '课程查询'];
if ($user['role'] === 'student') {
    $links += ['selection.php' => '我的选课', 'schedule.php' => '我的课表', 'grades.php' => '我的成绩'];
} elseif ($user['role'] === 'teacher') {
    $links['teaching.php'] = '我的授课';
    if (databaseModeEnabled()) {
        $links['grade_entry.php'] = '成绩录入';
    }
} elseif ($user['role'] === 'admin' && databaseModeEnabled()) {
    $links['admin_closure.php'] = '关闭选课';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?>｜课程注册系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="课程注册系统首页"><small>吉林大学</small><strong>课程注册系统</strong></a>
            <nav class="main-nav" aria-label="主导航">
                <?php foreach ($links as $url => $label): ?>
                    <a class="<?= $active === $url ? 'active' : '' ?>" href="<?= $url ?>" <?= $active === $url ? 'aria-current="page"' : '' ?>><?= $label ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="account-actions"><span class="user-chip"><?= e($user['name']) ?> · <?= e($user['label']) ?></span><form method="post" action="logout.php"><button class="logout-button" type="submit">退出</button></form></div>
        </div>
    </header>
