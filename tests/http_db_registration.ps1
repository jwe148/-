param([string]$BaseUrl = 'http://127.0.0.1:8766')

$ErrorActionPreference = 'Stop'

function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}

function Read-Availability($Session) {
    $response = Invoke-WebRequest "$BaseUrl/selection_availability.php" -WebSession $Session -UseBasicParsing
    if (-not $response.Headers['Cache-Control'].Contains('no-store')) { throw 'FAIL availability cache control' }
    return ($response.Content | ConvertFrom-Json)
}
function Reject-Availability($Session, [string]$Method, [int]$Expected, [string]$Name) {
    $status = 0
    try { $null = Invoke-WebRequest "$BaseUrl/selection_availability.php" -Method $Method -WebSession $Session -UseBasicParsing }
    catch { $status = [int]$_.Exception.Response.StatusCode }
    Check ($status -eq $Expected) $Name
}
$anonymous = New-Object Microsoft.PowerShell.Commands.WebRequestSession
Reject-Availability $anonymous 'GET' 401 'availability requires student login'

$student02 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest "$BaseUrl/login.php?role=student" -WebSession $student02 -UseBasicParsing
Check (-not $loginPage.Content.Contains('class="demo-credentials"')) 'database mode enabled'
$login = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student02';password='Demo@2026'} -WebSession $student02 -UseBasicParsing
Check ($login.BaseResponse.ResponseUri.AbsolutePath -eq '/index.php') 'student02 login'
$availability = Read-Availability $student02
Check ($availability.open -and $availability.offerings.'DB305-01'.remaining -eq 1 -and -not $availability.offerings.'DB305-01'.owned) 'availability reads fresh initial capacity'
Check (@($availability.offerings.PSObject.Properties).Count -eq 11 -and @($availability.offerings.'DB305-01'.PSObject.Properties.Name).Count -eq 3) 'availability exposes only counts status and own seat flag'
Reject-Availability $student02 'POST' 405 'availability endpoint rejects writes'
$teacherSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $teacherSession -UseBasicParsing
Reject-Availability $teacherSession 'GET' 403 'teacher cannot read student availability'

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
Check ($selectionPage.Content.Contains('data-availability-url="selection_availability.php"') -and $selectionPage.Content.Contains('id="selection-refresh"')) 'selection page enables live availability'

$body = "token=$token&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$saved = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $student02 -UseBasicParsing
Check ($saved.Content.Contains('选课方案已保存')) 'student02 saves four primary and full backup'
$full = Invoke-WebRequest "$BaseUrl/courses.php?q=DB305" -WebSession $student02 -UseBasicParsing
Check ($full.Content.Contains('已满')) 'last DB305 seat occupied'
$mine = Read-Availability $student02
$other = Read-Availability $student03
Check ($mine.offerings.'DB305-01'.remaining -eq 0 -and $mine.offerings.'DB305-01'.owned) 'full class retains own seat flag'
Check ($other.offerings.'DB305-01'.remaining -eq 0 -and -not $other.offerings.'DB305-01'.owned) 'another session sees filled class without own seat'


$retryFull = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body03 -ContentType 'application/x-www-form-urlencoded' -WebSession $student03 -UseBasicParsing
Check ($retryFull.Content.Contains('已满额')) 'full primary rejected for another student'

$null = Invoke-WebRequest "$BaseUrl/logout.php" -Method Post -WebSession $student02 -UseBasicParsing
$newSession = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student02';password='Demo@2026'} -WebSession $newSession -UseBasicParsing
$persisted = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $newSession -UseBasicParsing
Check ($persisted.Content.Contains('已选 4 门课程') -and $persisted.Content.Contains('软件工程')) 'schedule survives logout'
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
$afterDrop = Read-Availability $newSession
Check ($afterDrop.offerings.'SE301-01'.remaining -eq 3 -and -not $afterDrop.offerings.'SE301-01'.owned) 'availability reflects released seat and own enrollment removal'

$selectionAgain = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $newSession -UseBasicParsing
$tokenAgain = [regex]::Match($selectionAgain.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
$bodyAgain = "token=$tokenAgain&primary%5B%5D=SE301-01&primary%5B%5D=DB305-01&primary%5B%5D=CN309-01&primary%5B%5D=AI320-01&backup%5B%5D=OS312-01&backup%5B%5D=DSP342-01"
$restored = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $bodyAgain -ContentType 'application/x-www-form-urlencoded' -WebSession $newSession -UseBasicParsing
Check ($restored.Content.Contains('选课方案已保存')) 'dropped course can be added again'
