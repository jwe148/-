param([string]$BaseUrl)
$ErrorActionPreference='Stop'
function Check([bool]$ok,[string]$name) { if(-not $ok){throw "FAIL $name"}; Write-Output "PASS $name" }
function Login([string]$role,[string]$name) {
 $s=New-Object Microsoft.PowerShell.Commands.WebRequestSession
 $null=Invoke-WebRequest "$BaseUrl/login.php?role=$role" -Method Post -Body @{username=$name;password='Demo@2026'} -WebSession $s -UseBasicParsing
 return $s
}
function Page([string]$path,$s) { return Invoke-WebRequest "$BaseUrl/$path" -WebSession $s -UseBasicParsing }
function Hidden([string]$html,[string]$name) { return [regex]::Match($html, ('name="'+$name+'" value="([^"]*)"')).Groups[1].Value }
function QualificationForm([string]$html,[string]$action,[string]$teacher='',[string]$course='') {
 foreach($form in [regex]::Matches($html,'(?s)<form\b[^>]*>.*?</form>')) {
  if((Hidden $form.Value 'action') -ne $action) { continue }
  if($teacher -and (Hidden $form.Value 'teacher') -ne $teacher) { continue }
  if($course -and (Hidden $form.Value 'course') -ne $course) { continue }
  return $form.Value
 }
 return ''
}
$s=Login student student02
$p=Page selection.php $s
$token=[regex]::Match($p.Content,'name="token" value="([a-f0-9]+)"').Groups[1].Value
$p=Invoke-WebRequest "$BaseUrl/selection.php" -Method Post -Body "token=$token&action=draft&primary%5B%5D=SE301-01&backup%5B%5D=" -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('草稿已保存')) 'HTTP incomplete draft save'
Check ((Page schedule.php $s).Content.Contains('课表暂无课程')) 'HTTP draft has no enrollment'
$null=Invoke-WebRequest "$BaseUrl/logout.php" -Method Post -WebSession $s -UseBasicParsing
$s=Login student student02
$p=Page selection.php $s
Check ($p.Content.Contains('草稿待提交') -and ($p.Content -match '<option[^>]*value="SE301-01"[^>]*data-course-code="SE301"[^>]* selected(?:\s|>)')) 'HTTP draft survives login'
$token=[regex]::Match($p.Content,'name="token" value="([a-f0-9]+)"').Groups[1].Value
$p=Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body @{token=$token;action='delete'} -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('请确认删除整份课表')) 'HTTP whole delete requires confirmation'
$p=Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body @{token='bad';action='delete';confirm='yes'} -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('页面已过期')) 'HTTP whole delete checks token'
$p=Invoke-WebRequest "$BaseUrl/schedule.php" -Method Post -Body @{token=$token;action='delete';confirm='yes'} -WebSession $s -UseBasicParsing
Check ($p.Content.Contains('课表、备选和草稿已清空')) 'HTTP confirmed deletion'
Check (-not (Page selection.php $s).Content.Contains('草稿待提交')) 'HTTP deletion clears draft'
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
$people=Page 'admin_people.php?role=teacher' $a
$peopleToken=Hidden $people.Content 'token'
$person=@{token=$peopleToken;action='create';username='qual_http_teacher';display_name='王老师';password='QualTeacher@2026';date_of_birth='1980-01-01';social_security_number='QUAL-HTTP-T';person_status='在职';department='计算机学院';graduation_date='';is_active='0'}
$created=Invoke-WebRequest "$BaseUrl/admin_people.php?role=teacher" -Method Post -Body $person -WebSession $a -UseBasicParsing
$qualificationTeacher=Hidden $created.Content 'id'
Check ($created.Content.Contains('已新增教师') -and $qualificationTeacher -match '^[1-9][0-9]*$') 'HTTP disabled teacher fixture created'
$p=Page admin_extras.php $a
$grantForm=QualificationForm $p.Content 'grant'
Check ($grantForm -ne '' -and -not $grantForm.Contains('撤销资格')) 'HTTP grant is separate from existing qualification revocation'
Check ($grantForm.Contains('qual_http_teacher') -and $grantForm.Contains('import_teacher_01') -and $grantForm.Contains('停用')) 'HTTP all teachers available for qualification maintenance'
Check ($grantForm.Contains('PRE-LA')) 'HTTP course codes distinguish grant options'
$grant=@{token=(Hidden $grantForm 'token');action='grant';teacher=$qualificationTeacher;course='PRE-LA'}
$p=Invoke-WebRequest "$BaseUrl/admin_extras.php" -Method Post -Body $grant -WebSession $a -UseBasicParsing
$revokeForm=QualificationForm $p.Content 'revoke' $qualificationTeacher 'PRE-LA'
Check ($revokeForm -ne '' -and $p.Content.Contains('qual_http_teacher')) 'HTTP grant produces a revocation form for the exact teacher and course'
$p=Invoke-WebRequest "$BaseUrl/admin_extras.php" -Method Post -Body $grant -WebSession $a -UseBasicParsing
$forms=@([regex]::Matches($p.Content,'(?s)<form\b[^>]*>.*?</form>')|Where-Object{(Hidden $_.Value 'action') -eq 'revoke' -and (Hidden $_.Value 'teacher') -eq $qualificationTeacher -and (Hidden $_.Value 'course') -eq 'PRE-LA'})
Check ($forms.Count -eq 1) 'HTTP duplicate grant leaves one qualification'
$invalidRevoke=@{token='bad';action='revoke';teacher=$qualificationTeacher;course='PRE-LA'}
$p=Invoke-WebRequest "$BaseUrl/admin_extras.php" -Method Post -Body $invalidRevoke -WebSession $a -UseBasicParsing
Check ($p.Content.Contains('页面已过期') -and (QualificationForm $p.Content 'revoke' $qualificationTeacher 'PRE-LA') -ne '') 'HTTP bad token cannot revoke qualification'
$revoke=@{token=(Hidden $revokeForm 'token');action='revoke';teacher=(Hidden $revokeForm 'teacher');course=(Hidden $revokeForm 'course')}
$p=Invoke-WebRequest "$BaseUrl/admin_extras.php" -Method Post -Body $revoke -WebSession $a -UseBasicParsing
Check ($p.Content.Contains('处理完成') -and (QualificationForm $p.Content 'revoke' $qualificationTeacher 'PRE-LA') -eq '') 'HTTP row action revokes disabled teacher qualification'
$p=Invoke-WebRequest "$BaseUrl/admin_extras.php" -Method Post -Body $revoke -WebSession $a -UseBasicParsing
Check ($p.Content.Contains('处理完成') -and (QualificationForm $p.Content 'revoke' $qualificationTeacher 'PRE-LA') -eq '') 'HTTP repeated revocation is harmless'
$confirmation=Invoke-WebRequest "$BaseUrl/admin_people.php?role=teacher" -Method Post -Body @{token=$peopleToken;action='prepare_delete';id=$qualificationTeacher} -WebSession $a -UseBasicParsing
$null=Invoke-WebRequest "$BaseUrl/admin_people.php?role=teacher" -Method Post -Body @{token=$peopleToken;action='confirm_delete';id=$qualificationTeacher;confirmation=(Hidden $confirmation.Content 'confirmation')} -WebSession $a -UseBasicParsing
Check (-not (Page admin_extras.php $a).Content.Contains('qual_http_teacher')) 'HTTP fixture removed without residual qualifications'
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
