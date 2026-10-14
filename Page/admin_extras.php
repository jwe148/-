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
$teachers=$pdo->query("SELECT id,display_name,username,is_active FROM users WHERE role='teacher' ORDER BY username")->fetchAll();
$courses=$pdo->query('SELECT code,name FROM courses ORDER BY code')->fetchAll();
$quals=$pdo->query('SELECT q.teacher_id,q.course_code,u.display_name,u.username,u.is_active,c.name FROM teacher_qualifications q JOIN users u ON u.id=q.teacher_id JOIN courses c ON c.code=q.course_code ORDER BY u.username,c.code')->fetchAll();
$q=$pdo->prepare('SELECT b.id,u.username,b.payload,b.status,b.attempts,b.last_error,b.next_attempt_at FROM billing_outbox b JOIN users u ON u.id=b.student_id WHERE b.semester_id=? ORDER BY b.id'); $q->execute([$selectionPeriod['semester_id']]); $bills=$q->fetchAll();
$pageTitle='任教与计费'; $active='admin_extras.php'; require __DIR__.'/partials/header.php';
?>
<main class="content-page"><div class="container narrow-content"><div class="page-heading"><h1>任教与计费</h1></div>
<?php if(isset($_GET['saved'])): ?><p class="message success">处理完成。</p><?php endif; ?>
<?php foreach($errors as $error): ?><p class="message error"><?= e($error) ?></p><?php endforeach; ?>
<section class="simple-panel"><h2>授予任教资格</h2>
<?php if(!$teachers||!$courses): ?><p>暂无可选教师或课程。</p><?php else: ?>
<form method="post" class="form-actions"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="grant">
<label>教师<select name="teacher" required><?php foreach($teachers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['display_name'].' · '.$t['username'].($t['is_active']?'':' · 停用')) ?></option><?php endforeach; ?></select></label>
<label>课程<select name="course" required><?php foreach($courses as $c): ?><option value="<?= e($c['code']) ?>"><?= e($c['code'].' · '.$c['name']) ?></option><?php endforeach; ?></select></label>
<button class="primary-button" type="submit">授予资格</button></form>
<?php endif; ?></section>
<section class="simple-panel"><div class="section-heading"><h2>已有任教资格</h2><span>共 <?= count($quals) ?> 项</span></div>
<?php if(!$quals): ?><p>暂无任教资格。</p><?php else: ?>
<div class="table-scroll"><table class="data-table"><thead><tr><th>教师</th><th>课程</th><th>操作</th></tr></thead><tbody>
<?php foreach($quals as $qual): ?><tr>
<td><?= e($qual['display_name']) ?><br><small><?= e($qual['username'].($qual['is_active']?'':' · 停用')) ?></small></td>
<td><?= e($qual['name']) ?><br><small><?= e($qual['course_code']) ?></small></td>
<td><form method="post"><input type="hidden" name="token" value="<?= e($token) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="teacher" value="<?= (int)$qual['teacher_id'] ?>"><input type="hidden" name="course" value="<?= e($qual['course_code']) ?>"><button class="text-button" type="submit">撤销资格</button></form></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?></section>
<section class="simple-panel"><h2>计费发送记录</h2><p>模拟计费，不实际扣款。</p>
<?php if(!$bills): ?><p>暂无记录，关闭选课后生成。</p><?php else: ?><div class="table-scroll"><table class="data-table"><thead><tr><th>学生</th><th>账单</th><th>状态</th><th>尝试次数</th><th>下次重试</th><th>说明</th></tr></thead><tbody>
<?php $labels=['pending'=>'待发送','failed'=>'失败','sent'=>'模拟发送成功']; foreach($bills as $bill): $payload=json_decode($bill['payload'],true,512,JSON_THROW_ON_ERROR); ?><tr><td><?= e($bill['username']) ?></td><td><?php if(isset($payload['total_cents'])): ?>¥<?= e(billingMoney((int)$payload['total_cents'])) ?><details><summary>最终课表与课程明细</summary><ul><?php foreach($payload['offerings'] as $item): ?><li><?= e($item['name'].' · '.$item['id']) ?>：¥<?= e(billingMoney((int)$item['amount_cents'])) ?><br><small><?= e(billingScheduleLabel($item)) ?></small></li><?php endforeach; ?></ul></details><?php else: ?>历史记录未计算金额<?php endif; ?></td><td><?= e($labels[$bill['status']]) ?></td><td><?= (int)$bill['attempts'] ?></td><td><?= e($bill['next_attempt_at']??'—') ?></td><td><?= e($bill['last_error']??'—') ?></td></tr><?php endforeach; ?></tbody></table></div>
<form method="post"><input type="hidden" name="token" value="<?= e($token) ?>"><button class="primary-button" name="action" value="retry">发送待处理记录 / 重试失败记录</button></form><?php endif; ?></section>
</div></main><?php require __DIR__.'/partials/footer.php'; ?>
