param([string]$BaseUrl = 'http://127.0.0.1:8766')
$ErrorActionPreference = 'Stop'
function Check([bool]$Condition, [string]$Name) {
    if (-not $Condition) { throw "FAIL $Name" }
    Write-Output "PASS $Name"
}
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null = Invoke-WebRequest "$BaseUrl/login.php?role=student" -Method Post -Body @{username='student02';password='Demo@2026'} -WebSession $session -UseBasicParsing
$page = Invoke-WebRequest "$BaseUrl/selection.php" -WebSession $session -UseBasicParsing
$token = [regex]::Match($page.Content, 'name="token" value="([a-f0-9]+)"').Groups[1].Value
Check ($token.Length -eq 32) 'student login and token'
$fields = [regex]::Matches($page.Content, '<select\b[^>]*name="(?:primary|backup)\[\]"[^>]*>')
Check ($fields.Count -eq 6 -and @($fields | Where-Object { $_.Value -match '\brequired\b' }).Count -eq 0) 'all selection fields are optional'
$primary = @('SE301-01','DB305-01','CN309-01','AI320-01')
foreach ($count in 0..4) {
    $body = "token=$token"
    for ($i=0; $i -lt 4; $i++) { $body += '&primary%5B%5D=' + $(if ($i -lt $count) { $primary[$i] } else { '' }) }
    $body += '&backup%5B%5D=&backup%5B%5D='
    $saved = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $session -UseBasicParsing
    Check ($saved.Content.Contains('选课方案已保存')) "HTTP submit $count courses with blank fields"
    $schedule = Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $session -UseBasicParsing
    Check ($(if ($count -eq 0) { $schedule.Content.Contains('课表暂无课程') } else { $schedule.Content.Contains("已选 $count 门课程") })) "HTTP schedule contains $count courses"
}
$before = (Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $session -UseBasicParsing).Content
$body = "token=$token" + (($primary + 'WEB337-01' | ForEach-Object { '&primary%5B%5D=' + $_ }) -join '')
$rejected = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body $body -ContentType 'application/x-www-form-urlencoded' -WebSession $session -UseBasicParsing
Check ($rejected.Content.Contains('首选最多选择 4 个教学班')) 'HTTP fifth primary rejected'
Check ((Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $session -UseBasicParsing).Content -eq $before) 'HTTP rejection preserves schedule'
$null = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body "token=$token&primary%5B%5D=SE301-01&primary%5B%5D=&primary%5B%5D=&primary%5B%5D=&backup%5B%5D=&backup%5B%5D=" -ContentType 'application/x-www-form-urlencoded' -WebSession $session -UseBasicParsing
Check ((Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $session -UseBasicParsing).Content.Contains('已选 1 门课程')) 'HTTP reduce four courses to one'
$null = Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body "token=$token" -WebSession $session -UseBasicParsing
Check ((Invoke-WebRequest "$BaseUrl/schedule.php" -WebSession $session -UseBasicParsing).Content.Contains('课表暂无课程')) 'HTTP clear all courses'
$null = Invoke-WebRequest "$BaseUrl/logout.php" -Method Post -WebSession $session -UseBasicParsing
