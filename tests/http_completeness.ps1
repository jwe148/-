param([string]$BaseUrl = 'http://127.0.0.1:8765')
$ErrorActionPreference = 'Stop'
function Check([bool]$Ok, [string]$Name) {
    if (-not $Ok) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}
function Get-Page([string]$Path, $Session) {
    Invoke-WebRequest "$BaseUrl/$Path" -WebSession $Session -UseBasicParsing -TimeoutSec 15
}
$student = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student01';password='Demo@2026'} -WebSession $student -UseBasicParsing -TimeoutSec 15
$catalog = Get-Page 'courses.php' $student
$details = @([regex]::Matches($catalog.Content, 'href="(course.php\?id=[^"]+)"') | ForEach-Object { [Net.WebUtility]::HtmlDecode($_.Groups[1].Value) })
Check ($details.Count -eq 11 -and @($details | Select-Object -Unique).Count -eq 11) 'catalog contains all eleven unique offerings'
foreach ($detail in $details) {
    $page = Get-Page $detail $student
    Check ($page.StatusCode -eq 200 -and $page.Content.Contains('selection.php?offering=')) "offering detail and selection entry: $detail"
}
$empty = Get-Page 'courses.php?q=NO_SUCH_COURSE_987' $student
Check ($empty.Content.Contains('没有找到教学班') -and $empty.Content.Contains('查看全部教学班')) 'empty search has recovery link'
$caseSearch = Get-Page 'courses.php?q=se301' $student
Check ($caseSearch.Content.Contains('软件工程') -and $caseSearch.Content.Contains('共 1 个')) 'course code search ignores case'
$nameSearch = Get-Page ('courses.php?q=' + [uri]::EscapeDataString('软件工程')) $student
Check ($nameSearch.Content.Contains('共 1 个') -and $nameSearch.Content.Contains('course.php?id=SE301-01')) 'Chinese course name search finds matching offering'
$teacherSearch = Get-Page ('courses.php?q=' + [uri]::EscapeDataString('王老师')) $student
Check ($teacherSearch.Content.Contains('共 1 个') -and $teacherSearch.Content.Contains('course.php?id=SE301-01')) 'Chinese teacher name search finds assigned offering'
$invalidDay = Get-Page 'courses.php?day=invalid' $student
Check ($invalidDay.Content.Contains('共 11 个')) 'invalid weekday safely resets filter'
$monday = Get-Page ('courses.php?day=' + [uri]::EscapeDataString('周一')) $student
Check ($monday.Content.Contains('共 2 个') -and -not $monday.Content.Contains('course.php?id=DB305-01')) 'weekday filter excludes other weekdays'
$combined = Get-Page ('courses.php?q=SE301&day=' + [uri]::EscapeDataString('周五')) $student
Check ($combined.Content.Contains('没有找到教学班')) 'combined search applies keyword and weekday together'
$assets = [Collections.Generic.HashSet[string]]::new()
foreach ($route in @('index.php','courses.php','selection.php','schedule.php','grades.php')) {
    $page = Get-Page $route $student
    Check ($page.StatusCode -eq 200 -and $page.Content -match 'name="viewport"') "student page and viewport: $route"
    foreach ($match in [regex]::Matches($page.Content, '(?:href|src)="(assets/[^"]+)"')) { $null = $assets.Add($match.Groups[1].Value) }
}
Check ($assets.Contains('assets/css/style.css') -and $assets.Contains('assets/js/selection.js')) 'rendered pages reference stylesheet and selection script'
foreach ($asset in $assets) {
    $response = Get-Page $asset $student
    Check ($response.StatusCode -eq 200 -and $response.RawContentLength -gt 0) "static asset available: $asset"
}
foreach ($path in @('database/schema.sql','tests/db_extreme.php','README.md','../database/schema.sql')) {
    $request = [Net.HttpWebRequest]::Create("$BaseUrl/$path")
    $request.AllowAutoRedirect = $false
    try { $response = $request.GetResponse() } catch [Net.WebException] { $response = $_.Exception.Response }
    try { $status = [int]$response.StatusCode } finally { if ($null -ne $response) { $response.Dispose() } }
    Check ($status -in @(302,404)) "non-web project file inaccessible: $path"
}
$teacher = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=teacher" -Method Post -Body @{username='teacher01';password='Demo@2026'} -WebSession $teacher -UseBasicParsing -TimeoutSec 15
foreach ($route in @('index.php','courses.php','teaching.php','teacher_schedule.php','grade_entry.php')) {
    Check ((Get-Page $route $teacher).StatusCode -eq 200) "teacher page available: $route"
}
$admin = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=admin" -Method Post -Body @{username='admin01';password='Demo@2026'} -WebSession $admin -UseBasicParsing -TimeoutSec 15
foreach ($route in @('index.php','courses.php','admin_closure.php')) {
    Check ((Get-Page $route $admin).StatusCode -eq 200) "admin page available: $route"
}
