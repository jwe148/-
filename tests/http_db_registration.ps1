param([string]$BaseUrl = 'http://127.0.0.1:8766')

$ErrorActionPreference = 'Stop'

function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}

$student02 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest "$BaseUrl/login.php?role=student" -WebSession $student02 -UseBasicParsing
Check ($loginPage.Content.Contains('当前使用数据库账号登录')) 'database mode enabled'
$login = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student02';password='Demo@2026'} -WebSession $student02 -UseBasicParsing
Check ($login.BaseResponse.ResponseUri.AbsolutePath -eq '/index.php') 'student02 login'
$empty = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $student02 -UseBasicParsing
Check ($empty.Content.Contains('课表暂无课程')) 'student02 starts empty'
$grades02 = Invoke-WebRequest "$BaseUrl/grades.php" -WebSession $student02 -UseBasicParsing
Check ($grades02.Content.Contains('及格') -and $grades02.Content.Contains('数据结构')) 'student02 grades from database'

$student03 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student03';password='Demo@2026'} -WebSession $student03 -UseBasicParsing
$student03Page = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $student03 -UseBasicParsing
$token03 = [regex]::Match($student03Page.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$body03 = "token=$token03&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$rejected = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body03 -ContentType 'application/x-www-form-urlencoded' -WebSession $student03 -UseBasicParsing
Check ($rejected.Content.Contains('要求先修')) 'unqualified student rejected'
$stillEmpty = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $student03 -UseBasicParsing
Check ($stillEmpty.Content.Contains('课表暂无课程')) 'failed submission has no partial seats'

$selectionPage = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $student02 -UseBasicParsing
$token = [regex]::Match($selectionPage.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($token.Length -eq 32) 'student02 selection token'
$body = "token=$token&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$saved = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $student02 -UseBasicParsing
Check ($saved.Content.Contains('选课方案已保存')) 'student02 saves four primary and full backup'
$full = Invoke-WebRequest "$BaseUrl/courses.php?q=DB305" -WebSession $student02 -UseBasicParsing
Check ($full.Content.Contains('已满')) 'last DB305 seat occupied'

$retryFull = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body03 -ContentType 'application/x-www-form-urlencoded' -WebSession $student03 -UseBasicParsing
Check ($retryFull.Content.Contains('已满额')) 'full primary rejected for another student'

$null = Invoke-WebRequest "$BaseUrl/logout.php" -Method Post -WebSession $student02 -UseBasicParsing
$newSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student02';password='Demo@2026'} -WebSession $newSession -UseBasicParsing
$persisted = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $newSession -UseBasicParsing
Check ($persisted.Content.Contains('当前首选教学班，共 4 门')) 'schedule survives logout'
$newToken = [regex]::Match($persisted.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value

$student01 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student01';password='Demo@2026'} -WebSession $student01 -UseBasicParsing
$student01Page = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $student01 -UseBasicParsing
$token01 = [regex]::Match($student01Page.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$body01 = "token=$token01&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$saved01 = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body01 -ContentType 'application/x-www-form-urlencoded' -WebSession $student01 -UseBasicParsing
Check ($saved01.Content.Contains('选课方案已保存')) 'existing student retains seat in full class'

$removed = Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body "token=$newToken&id=SE301-01" -ContentType 'application/x-www-form-urlencoded' -WebSession $newSession -UseBasicParsing
Check ($removed.Content.Contains('已退课')) 'student02 drops course'
$shared = Invoke-WebRequest "$BaseUrl/courses.php?q=SE301" -WebSession $student01 -UseBasicParsing
Check ($shared.Content.Contains('剩余 3 席')) 'other account sees restored seat'
$selectionAgain = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $newSession -UseBasicParsing
$tokenAgain = [regex]::Match($selectionAgain.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$bodyAgain = "token=$tokenAgain&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$restored = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $bodyAgain -ContentType 'application/x-www-form-urlencoded' -WebSession $newSession -UseBasicParsing
Check ($restored.Content.Contains('选课方案已保存')) 'dropped course can be added again'
