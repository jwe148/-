<?php
declare(strict_types=1);
require __DIR__.'/auth.php'; $user=requireRole('admin');
require __DIR__.'/data/catalog_runtime.php';
require __DIR__.'/billing_db.php';
if(!databaseModeEnabled()) { http_response_code(503); exit('需要数据库模式。'); }
$pdo=databaseConnection();
$_SESSION['extras_token']=$_SESSION['extras_token']??bin2hex(random_bytes(16)); $token=$_SESSION['extras_token']; $errors=[];
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!is_string($_POST['token']??null)||!hash_equals($token,$_POST['token'])) $errors[]='页面已过期，请刷新重试。';
    else try {
        $action=$_POST['action']??'';
        if($action==='retry') $errors=dbDispatchBilling($pdo,$user['id'],$selectionPeriod['semester_id'])['errors'];
        elseif(in_array($action,['grant','revoke'],true)) {
            $teacher=filter_var($_POST['teacher']??null,FILTER_VALIDATE_INT);
            $course=is_string($_POST['course']??null)?$_POST['course']:'';
            $errors=dbSetTeacherQualification($pdo,$user['id'],(int)$teacher,$course,$action==='grant');
        } else $errors[]='无效操作。';
        if(!$errors) { header('Location: admin_extras.php?saved=1'); exit; }
    } catch(Throwable $e) { error_log('Registration extras failed: '.$e->getMessage()); $errors[]='操作未完成，请稍后重试。'; }
}
$teachers=$pdo->query("SELECT id,display_name,username FROM users WHERE role='teacher' AND is_active=1 ORDER BY username")->fetchAll();
$courses=$pdo->query('SELECT code,name FROM courses ORDER BY code')->fetchAll();
$quals=$pdo->query('SELECT q.teacher_id,q.course_code,u.display_name,c.name FROM teacher_qualifications q JOIN users u ON u.id=q.teacher_id JOIN courses c ON c.code=q.course_code ORDER BY u.username,c.code')->fetchAll();
$q=$pdo->prepare('SELECT b.id,u.username,b.status,b.attempts,b.last_error FROM billing_outbox b JOIN users u ON u.id=b.student_id WHERE b.semester_id=? ORDER BY b.id'); $q->execute([$selectionPeriod['semester_id']]); $bills=$q->fetchAll();
$pageTitle='任教与计费'; $active='admin_extras.php'; require __DIR__.'/partials/header.php';
?>
<main class="content-page"><div class="container narrow-content"><div class="page-heading"><h1>任教与计费</h1></div>
<?php if(isset($_GET['saved'])): ?><p class="message success">处理完成，请核对下方结果。</p><?php endif; ?>
<?php foreach($errors as $error): ?><p class="message error"><?= e($error) ?></p><?php endforeach; ?>
<section class="simple-panel"><h2>任教资格</h2>
<form method="post" class="form-actions"><input type="hidden" name="token" value="<?= e($token) ?>">
<label>教师<select name="teacher" required><?php foreach($teachers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['display_name'].' · '.$t['username']) ?></option><?php endforeach; ?></select></label>
<label>课程<select name="course" required><?php foreach($courses as $c): ?><option value="<?= e($c['code']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
<button class="primary-button" name="action" value="grant">授予资格</button><button class="text-button" name="action" value="revoke">撤销资格</button></form>
<details class="page-help"><summary>已有资格 · <?= count($quals) ?></summary><ul><?php foreach($quals as $qual): ?><li><?= e($qual['display_name'].' · '.$qual['name']) ?></li><?php endforeach; ?></ul></details></section>
<section class="simple-panel"><h2>计费发送记录</h2><p>模拟接收端，不产生费用。只发送关课后的最终选课信息。</p>
<?php if(!$bills): ?><p>暂无记录，关闭选课后生成。</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>学生</th><th>状态</th><th>尝试次数</th><th>说明</th></tr></thead><tbody>
<?php $labels=['pending'=>'待发送','failed'=>'失败','sent'=>'模拟发送成功']; foreach($bills as $bill): ?><tr><td><?= e($bill['username']) ?></td><td><?= e($labels[$bill['status']]) ?></td><td><?= (int)$bill['attempts'] ?></td><td><?= e($bill['last_error']??'—') ?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post"><input type="hidden" name="token" value="<?= e($token) ?>"><button class="primary-button" name="action" value="retry">发送待处理记录 / 重试失败记录</button></form><?php endif; ?></section>
</div></main><?php require __DIR__.'/partials/footer.php'; ?>
