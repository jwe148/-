param(
    [ValidateRange(1, 20)][int]$RaceRuns = 10,
    [string]$PhpPath = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe',
    [string]$MysqlDirectory = 'C:\Program Files\MySQL\MySQL Server 8.0'
)
$ErrorActionPreference = 'Stop'
$Project = Split-Path $PSScriptRoot -Parent
$Php = $PhpPath
$MysqlHome = $MysqlDirectory
$Mysql = Join-Path $MysqlHome 'bin\mysql.exe'
$MysqlAdmin = Join-Path $MysqlHome 'bin\mysqladmin.exe'
$Mysqld = Join-Path $MysqlHome 'bin\mysqld.exe'
$RunDir = Join-Path ([IO.Path]::GetTempPath()) ('course_stability_' + [guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $RunDir
$DataDir = Join-Path $RunDir 'mysql_data'
$SessionDir = Join-Path $RunDir 'sessions'
$null = New-Item -ItemType Directory -Path $SessionDir
$MysqlPort = Get-Random -Minimum 28000 -Maximum 29000
$DemoPort = $MysqlPort + 1000
$DbPort = $MysqlPort + 2000
$Servers = [Collections.Generic.List[object]]::new()
$Results = [Collections.Generic.List[object]]::new()
$DbProcess = $null
$MysqlArgs = @('--no-defaults', '--host=127.0.0.1', "--port=$MysqlPort", '--user=root', '--default-character-set=utf8mb4')
Write-Output "ARTIFACTS $RunDir"
function Run-Check([string]$Name, [scriptblock]$Action) {
    Write-Output "RUN $Name"
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $savedPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = @(& $Action 2>&1); $code = $LASTEXITCODE } finally { $ErrorActionPreference = $savedPreference }
    $output | Set-Content -Encoding UTF8 (Join-Path $RunDir "$Name.log")
    $output | Write-Output
    $watch.Stop()
    $passed = $code -eq 0 -and -not ($output -match '^FAIL ')
    $Results.Add([pscustomobject]@{ name=$Name; passed=$passed; exit_code=$code; checks=@($output -match '^PASS ').Count; requirement_gaps=@($output -match '^REQUIREMENT_GAP '); seconds=$watch.Elapsed.TotalSeconds })
    if (-not $passed) { throw "Failed $Name (exit $code)" }
}
function Invoke-Sql([string]$Sql) {
    $savedPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $result = @(& $Mysql @MysqlArgs '--batch' '--skip-column-names' '--execute' $Sql 2>&1) } finally { $ErrorActionPreference = $savedPreference }
    if ($LASTEXITCODE -ne 0) { throw ($result -join "`n") }
    return $result
}
function Reset-Demo {
    if ($null -eq $DbProcess -or $DbProcess.HasExited) { throw 'Isolated MySQL is not running' }
    $actualDataDir = [string](Invoke-Sql 'SELECT @@datadir;')
    if ([IO.Path]::GetFullPath($actualDataDir.TrimEnd('\','/')) -ne [IO.Path]::GetFullPath($DataDir)) { throw 'Refusing to reset a database outside the isolated test directory' }
    $null = Invoke-Sql 'DROP DATABASE IF EXISTS course_registration;'
    $schema = (Join-Path $Project 'database\schema.sql').Replace('\','/')
    $null = Invoke-Sql "SOURCE $schema;"
    $seedOutput = @(& $Php (Join-Path $Project 'database\import_demo.php') '--demo' 2>&1)
    if ($LASTEXITCODE -ne 0) { throw ($seedOutput -join "`n") }
}
function Start-Php([int]$Port, [string]$Name) {
    $arguments = @('-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=32767', '-d', "session.save_path=$SessionDir", '-S', "127.0.0.1:$Port", '-t', ('"' + (Join-Path $Project 'Page') + '"'))
    $process = Start-Process -FilePath $Php -ArgumentList $arguments -WorkingDirectory $Project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $RunDir "$Name.stdout.log") -RedirectStandardError (Join-Path $RunDir "$Name.stderr.log")
    $Servers.Add($process)
    for ($i=0; $i -lt 100; $i++) {
        if ($process.HasExited) { throw "PHP server $Name exited" }
        try { $null = Invoke-WebRequest "http://127.0.0.1:$Port/login.php" -UseBasicParsing -TimeoutSec 2; return } catch { Start-Sleep -Milliseconds 100 }
    }
    throw "PHP server $Name did not become ready"
}
try {
    Remove-Item Env:CR_DB_USER,Env:CR_DB_PASSWORD -ErrorAction SilentlyContinue
    Run-Check 'php_lint' {
        $files = @(& rg --files $Project -g '*.php')
        foreach ($file in $files) {
            $line = & $Php '-l' $file
            if ($LASTEXITCODE -ne 0) { throw $line }
            Write-Output "PASS $file"
        }
    }
    foreach ($name in @('selection_rules','teacher_rules','closure_rules')) {
        Run-Check $name { & $Php (Join-Path $Project "tests\$name.php") }
    }
    Run-Check 'selection_ui' { & node (Join-Path $Project 'tests\selection_ui.test.cjs') }
    Run-Check 'password_rules' { & $Php (Join-Path $Project 'tests\password_rules.php') }
    Start-Php $DemoPort 'demo'
    $demoUrl = "http://127.0.0.1:$DemoPort"
    Run-Check 'http_demo' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_smoke.ps1') -BaseUrl $demoUrl }
    Run-Check 'http_demo_stability' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_stability.ps1') -BaseUrl $demoUrl }
    Run-Check 'http_demo_completeness' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_completeness.ps1') -BaseUrl $demoUrl }
    Write-Output 'RUN mysql_initialize'
    $savedPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $initOutput = @(& $Mysqld '--no-defaults' '--initialize-insecure' "--basedir=$MysqlHome" "--datadir=$DataDir" '--console' 2>&1) } finally { $ErrorActionPreference = $savedPreference }
    $initOutput | Set-Content -Encoding UTF8 (Join-Path $RunDir 'mysql_initialize.log')
    if ($LASTEXITCODE -ne 0) { throw ($initOutput -join "`n") }
    $arguments = @('--no-defaults', ('--basedir="' + $MysqlHome + '"'), ('--datadir="' + $DataDir + '"'), "--port=$MysqlPort", '--bind-address=127.0.0.1', '--mysqlx=0', '--console')
    $DbProcess = Start-Process -FilePath $Mysqld -ArgumentList $arguments -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $RunDir 'mysql.stdout.log') -RedirectStandardError (Join-Path $RunDir 'mysql.stderr.log')
    $ready = $false
    for ($i=0; $i -lt 100; $i++) {
        if ($DbProcess.HasExited) { throw 'Isolated MySQL exited' }
        try { $null = Invoke-Sql 'SELECT 1;'; $ready = $true; break } catch { Start-Sleep -Milliseconds 200 }
    }
    if (-not $ready) { throw 'Isolated MySQL did not become ready' }
    $env:CR_DB_HOST = '127.0.0.1'
    $env:CR_DB_PORT = [string]$MysqlPort
    $env:CR_DB_NAME = 'course_registration'
    $env:CR_DB_USER = 'root'
    $env:CR_DB_PASSWORD = ''
    $env:CR_TEST_DATADIR = $DataDir
    Reset-Demo
    $schema = (Join-Path $Project 'database\schema.sql').Replace('\','/')
    $null = Invoke-Sql "SOURCE $schema; SOURCE $schema;"
    $personMigration = (Join-Path $Project 'database\add_person_profiles.sql').Replace('\','/')
    $null = Invoke-Sql "DROP TABLE course_registration.person_profiles; SOURCE $personMigration; SOURCE $personMigration;"
    Write-Output 'PASS existing-data personnel schema upgrade and repeat execution'
    Run-Check 'db_connection' { & $Php (Join-Path $Project 'tests\db_connection.php') }
    Run-Check 'db_seed' { & $Php (Join-Path $Project 'tests\db_seed.php') }
    Run-Check 'db_people' { & $Php (Join-Path $Project 'tests\db_people.php') '--disposable-demo' }
    Reset-Demo
    Run-Check 'db_completeness' { & $Php (Join-Path $Project 'tests\db_completeness.php') '--disposable-demo' }
    Reset-Demo
    Run-Check 'db_extreme' { & $Php (Join-Path $Project 'tests\db_extreme.php') '--disposable-demo' }
    Reset-Demo
    Run-Check 'db_extras' { & $Php (Join-Path $Project 'tests\db_extras.php') '--disposable-demo' }
    Reset-Demo
    Start-Php $DbPort 'database'
    $dbUrl = "http://127.0.0.1:$DbPort"
    Run-Check 'http_password' { & $Php (Join-Path $Project 'tests\http_password.php') '--disposable-demo' $dbUrl }
    Run-Check 'http_db_people' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_db_people.ps1') -BaseUrl $dbUrl }
    Run-Check 'http_db_completeness' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_completeness.ps1') -BaseUrl $dbUrl }
    Run-Check 'http_extras' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_extras.ps1') -BaseUrl $dbUrl }
    foreach ($name in @('http_db_registration','http_db_teacher','http_db_grades')) {
        Run-Check $name { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project "tests\$name.ps1") -BaseUrl $dbUrl }
    }
    Reset-Demo
    Run-Check 'http_db_stability' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_stability.ps1') -BaseUrl $dbUrl -DatabaseMode }
    $migration = (Join-Path $Project 'database\extend_selection_deadline.sql').Replace('\','/')
    $null = Invoke-Sql "USE course_registration; UPDATE semesters SET selection_ends_at='2026-10-09 17:00:00' WHERE status='open'; SOURCE $migration; SOURCE $migration;"
    $date = Invoke-Sql "SELECT selection_ends_at FROM course_registration.semesters WHERE status='open';"
    if ($date -ne '2026-11-01 17:00:00') { throw 'Deadline migration did not update correctly' }
    Write-Output 'PASS deadline migration updates old date and permits repeat execution'
    for ($race=1; $race -le $RaceRuns; $race++) {
        Reset-Demo
        Run-Check "concurrent_seat_$race" { & $Php (Join-Path $Project 'tests\concurrent_seat.php') '--test-db' }
    }
    Reset-Demo
    Run-Check 'http_db_closure' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_db_closure.ps1') -BaseUrl $dbUrl -PhpPath $Php }
    Run-Check 'db_closed' { & $Php (Join-Path $Project 'tests\db_closed.php') }
    $before = Invoke-Sql "SELECT selection_ends_at FROM course_registration.semesters WHERE name='2026—2027学年 第一学期';"
    $null = Invoke-Sql "SOURCE $migration;"
    $after = Invoke-Sql "SELECT selection_ends_at FROM course_registration.semesters WHERE name='2026—2027学年 第一学期';"
    if ($before -ne $after) { throw 'Deadline migration changed closed semester' }
    Write-Output 'PASS deadline migration preserves closed semester'
    $outageSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $null = Invoke-WebRequest "$dbUrl/login.php?role=student" -Method Post -Body @{username='student01';password='Demo@2026'} -WebSession $outageSession -UseBasicParsing -TimeoutSec 15
    $cookieFile = Join-Path $RunDir 'outage_session.txt'
    $outageSession.Cookies.GetCookies([uri]$dbUrl)['course_registration_demo'].Value | Set-Content -LiteralPath $cookieFile
    $null = & $MysqlAdmin @MysqlArgs 'shutdown' 2>&1
    if ($LASTEXITCODE -ne 0) { throw 'Could not stop isolated MySQL for failure injection' }
    $null = $DbProcess.WaitForExit(10000)
    Run-Check 'database_outage' { & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Project 'tests\http_stability.ps1') -BaseUrl $dbUrl -OutageOnly -CookieFile $cookieFile }
    Remove-Item -LiteralPath $cookieFile
    foreach ($name in @('demo','database')) {
        $errors = @(Select-String -Path (Join-Path $RunDir "$Name.stderr.log") -Pattern 'PHP (Warning|Fatal error|Parse error|Notice)|Uncaught' -ErrorAction SilentlyContinue)
        if ($errors.Count -gt 0) { throw "Unexpected PHP errors in $Name log: $($errors -join ' | ')" }
    }
    Write-Output 'PASS no PHP warning, fatal error, parse error or notice in HTTP server logs'
} catch {
    Write-Output "STABILITY_FAILURE $($_.Exception.Message)"
    $Results.Add([pscustomobject]@{ name='runner_error'; passed=$false; detail=$_.Exception.Message })
} finally {
    foreach ($process in $Servers) {
        if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue }
    }
    if ($null -ne $DbProcess -and -not $DbProcess.HasExited) {
        $null = & $MysqlAdmin @MysqlArgs 'shutdown' 2>&1
        $null = $DbProcess.WaitForExit(5000)
        if (-not $DbProcess.HasExited) { Stop-Process -Id $DbProcess.Id -Force -ErrorAction SilentlyContinue }
    }
    foreach ($directory in @($DataDir, $SessionDir)) {
        if (Test-Path -LiteralPath $directory) {
            $resolved = (Resolve-Path -LiteralPath $directory).Path
            $expectedRoot = [IO.Path]::GetFullPath($RunDir).TrimEnd('\') + '\'
            if (-not $resolved.StartsWith($expectedRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Refusing to delete a directory outside the isolated test run' }
            Remove-Item -LiteralPath $resolved -Recurse -Force
        }
    }
    $Results | ConvertTo-Json -Depth 4 | Set-Content -Encoding UTF8 (Join-Path $RunDir 'results.json')
    Write-Output "ARTIFACTS $RunDir"
}
if (@($Results | Where-Object { -not $_.passed }).Count -gt 0) { exit 1 }
$gaps = @($Results | ForEach-Object { $_.requirement_gaps })
if ($gaps.Count -gt 0) {
    Write-Output "REQUIREMENTS_INCOMPLETE $($gaps.Count) reproduced rule deviations; stability assertions passed"
    exit 2
}
exit 0
