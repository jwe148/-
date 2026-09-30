param([string]$BaseUrl = 'http://127.0.0.1:8766')

$ErrorActionPreference = 'Stop'

function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}

$teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $teacher -UseBasicParsing
$page = Invoke-WebRequest "$BaseUrl/teaching.php" -WebSession $teacher -UseBasicParsing
$token = [regex]::Match($page.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($token.Length -eq 32 -and $page.Content.Contains('人机交互')) 'teacher can view unassigned course'

$claimed = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$token;action='claim';id='HCI326-01'} -WebSession $teacher -UseBasicParsing
Check ($claimed.BaseResponse.ResponseUri.Query -eq '?claimed=1' -and $claimed.Content.Contains('人机交互')) 'teacher claims course'

$student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student01';password='Demo@2026'} -WebSession $student -UseBasicParsing
$studentView = Invoke-WebRequest "$BaseUrl/course.php?id=HCI326-01" -WebSession $student -UseBasicParsing
Check ($studentView.Content.Contains('演示教师')) 'student sees claimed teacher'

$anotherSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $anotherSession -UseBasicParsing
$persisted = Invoke-WebRequest "$BaseUrl/teaching.php" -WebSession $anotherSession -UseBasicParsing
$anotherToken = [regex]::Match($persisted.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($persisted.Content.Contains('取消认领') -and $anotherToken.Length -eq 32) 'claim survives new login session'

$alreadyClaimed = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$anotherToken;action='claim';id='HCI326-01'} -WebSession $anotherSession -UseBasicParsing
Check ($alreadyClaimed.Content.Contains('你已认领')) 'duplicate claim rejected'

$conflict = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$anotherToken;action='claim';id='UX328-01'} -WebSession $anotherSession -UseBasicParsing
Check ($conflict.Content.Contains('时间冲突')) 'overlapping teaching time rejected'
$otherCourse = Invoke-WebRequest "$BaseUrl/course.php?id=UX328-01" -WebSession $student -UseBasicParsing
Check ($otherCourse.Content.Contains('待认领')) 'conflicting claim has no partial update'

$wrongRelease = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$anotherToken;action='release';id='SE301-01'} -WebSession $anotherSession -UseBasicParsing
Check ($wrongRelease.Content.Contains('只能取消自己认领')) 'other teacher course cannot be released'

$released = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$anotherToken;action='release';id='HCI326-01'} -WebSession $anotherSession -UseBasicParsing
Check ($released.BaseResponse.ResponseUri.Query -eq '?released=1') 'teacher releases claim'
$studentAfter = Invoke-WebRequest "$BaseUrl/course.php?id=HCI326-01" -WebSession $student -UseBasicParsing
Check ($studentAfter.Content.Contains('待认领')) 'student sees available course again'
