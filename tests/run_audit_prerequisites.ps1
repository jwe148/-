param(
    [string]$PhpPath = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe',
    [string]$MysqlDirectory = 'C:\Program Files\MySQL\MySQL Server 8.0'
)
# 在独立 PowerShell 进程运行；只创建临时实例，结束后关闭进程并保留日志。
$ErrorActionPreference='Stop'
 $project=Split-Path $PSScriptRoot -Parent
 $php=$PhpPath
 $mysqlHome=$MysqlDirectory
$mysql=Join-Path $mysqlHome 'bin\mysql.exe'
$mysqld=Join-Path $mysqlHome 'bin\mysqld.exe'
$mysqladmin=Join-Path $mysqlHome 'bin\mysqladmin.exe'
$run=Join-Path $project ('tmp\prerequisite_audit_'+[guid]::NewGuid().ToString('N'))
$null=New-Item -ItemType Directory -Path $run
$data=Join-Path $run 'data'; $sessions=Join-Path $run 'sessions'
$null=New-Item -ItemType Directory -Path $sessions
$port=Get-Random -Minimum 28000 -Maximum 29000; $webport=$port+2000
$dbProcess=$null; $webProcess=$null
$argsMysql=@('--no-defaults','--host=127.0.0.1',"--port=$port",'--user=root','--default-character-set=utf8mb4')
function Sql([string]$text) { & $mysql @argsMysql '--execute' $text; if($LASTEXITCODE -ne 0) { throw 'SQL failed' } }
function CheckRun([string]$script,[string[]]$arguments=@()) { & $php (Join-Path $project $script) @arguments; if($LASTEXITCODE -ne 0) { throw "Failed $script" } }
function ResetDemo {
    if (!$dbProcess -or $dbProcess.HasExited) { throw 'Isolated MySQL is not running' }
    $actual = & $mysql @argsMysql --batch --skip-column-names --execute 'SELECT @@datadir'
    if ($LASTEXITCODE -ne 0 -or [IO.Path]::GetFullPath($actual.TrimEnd('\','/')) -ne [IO.Path]::GetFullPath($data)) { throw 'Refusing reset outside isolated test directory' }
    Sql 'DROP DATABASE IF EXISTS course_registration'
    Sql ('SOURCE '+(Join-Path $project 'database\schema.sql').Replace('\','/'))
    CheckRun 'database\import_demo.php' @('--demo')
}
try {
    Write-Output "ARTIFACTS $run"
    $ErrorActionPreference='Continue'
    & $mysqld '--no-defaults' '--initialize-insecure' "--basedir=$mysqlHome" "--datadir=$data" '--console' 2> (Join-Path $run 'initialize.log')
    $ErrorActionPreference='Stop'
    if($LASTEXITCODE -ne 0) { throw 'MySQL init failed' }
    $dbProcess=Start-Process -FilePath $mysqld -ArgumentList @('--no-defaults',('--basedir="'+$mysqlHome+'"'),('--datadir="'+$data+'"'),"--port=$port",'--bind-address=127.0.0.1','--mysqlx=0','--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $run 'mysql.out') -RedirectStandardError (Join-Path $run 'mysql.err')
    $ready=$false
    for($i=0;$i -lt 100;$i++) { $ErrorActionPreference='Continue'; & $mysqladmin @argsMysql ping 2>$null | Out-Null; $ErrorActionPreference='Stop'; if($LASTEXITCODE -eq 0) { $ready=$true; break }; Start-Sleep -Milliseconds 200 }
    if(!$ready) { throw 'MySQL not ready' }
    $env:CR_DEMO_MODE='0'; $env:CR_DB_HOST='127.0.0.1'; $env:CR_DB_PORT=[string]$port; $env:CR_DB_USER='root'; $env:CR_DB_PASSWORD=''; $env:CR_DB_NAME='course_registration'; $env:CR_TEST_DATADIR=$data
    Remove-Item Env:CR_BILLING_COURSE_FEE,Env:CR_BILLING_RETRY_SECONDS,Env:CR_BILLING_MODE_FILE,Env:CR_BILLING_MODE,Env:CR_CATALOG_FILE -ErrorAction SilentlyContinue
    ResetDemo
    CheckRun 'tests\db_audit_prerequisites.php'
} finally {
    if($webProcess -and !$webProcess.HasExited) { Stop-Process -Id $webProcess.Id }
    if($dbProcess -and !$dbProcess.HasExited) { & $mysqladmin @argsMysql shutdown 2>$null; if(!$dbProcess.WaitForExit(5000)) { Stop-Process -Id $dbProcess.Id } }
}
