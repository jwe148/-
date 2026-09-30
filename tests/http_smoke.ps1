param([string]$BaseUrl = 'http://127.0.0.1:8765')

$ErrorActionPreference = 'Stop'

function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}

$guest = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$guestPage = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $guest -UseBasicParsing
Check ($guestPage.BaseResponse.ResponseUri.AbsolutePath -eq '/login.php') 'anonymous redirect'

$teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $teacher -UseBasicParsing
$teacherStatus = 0
try { $null = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $teacher -UseBasicParsing } catch { $teacherStatus = [int]$_.Exception.Response.StatusCode }
Check ($teacherStatus -eq 403) 'teacher forbidden from student page'
$teachingPage = Invoke-WebRequest "$BaseUrl/teaching.php" -WebSession $teacher -UseBasicParsing
$teacherToken = [regex]::Match($teachingPage.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($teacherToken.Length -eq 32) 'teacher form token'
$claimed = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$teacherToken;action='claim';id='HCI326-01'} -WebSession $teacher -UseBasicParsing
Check ($claimed.Content.Contains('已认领教学班')) 'teacher claims offering'
$teacherCatalog = Invoke-WebRequest "$BaseUrl/courses.php?q=HCI326" -WebSession $teacher -UseBasicParsing
Check ($teacherCatalog.Content.Contains('演示教师')) 'teacher catalog shows claim'
$conflicted = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$teacherToken;action='claim';id='UX328-01'} -WebSession $teacher -UseBasicParsing
Check ($conflicted.Content.Contains('时间冲突')) 'teacher time conflict'
$released = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$teacherToken;action='release';id='HCI326-01'} -WebSession $teacher -UseBasicParsing
Check ($released.Content.Contains('已取消认领')) 'teacher releases offering'

$student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$studentLogin = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student01';password='Demo@2026'} -WebSession $student -UseBasicParsing
Check ($studentLogin.BaseResponse.ResponseUri.AbsolutePath -eq '/index.php') 'student login'
$studentTeacherStatus = 0
try { $null = Invoke-WebRequest "$BaseUrl/teaching.php" -WebSession $student -UseBasicParsing } catch { $studentTeacherStatus = [int]$_.Exception.Response.StatusCode }
Check ($studentTeacherStatus -eq 403) 'student forbidden from teacher page'

$detail = Invoke-WebRequest "$BaseUrl/course.php?id=SE301-01" -WebSession $student -UseBasicParsing
Check ($detail.Content.Contains('selection.php?offering=SE301-01')) 'course detail links to selection'
$prefilled = Invoke-WebRequest "$BaseUrl/selection.php?offering=SE301-01" -WebSession $student -UseBasicParsing
Check ($prefilled.Content -match '<option[^>]*value="SE301-01"[^>]*selected' -and $prefilled.Content.Contains('保存后才会生效')) 'course prefilled without saving'
$beforeSave = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $student -UseBasicParsing
Check ($beforeSave.Content.Contains('课表暂无课程')) 'prefill does not change schedule'

$selectionPage = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $student -UseBasicParsing
$token = [regex]::Match($selectionPage.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($token.Length -eq 32) 'selection form token'

$body = "token=$token&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$saved = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $student -UseBasicParsing
Check ($saved.Content.Contains('选课方案已保存')) 'save four primary and full backup'

$schedule = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $student -UseBasicParsing
Check ($schedule.Content.Contains('当前首选教学班，共 4 门') -and $schedule.Content.Contains('软件工程')) 'schedule after selection'
$catalog = Invoke-WebRequest "$BaseUrl/courses.php?q=SE301" -WebSession $student -UseBasicParsing
Check ($catalog.Content.Contains('剩余 2 席')) 'remaining seats decrease'
$grades = Invoke-WebRequest "$BaseUrl/grades.php" -WebSession $student -UseBasicParsing
Check ($grades.Content.Contains('暂无成绩') -and $grades.Content.Contains('数据结构')) 'grades page'

$removed = Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body "token=$token&id=SE301-01" -ContentType 'application/x-www-form-urlencoded' -WebSession $student -UseBasicParsing
Check ($removed.Content.Contains('已退课')) 'drop course'
$catalog = Invoke-WebRequest "$BaseUrl/courses.php?q=SE301" -WebSession $student -UseBasicParsing
Check ($catalog.Content.Contains('剩余 3 席')) 'remaining seats restored'

$logout = Invoke-WebRequest "$BaseUrl/logout.php" -Method Post -WebSession $student -UseBasicParsing
Check ($logout.BaseResponse.ResponseUri.AbsolutePath -eq '/login.php') 'logout'
