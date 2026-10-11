# 课程注册系统

正式材料：[开发计划](课程注册系统开发计划.md)、[需求分析](课程注册系统需求分析文档.md)、[设计文档](课程注册系统设计文档.md)、[测试计划](课程注册系统测试计划.md)、[测试报告](课程注册系统测试报告.md)，另附[工作总结](课程注册系统工作总结.md)。旧稿在`archive/`，不作为当前提交版。

首次在另一台电脑运行，请按下方[从 GitHub 下载并迁移到另一台电脑](#从-github-下载并迁移到另一台电脑)操作，包含环境安装、生成数据库、导入演示数据及迁移原有数据的完整步骤。

## 使用说明

| 情况 | 操作入口 |
| --- | --- |
| 首次安装和生成演示数据库 | 下方迁移说明第1～8步 |
| 保留原电脑已有数据 | 下方迁移说明第9步 |
| 数据库已准备好，重新启动 | 下方迁移说明第8步的启动命令 |
| 运行自动测试 | 本文“测试”及测试计划第5节 |

需要PHP 8.0+、PDO MySQL和MySQL 8.0.16+。启动前在同一PowerShell窗口设置`CR_DB_*`；未设置`CR_DB_USER`时使用会话演示模式，不读取数据库。更改配置后须重启PHP。MySQL密码与网页登录密码不同。

## 旧数据库升级

新库执行`database/schema.sql`即可。旧库按缺少的内容执行对应脚本：

| 内容 | 升级脚本 |
| --- | --- |
| 人员资料 | `database/add_person_profiles.sql` |
| 草稿、任教资格和模拟计费 | `database/add_registration_extras.sql` |
| 课程所属院系 | `database/add_course_department.sql` |
| 将开放演示学期截止延长至2026年11月1日17:00 | `database/extend_selection_deadline.sql` |

升级前备份数据。`CREATE TABLE IF NOT EXISTS`不会修改旧字段；院系脚本只补空值。旧演示库可按需执行`database/seed_demo_qualifications.sql`，重复执行会恢复`teacher01`被撤销的样例资格；新导入样例已含资格。真实新增教师由教务授予资格。

## 账号

演示模式和新导入库的账号为`student01`、`teacher01`、`admin01`，初始密码均为`Demo@2026`。数据库模式以`users`表实际密码和启用状态为准，手动改过的密码不再是初始值。

`users.password_hash`支持明文和PHP密码哈希，登录自动识别格式并验证，不修改原存储值。明文在教务人员页可查询，哈希只显示状态且不回填密码输入框，留空编辑保留原值。新增或重置继续保存输入的明文密码，重置会使旧会话失效。占位账号禁用。当前截止时间为北京时间2026年11月1日17:00。

课程详情展示“所属院系”，演示院系为样例；实际归属可在`courses.department`中填写。

## 计费与课程目录

关课生成最终课表的计费记录并模拟发送，不产生费用。`CR_BILLING_MODE=fail`用于模拟失败，改为`success`并重启服务后，在“任教与计费”重试；成功记录不会再次发送。

登录后访问`/catalog_api.php`可导出只读课程JSON。`CR_CATALOG_FILE`可指定该格式的本地快照路径，设置后重启服务；不设置则读取本地数据库。快照需包含所有开课和先修课程。真实外部系统尚未接入。

## 测试

测试方法见[测试计划](课程注册系统测试计划.md)。涉及选课、成绩和关课的写入测试只在可丢弃的测试库运行，结果见[测试报告](课程注册系统测试报告.md)。

## 从 GitHub 下载并迁移到另一台电脑

以下步骤适用于另一台 Windows 电脑上的本地运行与演示。GitHub 下载的是项目文件，原电脑 MySQL 中的账号、选课和成绩不会自动随代码下载。

请选择数据准备方式：

- **新建演示数据库**：依次执行第 1～8 步。
- **保留原电脑已有数据**：执行第 1～3 步，然后使用第 9 步的备份恢复流程，恢复后执行第 5、7、8 步；不再导入演示数据。

### 1. 从 GitHub 下载代码

1. 在新电脑打开项目的 GitHub 仓库；私有仓库需要登录有访问权限的账号。
2. 选择包含最新项目代码的分支。
3. 点击绿色 **Code → Download ZIP**。
4. 将压缩包完整解压到固定目录，例如 `D:\projects\course-registration`。
5. 找到实际包含 `Page` 和 `database` 的项目根目录。ZIP 解压后可能多套一层文件夹，后续 `cd` 路径应以实际目录为准。

项目目录至少应包含：

```text
course-registration/
├─ Page/
│  ├─ index.php
│  ├─ login.php
│  └─ db.php
├─ database/
│  ├─ schema.sql
│  └─ import_demo.php
├─ tests/
│  └─ db_connection.php
└─ README.md
```

如已安装 Git，也可以在 PowerShell 中克隆；把示例地址换成实际仓库地址：

```powershell
git clone https://github.com/你的用户名/你的仓库名.git D:\projects\course-registration
```

下载方法参考：[GitHub 源码下载说明](https://docs.github.com/en/repositories/working-with-files/using-files/downloading-source-code-archives)。

### 2. 安装 PHP 和 MySQL

| 环境 | 项目要求 |
| --- | --- |
| PHP | 8.0 或更高版本 |
| PHP 数据库扩展 | PDO、`pdo_mysql` |
| MySQL | 8.0.16 或更高版本，以支持项目使用的 CHECK 约束 |

可以继续使用原电脑的 phpStudy 环境，也可以分别安装 [PHP Windows 版本](https://www.php.net/downloads.php?os=windows)和 [MySQL Community Server](https://dev.mysql.com/downloads/mysql/)。

如果使用 phpStudy：

1. 安装 phpStudy，安装或启用 PHP 8.x。
2. 安装或启用符合上述要求的 MySQL，注意不要使用默认的旧版 MySQL 5.x。
3. 在面板中启动 MySQL 服务。
4. 找到实际的 `php.exe`、`mysql.exe`，并记录 MySQL 端口、用户名和密码。

如果分别安装：

1. 下载 PHP Windows ZIP，解压到固定目录，例如 `C:\tools\php`。
2. 如果目录中没有 `php.ini`，复制 `php.ini-development`，将副本命名为 `php.ini`。
3. 安装 MySQL Server，在配置向导中设置端口和 root 密码，并启用、启动 MySQL 服务。
4. 记录 MySQL 的 `bin` 目录位置，后续会使用其中的 `mysql.exe` 和 `mysqldump.exe`。

本说明使用 PHP 自带服务器启动网站，只需启动 MySQL，不需要同时启动 Apache 或 Nginx。

### 3. 设置工具路径并检查扩展

打开 PowerShell。下列路径是示例，必须改成新电脑实际安装路径：

```powershell
cd 'D:\projects\course-registration'

# 如果单独解压 PHP，可改为 C:\tools\php\php.exe。
$phpExe = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe'

# 如果由 phpStudy 安装 MySQL，请改成它的实际 mysql.exe 路径。
$mysqlExe = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe'

& $phpExe -v
& $phpExe --ini
& $phpExe -m
& $mysqlExe --version
```

`php -m` 输出应包含 `PDO` 和 `pdo_mysql`。如果没有 `pdo_mysql`：

1. 根据 `php --ini` 的 `Loaded Configuration File` 找到实际加载的 `php.ini`；如果显示 `(none)`，先在 PHP 目录创建该文件。
2. 设置扩展目录，找到 `extension=pdo_mysql` 并去掉行首分号。配置示例：

   ```ini
   extension_dir="ext"
   extension=pdo_mysql
   date.timezone=Asia/Shanghai
   ```

3. 如果仍然找不到扩展，将 `extension_dir` 改为实际绝对路径，例如 `C:\tools\php\ext`，并确认目录内存在 `php_pdo_mysql.dll`。
4. 保存后重新运行 `& $phpExe -m`；如果网站已启动，停止并重新启动 PHP 服务。

配置方法参考：[PHP PDO 安装说明](https://www.php.net/manual/en/pdo.installation.php)。

### 4. 生成数据库和数据表

这一步用于新建数据库。`database/schema.sql` 已包含 `CREATE DATABASE`、`USE` 和建表语句，执行它即可创建 `course_registration` 数据库及所需数据表。

先确认 MySQL 服务正在运行，再在 PowerShell 中登录。端口、用户名按实际配置修改：

```powershell
& $mysqlExe -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4
```

出现 `Enter password:` 后，输入新电脑的 MySQL 密码。输入过程中不显示字符属于正常情况。

看到 `mysql>` 提示符后，执行以下 **MySQL 命令**，不要把它们直接输入 PowerShell；文件路径使用 `/`：

```sql
SOURCE D:/projects/course-registration/database/schema.sql;

SHOW DATABASES;
USE course_registration;
SHOW TABLES;
```

应生成以下 16 张表：

```text
users                     person_profiles
semesters                 courses
course_prerequisites      offerings
offering_times            teacher_claims
offering_closure_results  closure_backup_attempts
selection_choices         enrollments
grades                    selection_drafts
teacher_qualifications    billing_outbox
```

确认脚本执行过程中没有错误，然后退出 MySQL：

```sql
EXIT;
```

此时只有表结构，还没有演示账号或课程。新库使用当前 `schema.sql` 即可，无须额外执行 `add_*.sql` 升级脚本。脚本中的数据库名固定为 `course_registration`，下面的连接配置应与它一致。

执行方法参考：[MySQL 执行 SQL 文件说明](https://dev.mysql.com/doc/refman/8.0/en/mysql-batch-commands.html)。

### 5. 配置并验证数据库连接

回到刚才的 PowerShell，在同一窗口中设置环境变量：

```powershell
$env:CR_DB_HOST = '127.0.0.1'
$env:CR_DB_PORT = '3306'
$env:CR_DB_NAME = 'course_registration'
$env:CR_DB_USER = 'root'
$env:CR_DB_PASSWORD = '改成新电脑的MySQL密码'

& $phpExe .\tests\db_connection.php
```

成功输出类似：

```text
当前数据库：course_registration；MySQL 服务：……:3306
MySQL 连接成功：8.0……
```

注意：

- root 是本地操作示例；也可使用已获得此数据库所需权限的数据库用户。
- `CR_DB_PASSWORD` 是 MySQL 服务密码，与网页账号密码不同。
- 必须设置 `CR_DB_USER`，否则网站会进入会话演示模式，不读取 MySQL。
- 当前项目通过 `getenv()` 读取环境变量，不会自动加载 `.env` 文件。
- `$env:` 设置只对当前 PowerShell 窗口及其启动的程序生效。应在此窗口继续导入数据和启动网站；更换窗口后需要重新设置。

### 6. 导入演示账号、课程和选课数据

确认第 4 步已经建表、第 5 步连接成功，并且数据库为空，然后运行：

```powershell
& $phpExe .\database\import_demo.php --demo
```

成功时会显示“演示数据导入完成”，并列出教学班、已选名额和历史成绩数量。

该脚本会生成演示登录账号、当前及历史学期、课程、教学班、上课时间、先修关系、选课记录、占位学生、历史成绩和演示教师任教资格。

导入器只允许命令行运行，并且必须带 `--demo` 参数；不能通过浏览器打开它进行导入。主要表已有数据时，脚本会取消导入。成功后不需要再次执行；迁移原有数据时也不要执行这一步。

### 7. 启动网站并登录

在配置过数据库环境变量的同一 PowerShell 窗口运行：

```powershell
& $phpExe -S localhost:8000 -t .\Page
```

保持窗口开启，在浏览器访问：<http://localhost:8000/>。

新导入的演示库可使用：

| 角色 | 用户名 | 初始密码 |
| --- | --- | --- |
| 学生 | `student01` | `Demo@2026` |
| 学生 | `student02`、`student03` | `Demo@2026` |
| 教师 | `teacher01` | `Demo@2026` |
| 教务员 | `admin01` | `Demo@2026` |

恢复原电脑数据库后，使用原数据库的实际网页登录密码，已修改的密码不会自动恢复为初始密码。

`-t .\Page` 指定项目的网站目录；停止网站时按 `Ctrl+C`。启动方式参考：[PHP 自带服务器说明](https://www.php.net/manual/en/features.commandline.webserver.php)。

### 8. 检查结果及以后重新启动

依次检查：

1. 数据库连接脚本显示成功。
2. 学生登录后能查看课程、课表和历史成绩。
3. 教师登录后能打开任教和成绩相关页面。
4. 教务员登录后能查看人员、任教资格和计费相关页面。

如需核对数据库记录，重新登录 MySQL 后执行：

```sql
USE course_registration;
SELECT COUNT(*) AS user_count FROM users;
SELECT COUNT(*) AS course_count FROM courses;
SELECT COUNT(*) AS offering_count FROM offerings;
SELECT name, selection_starts_at, selection_ends_at, status FROM semesters;
```

当前仓库演示数据的选课截止时间为北京时间 **2026 年 11 月 1 日 17:00**。如果演示时已过截止时间，选课限制可能来自时间规则，需要根据演示安排调整开放学期的 `selection_ends_at`。`extend_selection_deadline.sql` 只延长到上述固定日期，不能无限延后，也不会重新开放已关闭的学期。

以后每次开机或打开新的 PowerShell 窗口，先启动 MySQL，再运行以下命令。路径和密码仍应按新电脑实际配置修改：

```powershell
cd 'D:\projects\course-registration'
$phpExe = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe'

$env:CR_DB_HOST = '127.0.0.1'
$env:CR_DB_PORT = '3306'
$env:CR_DB_NAME = 'course_registration'
$env:CR_DB_USER = 'root'
$env:CR_DB_PASSWORD = '改成新电脑的MySQL密码'

& $phpExe -S localhost:8000 -t .\Page
```

数据库和已导入的数据会保留，关闭 PowerShell 不会删除数据。重新启动时无需再次建表或导入。

### 9. 完整迁移原电脑已有数据

如果要保留原电脑已经修改的账号、密码、选课、成绩和人员资料，使用此流程替代第 4 步和第 6 步。

#### 9.1 在原电脑备份数据库

先停止网站操作。在原电脑 PowerShell 中执行，将工具路径、端口、用户名和数据库名改成原电脑的配置：

```powershell
$dumpExe = 'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe'

& $dumpExe -h 127.0.0.1 -P 3306 -u root -p `
  --default-character-set=utf8mb4 `
  --single-transaction `
  --no-tablespaces `
  --databases course_registration `
  --result-file='D:\course_registration_backup.sql'

$LASTEXITCODE
```

输入原电脑 MySQL 密码。确认没有报错、退出码为 `0`，且已生成非空的 `D:\course_registration_backup.sql`；备份过程如有错误，不要继续使用该文件恢复。

这里使用 `--result-file`，避免 Windows PowerShell 使用 `>` 重定向时引入文件编码问题。备份方法参考：[MySQL mysqldump 说明](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html)。

#### 9.2 在新电脑恢复

1. 将备份文件通过 U 盘或其他方式复制到新电脑，例如 `D:\backup\course_registration_backup.sql`。该文件含业务数据和账号密码信息，不要提交到公开 GitHub 仓库。
2. 确认新电脑已完成第 1～3 步，并启动 MySQL。
3. 在新电脑尚无需要保留的同名数据库时恢复；备份脚本可能重建同名表。如已有重要数据，先另行备份。
4. 在新电脑 PowerShell 中登录 MySQL：

   ```powershell
   & $mysqlExe -h 127.0.0.1 -P 3306 -u root -p --default-character-set=utf8mb4
   ```

5. 在 `mysql>` 后执行：

   ```sql
   SOURCE D:/backup/course_registration_backup.sql;
   USE course_registration;
   SHOW TABLES;
   SELECT COUNT(*) AS user_count FROM users;
   SELECT COUNT(*) AS course_count FROM courses;
   SELECT COUNT(*) AS offering_count FROM offerings;
   EXIT;
   ```

6. 确认恢复过程无错误，并将记录数量与原电脑核对。
7. 按第 5 步设置新电脑自己的 MySQL 用户名、密码并检查连接，再按第 7、8 步启动和验证。

此备份包含建库语句、表结构和数据，无须先导入演示数据。项目数据库的备份不会迁移 MySQL 服务账号，数据库连接仍使用新电脑的服务账号和密码。

#### 9.3 旧版本数据库按需升级

当前版本完整数据库的备份通常无须升级。如果备份来自旧版本，先保留备份，再根据缺失结构在 MySQL 中执行相应脚本：

```sql
SOURCE D:/projects/course-registration/database/add_person_profiles.sql;
SOURCE D:/projects/course-registration/database/add_registration_extras.sql;
SOURCE D:/projects/course-registration/database/add_course_department.sql;
```

`CREATE TABLE IF NOT EXISTS` 不会自动修改旧表字段。`seed_demo_qualifications.sql` 仅用于补充旧演示库中 `teacher01` 的样例资格，重复执行会恢复撤销的样例资格，不应作为恢复原有数据的必做步骤。

### 10. 常见问题

| 现象 | 检查与处理 |
| --- | --- |
| 找不到 `php.exe`、`mysql.exe` 或 `mysqldump.exe` | 示例安装路径与新电脑不同，改成实际完整路径 |
| PHP 提示缺少 `VCRUNTIME` 等运行库 DLL | 按所下载 PHP 版本的 Windows 安装要求补齐对应 Microsoft Visual C++ 运行库 |
| `could not find driver` | 用 `php --ini` 找到当前 PHP 配置文件，启用 `pdo_mysql` 后重新检查 |
| `Access denied for user` | 核对 MySQL 用户名、密码及数据库权限 |
| 无法连接或 `Connection refused` | 检查 MySQL 服务是否启动、端口是否一致 |
| `Unknown database` | 尚未建库，或 `CR_DB_NAME` 与脚本创建的数据库名不一致 |
| 数据表不存在 | 检查建表或恢复脚本是否完整执行，以及当前连接的数据库 |
| 演示导入提示数据库已有数据 | 保留现有数据；若要新建演示环境，应使用专门的空库，勿清空需要保留的数据 |
| 网站能打开但修改没有写入 MySQL | 检查启动 PHP 的同一窗口是否已设置 `CR_DB_USER` |
| 修改连接配置后仍没有变化 | 按 `Ctrl+C` 停止 PHP，在同一窗口重新设置配置并启动 |
| 8000 端口被占用 | 改用 `-S localhost:8001`，浏览器访问 `http://localhost:8001/` |
| 页面 404 或路径错误 | 检查项目根目录是否正确，启动参数是否为 `-t .\Page` |
| 登录成功但不能选课 | 检查学期状态、选课时间，以及先修、容量和冲突等业务规则 |
