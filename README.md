# 课程注册系统

正式材料：[开发计划](课程注册系统开发计划.md)、[需求分析](课程注册系统需求分析文档.md)、[设计文档](课程注册系统设计文档.md)、[测试计划](课程注册系统测试计划.md)、[测试报告](课程注册系统测试报告.md)。旧稿在`archive/`，不作为当前提交版。

## 启动

在PowerShell中运行，保持窗口开启：

```powershell
cd 'D:\360MoveData\Users\Lenovo\Desktop\25-26\code'
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' -S localhost:8000 -t .\Page
```

访问 http://localhost:8000/ 。停止服务按`Ctrl+C`。

## 数据库

使用PHP 8.0+、PDO MySQL和MySQL 8.0.16+。在启动PHP前，于同一窗口设置：

```powershell
$env:CR_DB_HOST = '127.0.0.1'
$env:CR_DB_PORT = '3306'
$env:CR_DB_NAME = 'course_registration'
$env:CR_DB_USER = '你的数据库用户'
$env:CR_DB_PASSWORD = '你的MySQL密码'
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\db_connection.php
```

未设置`CR_DB_USER`时为会话演示模式，不读取数据库；配置变化后须重启PHP。MySQL密码和网页登录密码是两回事。

新建库先执行`database/schema.sql`。如需导入样例，只对空库运行：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\database\import_demo.php --demo
```

本次新增功能：草稿、整份课表删除、教师班级名单、任教资格、模拟计费及只读目录。已有库须执行`database/add_registration_extras.sql`；管理员在“任教与计费”授予课程资格，新教师默认无资格。旧演示库可执行`database/seed_demo_qualifications.sql`给`teacher01`授予样例资格，重复执行会恢复撤销的样例资格。新导入样例会给`teacher01`授予样例课程资格。

已有库需要人员表时执行`database/add_person_profiles.sql`；延后开放学期截止可执行`database/extend_selection_deadline.sql`。`CREATE TABLE IF NOT EXISTS`不会自动修改旧字段，也不会清空数据。

## 账号

演示模式和新导入库的账号为`student01`、`teacher01`、`admin01`，初始密码均为`Demo@2026`。数据库模式以`users`表实际密码和启用状态为准，手动改过的密码不再是初始值。

`users.password_hash`支持明文和PHP密码哈希，登录自动识别格式并验证，不修改原存储值。明文在教务人员页可查询，哈希只显示状态且不回填密码输入框，留空编辑保留原值。新增或重置继续保存输入的明文密码，重置会使旧会话失效。占位账号禁用。当前截止时间为北京时间2026年11月1日17:00。

课程详情展示“所属院系”。已有库执行`database/add_course_department.sql`；演示院系为样例，实际归属可在`courses.department`中填写。升级仅补空值，不覆盖已填写院系。

## 计费与课程目录

关课生成最终课表的计费记录并模拟发送，不产生费用。`CR_BILLING_MODE=fail`用于模拟失败，改为`success`并重启服务后，在“任教与计费”重试；成功记录不会再次发送。

登录后访问`/catalog_api.php`可导出只读课程JSON。`CR_CATALOG_FILE`可指定该格式的本地快照路径，设置后重启服务；不设置则读取本地数据库。快照需包含所有开课和先修课程。真实外部系统尚未接入。

## 测试

测试方法见[测试计划](课程注册系统测试计划.md)。涉及选课、成绩和关课的写入测试只在可丢弃的测试库运行，结果见[测试报告](课程注册系统测试报告.md)。
