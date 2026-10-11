<?php
declare(strict_types=1);
require __DIR__.'/auth.php';
$user=requireRole('teacher');
require __DIR__.'/data/catalog_runtime.php';
require __DIR__.'/registration_extras.php';
$id=is_string($_GET['id']??null)?$_GET['id']:'';
$rows=databaseModeEnabled()?dbTeacherRoster(databaseConnection(),$user['id'],$selectionPeriod['semester_id'],$id):null;
if ($rows===null || !isset($offerings[$id])) { http_response_code(403); exit('只能查看本人教学班名单，且需要数据库模式。'); }
$pageTitle='班级名单'; $active='teaching.php';
require __DIR__.'/partials/header.php';
?>
<main class="content-page"><div class="container narrow-content">
<div class="page-heading"><h1><?= e($offerings[$id]['name'].' · '.$offerings[$id]['class']) ?></h1><p>在选学生 <?= count($rows) ?> 人</p></div>
<section class="simple-panel"><table class="data-table"><thead><tr><th>学号 / 账号</th><th>姓名</th></tr></thead><tbody>
<?php foreach($rows as $row): ?><tr><td><?= e($row['username']) ?></td><td><?= e($row['display_name']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php if (!$rows): ?><p>暂无学生。</p><?php endif; ?></section><a href="teaching.php">返回我的授课</a>
</div></main>
<?php require __DIR__.'/partials/footer.php'; ?>
