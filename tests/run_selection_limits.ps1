$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$php = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe'
$mysqlHome = 'C:\Program Files\MySQL\MySQL Server 8.0'
$mysql = Join-Path $mysqlHome 'bin\mysql.exe'
$mysqld = Join-Path $mysqlHome 'bin\mysqld.exe'
$run = Join-Path ([IO.Path]::GetTempPath()) ('selection_limits_' + [guid]::NewGuid().ToString('N'))
$null = New-Item -ItemType Directory -Path $run
$data = Join-Path $run 'mysql_data'
$sessions = Join-Path $run 'sessions'
$null = New-Item -ItemType Directory -Path $sessions
$port = Get-Random -Minimum 29000 -Maximum 30000
$webPort = $port + 1000
$dbProcess = $null
$webProcess = $null
function Run-Php([string]$File, [string[]]$Arguments = @()) {
    & $php (Join-Path $project $File) @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Failed $File" }
}
try {
    $env:CR_DEMO_MODE = '1'
    foreach ($file in @('Page\selection.php','Page\student_data.php','Page\student_registration_db.php','Page\admin_closure_db.php','tests\rules.php','tests\db_selection_limits.php')) {
        & $php -l (Join-Path $project $file)
        if ($LASTEXITCODE -ne 0) { throw "Lint failed: $file" }
    }
    Run-Php 'tests\rules.php' @('all')
    & node (Join-Path $project 'tests\selection_ui.test.cjs')
    if ($LASTEXITCODE -ne 0) { throw 'JS checks failed' }
    $ErrorActionPreference = 'Continue'
    & $mysqld '--no-defaults' '--initialize-insecure' "--basedir=$mysqlHome" "--datadir=$data" '--console' 2>&1 | Out-File (Join-Path $run 'mysql_init.log')
    $initCode = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($initCode -ne 0) { throw "MySQL initialization failed; see $run" }
    $dbProcess = Start-Process -FilePath $mysqld -ArgumentList @('--no-defaults', ('--basedir="' + $mysqlHome + '"'), ('--datadir="' + $data + '"'), "--port=$port", '--bind-address=127.0.0.1', '--mysqlx=0', '--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $run 'mysql.stdout.log') -RedirectStandardError (Join-Path $run 'mysql.stderr.log')
    $env:CR_DEMO_MODE = '0'
    $env:CR_DB_HOST = '127.0.0.1'; $env:CR_DB_PORT = "$port"; $env:CR_DB_NAME = 'course_registration'; $env:CR_DB_USER = 'root'; $env:CR_DB_PASSWORD = ''; $env:CR_TEST_DATADIR = $data
    $ready = $false
    for ($i=0; $i -lt 100; $i++) {
        & $mysql '--no-defaults' '--host=127.0.0.1' "--port=$port" '--user=root' '--execute=SELECT 1' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { $ready = $true; break }
        Start-Sleep -Milliseconds 100
    }
    if (!$ready) { throw 'MySQL did not become ready' }
    Get-Content -LiteralPath (Join-Path $project 'database\schema.sql') -Raw -Encoding UTF8 | & $mysql '--no-defaults' '--host=127.0.0.1' "--port=$port" '--user=root' '--default-character-set=utf8mb4'
    if ($LASTEXITCODE -ne 0) { throw 'Schema import failed' }
    Run-Php 'database\import_demo.php' @('--demo')
    Run-Php 'tests\db_selection_limits.php' @('--disposable-demo')
    $webProcess = Start-Process -FilePath $php -ArgumentList @('-d', ('session.save_path="' + $sessions + '"'), '-S', "127.0.0.1:$webPort", '-t', ('"' + (Join-Path $project 'Page') + '"')) -WorkingDirectory $project -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $run 'php.stdout.log') -RedirectStandardError (Join-Path $run 'php.stderr.log')
    for ($i=0; $i -lt 100; $i++) {
        try { $null = Invoke-WebRequest "http://127.0.0.1:$webPort/login.php" -UseBasicParsing -TimeoutSec 2; break } catch { Start-Sleep -Milliseconds 100 }
    }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $project 'tests\http_selection_limits.ps1') -BaseUrl "http://127.0.0.1:$webPort"
    if ($LASTEXITCODE -ne 0) { throw 'HTTP checks failed' }
    Run-Php 'tests\db_selection_limits.php' @('--closure')
    Write-Output "PASS isolated selection verification; logs: $run"
} finally {
    if ($webProcess -and -not $webProcess.HasExited) { Stop-Process -Id $webProcess.Id }
    if ($dbProcess -and -not $dbProcess.HasExited) { Stop-Process -Id $dbProcess.Id }
}
