<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--disposable-demo','--worker'], true)) { exit(1); }
require __DIR__ . '/../Page/db.php';
require __DIR__ . '/../Page/admin_people_db.php';
$pdo = databaseConnection();
$expected = getenv('CR_TEST_DATADIR');
if (!$expected || realpath($expected) !== realpath($pdo->query('SELECT @@datadir')->fetchColumn())) {
    throw new RuntimeException('Refusing personnel tests outside isolated MySQL');
}
function peopleInput(string $role, string $username, string $identity): array {
    return ['username'=>$username,'display_name'=>'测试人员','password'=>'People@2026','date_of_birth'=>'2000-02-29',
        'social_security_number'=>$identity,'person_status'=>$role === 'student' ? '在读' : '在职',
        'department'=>$role === 'teacher' ? '计算机学院' : '', 'graduation_date'=>$role === 'student' ? '2027-06-30' : '', 'is_active'=>'1'];
}
if (($argv[1] ?? '') === '--worker') {
    [$actor,$id,$revision,$gate,$name] = array_slice($argv,2);
    $until = microtime(true)+10;
    while (!is_file($gate) && microtime(true)<$until) { usleep(1000); }
    if (!is_file($gate)) { exit(2); }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $input = peopleInput('student','people_student','PEOPLE-S');
    $input['display_name'] = $name; $input['password']='';
    echo json_encode(dbSavePerson($pdo,(int)$actor,'student',(int)$id,$input,(int)$revision),JSON_UNESCAPED_UNICODE);
    exit(0);
}
$checks=0;
function peopleCheck(bool $ok,string $name):void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL '.$name); }
    echo 'PASS '.$name.PHP_EOL; $checks++;
}
function peopleSnapshot():array {
    global $pdo;
    $snapshot=[];
    foreach (['users','person_profiles','semesters','courses','course_prerequisites','offerings','offering_times','teacher_claims','selection_choices','enrollments','grades','offering_closure_results','closure_backup_attempts'] as $table) {
        $rows=$pdo->query('SELECT * FROM '.$table)->fetchAll();
        usort($rows,static fn(array $a,array $b):int=>strcmp(json_encode($a),json_encode($b)));
        $snapshot[$table]=$rows;
    }
    return $snapshot;
}
function peopleSql(string $sql,array $params=[]):void {
    global $pdo; $pdo->prepare($sql)->execute($params);
}
function peopleRejected(callable $action,string $name):void {
    $before=peopleSnapshot(); $result=$action();
    $errors=array_key_exists('errors',$result) ? $result['errors'] : $result;
    peopleCheck($errors!==[] && peopleSnapshot()===$before,$name.' rejects with every row unchanged');
}
function peopleFailure(callable $action,string $name):void {
    global $pdo;
    $before=peopleSnapshot(); $thrown=false;
    try { $action(); } catch (PDOException $error) { $thrown=true; }
    peopleCheck($thrown && !$pdo->inTransaction() && peopleSnapshot()===$before,$name.' rolls back every row');
}
$admin=(int)$pdo->query("SELECT id FROM users WHERE username='admin01'")->fetchColumn();
$existingStudent=(int)$pdo->query("SELECT id FROM users WHERE username='student01'")->fetchColumn();
$existingTeacher=(int)$pdo->query("SELECT id FROM users WHERE username='teacher01'")->fetchColumn();
$s=peopleInput('student','people_student','PEOPLE-S');
$t=peopleInput('teacher','people_teacher','PEOPLE-T');
$studentResult=dbSavePerson($pdo,$admin,'student',null,$s);
$teacherResult=dbSavePerson($pdo,$admin,'teacher',null,$t);
$student=(int)$studentResult['id']; $teacher=(int)$teacherResult['id'];
peopleCheck($studentResult['errors']===[] && $teacherResult['errors']===[] && $student>0 && $teacher>$student,'both roles created with generated unique IDs');
$record=dbPersonRecord($pdo,$student,'student');
peopleCheck($record['date_of_birth']==='2000-02-29' && $record['graduation_date']==='2027-06-30' && $record['department']===null && (int)$record['revision']===1,'student required profile fields persisted');
$record=dbPersonRecord($pdo,$teacher,'teacher');
peopleCheck($record['department']==='计算机学院' && $record['graduation_date']===null,'teacher required profile fields persisted');
$hash=$pdo->query("SELECT password_hash FROM users WHERE id=$student")->fetchColumn();
peopleCheck($hash!=='People@2026' && password_verify('People@2026',$hash),'new account password stored as verified hash');
peopleCheck(dbPersonRecord($pdo,$student,'teacher')===null && dbPersonRecord($pdo,$admin,'admin')===null,'record lookup enforces person roles');
peopleCheck(dbPersonList($pdo,'student','people_student',999)['total']===1 && dbPersonList($pdo,'student','people_student',999)['page']===1,'list search and pagination clamp work');
peopleCheck(dbPersonList($pdo,'student',"' OR 1=1 --",1)['total']===0,'SQL-looking search stays literal');

$invalid=[['display_name',''],['display_name',str_repeat('中',101)],['display_name',"bad\nname"],['display_name',"\xff"],['person_status',' '],
    ['username','a'],['username',str_repeat('a',65)],['username',"a' OR 1=1"],['date_of_birth','2023-02-29'],['date_of_birth','2999-01-01'],['date_of_birth',"2000-01-01\0"],
    ['graduation_date','1999-01-01'],['graduation_date','2000-02-29'],['social_security_number',''],['social_security_number','bad id'],['social_security_number',str_repeat('1',33)],
    ['is_active','2'],['password','1234567'],['password',str_repeat('a',73)],['password',"abc\0defgh"]];
foreach ($invalid as $index=>[$field,$value]) {
    $input=$s; $input['username']='invalid_person_'.$index; $input['social_security_number']='INVALID-'.$index; $input[$field]=$value;
    peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',null,$input),'invalid field '.$field.' '.$index);
}
foreach (array_keys($s) as $field) {
    $input=$s; $input[$field]=['nested'];
    peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',null,$input),'array field '.$field);
}
$input=$t; $input['department']='';
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'teacher',null,$input),'empty teacher department');
$input=$t; $input['graduation_date']='2027-01-01';
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'teacher',null,$input),'teacher graduation field');
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'admin',null,$s),'cannot create admin through person module');
peopleRejected(fn()=>dbSavePerson($pdo,$existingStudent,'student',null,$s),'non-admin save');
peopleSql('UPDATE users SET is_active=0 WHERE id=?',[$admin]);
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',null,$s),'disabled admin save');
peopleSql('UPDATE users SET is_active=1 WHERE id=?',[$admin]);
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',null,$s),'duplicate username');
$input=$s; $input['username']='duplicate_identity';
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',null,$input),'duplicate identity cannot overwrite another profile');
$input=$t; $input['password']=''; $input['social_security_number']=$s['social_security_number'];
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'teacher',$teacher,$input,1),'duplicate identity during edit');

$input=$s; $input['password']=''; $input['display_name']='已修改人员'; $input['is_active']='0';
peopleCheck(dbSavePerson($pdo,$admin,'student',$student,$input,1)['errors']===[],'edit name and disable account');
peopleCheck($pdo->query("SELECT password_hash FROM users WHERE id=$student")->fetchColumn()===$hash && (int)dbPersonRecord($pdo,$student,'student')['revision']===2,'blank update password preserves hash and increments revision');
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',$student,$input,1),'stale editor version');
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'teacher',$student,$t,2),'cross-role edit');
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',999999,$input,1),'missing edit target');
$changed=$input; $changed['username']='renamed_account';
peopleRejected(fn()=>dbSavePerson($pdo,$admin,'student',$student,$changed,2),'existing account username immutable');
$input['password']='Changed@2026'; $input['is_active']='1';
peopleCheck(dbSavePerson($pdo,$admin,'student',$student,$input,2)['errors']===[] && password_verify('Changed@2026',$pdo->query("SELECT password_hash FROM users WHERE id=$student")->fetchColumn()),'reset password and re-enable account');
$legacy=peopleInput('student','student01','LEGACY-S'); $legacy['password']='';
peopleCheck((int)dbPersonRecord($pdo,$existingStudent,'student')['revision']===0 && dbSavePerson($pdo,$admin,'student',$existingStudent,$legacy,0)['errors']===[],'legacy imported account can receive first profile');
peopleRejected(fn()=>dbDeletePerson($pdo,$admin,'student',$existingStudent,1),'linked student deletion');
peopleRejected(fn()=>dbDeletePerson($pdo,$admin,'teacher',$existingTeacher,0),'linked teacher deletion');
peopleRejected(fn()=>dbDeletePerson($pdo,$existingStudent,'student',$student,3),'non-admin deletion');
peopleRejected(fn()=>dbDeletePerson($pdo,$admin,'admin',$admin,0),'admin cannot be deleted through personnel route');
peopleRejected(fn()=>dbDeletePerson($pdo,$admin,'student',$student,2),'stale delete confirmation');
peopleRejected(fn()=>dbDeletePerson($pdo,$admin,'student',999999,0),'missing delete target');

peopleSql("CREATE TRIGGER people_insert_failure BEFORE INSERT ON person_profiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected profile insertion failure'");
try { $input=peopleInput('student','failure_create','FAIL-NEW'); peopleFailure(fn()=>dbSavePerson($pdo,$admin,'student',null,$input),'profile insertion fault after account creation'); }
finally { peopleSql('DROP TRIGGER people_insert_failure'); }
peopleSql("CREATE TRIGGER people_update_failure BEFORE UPDATE ON person_profiles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected profile update failure'");
try { $input=$s; $input['password']='Failure@2026'; $input['display_name']='不应保存'; $input['is_active']='0'; peopleFailure(fn()=>dbSavePerson($pdo,$admin,'student',$student,$input,3),'profile update fault after account modification'); }
finally { peopleSql('DROP TRIGGER people_update_failure'); }
peopleSql("CREATE TRIGGER people_delete_failure BEFORE DELETE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected account delete failure'");
try { peopleFailure(fn()=>dbDeletePerson($pdo,$admin,'student',$student,3),'account deletion fault'); }
finally { peopleSql('DROP TRIGGER people_delete_failure'); }

peopleSql("INSERT INTO users (username,display_name,role,password_hash,is_active) VALUES ('people_admin2','第二教务员','admin','!',1)");
$admin2=(int)$pdo->lastInsertId();
$gate=tempnam(sys_get_temp_dir(),'people_race_');
if ($gate===false) { throw new RuntimeException('Cannot create race gate'); }
unlink($gate); $workers=[];
try {
    foreach ([$admin,$admin2] as $actor) {
        $process=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$actor,(string)$student,'3',$gate,'并发修改'.$actor],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot start race worker'); }
        fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    usleep(300000); file_put_contents($gate,'start'); $results=[];
    foreach ($workers as [$process,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0) { throw new RuntimeException('Race failed: '.$err); }
        $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
} finally { if (is_file($gate)) { unlink($gate); } }
peopleCheck(count(array_filter($results,static fn(array $r):bool=>$r['errors']===[]))===1,'simultaneous admin edits have one winner');
peopleCheck((int)dbPersonRecord($pdo,$student,'student')['revision']===4,'simultaneous edits increment revision only once');
peopleCheck(dbDeletePerson($pdo,$admin,'student',$student,4)===[] && dbPersonRecord($pdo,$student,'student')===null,'unlinked student physically deleted');
peopleCheck((int)$pdo->query("SELECT COUNT(*) FROM person_profiles WHERE user_id=$student")->fetchColumn()===0,'deleted account profile removed by foreign key');
peopleCheck(dbDeletePerson($pdo,$admin,'teacher',$teacher,1)===[] && dbPersonRecord($pdo,$teacher,'teacher')===null,'unlinked teacher physically deleted');
echo 'CHECKS '.$checks.PHP_EOL;
