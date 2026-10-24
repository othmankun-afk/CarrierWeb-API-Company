param([Parameter(Mandatory=$true)][string]$PlanPath)
$ErrorActionPreference='Stop';$file=(Resolve-Path -LiteralPath $PlanPath).Path;$plan=Get-Content -LiteralPath $file -Raw|ConvertFrom-Json
if($plan.profile -ne 'LOCAL_EAI_SUITE_V1' -or (Split-Path $file -Parent) -ne $plan.directory -or $plan.intervalSeconds -lt 60){throw 'SUITE_PLAN_REQUIRED'}
$match=[regex]::Match((Split-Path $plan.directory -Leaf),'^cwvehicles-([a-f0-9-]{36})$');if(-not $match.Success){throw 'OWN_INSTALLATION_UUID_REQUIRED'}
$name='CWEaiSuite-'+$match.Groups[1].Value;$taskFile=Join-Path $plan.directory 'task.json'
$launcher=Join-Path $PSScriptRoot 'Start-Suite-Supervisor.ps1';$exe=Join-Path $env:WINDIR 'System32/WindowsPowerShell/v1.0/powershell.exe'
$arguments='-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "'+$launcher+'" -PlanPath "'+$file+'"'
# Check scheduler access before registration and distinguish denied access
# from an unavailable scheduler.
try{$scheduler=New-Object -ComObject Schedule.Service;$scheduler.Connect()}
catch{
    $cause=$_.Exception;$denied=$false
    while($cause){if($cause.HResult -eq -2147024891){$denied=$true};$cause=$cause.InnerException}
    $category=if($denied){'SCHEDULER_OS_ACCESS_DENIED'}else{'SCHEDULER_ACCESS_NOT_CONFIRMED'}
    [Console]::Out.Write((@{result='FAIL';category=$category;scheduledTaskRegistered=$false;started=$false;windowsRebootOrLogonTested=$false}|ConvertTo-Json -Compress));exit 1
}
$existing=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
if($existing){throw 'OWN_SCHEDULED_TASK_ALREADY_EXISTS_NO_OVERWRITE'}
$identity=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$action=New-ScheduledTaskAction -Execute $exe -Argument $arguments -WorkingDirectory $PSScriptRoot
$trigger=New-ScheduledTaskTrigger -AtLogOn -User $identity
$principal=New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -DisallowHardTerminate
$definition=New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Local EAI API: supervisor, remote reads, interval >=60s, manual review for ambiguous requests.'
Register-ScheduledTask -TaskName $name -InputObject $definition|Out-Null
$verified=Get-ScheduledTask -TaskName $name
if($verified.Actions.Count -ne 1 -or $verified.Actions[0].Arguments -ne $arguments){throw 'SCHEDULED_TASK_VERIFICATION_FAILED'}
if(Test-Path -LiteralPath $taskFile){$backup=Join-Path $plan.directory ('task-before-supervisor-'+[Guid]::NewGuid().ToString()+'.json');Copy-Item -LiteralPath $taskFile -Destination $backup}
$result=@{method='ScheduledTask';taskName=$name;planPath=$file;trigger='AtLogOnCurrentUser';runLevel='Limited';requiresLoggedOnUser=$true;windowsRebootOrLogonTested=$false;passwordsInArguments=$false;oneMinuteRuleRetained=$true}
[IO.File]::WriteAllText($taskFile,($result|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
[Console]::Out.Write('{"result":"PASS","scheduledTaskRegistered":true,"started":false,"passwordsInArguments":false,"windowsRebootOrLogonTested":false,"requiresLoggedOnUser":true}')
