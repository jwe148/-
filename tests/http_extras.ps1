param([string]$BaseUrl)
$ErrorActionPreference='Stop'
function Check([bool]$ok,[string]$name) { if(-not $ok){throw "FAIL $name"}; Write-Output "PASS $name" }
function Login([string]$role,[string]$name) {
 $s=New-Object Microsoft.PowerShell.Commands.WebRequestSession
 $null=Invoke-WebRequest "$BaseUrl/login.php?role=$role" -Method Post -Body @{username=$name;password='Demo@2026'} -WebSession $s -UseBasicParsing
 return $s
}
function Page([string]$path,$s) { return Invoke-WebRequest "$BaseUrl/$path" -WebSession $s -UseBasicParsing }
$s=Login student student02
$p=Page selection.php $s
$token=[regex]::Match($p.Content,'name="token" value="([a-f0-9]+)"').Groups[1].Value
$p=Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body "token=$token&action=draft&primary%5B%5D=SE301-01&backup%5B%5D=" -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('草稿已保存')) 'HTTP incomplete draft save'
Check ((Page schedule.php $s).Content.Contains('课表暂无课程')) 'HTTP draft has no enrollment'
$null=Invoke-WebRequest "$BaseUrl/logout.php" -Method Post -WebSession $s -UseBasicParsing
$s=Login student student02
$p=Page selection.php $s
Check ($p.Content.Contains('载入未提交草稿') -and ($p.Content -match '<option[^>]*value="SE301-01"[^>]*data-course-code="SE301"[^>]* selected(?:\s|>)')) 'HTTP draft survives login'
$token=[regex]::Match($p.Content,'name="token" value="([a-f0-9]+)"').Groups[1].Value
$p=Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body @{token=$token;action='delete'} -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('请确认删除整份课表')) 'HTTP whole delete requires confirmation'
$p=Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body @{token='bad';action='delete';confirm='yes'} -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('页面已过期')) 'HTTP whole delete checks token'
$p=Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body @{token=$token;action='delete';confirm='yes'} -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('课表、备选和草稿已清空')) 'HTTP confirmed deletion'
Check (-not (Page selection.php $s).Content.Contains('载入未提交草稿')) 'HTTP deletion clears draft'
$catalog=Page catalog_api.php $s
Check (($catalog.Content|ConvertFrom-Json).courses.Count -gt 0) 'HTTP read-only catalog returns definitions'
$rejected=$false
try {$null=Invoke-WebRequest "$BaseUrl/catalog_api.php" -Method Post -WebSession $s -UseBasicParsing}catch {$rejected=$_.Exception.Response.StatusCode.value__ -eq 405}
Check $rejected 'HTTP catalog forbids writes'
$a=Login admin admin01
$p=Page admin_extras.php $a
Check ($p.Content.Contains('任教资格') -and $p.Content.Contains('计费发送记录')) 'HTTP admin extras page'
$token=[regex]::Match($p.Content,'name="token" value="([a-f0-9]+)"').Groups[1].Value
$p=Invoke-WebRequest "$BaseUrl/admin_extras.php" -Method Post -Body @{token='bad';action='retry'} -WebSession $a -UseBasicParsing
Check ($p.Content.Contains('页面已过期')) 'HTTP billing action checks token'
$t=Login teacher teacher01
$p=Page teaching.php $t
$teacherToken=[regex]::Match($p.Content,'name="token" value="([a-f0-9]+)"').Groups[1].Value
$offering=[regex]::Match($p.Content,'name="action" value="claim"><input type="hidden" name="id" value="([^"]+)"').Groups[1].Value
Check ($offering -ne '') 'HTTP eligible claim offered'
$p=Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$teacherToken;action='claim';id=$offering} -WebSession $t -UseBasicParsing
Check ($p.Content.Contains('学生名单')) 'HTTP teacher roster links'
$p=Page "roster.php?id=$offering" $t
Check ($p.Content.Contains('在选学生') -and $p.Content.Contains('学号 / 账号')) 'HTTP teacher current roster renders'
$null=Invoke-WebRequest "$BaseUrl/teaching.php" -Method Post -Body @{token=$teacherToken;action='release';id=$offering} -WebSession $t -UseBasicParsing
$rejected=$false
try {$null=Page 'roster.php?id=SE301-01' $t}catch {$rejected=$_.Exception.Response.StatusCode.value__ -eq 403}
Check $rejected 'HTTP teacher cannot read other roster'
exit 0
