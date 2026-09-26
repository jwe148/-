# 课程注册系统

当前完成登录后首页、课程查询和教学班详情。访问首页会先转到登录页。课程与名额使用 `Page/data/catalog.php` 中的演示数据；登录使用本地演示账号和会话，尚未接入 MySQL。选课提交尚未实现。

在项目根目录运行（本机 phpStudy 中的 PHP 路径）：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' -S localhost:8000 -t .\Page
```

然后访问 `http://localhost:8000/`，会先看到登录页。演示账号分别为 `student01`、`teacher01`、`admin01`，密码统一为 `Demo@2026`。课程查询支持课程名称、课程代码、教师和上课星期筛选。

这些账号只用于本地页面演示，不能作为正式身份认证。后续按需求分析文档接入 MySQL 用户与角色权限，实现学生选课与课表、教师认领与成绩、教务关闭选课，并将课程演示数据换成数据库数据。
