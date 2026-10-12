# 课程注册系统

软件工程课程项目，使用 PHP 和 MySQL 实现学生选课、教师授课及教务管理。采用普通 PHP 页面与业务函数组织代码，适合本地演示和课程答辩。

## 主要功能

| 角色 | 功能 |
| --- | --- |
| 学生 | 课程查询、4个首选和2个备选、保存草稿、退课、查看课表和成绩 |
| 教师 | 按资格认领教学班、查看学生名单、维护成绩 |
| 教务员 | 人员资料与账号管理、任教资格、截止后关课和备选补位、模拟计费 |

计费只模拟发送，不产生费用；外部课程目录以本地JSON快照模拟。

## 快速启动

需要 PHP 8.0+、PDO MySQL 扩展和 MySQL 8.0.16+。首次运行先在MySQL中执行[建表脚本](database/schema.sql)，然后配置连接并导入演示数据。配置与启动步骤如下：

1. 将根目录的 `config.example.php` 复制为 `config.local.php`，填写本机 MySQL 用户名、密码和端口。
2. 启动 MySQL 服务，在项目根目录打开 PowerShell。
3. 将下方PHP路径改为本机路径，再运行连接检查和网站。

```powershell
$phpExe = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe'
& $phpExe .\tests\db_connection.php

# 仅首次使用空数据库时执行；已有数据时跳过。
& $phpExe .\database\import_demo.php --demo

& $phpExe -S localhost:8000 -t .\Page
```

浏览器访问 <http://localhost:8000/>，关闭服务按 `Ctrl+C`。网站目录必须为 `Page`；本机配置位于网站目录外，已被Git忽略。不要上传数据库密码或数据备份。

### 配置与运行模式

配置优先级：`CR_DEMO_MODE=1` 强制演示 → 已设置 `CR_DB_USER` 时整组采用 `CR_DB_*` 环境变量 → 根目录 `config.local.php` → 无数据库用户时使用演示模式。

| 模式 | 数据保存 | 功能范围 |
| --- | --- | --- |
| 数据库模式 | 保存到MySQL，可由不同账号共享 | 完整的学生、教师、教务业务 |
| 会话演示模式 | 内置样例与当前浏览器会话，退出后不保留修改 | 课程查询、学生选课和教师认领演示；不替代数据库验收 |

无需MySQL即可体验演示模式：

```powershell
$env:CR_DEMO_MODE = '1'
& $phpExe -S localhost:8000 -t .\Page
```

恢复数据库模式时，先停止PHP，再执行 `Remove-Item Env:CR_DEMO_MODE -ErrorAction SilentlyContinue` 并重新启动。环境变量仅对当前终端及子进程生效。项目不自动读取 `.env`。数据库密码与网页登录密码不同。

## 演示账号与数据

| 角色 | 用户名 | 初始密码 |
| --- | --- | --- |
| 学生 | `student01` | `Demo@2026` |
| 教师 | `teacher01` | `Demo@2026` |
| 教务员 | `admin01` | `Demo@2026` |

数据库模式以实际账号、密码和启用状态为准。新增或重置人员密码使用PHP密码哈希；兼容旧明文账号，登录不修改原值。人员页只显示密码状态，编辑留空保留原值，重置后旧会话失效。

样例选课截止为北京时间 **2026年11月1日17:00**。超过截止时间后，需按演示安排调整开放学期；调整日期不会重新开放已关闭学期。更多账号、课程与操作场景见[扩展测试数据说明](database/测试数据说明.md)。

## 项目结构

```text
Page/          网页、业务逻辑及静态资源（网站根目录）
database/      建表、升级及演示数据导入脚本
tests/         自动测试脚本与当前回归结果
archive/       历史材料，不作为当前提交版
config.example.php  可提交的配置示例
config.local.php    本机配置，不提交
```

## 测试与验收

在项目根目录运行：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tests\run_stability.ps1 -RaceRuns 1
```

脚本需要PHP、Node.js、`rg`及MySQL完整安装目录；可通过 `-PhpPath`、`-MysqlDirectory` 修改默认工具路径。演示阶段强制使用会话数据，数据库阶段自动创建独立临时MySQL实例，不使用日常数据库。`-RaceRuns 1`执行一轮最后一席竞争，省略则为10轮；不视为压力测试。结束时输出日志和 `results.json` 的位置。

日常使用只需运行上述入口。`rules.php`合并选课、任教和关课规则检查；`db_*.php`检查数据库业务，`http_*`检查网页流程，历史结果已合并到`archive/test_results.json`。

详细方法与结果见[测试计划](课程注册系统测试计划.md)和[测试报告](课程注册系统测试报告.md)。人工检查结果按实际执行情况写入测试报告，未执行项不计为通过。

## 课程文档

正式材料：[开发计划](课程注册系统开发计划.md)、[需求分析](课程注册系统需求分析文档.md)、[设计文档](课程注册系统设计文档.md)、[测试计划](课程注册系统测试计划.md)、[测试报告](课程注册系统测试报告.md)，另附[工作总结](课程注册系统工作总结.md)。旧稿在`archive/`，不作为当前提交版。
