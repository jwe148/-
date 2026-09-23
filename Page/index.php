<?php
declare(strict_types=1);

$semester = '2026—2027学年 第一学期';
$selectionOpen = true;

$courses = [
    [
        'code' => 'CS301',
        'name' => '软件工程',
        'teacher' => '王老师',
        'time' => '周一 08:00—09:40',
        'location' => '逸夫楼 B201',
        'capacity' => 10,
        'selected' => 7,
        'color' => 'blue',
    ],
    [
        'code' => 'CS305',
        'name' => '数据库系统',
        'teacher' => '李老师',
        'time' => '周三 10:00—11:40',
        'location' => '计算机楼 A305',
        'capacity' => 10,
        'selected' => 9,
        'color' => 'orange',
    ],
    [
        'code' => 'CS309',
        'name' => '计算机网络',
        'teacher' => '张老师',
        'time' => '周五 13:30—15:10',
        'location' => '逸夫楼 B105',
        'capacity' => 10,
        'selected' => 5,
        'color' => 'green',
    ],
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="吉林大学课程注册系统首页">
    <title>课程注册系统｜吉林大学</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header class="site-header">
        <div class="container nav-wrap">
            <a class="brand" href="index.php" aria-label="课程注册系统首页">
                <span class="brand-mark" aria-hidden="true">JLU</span>
                <span>
                    <strong>课程注册系统</strong>
                    <small>COURSE REGISTRATION</small>
                </span>
            </a>

            <nav class="main-nav" aria-label="主导航">
                <a class="active" href="index.php">首页</a>
                <a href="#courses">课程查询</a>
                <a href="#services">使用指南</a>
            </nav>

            <a class="login-button" href="login.php">
                <span>登录系统</span>
                <span aria-hidden="true">→</span>
            </a>
        </div>
    </header>

    <main>
        <section class="hero">
            <div class="hero-shape hero-shape-one"></div>
            <div class="hero-shape hero-shape-two"></div>
            <div class="container hero-grid">
                <div class="hero-copy">
                    <div class="eyebrow"><span></span><?= e($semester) ?></div>
                    <h1>让每一次选择，<br><em>连接成长的方向</em></h1>
                    <p>集中完成课程查询、在线选课、课表查看与成绩管理，让教学安排更清晰、更高效。</p>
                    <div class="hero-actions">
                        <a class="primary-button" href="login.php">进入选课 <span>→</span></a>
                        <a class="text-link" href="#courses">浏览本学期课程 <span>↓</span></a>
                    </div>
                </div>

                <aside class="status-card" aria-label="选课开放状态">
                    <div class="status-card-head">
                        <span class="status-icon" aria-hidden="true">✓</span>
                        <span class="status-pill <?= $selectionOpen ? 'open' : 'closed' ?>">
                            <?= $selectionOpen ? '选课进行中' : '选课已关闭' ?>
                        </span>
                    </div>
                    <p>当前学期</p>
                    <h2><?= e($semester) ?></h2>
                    <dl class="date-list">
                        <div><dt>开始时间</dt><dd>09月21日 08:00</dd></div>
                        <div><dt>截止时间</dt><dd>10月09日 17:00</dd></div>
                    </dl>
                    <div class="status-note"><span aria-hidden="true">i</span>请在截止时间前完成首选与备选课程提交</div>
                </aside>
            </div>
        </section>

        <section class="quick-services" id="services">
            <div class="container">
                <div class="section-heading compact">
                    <div><span class="section-kicker">QUICK ACCESS</span><h2>快速服务</h2></div>
                    <p>根据您的身份选择相应服务</p>
                </div>
                <div class="service-grid">
                    <a class="service-card" href="login.php?role=student">
                        <span class="service-icon student" aria-hidden="true">学</span>
                        <span><strong>学生选课</strong><small>查询课程、调整课表与查看成绩</small></span>
                        <b aria-hidden="true">→</b>
                    </a>
                    <a class="service-card" href="login.php?role=teacher">
                        <span class="service-icon teacher" aria-hidden="true">师</span>
                        <span><strong>教师服务</strong><small>认领教学班并录入课程成绩</small></span>
                        <b aria-hidden="true">→</b>
                    </a>
                    <a class="service-card" href="login.php?role=admin">
                        <span class="service-icon admin" aria-hidden="true">管</span>
                        <span><strong>教务管理</strong><small>管理选课进程与查看最终课表</small></span>
                        <b aria-hidden="true">→</b>
                    </a>
                </div>
            </div>
        </section>

        <section class="course-section" id="courses">
            <div class="container">
                <div class="section-heading">
                    <div><span class="section-kicker">COURSES</span><h2>热门课程</h2></div>
                    <a href="login.php">查看全部课程 <span>→</span></a>
                </div>
                <div class="course-grid">
                    <?php foreach ($courses as $course): ?>
                        <?php $remaining = $course['capacity'] - $course['selected']; ?>
                        <article class="course-card">
                            <div class="course-top <?= e($course['color']) ?>">
                                <span><?= e($course['code']) ?></span>
                                <span><?= $remaining > 0 ? '剩余 ' . $remaining . ' 席' : '已满' ?></span>
                            </div>
                            <div class="course-body">
                                <h3><?= e($course['name']) ?></h3>
                                <p><span aria-hidden="true">👤</span><?= e($course['teacher']) ?></p>
                                <p><span aria-hidden="true">◷</span><?= e($course['time']) ?></p>
                                <p><span aria-hidden="true">⌖</span><?= e($course['location']) ?></p>
                                <div class="capacity" aria-label="已选 <?= $course['selected'] ?> 人，容量 <?= $course['capacity'] ?> 人">
                                    <div><span>选课进度</span><b><?= $course['selected'] ?>/<?= $course['capacity'] ?></b></div>
                                    <progress value="<?= $course['selected'] ?>" max="<?= $course['capacity'] ?>"></progress>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="notice-section">
            <div class="container notice-bar">
                <div><span class="notice-label">通知</span><strong>2026—2027学年第一学期选课工作安排</strong></div>
                <span>请同学们合理安排时间，及时完成选课。</span>
                <a href="#">查看详情 →</a>
            </div>
        </section>
    </main>

    <footer>
        <div class="container footer-wrap">
            <div><strong>吉林大学 · 课程注册系统</strong><span>让教学管理更简单</span></div>
            <p>© <?= date('Y') ?> 吉林大学计算机科学与技术学院</p>
        </div>
    </footer>
</body>
</html>
