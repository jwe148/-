<?php
declare(strict_types=1);

// 课程定义只读接口；占座、任教和学期状态始终由本地注册库管理。
function readCourseDefinitions(PDO $pdo): array
{
    $path=getenv('CR_CATALOG_FILE');
    if ($path !== false && $path !== '') {
        if (!is_file($path) || filesize($path)>2097152) throw new RuntimeException('课程目录文件不可用。');
        $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['courses']??null)) throw new RuntimeException('课程目录格式无效。');
        $rows=$data['courses'];
    } else $rows=$pdo->query('SELECT code,name,description,department FROM courses ORDER BY code')->fetchAll();
    $result=[];
    foreach($rows as $row) {
        if (!is_array($row) || !is_string($row['code']??null) || !is_string($row['name']??null) || !is_string($row['description']??null) || $row['code']==='' || strlen($row['code'])>32 || $row['name']==='' || isset($result[$row['code']])) throw new RuntimeException('课程定义无效。');
        if (isset($row['department']) && !is_string($row['department'])) throw new RuntimeException('课程院系格式无效。');
        $row['department']=$row['department']??''; // 兼容旧JSON快照。
        $result[$row['code']]=$row;
    }
    return $result;
}
