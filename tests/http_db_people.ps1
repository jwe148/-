param([string]$BaseUrl='http://127.0.0.1:8765')
$ErrorActionPreference='Stop'
function Check([bool]$Ok,[string]$Name) { if (-not $Ok) { throw "FAIL $Name" }; Write-Output "PASS $Name" }
function Request([string]$Path,$Session,[string]$Method='GET',$Body=$null) {
    $requestParams=@{Uri="$BaseUrl/$Path";WebSession=$Session;UseBasicParsing=$true;Method=$Method;TimeoutSec=15}
    if ($null -ne $Body) { $requestParams.Body=$Body; $requestParams.ContentType='application/x-www-form-urlencoded' }
    try { Invoke-WebRequest @requestParams } catch {
        if ($null -eq $_.Exception.Response) { throw }
        $response=$_.Exception.Response; $reader=[IO.StreamReader]::new($response.GetResponseStream())
        try { $content=$reader.ReadToEnd() } finally { $reader.Dispose() }
        [pscustomobject]@{StatusCode=[int]$response.StatusCode;Content=$content}
    }
}
function Hidden($Page,[string]$Name) { [regex]::Match($Page.Content, ('name="'+$Name+'" value="([^"]*)"')).Groups[1].Value }
function Form([string]$Role,[string]$Username,[string]$Identity) {
    @{token=$script:Token;action='create';revision='0';username=$Username;display_name='网页测试人员';password='WebPeople@2026';date_of_birth='2000-02-29';social_security_number=$Identity;person_status='正常';department=$(if ($Role -eq 'teacher') {'计算机学院'} else {''});graduation_date=$(if ($Role -eq 'student') {'2027-06-30'} else {''});is_active='1'}
}
$guest=New-Object Microsoft.PowerShell.Commands.WebRequestSession
Check ((Request 'admin_people.php' $guest).Content.Contains('请输入学号或工号')) 'anonymous cannot access personnel'
foreach ($role in @('student','teacher')) {
    $session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
    $null=Request "login.php?role=$role" $session 'POST' @{username=($role+'01');password='Demo@2026'}
    Check ((Request 'admin_people.php' $session).StatusCode -eq 403) "$role cannot access personnel"
    Check ((Request 'admin_people.php' $session 'POST' @{action='create'}).StatusCode -eq 403) "$role cannot write personnel"
}
$admin=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$null=Request 'login.php?role=admin' $admin 'POST' @{username='admin01';password='Demo@2026'}
$list=Request 'admin_people.php?role=student' $admin
$script:Token=Hidden $list 'token'
Check ($list.StatusCode -eq 200 -and $Token.Length -eq 32 -and $list.Content.Contains('人员资料')) 'admin list and CSRF token'
Check (-not $list.Content.Contains('password_hash') -and -not $list.Content.Contains('social_security_number')) 'list omits password and identity fields'
foreach ($path in @('admin_people.php?role=admin','admin_people.php?role%5B%5D=student')) {
    Check ((Request $path $admin).StatusCode -eq 400) "invalid person role rejected: $path"
}
Check ((Request 'admin_people.php?view=edit&id=999999' $admin).StatusCode -eq 404) 'missing edit target returns controlled 404'
Check ((Request 'admin_people.php?view=edit&id%5B%5D=1' $admin).StatusCode -eq 404) 'array edit ID returns controlled 404'
Check ((Request 'admin_people.php?q=%3Cscript%3Ebad%3C%2Fscript%3E' $admin).Content.Contains('&lt;script&gt;')) 'search query escapes HTML'
Check ((Request 'admin_people.php?q%5B%5D=x&page%5B%5D=2' $admin).StatusCode -eq 200) 'array search and page handled safely'
Check ((Request 'admin_people.php' $admin 'PUT').StatusCode -eq 405) 'unsupported method rejected'
$bad=Form 'student' 'web_people_student' 'WEB-S'; $bad.token='bad'
Check ((Request 'admin_people.php' $admin 'POST' $bad).Content.Contains('页面已过期')) 'missing or invalid CSRF blocks creation'
$bad=Form 'student' 'web_people_student' 'WEB-S'; $bad.date_of_birth='2023-02-29'
$invalid=Request 'admin_people.php' $admin 'POST' $bad
Check ($invalid.Content.Contains('出生日期无效') -and -not $invalid.Content.Contains('value="WebPeople@2026"')) 'invalid date rejected and password never echoed'
$array=Request 'admin_people.php' $admin 'POST' "token=$Token&action=create&username%5B%5D=x"
Check ($array.StatusCode -eq 200 -and $array.Content.Contains('资料字段无效')) 'array profile field rejected without exception'
Check ((Request 'admin_people.php?q=web_people_student' $admin).Content.Contains('共 0 人')) 'rejected creations leave no account'
$s=Form 'student' 'web_people_student' 'WEB-S'
$saved=Request 'admin_people.php?role=student' $admin 'POST' $s
$studentId=Hidden $saved 'id'; $rev=Hidden $saved 'revision'
Check ($saved.Content.Contains('已新增学生') -and $studentId -match '^[1-9][0-9]*$' -and $rev -eq '1') 'student created with generated system ID'
Check ($saved.Content.Contains('value="2000-02-29"') -and $saved.Content.Contains('value="2027-06-30"')) 'student full profile displayed'
$dup=Form 'student' 'web_people_duplicate' 'WEB-S'
Check ((Request 'admin_people.php' $admin 'POST' $dup).Content.Contains('账号或证件号已存在')) 'duplicate identity rejected'
Check ((Request 'admin_people.php?q=web_people_duplicate' $admin).Content.Contains('共 0 人')) 'duplicate profile insertion rolls back account'
$student=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$login=Request 'login.php?role=student' $student 'POST' @{username='web_people_student';password='WebPeople@2026'}
Check ($login.Content.Contains('action="logout.php"')) 'created student can login'
$s.action='update'; $s.id=$studentId; $s.revision='1'; $s.password=''; $s.display_name='<script>姓名</script>'
$updated=Request 'admin_people.php' $admin 'POST' $s
Check ($updated.Content.Contains('已保存学生') -and (Hidden $updated 'revision') -eq '2' -and $updated.Content.Contains('&lt;script&gt;姓名&lt;/script&gt;') -and -not $updated.Content.Contains('<script>姓名</script>')) 'edit persists and escapes stored markup'
Check ((Request 'index.php' $student).Content.Contains('&lt;script&gt;姓名&lt;/script&gt;')) 'existing session refreshes changed display name'
Check ((Request 'admin_people.php' $admin 'POST' $s).Content.Contains('资料已被其他操作修改')) 'stale editor cannot overwrite newer profile'
$s.revision='2'; $s.password='ResetPeople@2026'
$updated=Request 'admin_people.php' $admin 'POST' $s
Check ((Hidden $updated 'revision') -eq '3') 'password reset increments profile revision'
Check ((Request 'schedule.php' $student).Content.Contains('请输入学号或工号')) 'password reset revokes existing session'
Check ((Request 'login.php?role=student' $student 'POST' @{username='web_people_student';password='WebPeople@2026'}).Content.Contains('账号、密码或身份不正确')) 'old password no longer authenticates'
Check ((Request 'login.php?role=student' $student 'POST' @{username='web_people_student';password='ResetPeople@2026'}).Content.Contains('action="logout.php"')) 'reset password authenticates'
$s.revision='3'; $s.password=''; $s.is_active='0'
$updated=Request 'admin_people.php' $admin 'POST' $s
Check ((Hidden $updated 'revision') -eq '4') 'admin can disable account'
Check ((Request 'grades.php' $student).Content.Contains('请输入学号或工号')) 'disable revokes existing session'
Check ((Request 'login.php?role=student' $student 'POST' @{username='web_people_student';password='ResetPeople@2026'}).Content.Contains('账号、密码或身份不正确')) 'disabled account cannot login'
$s.revision='4'; $s.is_active='1'
$updated=Request 'admin_people.php' $admin 'POST' $s
Check ((Hidden $updated 'revision') -eq '5') 'admin can re-enable account'
$null=Request 'login.php?role=student' $student 'POST' @{username='web_people_student';password='ResetPeople@2026'}
$before=(Request "admin_people.php?view=edit&id=$studentId" $admin).Content
Check ((Request "admin_people.php?action=confirm_delete&id=$studentId" $admin).StatusCode -eq 200) 'GET delete-looking query does not delete'
Check ((Request "admin_people.php?view=edit&id=$studentId" $admin).Content -eq $before) 'GET leaves profile unchanged'
$confirmation=Request 'admin_people.php' $admin 'POST' @{token=$Token;action='prepare_delete';id=$studentId}
$nonce=Hidden $confirmation 'confirmation'
Check ($confirmation.Content.Contains('确认删除学生') -and $nonce.Length -eq 32) 'delete opens dedicated confirmation'
Check ((Request 'admin_people.php' $admin 'POST' @{token=$Token;action='confirm_delete';id=$studentId;confirmation='bad'}).Content.Contains('删除确认已失效')) 'wrong delete confirmation rejected'
$cancelled=Request 'admin_people.php' $admin 'POST' @{token=$Token;action='cancel_delete'}
Check ($cancelled.Content.Contains('删除已取消')) 'cancel deletion returns to list'
Check ((Request "admin_people.php?view=edit&id=$studentId" $admin).StatusCode -eq 200) 'cancel preserves profile'
Check ((Request 'admin_people.php' $admin 'POST' @{token=$Token;action='confirm_delete';id=$studentId;confirmation=$nonce}).Content.Contains('删除确认已失效')) 'cancelled confirmation cannot be replayed'
$confirmation=Request 'admin_people.php' $admin 'POST' @{token=$Token;action='prepare_delete';id=$studentId}
$oldNonce=Hidden $confirmation 'confirmation'
$s.revision='5'; $s.display_name='修改后需重新确认'
$null=Request 'admin_people.php' $admin 'POST' $s
Check ((Request 'admin_people.php' $admin 'POST' @{token=$Token;action='confirm_delete';id=$studentId;confirmation=$oldNonce}).Content.Contains('删除确认已失效')) 'editing clears pending delete confirmation'
$confirmation=Request 'admin_people.php' $admin 'POST' @{token=$Token;action='prepare_delete';id=$studentId}
$nonce=Hidden $confirmation 'confirmation'
$deleted=Request 'admin_people.php' $admin 'POST' @{token=$Token;action='confirm_delete';id=$studentId;confirmation=$nonce}
Check ($deleted.Content.Contains('已删除学生')) 'confirmed unlinked student deleted'
Check ((Request "admin_people.php?view=edit&id=$studentId" $admin).StatusCode -eq 404) 'deleted student no longer exists'
Check ((Request 'index.php' $student).Content.Contains('请输入学号或工号')) 'deleted account session revoked'
Check ((Request 'admin_people.php' $admin 'POST' @{token=$Token;action='confirm_delete';id=$studentId;confirmation=$nonce}).Content.Contains('删除确认已失效')) 'successful delete confirmation is single use'
$t=Form 'teacher' 'web_people_teacher' 'WEB-T'
$saved=Request 'admin_people.php?role=teacher' $admin 'POST' $t
$teacherId=Hidden $saved 'id'
Check ($saved.Content.Contains('已新增教师') -and $saved.Content.Contains('value="计算机学院"')) 'teacher created with department'
$teacher=New-Object Microsoft.PowerShell.Commands.WebRequestSession
Check ((Request 'login.php?role=teacher' $teacher 'POST' @{username='web_people_teacher';password='WebPeople@2026'}).Content.Contains('action="logout.php"')) 'created teacher can login'
Check ((Request "admin_people.php?role=student&view=edit&id=$teacherId" $admin).StatusCode -eq 404) 'role-specific lookup cannot expose other type'
$confirmation=Request 'admin_people.php?role=teacher' $admin 'POST' @{token=$Token;action='prepare_delete';id=$teacherId}
$deleted=Request 'admin_people.php?role=teacher' $admin 'POST' @{token=$Token;action='confirm_delete';id=$teacherId;confirmation=(Hidden $confirmation 'confirmation')}
Check ($deleted.Content.Contains('已删除教师')) 'confirmed unlinked teacher deleted'
$list=Request 'admin_people.php?role=teacher&q=teacher01' $admin
$linkedTeacher=Hidden $list 'id'
$confirmation=Request 'admin_people.php?role=teacher' $admin 'POST' @{token=$Token;action='prepare_delete';id=$linkedTeacher}
$blocked=Request 'admin_people.php?role=teacher' $admin 'POST' @{token=$Token;action='confirm_delete';id=$linkedTeacher;confirmation=(Hidden $confirmation 'confirmation')}
Check ($blocked.Content.Contains('关联记录') -and -not $blocked.Content.Contains('SQLSTATE')) 'linked teacher deletion rejected without SQL leak'
Check ((Request "admin_people.php?role=teacher&view=edit&id=$linkedTeacher" $admin).StatusCode -eq 200) 'linked teacher preserved after rejected deletion'
