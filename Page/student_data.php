<?php
declare(strict_types=1);

// 学生端的本地演示状态。正式系统需改为 MySQL 中按学生编号保存的选课记录。
function studentSelection(): array
{
    $selection = $_SESSION['student_selection'] ?? null;
    return is_array($selection) && isset($selection['primary'], $selection['backup'])
        ? $selection
        : ['primary' => [], 'backup' => []];
}

function studentGrades(): array
{
    return [
        ['semester' => '2025—2026学年 第二学期', 'course' => '程序设计基础', 'grade' => '良好'],
        ['semester' => '2025—2026学年 第二学期', 'course' => '数据结构', 'grade' => '优秀'],
    ];
}

function selectionOpen(array $period): bool
{
    $zone = new DateTimeZone('Asia/Shanghai');
    $now = new DateTimeImmutable('now', $zone);
    return $now >= new DateTimeImmutable($period['start'], $zone)
        && $now <= new DateTimeImmutable($period['end'], $zone);
}

function selectionTimeLabel(string $value): string
{
    return (new DateTimeImmutable($value, new DateTimeZone('Asia/Shanghai')))->format('m月d日 H:i');
}

function seatsRemaining(string $id, array $offerings): int
{
    $course = $offerings[$id];
    $selectedHere = in_array($id, studentSelection()['primary'], true) ? 1 : 0;
    return max(0, $course['capacity'] - $course['selected'] - $selectedHere);
}

function selectionToken(): string
{
    if (!isset($_SESSION['selection_token'])) {
        $_SESSION['selection_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['selection_token'];
}

function validSelectionToken($token): bool
{
    return is_string($token) && hash_equals(selectionToken(), $token);
}

function validateStudentSelection(array $primary, array $backup, array $offerings): array
{
    $errors = [];
    if (count($primary) !== 4 || count($backup) !== 2) {
        return ['请选择 4 个首选教学班和 2 个备选教学班。'];
    }
    $all = array_merge($primary, $backup);
    if (count(array_filter($all, 'is_string')) !== 6 || count(array_unique($all)) !== 6) {
        return ['六个教学班必须各不相同。'];
    }
    $completed = array_column(studentGrades(), 'course');
    $codes = [];
    foreach ($all as $id) {
        if (!isset($offerings[$id])) {
            $errors[] = '选课方案中包含不存在的教学班。';
            continue;
        }
        $course = $offerings[$id];
        if (in_array($course['code'], $codes, true)) {
            $errors[] = $course['name'] . '不能重复选择不同教学班。';
        }
        $codes[] = $course['code'];
        if ($course['prerequisite'] !== '无' && !in_array($course['prerequisite'], $completed, true)) {
            $errors[] = $course['name'] . '要求先修' . $course['prerequisite'] . '。';
        }
        if ($course['selected'] >= $course['capacity']) {
            $errors[] = $course['name'] . '已满额。';
        }
    }
    for ($i = 0; $i < 4; $i++) {
        if (!isset($offerings[$primary[$i]])) {
            continue;
        }
        for ($j = $i + 1; $j < 4; $j++) {
            if (!isset($offerings[$primary[$j]])) {
                continue;
            }
            $first = $offerings[$primary[$i]];
            $second = $offerings[$primary[$j]];
            [$startA, $endA] = explode('—', $first['time']);
            [$startB, $endB] = explode('—', $second['time']);
            if ($first['day'] === $second['day'] && $startA < $endB && $startB < $endA) {
                $errors[] = $first['name'] . '与' . $second['name'] . '上课时间冲突。';
            }
        }
    }
    return array_values(array_unique($errors));
}
