# 课程注册系统

当前完成登录后首页、课程查询、教学班详情，学生端的选课、课表和成绩页面，以及教师端的教学班认领页面。访问首页会先转到登录页。

在项目根目录运行（本机 phpStudy 中的 PHP 路径）：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' -S localhost:8000 -t .\Page
```

然后访问 `http://localhost:8000/`。演示账号分别为 `student01`、`teacher01`、`admin01`，密码统一为 `Demo@2026`。

学生账号可提交 4 个首选和 2 个备选、查看课表、在选课期内退课，以及查看演示成绩。提交时检查教学班是否存在、重复选择、名额、先修课和首选课程的时间冲突。课程名额会随本次会话的选课和退课变化。

教师账号可在“我的授课”认领待安排的教学班，系统会检查与已认领教学班的上课时间冲突，也可在开放期间取消认领。

当前数据在 `Page/data/catalog.php`、`Page/student_data.php` 和 `Page/teacher_data.php` 中，仅用于本地演示。选课与教师认领记录只保存在当前登录会话；不同浏览器会话之间不共享名额或认领结果。选课开放时间设置为 2026 年 9 月 21 日 08:00 至 10 月 9 日 17:00（北京时间）。教务关闭选课、成绩录入和 MySQL 持久化尚未实现。演示账号不能作为正式身份认证。

规则测试：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\selection_rules.php
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\teacher_rules.php
```

HTTP 流程测试需要先启动 PHP 服务，再运行：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\http_smoke.ps1 -BaseUrl http://localhost:8000
```
