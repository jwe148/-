<?php
declare(strict_types=1);

// 维护入口：补齐旧库结构，不导入样例、不重置业务数据。
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../Page/db.php';
function applyUpgradeFile(PDO $pdo,string $filename): void
{
    $sql=file_get_contents(__DIR__.'/'.$filename);
    if($sql===false) throw new RuntimeException('无法读取升级文件：'.$filename);
    $sql=preg_replace('/^\s*--[^\r\n]*/m','',$sql);
    foreach(explode(';',$sql) as $statement) {
        $statement=trim($statement);
        // 始终使用本机配置选定的数据库，避免脚本中的默认库名切换连接。
        if($statement==='' || preg_match('/\A(?:CREATE\s+DATABASE|USE\s)/i',$statement)) continue;
        $query=$pdo->query($statement);
        if($query) $query->closeCursor();
    }
    echo '完成 '.$filename.PHP_EOL;
}
try {
    $pdo=databaseConnection();
    foreach(['schema.sql','add_person_profiles.sql','add_course_department.sql','add_registration_extras.sql','add_teacher_grade_tools.sql','add_billing_retry.sql','add_teaching_period.sql','add_selection_reopen.sql','add_selection_sessions.sql'] as $file) applyUpgradeFile($pdo,$file);
    echo '数据库升级完成，已有人员、选课、成绩和账单保留。'.PHP_EOL;
    echo '请在“关闭选课”页面核对本学期时间；旧学期教学结束时间默认按选课开始后五个月补齐。'.PHP_EOL;
} catch(Throwable $e) {
    fwrite(STDERR,'升级未完成：'.$e->getMessage().PHP_EOL.'已完成的结构修改保留，修正后可重复执行。'.PHP_EOL); exit(1);
}
