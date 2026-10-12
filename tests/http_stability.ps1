param(
    [string]$BaseUrl = 'http://127.0.0.1:8765',
    [switch]$DatabaseMode,
    [switch]$OutageOnly,
    [string]$CookieFile = ''
)
$ErrorActionPreference = 'Stop'
function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}
function Request([string]$Path, $Session, [string]$Method = 'GET', $Body = $null) {
    $params = @{ Uri="$BaseUrl/$Path"; WebSession=$Session; UseBasicParsing=$true; Method=$Method; TimeoutSec=15 }
    if ($null -ne $Body) { $params.Body=$Body; $params.ContentType='application/x-www-form-urlencoded' }
    try { return Invoke-WebRequest @params } catch {
        if ($null -eq $_.Exception.Response) { throw }
        $response = $_.Exception.Response
        $reader = [IO.StreamReader]::new($response.GetResponseStream())
        try { $content = $reader.ReadToEnd() } finally { $reader.Dispose() }
        return [pscustomobject]@{ StatusCode=[int]$response.StatusCode; Content=$content }
    }
}
if ($OutageOnly) {
    $guest = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $failed = Request 'login.php' $guest 'POST' @{username='student01';password='Demo@2026'}
    Check ($failed.StatusCode -eq 200 -and $failed.Content.Contains('数据库暂时无法连接')) 'database outage gives controlled login error'
    Check (-not $failed.Content.Contains('SQLSTATE') -and -not $failed.Content.Contains('Fatal error')) 'database outage does not leak internal exception'
    $protected = Request 'selection.php' $guest
    Check ($protected.StatusCode -eq 200 -and $protected.Content.Contains('请输入学号或工号')) 'outage does not allow unauthenticated access'
    if ($CookieFile -ne '') {
        $saved = New-Object Microsoft.PowerShell.Commands.WebRequestSession
        $cookie = [Net.Cookie]::new('course_registration_demo', (Get-Content -LiteralPath $CookieFile -Raw).Trim(), '/', '127.0.0.1')
        $saved.Cookies.Add($cookie)
        foreach ($path in @('courses.php','selection.php','schedule.php','grades.php')) {
            $page = Request $path $saved
            Check ($page.StatusCode -eq 503 -and $page.Content.Contains('课程数据暂不可用')) "authenticated outage handled: $path"
            Check (-not $page.Content.Contains('SQLSTATE') -and -not $page.Content.Contains('Fatal error')) "authenticated outage hides internals: $path"
        }
        $available = Request 'selection_availability.php' $saved
        Check ($available.StatusCode -eq 503 -and -not $available.Content.Contains('SQLSTATE')) 'availability fails safely during database outage'
        $write = Request 'selection.php' $saved 'POST' @{token='old';primary='SE301-01'}
        Check ($write.StatusCode -eq 503) 'authenticated write fails safely during database outage'
    }
    exit 0
}
$guest = New-Object Microsoft.PowerShell.Commands.WebRequestSession
foreach ($path in @('index.php','courses.php','course.php','selection.php','schedule.php','grades.php','teaching.php','grade_entry.php','admin_closure.php')) {
    $page = Request $path $guest
    Check ($page.StatusCode -eq 200 -and $page.Content.Contains('请输入学号或工号')) "anonymous redirected: $path"
}
foreach ($body in @(@{username='student01';password='wrong'}, @{username="' OR 1=1 --";password='wrong'}, 'username%5B%5D=student01&password%5B%5D=x')) {
    $page = Request 'login.php?role%5B%5D=student' $guest 'POST' $body
    Check ($page.StatusCode -eq 200 -and $page.Content.Contains('账号、密码或身份不正确')) 'invalid login rejected without server error'
}
$student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login = Request 'login.php?role=student' $student 'POST' @{username='student01';password='Demo@2026'}
Check ($login.StatusCode -eq 200 -and $login.Content.Contains('action="logout.php"')) 'student login for stability checks'
foreach ($path in @('teaching.php','grade_entry.php','admin_closure.php')) {
    Check ((Request $path $student).StatusCode -eq 403) "student access forbidden: $path"
}
foreach ($path in @('course.php?id=missing','course.php?id%5B%5D=x')) {
    Check ((Request $path $student).StatusCode -eq 404) "unknown or malformed offering: $path"
}
foreach ($path in @('courses.php?q%5B%5D=x&day%5B%5D=x','selection.php?offering%5B%5D=x')) {
    Check ((Request $path $student).StatusCode -eq 200) "array query handled: $path"
}
$escaped = Request 'courses.php?q=%3Cscript%3Ealert(1)%3C%2Fscript%3E' $student
Check (-not $escaped.Content.Contains('<script>alert(1)</script>') -and $escaped.Content.Contains('&lt;script&gt;')) 'search output escapes markup'
$selection = Request 'selection.php' $student
$token = [regex]::Match($selection.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($token.Length -eq 32 -and $selection.Content.Contains('2026年11月01日 17:00')) 'selection token and extended deadline shown'
$before = (Request 'schedule.php' $student).Content
foreach ($body in @('primary%5B%5D=SE301-01', 'token%5B%5D=x&primary%5B%5D=SE301-01', "token=$token&primary=SE301-01&backup=OS312-01", "token=$token&primary%5B0%5D%5Bnested%5D=x&backup%5B%5D=OS312-01")) {
    $rejected = Request 'selection.php' $student 'POST' $body
    Check ($rejected.StatusCode -eq 200 -and $rejected.Content.Contains('提交失败')) 'malformed selection rejected without server error'
    Check ((Request 'schedule.php' $student).Content -eq $before) 'malformed selection leaves schedule unchanged'
}
$invalidDrop = Request 'schedule.php' $student 'POST' @{token='bad';id='SE301-01'}
Check ($invalidDrop.StatusCode -eq 200 -and $invalidDrop.Content.Contains('页面已过期')) 'invalid drop token rejected'
Check ((Request 'schedule.php' $student).Content -eq $before) 'invalid drop does not mutate schedule'
$ownGrades = (Request 'grades.php' $student).Content
Check ((Request 'grades.php?student_id=999999&username=student03' $student).Content -eq $ownGrades) 'query parameters cannot select another student grades'
Check ((Request 'logout.php' $student).StatusCode -eq 405) 'GET logout rejected'
$teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$wrongRole = Request 'login.php?role=student' $teacher 'POST' @{username='teacher01';password='Demo@2026'}
Check ($wrongRole.Content.Contains('账号、密码或身份不正确')) 'valid account with wrong role rejected'
$null = Request 'login.php?role=teacher' $teacher 'POST' @{username='teacher01';password='Demo@2026'}
$teaching = Request 'teaching.php' $teacher
$teacherToken = [regex]::Match($teaching.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
foreach ($body in @(@{token='bad';action='claim';id='HCI326-01'}, "token=$teacherToken&action%5B%5D=claim&id%5B%5D=HCI326-01", "token%5B%5D=bad&action=claim&id=HCI326-01")) {
    $page = Request 'teaching.php' $teacher 'POST' $body
    Check ($page.StatusCode -eq 200 -and $page.Content.Contains('role="alert"')) 'malformed teacher request rejected without server error'
}
Check ((Request 'teaching.php' $teacher).Content -eq $teaching.Content) 'malformed teacher requests do not change claims'
if ($DatabaseMode) {
    $emptyStudent = New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $null = Request 'login.php?role=student' $emptyStudent 'POST' @{username='student03';password='Demo@2026'}
    Check ((Request 'grades.php' $emptyStudent).Content.Contains('暂无成绩')) 'student without grades sees empty state'
    $gradePage = Request 'grade_entry.php?offering=PRE-PROG-2025' $teacher
    foreach ($body in @("token=$teacherToken&offering_id%5B%5D=x&enrollment_id%5B%5D=1&grade%5B%5D=A", @{token='bad';offering_id='PRE-PROG-2025';enrollment_id='1';grade='A'})) {
        $page = Request 'grade_entry.php' $teacher 'POST' $body
        Check ($page.StatusCode -eq 200 -and $page.Content.Contains('role="alert"')) 'malformed grade request rejected without server error'
    }
    Check ((Request 'grade_entry.php?offering=PRE-PROG-2025' $teacher).Content -eq $gradePage.Content) 'malformed grade requests leave roster unchanged'
}
if ($DatabaseMode) {
    $body = "token=$token&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
    for ($i=0; $i -lt 10; $i++) {
        $saved = Request 'selection.php' $student 'POST' $body
        Check ($saved.StatusCode -eq 200 -and $saved.Content.Contains('选课方案已保存')) "repeat selection submission $i"
    }
    Check ((Request 'schedule.php' $student).Content -eq $before) 'repeat submissions preserve same schedule'
}
$null = Request 'logout.php' $student 'POST'
$afterLogout = Request 'selection.php' $student
Check ($afterLogout.Content.Contains('请输入学号或工号')) 'logged-out session cannot access protected page'
$null = Request 'login.php?role=student' $student 'POST' @{username='student01';password='Demo@2026'}
$oldToken = Request 'selection.php' $student 'POST' @{token=$token}
Check ($oldToken.StatusCode -eq 200 -and $oldToken.Content.Contains('页面已过期')) 'token from previous login rejected'
