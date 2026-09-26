<?php
declare(strict_types=1);

// 界面阶段的演示数据。接入 MySQL 时由课程与教学班数据表替换。
$semester = '2026—2027学年 第一学期';
$offerings = [
    'SE301-01' => [
        'code' => 'SE301', 'name' => '软件工程', 'class' => '01 班',
        'teacher' => '王老师', 'day' => '周一', 'time' => '08:00—09:40',
        'location' => '逸夫楼 B201', 'prerequisite' => '程序设计基础',
        'capacity' => 10, 'selected' => 7, 'color' => 'blue',
        'description' => '围绕软件需求、设计、实现与测试，完成一个小型团队项目。',
    ],
    'DB305-01' => [
        'code' => 'DB305', 'name' => '数据库系统', 'class' => '01 班',
        'teacher' => '李老师', 'day' => '周三', 'time' => '10:00—11:40',
        'location' => '计算机楼 A305', 'prerequisite' => '数据结构',
        'capacity' => 10, 'selected' => 9, 'color' => 'orange',
        'description' => '学习关系模型、SQL、数据库设计与事务处理。',
    ],
    'CN309-01' => [
        'code' => 'CN309', 'name' => '计算机网络', 'class' => '01 班',
        'teacher' => '张老师', 'day' => '周五', 'time' => '13:30—15:10',
        'location' => '逸夫楼 B105', 'prerequisite' => '无',
        'capacity' => 10, 'selected' => 5, 'color' => 'green',
        'description' => '介绍网络分层、主要协议以及网络应用的基本原理。',
    ],
    'OS312-01' => [
        'code' => 'OS312', 'name' => '操作系统', 'class' => '01 班',
        'teacher' => '陈老师', 'day' => '周二', 'time' => '08:00—09:40',
        'location' => '计算机楼 A208', 'prerequisite' => '数据结构',
        'capacity' => 10, 'selected' => 10, 'color' => 'blue',
        'description' => '学习进程、内存、文件系统和并发控制。',
    ],
    'AI320-01' => [
        'code' => 'AI320', 'name' => '人工智能导论', 'class' => '01 班',
        'teacher' => '刘老师', 'day' => '周四', 'time' => '10:00—11:40',
        'location' => '逸夫楼 C301', 'prerequisite' => '程序设计基础',
        'capacity' => 10, 'selected' => 6, 'color' => 'orange',
        'description' => '了解搜索、知识表示和机器学习的基础方法。',
    ],
    'HCI326-01' => [
        'code' => 'HCI326', 'name' => '人机交互', 'class' => '01 班',
        'teacher' => '待认领', 'day' => '周四', 'time' => '13:30—15:10',
        'location' => '计算机楼 A102', 'prerequisite' => '无',
        'capacity' => 10, 'selected' => 0, 'color' => 'green',
        'description' => '学习用户研究、交互设计和可用性评估。',
    ],
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
