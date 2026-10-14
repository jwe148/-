<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--disposable-demo')exit(1);
require __DIR__.'/../Page/db.php';
require __DIR__.'/../Page/data/catalog_runtime.php';
require __DIR__.'/../Page/student_data.php';
require __DIR__.'/../Page/teacher_data.php';
require __DIR__.'/../Page/admin_closure_db.php';
$pdo=databaseConnection();
if(!getenv('CR_TEST_DATADIR')||realpath(getenv('CR_TEST_DATADIR'))!==realpath($pdo->query('SELECT @@datadir')->fetchColumn())) throw new RuntimeException('Refusing extras tests outside isolated MySQL');
function checkExtra(bool $ok,string $name):void { if(!$ok) throw new RuntimeException('FAIL '.$name); echo 'PASS '.$name.PHP_EOL; }
function extraSnapshot(PDO $pdo):array {
    $result=[]; foreach(['selection_choices','enrollments','selection_drafts','teacher_qualifications','billing_outbox'] as $t) $result[$t]=$pdo->query('SELECT * FROM '.$t)->fetchAll(); return $result;
}
$student=(int)$pdo->query("SELECT id FROM users WHERE username='student01'")->fetchColumn();
$other=(int)$pdo->query("SELECT id FROM users WHERE username='student02'")->fetchColumn();
$teacher=(int)$pdo->query("SELECT id FROM users WHERE username='teacher01'")->fetchColumn();
$admin=(int)$pdo->query("SELECT id FROM users WHERE username='admin01'")->fetchColumn();
$sid=$selectionPeriod['semester_id']; $original=dbStudentSelection($pdo,$student,$sid); $before=extraSnapshot($pdo);
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'draft',[$original['primary'][0]],[''],$offerings)===[],'incomplete draft saved');
$draft=dbSelectionDraft($pdo,$student,$sid);
checkExtra($draft['primary']===[$original['primary'][0]]&&$draft['backup']===[''],'draft round trip');
$after=extraSnapshot($pdo); checkExtra($before['enrollments']===$after['enrollments']&&$before['selection_choices']===$after['selection_choices'],'draft never occupies or changes seats');
checkExtra(dbSelectionDraft($pdo,$other,$sid)===null,'draft isolated per student');
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'draft',['unknown'],[],$offerings)!==[],'unknown offering rejected');
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'draft',[[]],[],$offerings)!==[],'malformed draft rejected');
checkExtra(dbChangeStudentPlan($pdo,$teacher,$sid,'delete')!==[],'teacher cannot delete student schedule');
$before=extraSnapshot($pdo);
$pdo->exec("CREATE TRIGGER extras_fail_delete BEFORE DELETE ON selection_choices FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'");
$failed=false; try {dbChangeStudentPlan($pdo,$student,$sid,'delete');}catch(PDOException $e){$failed=true;}
$pdo->exec('DROP TRIGGER extras_fail_delete');
checkExtra($failed&&!$pdo->inTransaction()&&extraSnapshot($pdo)===$before,'delete failure rolls back seats and draft');
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'delete')===[],'whole schedule deletion succeeds');
checkExtra(dbStudentSelection($pdo,$student,$sid)===['primary'=>[],'backup'=>[]]&&dbSelectionDraft($pdo,$student,$sid)===null,'deletion clears choices and draft');
$q=$pdo->prepare("SELECT COUNT(*) FROM enrollments e JOIN offerings o ON o.id=e.offering_id WHERE e.student_id=? AND o.semester_id=? AND e.status='active'");$q->execute([$student,$sid]);checkExtra((int)$q->fetchColumn()===0,'deletion releases all seats');
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'delete')===[],'repeat deletion harmless');
checkExtra(dbSaveStudentSelection($pdo,$student,$sid,$original['primary'],$original['backup'],$offerings)===[],'selection can be restored after deletion');
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'draft',[],[],$offerings)===[]&&dbSaveStudentSelection($pdo,$student,$sid,$original['primary'],$original['backup'],$offerings)===[]&&dbSelectionDraft($pdo,$student,$sid)===null,'submission consumes draft');
$inactiveTeacher=(int)$pdo->query("SELECT id FROM users WHERE role='teacher' AND is_active=0 ORDER BY id LIMIT 1")->fetchColumn();
$qualificationCount=static function() use($pdo,$inactiveTeacher):int {
    $q=$pdo->prepare("SELECT COUNT(*) FROM teacher_qualifications WHERE teacher_id=? AND course_code='PRE-LA'");
    $q->execute([$inactiveTeacher]); return (int)$q->fetchColumn();
};
checkExtra($inactiveTeacher>0&&dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',true)===[]&&$qualificationCount()===1,'qualification grant includes disabled imported teachers');
checkExtra(dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',true)===[]&&$qualificationCount()===1,'repeated qualification grant creates no duplicate');
checkExtra(dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',false)===[]&&$qualificationCount()===0,'disabled teacher qualification can be revoked');
checkExtra((int)$pdo->query("SELECT is_active FROM users WHERE id=$inactiveTeacher")->fetchColumn()===0,'qualification changes do not enable teacher login');
$pdo->prepare("INSERT INTO offerings(id,course_code,semester_id,class_name,teacher_id,location,capacity,status) VALUES('QUAL-CANCELLED','PRE-LA',?,'资格测试班',?,'测试',10,'cancelled')")->execute([$sid,$inactiveTeacher]);
dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',true);
checkExtra(dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',false)===[]&&$qualificationCount()===0&&(int)$pdo->query("SELECT teacher_id FROM offerings WHERE id='QUAL-CANCELLED'")->fetchColumn()===$inactiveTeacher,'cancelled class does not block revocation or lose teacher history');
$closedSemester=(int)$pdo->query("SELECT id FROM semesters WHERE status='closed' ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO offerings(id,course_code,semester_id,class_name,teacher_id,location,capacity,status) VALUES('QUAL-HISTORY','PRE-LA',?,'资格历史班',?,'测试',10,'confirmed')")->execute([$closedSemester,$inactiveTeacher]);
dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',true);
checkExtra(dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',false)===[]&&$qualificationCount()===0&&(int)$pdo->query("SELECT teacher_id FROM offerings WHERE id='QUAL-HISTORY'")->fetchColumn()===$inactiveTeacher,'closed semester permits revocation and preserves assignment');
dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',true);
$before=extraSnapshot($pdo);
checkExtra(dbSetTeacherQualification($pdo,$admin,$student,'PRE-LA',false)!==[]&&extraSnapshot($pdo)===$before,'qualification revocation rejects a non-teacher target');
$pdo->exec("CREATE TRIGGER qualification_fail_delete BEFORE DELETE ON teacher_qualifications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected'");
$failed=false;
try { dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',false); } catch(PDOException $e) { $failed=true; }
finally { $pdo->exec('DROP TRIGGER qualification_fail_delete'); }
checkExtra($failed&&!$pdo->inTransaction()&&extraSnapshot($pdo)===$before,'qualification deletion failure rolls back without changing records');
dbSetTeacherQualification($pdo,$admin,$inactiveTeacher,'PRE-LA',false);
$pdo->exec("DELETE FROM offerings WHERE id IN ('QUAL-CANCELLED','QUAL-HISTORY')");
$wait=$pdo->query("SELECT id,course_code FROM offerings WHERE semester_id=$sid AND teacher_id IS NULL AND status='open' ORDER BY id LIMIT 1")->fetch();
checkExtra(dbSetTeacherQualification($pdo,$student,$teacher,$wait['course_code'],true)!==[],'student cannot grant qualification');
checkExtra(dbSetTeacherQualification($pdo,$admin,$teacher,$wait['course_code'],false)===[],'qualification may be revoked before assignment');
$before=extraSnapshot($pdo);checkExtra(dbChangeTeacherClaim($pdo,$teacher,$sid,$wait['id'],'claim')!==[]&&extraSnapshot($pdo)===$before,'unqualified claim rejected without mutation');
checkExtra(dbSetTeacherQualification($pdo,$admin,$teacher,$wait['course_code'],true)===[]&&dbChangeTeacherClaim($pdo,$teacher,$sid,$wait['id'],'claim')===[],'qualified teacher can claim');
$before=extraSnapshot($pdo);
checkExtra(dbSetTeacherQualification($pdo,$admin,$teacher,$wait['course_code'],false)!==[]&&extraSnapshot($pdo)===$before,'assigned qualification cannot be removed');
checkExtra(is_array(dbTeacherRoster($pdo,$teacher,$sid,$wait['id'])),'teacher sees current owned roster before closure');
checkExtra(dbTeacherRoster($pdo,$student,$sid,$wait['id'])===null,'roster denies wrong role');
checkExtra(dbTeacherRoster($pdo,$teacher,$sid,$original['primary'][0])===null,'roster denies other teacher class');
checkExtra(dbChangeTeacherClaim($pdo,$teacher,$sid,$wait['id'],'release')===[],'qualified claim can be released');
$pdo->prepare('UPDATE semesters SET selection_ends_at=? WHERE id=?')->execute([(new DateTimeImmutable('now',new DateTimeZone('Asia/Shanghai')))->modify('-1 hour')->format('Y-m-d H:i:s'),$sid]);
checkExtra(dbChangeStudentPlan($pdo,$student,$sid,'draft',[],[],$offerings)!==[]&&dbChangeStudentPlan($pdo,$student,$sid,'delete')!==[],'deadline blocks draft and whole delete');
$stamp = $pdo->query("SELECT SHA2(password_hash,256) FROM users WHERE id=$student")->fetchColumn();
$pdo->prepare('INSERT INTO selection_sessions VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 1 HOUR))')
    ->execute([str_repeat('a',64),$student,$sid,$stamp]);
$before=extraSnapshot($pdo);
$denied=dbCloseSemester($pdo,$admin,$sid);
checkExtra(!$denied['changed'] && str_contains(implode('',$denied['errors']),'有人正在选课') && extraSnapshot($pdo)===$before,
    'live session rejects closure with no selection or billing changes');
$pdo->exec('UPDATE selection_sessions SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)');
checkExtra(dbCloseSemester($pdo,$admin,$sid)['errors']===[],'expired session permits closure and queues billing');
$count=(int)$pdo->query("SELECT COUNT(*) FROM billing_outbox WHERE semester_id=$sid")->fetchColumn();checkExtra($count>0,'closed schedules generate billing records');
$payloads=$pdo->query("SELECT payload FROM billing_outbox WHERE semester_id=$sid")->fetchAll(PDO::FETCH_COLUMN);$valid=true;
foreach($payloads as $json)foreach(json_decode($json,true)['offerings'] as $o){$q=$pdo->prepare('SELECT status FROM offerings WHERE id=?');$q->execute([$o['id']]);$valid=$valid&&$q->fetchColumn()==='confirmed';}
checkExtra($valid,'billing snapshot excludes cancelled classes');
checkExtra(dbCloseSemester($pdo,$admin,$sid)['changed']===false&&(int)$pdo->query("SELECT COUNT(*) FROM billing_outbox WHERE semester_id=$sid")->fetchColumn()===$count,'repeat closure does not duplicate billing');
checkExtra(dbDispatchBilling($pdo,$student,$sid)['errors']!==[],'student cannot send billing');
putenv('CR_BILLING_MODE=fail');$r=dbDispatchBilling($pdo,$admin,$sid);checkExtra($r['failed']===$count&&$r['sent']===0,'failed billing preserved for retry');
putenv('CR_BILLING_MODE=success');$r=dbDispatchBilling($pdo,$admin,$sid);checkExtra($r['sent']===$count&&$r['failed']===0,'billing retry succeeds');
$before=extraSnapshot($pdo);checkExtra(dbDispatchBilling($pdo,$admin,$sid)['sent']===0&&extraSnapshot($pdo)===$before,'sent billing never repeated');
checkExtra((int)$pdo->query("SELECT MIN(attempts) FROM billing_outbox WHERE semester_id=$sid")->fetchColumn()===2,'retry attempt count retained');
$definitions=readCourseDefinitions($pdo);$file=tempnam(sys_get_temp_dir(),'catalog');
try {
    file_put_contents($file,json_encode(['courses'=>array_values($definitions)]));putenv('CR_CATALOG_FILE='.$file);
    checkExtra(readCourseDefinitions($pdo)===$definitions,'read-only catalog snapshot round trip');
    file_put_contents($file,'{}');$failed=false;try{readCourseDefinitions($pdo);}catch(RuntimeException $e){$failed=true;}checkExtra($failed,'malformed catalog rejected');
}finally{putenv('CR_CATALOG_FILE');unlink($file);}
echo 'Extras tests complete'.PHP_EOL;
