<?php
declare(strict_types=1);

// 追加演示数据，不清空或重置已有记录。
function importMoreDemoData(PDO $pdo): bool
{
    $marker = '扩展演示数据：创新实践，供课程注册测试使用。';
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT id, status FROM semesters WHERE name=? FOR UPDATE');
        $q->execute(['2026—2027学年 第一学期']);
        $semester = $q->fetch();
        if (!$semester) {
            throw new RuntimeException('请先建表并执行 import_demo.php --demo。');
        }
        $q = $pdo->prepare('SELECT description FROM courses WHERE code=?');
        $q->execute(['TEST109']);
        $existing = $q->fetchColumn();
        if ($existing !== false) {
            if ($existing !== $marker) {
                throw new RuntimeException('TEST109编号已被其他课程使用，未追加数据。');
            }
            $pdo->rollBack();
            return false;
        }
        if ($semester['status'] !== 'open') {
            throw new RuntimeException('当前学期已关闭，请在新的演示库中追加数据。');
        }
        $sid = (int) $semester['id'];
        $q = $pdo->prepare("SELECT id FROM semesters WHERE name=? AND status='closed'");
        $q->execute(['2025—2026学年 第二学期']);
        $history = (int) $q->fetchColumn();
        $baseUsers = $pdo->query("SELECT username,id FROM users WHERE username IN ('student01','teacher01','admin01')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['student01', 'teacher01', 'admin01'] as $name) {
            if (!isset($baseUsers[$name])) {
                throw new RuntimeException('缺少基础演示账号，请先执行 import_demo.php --demo。');
            }
        }
        if (!$history || (int) $pdo->query("SELECT COUNT(*) FROM offerings WHERE id IN ('PRE-PROG-2025','PRE-DS-2025') AND status='confirmed'")->fetchColumn() !== 2
            || (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE code IN ('PRE-PROG','PRE-DS','PRE-LA')")->fetchColumn() !== 3) {
            throw new RuntimeException('基础演示课程或历史学期不完整，未追加数据。');
        }

        $users = [];
        $user = $pdo->prepare('INSERT INTO users(username,display_name,role,password_hash,is_active) VALUES(?,?,?,?,?)');
        $profile = $pdo->prepare('INSERT INTO person_profiles(user_id,date_of_birth,social_security_number,person_status,department,graduation_date) VALUES(?,?,?,?,?,?)');
        for ($i = 4; $i <= 15; $i++) {
            $name = sprintf('student%02d', $i);
            $password = $i === 13 ? password_hash('Demo@2026', PASSWORD_BCRYPT) : 'Demo@2026';
            $user->execute([$name, '测试学生' . $i, 'student', $password, $i === 15 ? 0 : 1]);
            $users[$name] = (int) $pdo->lastInsertId();
            $profile->execute([$users[$name], '2005-06-15', sprintf('DEMO-STU-%02d', $i), '在读', null, '2028-06-30']);
        }
        foreach ([2 => '测试教师甲', 3 => '测试教师乙', 4 => '待授资格教师', 5 => '停用测试教师'] as $i => $display) {
            $name = sprintf('teacher%02d', $i);
            $user->execute([$name, $display, 'teacher', 'Demo@2026', $i === 5 ? 0 : 1]);
            $users[$name] = (int) $pdo->lastInsertId();
            $profile->execute([$users[$name], '1985-03-12', sprintf('DEMO-TEA-%02d', $i), '在职', '计算机学院', null]);
        }

        $courses = [
            'TEST101' => ['Python程序设计', '计算机学院', '用于正常选课和同课程不同班测试。'],
            'TEST102' => ['数据可视化', '计算机学院', '初始9人，用于最后一个名额测试。'],
            'TEST103' => ['软件测试', '计算机学院', '初始满额，另有历史班用于成绩维护。'],
            'TEST104' => ['项目管理', '管理学院', '初始2人，用于关课时的低人数处理。'],
            'TEST105' => ['技术写作', '人文学院', '初始3人但无教师，用于认领和无教师停开。'],
            'TEST106' => ['数字媒体基础', '计算机学院', '与Python程序设计01班端点相接，不算时间冲突。'],
            'TEST107' => ['应用数学', '数学学院', '要求通过线性代数，用于先修条件检查。'],
            'TEST108' => ['综合实验', '计算机学院', '每周两个时段，用于检查第二个时段的冲突。'],
            'TEST109' => ['创新实践', '计算机学院', $marker],
        ];
        $course = $pdo->prepare('INSERT INTO courses(code,name,department,description) VALUES(?,?,?,?)');
        foreach ($courses as $code => [$name, $department, $description]) {
            $course->execute([$code, $name, $department, $description]);
        }
        $prerequisite = $pdo->prepare('INSERT INTO course_prerequisites(course_code,prerequisite_code) VALUES(?,?)');
        foreach (['TEST101' => 'PRE-PROG', 'TEST102' => 'PRE-DS', 'TEST103' => 'PRE-PROG', 'TEST107' => 'PRE-LA'] as $code => $required) {
            $prerequisite->execute([$code, $required]);
        }
        $qualification = $pdo->prepare('INSERT INTO teacher_qualifications(teacher_id,course_code) VALUES(?,?)');
        foreach ([(int) $baseUsers['teacher01'] => array_keys($courses), $users['teacher02'] => array_keys($courses), $users['teacher03'] => ['TEST103', 'TEST104', 'TEST107']] as $teacher => $codes) {
            foreach ($codes as $code) {
                $qualification->execute([$teacher, $code]);
            }
        }

        // 星期为1～5；所有班都有时间，避免缺失数据导致整个课程目录不可用。
        $classes = [
            'TEST101-01' => ['TEST101', 'teacher02', 3, [[1, '10:00', '11:40']]],
            'TEST101-02' => ['TEST101', null, 0, [[1, '13:30', '15:10']]],
            'TEST102-01' => ['TEST102', 'teacher02', 9, [[2, '13:30', '15:10']]],
            'TEST103-01' => ['TEST103', 'teacher03', 10, [[3, '08:00', '09:40']]],
            'TEST104-01' => ['TEST104', 'teacher03', 2, [[4, '08:00', '09:40']]],
            'TEST105-01' => ['TEST105', null, 3, [[5, '08:00', '09:40']]],
            'TEST106-01' => ['TEST106', 'teacher02', 3, [[1, '11:40', '13:20']]],
            'TEST107-01' => ['TEST107', 'teacher03', 0, [[3, '15:30', '17:10']]],
            'TEST108-01' => ['TEST108', null, 0, [[5, '15:30', '16:20'], [1, '09:00', '10:00']]],
            'TEST109-01' => ['TEST109', null, 0, [[4, '15:30', '17:10']]],
        ];
        $offering = $pdo->prepare('INSERT INTO offerings(id,course_code,semester_id,class_name,teacher_id,location,capacity,status) VALUES(?,?,?,?,?,?,10,?)');
        $time = $pdo->prepare('INSERT INTO offering_times(offering_id,weekday,starts_at,ends_at) VALUES(?,?,?,?)');
        foreach ($classes as $id => [$code, $teacher, $count, $times]) {
            $offering->execute([$id, $code, $sid, substr($id, -2) . ' 班', $teacher === null ? null : $users[$teacher], '测试教学楼 A201', 'open']);
            foreach ($times as [$day, $start, $end]) {
                $time->execute([$id, $day, $start, $end]);
            }
        }
        foreach ([
            ['TEST-PRE-LA-2025', 'PRE-LA', 'teacher02', 2],
            ['TEST103-HIST', 'TEST103', 'teacher03', 4],
        ] as [$id, $code, $teacher, $day]) {
            $offering->execute([$id, $code, $history, '历史测试班', $users[$teacher], '历史课程', 'confirmed']);
            $time->execute([$id, $day, '15:30', '17:10']);
        }
        $enrollment = $pdo->prepare("INSERT INTO enrollments(student_id,offering_id,status,source,registered_at) VALUES(?,?,'active','primary',?)");
        $grade = $pdo->prepare('INSERT INTO grades(enrollment_id,grade_value) VALUES(?,?)');
        $historyGrades = [
            'student04' => ['A', 'B', 'C'],
            'student05' => ['F', 'I', null],
            'student06' => ['B', null, null],
            'student08' => ['A', 'A', 'A'],
            'student09' => ['B', 'B', 'B'],
            'student11' => ['C', 'D', null],
            'student13' => ['A', 'B', 'C'],
        ];
        $historyIds = ['PRE-PROG-2025', 'PRE-DS-2025', 'TEST-PRE-LA-2025'];
        foreach ($historyGrades as $student => $values) {
            foreach ($values as $index => $value) {
                if ($value === null) {
                    continue;
                }
                $enrollment->execute([$users[$student], $historyIds[$index], '2026-02-20 12:10:00']);
                $grade->execute([(int) $pdo->lastInsertId(), $value]);
            }
        }
        foreach (['student04' => 'A', 'student05' => 'I', 'student06' => null, 'student07' => 'B'] as $student => $value) {
            $enrollment->execute([$users[$student], 'TEST103-HIST', '2026-02-20 12:10:00']);
            $grade->execute([(int) $pdo->lastInsertId(), $value]);
        }

        $plans = [
            'student08' => [
                ['TEST101-01', 'TEST102-01', 'TEST104-01', 'TEST105-01'],
                ['TEST106-01', 'TEST103-01'],
            ],
            'student09' => [
                ['TEST101-01', 'TEST103-01', 'TEST104-01', 'TEST106-01'],
                ['TEST102-01', 'TEST105-01'],
            ],
        ];
        $choice = $pdo->prepare('INSERT INTO selection_choices(student_id,semester_id,offering_id,kind,choice_rank,created_at) VALUES(?,?,?,?,?,?)');
        $realCounts = [];
        foreach ($plans as $student => [$primary, $backup]) {
            $saved = $student === 'student08' ? '2026-10-01 09:00:00' : '2026-10-01 09:01:00';
            foreach (['primary' => $primary, 'backup' => $backup] as $kind => $ids) {
                foreach ($ids as $index => $id) {
                    $choice->execute([$users[$student], $sid, $id, $kind, $index + 1, $saved]);
                    if ($kind === 'primary') {
                        $enrollment->execute([$users[$student], $id, $saved]);
                        $realCounts[$id] = ($realCounts[$id] ?? 0) + 1;
                    }
                }
            }
        }
        $seat = 0;
        foreach ($classes as $id => [$code, $teacher, $count, $times]) {
            for ($i = $realCounts[$id] ?? 0; $i < $count; $i++) {
                $user->execute([sprintf('extra_seat_%04d', ++$seat), '扩展占位学生', 'student', '!', 0]);
                $enrollment->execute([(int) $pdo->lastInsertId(), $id, '2026-10-01 08:00:00']);
            }
        }
        $draft = $pdo->prepare('INSERT INTO selection_drafts(student_id,semester_id,choices) VALUES(?,?,?)');
        $draft->execute([$users['student07'], $sid, json_encode(['primary' => ['TEST101-01', '', '', ''], 'backup' => ['TEST109-01', '']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--demo-more') {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "用法：php database/import_more_demo.php --demo-more\n");
        } else {
            http_response_code(403);
        }
        exit(1);
    }
    require __DIR__ . '/../Page/db.php';
    try {
        $added = importMoreDemoData(databaseConnection());
        echo $added
            ? "扩展数据已追加：12个学生、4个教师、9门课程、10个当前教学班、2个历史班、22个占位账号。新增可登录账号密码均为 Demo@2026。\n"
            : "扩展数据已存在，未重复导入，也未重置已修改的数据。\n";
    } catch (Throwable $error) {
        fwrite(STDERR, '追加失败（本次修改已回滚）：' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
