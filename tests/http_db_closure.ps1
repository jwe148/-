param(
    [string]$BaseUrl = 'http://127.0.0.1:8766',
    [string]$PhpPath = 'D:\phpstudy_pro\Extensions\php\php8.0.2nts\php.exe'
)

$ErrorActionPreference = 'Stop'

function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}

$student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student02';password='Demo@2026'} -WebSession $student -UseBasicParsing
$selection = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $student -UseBasicParsing
$studentToken = [regex]::Match($selection.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$body = "token=$studentToken&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=HCI326-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$saved = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $student -UseBasicParsing
Check ($saved.Content.Contains('选课方案已保存')) 'student has one primary course that will be cancelled'

$teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $teacher -UseBasicParsing
$teaching = Invoke-WebRequest "$BaseUrl/teaching.php" -WebSession $teacher -UseBasicParsing
$teacherToken = [regex]::Match($teaching.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value

$admin = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=admin" -Method Post -Body @{username='admin01';password='Demo@2026'} -WebSession $admin -UseBasicParsing
$preview = Invoke-WebRequest "$BaseUrl/admin_closure.php" -WebSession $admin -UseBasicParsing
$adminToken = [regex]::Match($preview.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($adminToken.Length -eq 32 -and $preview.Content.Contains('截止后才能操作')) 'admin sees preview before deadline'
$early = Invoke-WebRequest "$BaseUrl/admin_closure.php" -Method Post -Body @{token=$adminToken} -WebSession $admin -UseBasicParsing
Check ($early.Content.Contains('选课截止后才能关闭')) 'early close rejected without changes'

& $PhpPath "$PSScriptRoot\advance_demo_deadline.php" --disposable-demo
if ($LASTEXITCODE -ne 0) { throw 'Failed to advance disposable demo deadline' }

$closed = Invoke-WebRequest "$BaseUrl/admin_closure.php" -Method Post -Body @{token=$adminToken} -WebSession $admin -UseBasicParsing
Check ($closed.BaseResponse.ResponseUri.Query -eq '?closed=1' -and $closed.Content.Contains('选课已关闭')) 'admin closes semester'
Check ($closed.Content.Contains('停开：无教师') -and $closed.Content.Contains('停开：不足 3 人')) 'no-teacher and low-count courses cancelled'
Check ($closed.Content.Contains('备选补入 1')) 'full first backup skipped and second backup promoted'

$schedule = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $student -UseBasicParsing
Check ($schedule.Content.Contains('当前首选教学班，共 4 门') -and $schedule.Content.Contains('数据处理实践')) 'student final schedule contains promoted backup'
Check ($schedule.Content.Contains('人机交互：无教师，已停开') -and $schedule.Content.Contains('数据处理实践：由备选补入')) 'student sees closure result'
Check ($schedule.Content.Contains('名额已满，未补入') -and $schedule.Content.Contains('备选 2：数据处理实践')) 'student sees backup attempt reasons'
$afterSelection = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $student -UseBasicParsing
Check ($afterSelection.Content.Contains('当前不在选课时间内')) 'student cannot resubmit after close'
$claimAfter = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$teacherToken;action='claim';id='UX328-01'} -WebSession $teacher -UseBasicParsing
Check ($claimAfter.Content.Contains('选课已结束')) 'teacher cannot claim after close'

$again = Invoke-WebRequest "$BaseUrl/admin_closure.php" -Method Post -Body @{token=$adminToken} -WebSession $admin -UseBasicParsing
Check ($again.BaseResponse.ResponseUri.Query -eq '?already=1' -and $again.Content.Contains('备选补入 1')) 'repeated close does not allocate again'

$forbidden = 0
try { $null = Invoke-WebRequest "$BaseUrl/admin_closure.php" -WebSession $student -UseBasicParsing } catch { $forbidden = [int]$_.Exception.Response.StatusCode }
Check ($forbidden -eq 403) 'student cannot access admin closure'
