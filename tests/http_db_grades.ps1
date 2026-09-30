param([string]$BaseUrl = 'http://127.0.0.1:8766')

$ErrorActionPreference = 'Stop'

function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}

$student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student01';password='Demo@2026'} -WebSession $student -UseBasicParsing
$forbidden = 0
try { $null = Invoke-WebRequest "$BaseUrl/grade_entry.php" -WebSession $student -UseBasicParsing } catch { $forbidden = [int]$_.Exception.Response.StatusCode }
Check ($forbidden -eq 403) 'student cannot open grade entry'

$teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $teacher -UseBasicParsing
$list = Invoke-WebRequest "$BaseUrl/grade_entry.php" -WebSession $teacher -UseBasicParsing
Check ($list.Content.Contains('程序设计基础') -and $list.Content.Contains('数据结构')) 'teacher sees closed confirmed courses'
$roster = Invoke-WebRequest "$BaseUrl/grade_entry.php?offering=PRE-PROG-2025" -WebSession $teacher -UseBasicParsing
$token = [regex]::Match($roster.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$studentId = [regex]::Match($roster.Content, '<td>[^<]*student01</td>[\s\S]*?name="enrollment_id" value="(\d+)"').Groups[1].Value
Check ($token.Length -eq 32 -and $studentId -match '^\d+$' -and $roster.Content.Contains('良好')) 'teacher sees legacy grade and roster'

$otherRoster = Invoke-WebRequest "$BaseUrl/grade_entry.php?offering=PRE-DS-2025" -WebSession $teacher -UseBasicParsing
$otherId = [regex]::Match($otherRoster.Content, '<td>[^<]*student01</td>[\s\S]*?name="enrollment_id" value="(\d+)"').Groups[1].Value
Check ($otherId -match '^\d+$' -and $otherId -ne $studentId) 'second course has separate enrollment'

$wrongClass = Invoke-WebRequest "$BaseUrl/grade_entry.php" -Method Post -Body @{token=$token;offering_id='PRE-PROG-2025';enrollment_id=$otherId;grade='A'} -WebSession $teacher -UseBasicParsing
Check ($wrongClass.Content.Contains('不在当前教学班')) 'cross-course enrollment rejected'
$invalid = Invoke-WebRequest "$BaseUrl/grade_entry.php" -Method Post -Body @{token=$token;offering_id='PRE-PROG-2025';enrollment_id=$studentId;grade='Z'} -WebSession $teacher -UseBasicParsing
Check ($invalid.Content.Contains('请选择有效的学生和成绩等级')) 'invalid grade rejected'

$teaching = Invoke-WebRequest "$BaseUrl/teaching.php" -WebSession $teacher -UseBasicParsing
$claimToken = [regex]::Match($teaching.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$null = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$claimToken;action='claim';id='HCI326-01'} -WebSession $teacher -UseBasicParsing
$openClass = Invoke-WebRequest "$BaseUrl/grade_entry.php" -Method Post -Body @{token=$token;offering_id='HCI326-01';enrollment_id=$studentId;grade='A'} -WebSession $teacher -UseBasicParsing
Check ($openClass.Content.Contains('只能录入本人已结课教学班')) 'open semester grade write rejected'
$null = Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$claimToken;action='release';id='HCI326-01'} -WebSession $teacher -UseBasicParsing

$saved = Invoke-WebRequest "$BaseUrl/grade_entry.php" -Method Post -Body @{token=$token;offering_id='PRE-PROG-2025';enrollment_id=$studentId;grade='A'} -WebSession $teacher -UseBasicParsing
Check ($saved.BaseResponse.ResponseUri.Query.Contains('saved=1') -and $saved.Content.Contains('当前成绩')) 'letter grade saved'
$studentView = Invoke-WebRequest "$BaseUrl/grades.php" -WebSession $student -UseBasicParsing
Check ($studentView.Content.Contains('<td>程序设计基础</td><td>A</td>')) 'student immediately sees new grade'

$cleared = Invoke-WebRequest "$BaseUrl/grade_entry.php" -Method Post -Body @{token=$token;offering_id='PRE-PROG-2025';enrollment_id=$studentId;grade='clear'} -WebSession $teacher -UseBasicParsing
Check ($cleared.BaseResponse.ResponseUri.Query.Contains('saved=1') -and $cleared.Content.Contains('未录入')) 'grade can be cleared'
$studentAfter = Invoke-WebRequest "$BaseUrl/grades.php" -WebSession $student -UseBasicParsing
Check (-not $studentAfter.Content.Contains('<td>程序设计基础</td>')) 'cleared grade disappears for student'
