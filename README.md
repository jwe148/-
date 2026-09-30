# 课程注册系统

当前完成登录后首页、课程查询、教学班详情，学生端的选课、课表和成绩页面，教师端的教学班认领和成绩录入页面，以及教务关闭选课页面。访问首页会先转到登录页。

在项目根目录运行（本机 phpStudy 中的 PHP 路径）：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' -S localhost:8000 -t .\Page
```

然后访问 `http://localhost:8000/`。演示账号分别为 `student01`、`teacher01`、`admin01`，密码统一为 `Demo@2026`。

学生账号可提交 4 个首选和 2 个备选、查看课表、在选课期内退课，以及查看演示成绩。提交时检查教学班是否存在、重复选择、首选名额、先修课和首选课程的时间冲突。备选不占名额，即使当前满额也可登记；实际补位时须重新检查。配置数据库后，名额随所有账号的选课和退课变化。

教师账号可在“我的授课”认领待安排的教学班，系统会检查与已认领教学班的上课时间冲突，也可在开放期间取消认领。配置数据库后，可在“成绩录入”维护本人已确认开设且学期已关闭的教学班成绩；保存后学生立即可见。

未配置数据库时使用内置演示账号。课程数据在 `Page/data/catalog.php` 中，选课与教师认领记录只保存在当前登录会话；不同浏览器会话之间不共享名额或认领结果。选课开放时间设置为 2026 年 9 月 21 日 08:00 至 10 月 9 日 17:00（北京时间）。教师成绩录入和教务关闭选课需要数据库。内置演示账号不能作为正式身份认证。

## MySQL 数据与登录

`database/schema.sql` 提供目标表结构：用户、学期、课程与先修关系、教学班与上课时间、教师认领来源、关闭及备选尝试结果、首选/备选、有效占座记录及成绩。建议使用 MySQL 8.0.16 或以上版本，以启用表中的 `CHECK` 约束。建表脚本本身不导入数据。配置 `CR_DB_USER` 并导入样例后，登录、学生选课和退课、课表、名额、学生成绩、教师认领及关闭选课从 MySQL 读写。课程名称、时间和简介暂用与数据库样例一致的 PHP 目录。

本机已有 MySQL 服务，但仓库不保存数据库密码。可用 MySQL Workbench 打开 `database/schema.sql` 并执行；或在 MySQL 命令行登录到自己的开发实例后运行：

```sql
SOURCE D:/360MoveData/Users/Lenovo/Desktop/25-26/code/database/schema.sql;
```

在同一个 PowerShell 窗口设置连接环境变量后，可检查 PHP 是否能连接数据库：

```powershell
$env:CR_DB_HOST = '127.0.0.1'
$env:CR_DB_PORT = '3306'
$env:CR_DB_NAME = 'course_registration'
$env:CR_DB_USER = '你的数据库用户'
$env:CR_DB_PASSWORD = '你的数据库密码'
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\db_connection.php
```

如需让网页登录使用 MySQL，须在**启动 PHP 服务之前**设置这些变量，并在同一窗口启动服务；已经运行的 PHP 服务不会自动读取新设置的变量。已导入样例库的，请重新执行 `schema.sql` 以新增 `teacher_claims`、`offering_closure_results`、`closure_backup_attempts` 表；它不会清除已有数据。更早版本的表字段差异不会被 `CREATE TABLE IF NOT EXISTS` 自动更新。

如需在**空的开发数据库**中导入当前演示课程，可运行：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\database\import_demo.php --demo
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\db_seed.php
```

导入脚本会建立两个学期、当前学期 11 个教学班、先修关系和下列样例。当前学期已选人数仍与课程页面一致，共 52 个名额，其中 4 个由 `student01` 占用，另外 48 个由**不可登录的占位学生**保留。

| 账号 | 密码 | 样例内容 |
| --- | --- | --- |
| `student01` | `Demo@2026` | 当前学期 4 个首选、2 个备选；上一学期 2 门成绩。 |
| `student02` | `Demo@2026` | 当前课表为空；上一学期两门先修课成绩均为及格，可用于跨账号选课测试。 |
| `student03` | `Demo@2026` | 当前课表为空、没有先修课成绩，可用于先修校验。 |
| `teacher01` | `Demo@2026` | 可认领未分配教师的教学班；历史课程示例教师。 |
| `admin01` | `Demo@2026` | 截止后可执行关闭选课并查看停开、补位结果。 |

从课程列表导入的其他教师账号和占位学生账号处于禁用状态，不能登录。若数据库已有数据，脚本直接拒绝导入，不会覆盖。样例仅供开发和测试，不能当作正式教务数据。

配置 MySQL 后，学生选课、退课、课表、名额、成绩、教师认领和教务关闭选课会从数据库读写。教务员须等选课时间截止后才能关闭；系统在一笔事务中先停开无教师或不足 3 人的班，再按学生当前方案的保存时间、学生 ID 顺序，以第 1、2 备选补位。补位时检查容量、先修和时间冲突，并记录每个实际尝试过的备选及结果，供学生在最终课表查看；重复关闭只显示已有结果。**先停开再补位是按当前中文需求采用的暂定顺序，小组仍须核对原题。**

教师可录入 A、B、C、D、F、I，也可撤销成绩；已有中文样例成绩保留原值，修改时使用字母等级。当前没有单独的“公布”步骤，保存后学生立即可见。最终等级格式仍需小组确认。

规则测试：

```powershell
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\selection_rules.php
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\teacher_rules.php
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\closure_rules.php
```

未配置数据库时，先启动 PHP 服务，再运行演示模式的 HTTP 流程测试：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\http_smoke.ps1 -BaseUrl http://localhost:8000
```

使用新建并导入样例的 MySQL 开发库启动 PHP 服务后，可运行数据库流程测试。选课测试会修改 `student02` 的选课，成绩测试会修改并撤销一条历史成绩；请只在可丢弃的测试库中运行：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\http_db_registration.ps1 -BaseUrl http://localhost:8000
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\http_db_teacher.ps1 -BaseUrl http://localhost:8000
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\http_db_grades.ps1 -BaseUrl http://localhost:8000
```

关闭选课测试会修改截止时间并永久关闭测试库的当前学期，只能在**全新、可丢弃的样例库**运行。启动测试脚本的 PowerShell 窗口也要设置上述 `CR_DB_*` 环境变量：

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\http_db_closure.ps1 -BaseUrl http://localhost:8000
& 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe' .\tests\db_closed.php
```
