param(
    [Parameter(Mandatory=$true)][string]$PlanPath,
    [switch]$ValidateOnly,
    [switch]$Register,
    [string]$ApprovalReference
)
# Default is a read-only validation. Registration is a separate, explicit IT
# operation. It is never invoked by the local double-click installer.
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$registered=$false;$metadataWritten=$false;$credential=$null;$plainPassword=$null
$secret=$null;$secure=$null;$pointer=[IntPtr]::Zero
function Write-NewJson([string]$filename,$value){
    $stream=[IO.File]::Open($filename,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try{$bytes=[Text.UTF8Encoding]::new($false).GetBytes(($value|ConvertTo-Json -Depth 12));$stream.Write($bytes,0,$bytes.Length)}finally{$stream.Dispose()}
}
function Account-Sid([string]$name){
    if($name -match '^S-1-') {return ([Security.Principal.SecurityIdentifier]::new($name)).Value}
    if($name.StartsWith('.\')){$name=$env:COMPUTERNAME+$name.Substring(1)}
    return ([Security.Principal.NTAccount]::new($name)).Translate([Security.Principal.SecurityIdentifier]).Value
}
try{
    if($ValidateOnly -and $Register){throw 'VALIDATE_AND_REGISTER_ARE_EXCLUSIVE'}
    if(-not [IO.Path]::IsPathRooted($PlanPath)){throw 'ABSOLUTE_PLAN_REQUIRED'}
    $file=(Resolve-Path -LiteralPath $PlanPath).Path
    if($file -match '["\r\n\x00]'){throw 'PLAN_PATH_INVALID'}
    $plan=Get-Content -LiteralPath $file -Raw|ConvertFrom-Json
    if($plan.profile -ne 'LOCAL_EAI_SUITE_V1' -or (Split-Path $file -Parent) -ne $plan.directory -or $plan.intervalSeconds -lt 60){throw 'SUITE_PLAN_REQUIRED'}
    $match=[regex]::Match((Split-Path $plan.directory -Leaf),'^cwvehicles-([a-f0-9-]{36})$')
    if(-not $match.Success){throw 'OWN_INSTALLATION_UUID_REQUIRED'}
    $current=[Security.Principal.WindowsIdentity]::GetCurrent()
    $currentSid=$current.User.Value
    if($plan.windowsOwnerSid -notmatch '^S-1-5-21-[0-9-]+$' -or $plan.windowsOwnerSid -ne $currentSid){throw 'BOOTSTRAP_WINDOWS_IDENTITY_REQUIRED'}
    if(-not (Test-Path -LiteralPath $plan.node -PathType Leaf)){throw 'NODE_RUNTIME_REQUIRED'}
    $launcher=(Resolve-Path -LiteralPath (Join-Path $PSScriptRoot 'Start-Suite-Supervisor.ps1')).Path
    $exe=(Resolve-Path -LiteralPath (Join-Path $env:WINDIR 'System32/WindowsPowerShell/v1.0/powershell.exe')).Path
    if($launcher -match '["\r\n\x00]'){throw 'LAUNCHER_PATH_INVALID'}
    $secretFile=Join-Path $plan.directory 'secrets.dpapi'
    if((Get-Item -LiteralPath $secretFile).Length -gt 1048576){throw 'PRIVATE_SECRET_BOUND'}
    # Do not call secrets.ps1 Read: that CLI intentionally writes decrypted JSON
    # to its stdout. This check keeps the plaintext entirely in this process.
    $secure=ConvertTo-SecureString -String ([IO.File]::ReadAllText($secretFile))
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try{$secret=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)|ConvertFrom-Json}
    finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer);$pointer=[IntPtr]::Zero;$secure.Dispose();$secure=$null}
    if($secret.rootPassword -notmatch '^[a-f0-9]{64}$' -or $null -eq $secret.clients){throw 'LOCAL_DPAPI_SECRET_INVALID'}
    $secret=$null
    $name='CWEaiSuite-'+$match.Groups[1].Value
    $arguments='-NoProfile -NonInteractive -WindowStyle Hidden -File "'+$launcher+'" -PlanPath "'+$file+'"'
    if(-not $Register){
        [Console]::Out.Write((@{result='PASS';phase='VALIDATION_ONLY';taskName=$name;bootstrapIdentityVerified=$true;dpapiReadable=$true;scheduledTaskRegistered=$false;osConfigurationChanged=$false;filesWritten=0;credentialPrompted=$false;started=$false;windowsRebootOrLogonTested=$false;productionReady=$false}|ConvertTo-Json -Compress))
        exit 0
    }
    if([string]::IsNullOrWhiteSpace($ApprovalReference) -or $ApprovalReference.Length -gt 256 -or $ApprovalReference -match '[\r\n\x00]'){throw 'EXPLICIT_IT_APPROVAL_REFERENCE_REQUIRED'}
    foreach($command in @('Get-ScheduledTask','New-ScheduledTaskAction','New-ScheduledTaskTrigger','New-ScheduledTaskPrincipal','New-ScheduledTaskSettingsSet','New-ScheduledTask','Register-ScheduledTask','Export-ScheduledTask')){
        if(-not (Get-Command $command -ErrorAction SilentlyContinue)){throw 'SCHEDULED_TASKS_PREREQUISITE_REQUIRED'}
    }
    # Scheduler access must succeed before checking for an existing task.
    # Registration refuses an existing task with the same name.
    $existing=@(Get-ScheduledTask -TaskPath '\' -ErrorAction Stop|Where-Object {$_.TaskName -eq $name})
    if($existing.Count -gt 0){throw 'OWN_SCHEDULED_TASK_ALREADY_EXISTS_NO_OVERWRITE'}
    $credential=Get-Credential -UserName $current.Name -Message 'Bootstrap Windows account only. Task Scheduler will retain this credential for unattended startup.'
    if($null -eq $credential){throw 'WINDOWS_CREDENTIAL_REQUIRED'}
    if((Account-Sid $credential.UserName) -ne $currentSid){throw 'CREDENTIAL_MUST_MATCH_BOOTSTRAP_IDENTITY'}
    $action=New-ScheduledTaskAction -Execute $exe -Argument $arguments -WorkingDirectory $PSScriptRoot
    $trigger=New-ScheduledTaskTrigger -AtStartup
    $principal=New-ScheduledTaskPrincipal -UserId $current.Name -LogonType Password -RunLevel Limited
    # The application supervisor handles restarts and pauses ambiguous EAI attempts.
    # Register the startup task without starting it here.
    $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -DisallowHardTerminate
    $definition=New-ScheduledTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'EAI API suite: machine startup under the DPAPI identity; no automatic retry of ambiguous EAI attempts.'
    $intent=Join-Path $plan.directory ('task-registration-intent-'+[Guid]::NewGuid().ToString()+'.json')
    Write-NewJson $intent @{profile='SERVER_STARTUP_INTENT_V1';taskName=$name;planPath=$file;ownerSid=$currentSid;approvalReference=$ApprovalReference;utc=[DateTime]::UtcNow.ToString('o');secretsRecorded=$false}
    # Register-ScheduledTask requires an in-process String. It is not a process
    # command argument, output or file. Clear the managed reference immediately.
    try{$plainPassword=$credential.GetNetworkCredential().Password
        $null=Register-ScheduledTask -TaskName $name -TaskPath '\' -InputObject $definition -User $current.Name -Password $plainPassword -ErrorAction Stop
        $registered=$true
    }finally{$plainPassword=$null}
    $verified=Get-ScheduledTask -TaskName $name -TaskPath '\' -ErrorAction Stop
    if($verified.Actions.Count -ne 1 -or $verified.Actions[0].Execute -ne $exe -or $verified.Actions[0].Arguments -ne $arguments -or $verified.Actions[0].WorkingDirectory -ne $PSScriptRoot){throw 'SERVER_STARTUP_ACTION_VERIFICATION_FAILED'}
    if((Account-Sid $verified.Principal.UserId) -ne $currentSid){throw 'SERVER_STARTUP_PRINCIPAL_VERIFICATION_FAILED'}
    [xml]$xml=Export-ScheduledTask -TaskName $name -TaskPath '\' -ErrorAction Stop
    $ns=[Xml.XmlNamespaceManager]::new($xml.NameTable);$ns.AddNamespace('t','http://schemas.microsoft.com/windows/2004/02/mit/task')
    if($xml.SelectNodes('/t:Task/t:Triggers/*',$ns).Count -ne 1 -or $xml.SelectNodes('/t:Task/t:Triggers/t:BootTrigger',$ns).Count -ne 1){throw 'SERVER_STARTUP_TRIGGER_VERIFICATION_FAILED'}
    if($xml.SelectSingleNode('/t:Task/t:Principals/t:Principal/t:LogonType',$ns).InnerText -ne 'Password' -or $xml.SelectSingleNode('/t:Task/t:Settings/t:MultipleInstancesPolicy',$ns).InnerText -ne 'IgnoreNew'){throw 'SERVER_STARTUP_SETTINGS_VERIFICATION_FAILED'}
    $taskFile=Join-Path $plan.directory 'task.json';$backup=$null
    if(Test-Path -LiteralPath $taskFile){
        $backup=Join-Path $plan.directory ('task-before-server-startup-'+[Guid]::NewGuid().ToString()+'.json')
        $stream=[IO.File]::Open($backup,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
        try{$bytes=[IO.File]::ReadAllBytes($taskFile);$stream.Write($bytes,0,$bytes.Length)}finally{$stream.Dispose()}
    }
    $metadata=@{method='ScheduledTask';taskName=$name;planPath=$file;trigger='AtStartup';logonType='Password';ownerSid=$currentSid;approvalReference=$ApprovalReference;runLevel='Limited';requiresLoggedOnUser=$false;passwordsInArguments=$false;taskDefinitionVerified=$true;windowsRebootOrLogonTested=$false;metadataBackup=$backup;oneMinuteRuleRetained=$true;utc=[DateTime]::UtcNow.ToString('o')}
    if($backup){[IO.File]::WriteAllText($taskFile,($metadata|ConvertTo-Json -Depth 12),[Text.UTF8Encoding]::new($false))}else{Write-NewJson $taskFile $metadata}
    $metadataWritten=$true
    [Console]::Out.Write((@{result='PASS';phase='TASK_REGISTERED_NOT_STARTED';scheduledTaskRegistered=$true;taskMetadataWritten=$true;taskDefinitionVerified=$true;started=$false;passwordsInArguments=$false;windowsRebootOrLogonTested=$false;requiresLoggedOnUser=$false;filesDeleted=0;productionReady=$false}|ConvertTo-Json -Compress))
}catch{
    $category=if($_.Exception.Message -match '^[A-Z0-9_]{1,100}$'){$_.Exception.Message}else{'SERVER_STARTUP_NOT_CONFIRMED_REVIEW_REQUIRED'}
    [Console]::Out.Write((@{result='FAIL';category=$category;scheduledTaskRegistered=$registered;taskMetadataWritten=$metadataWritten;started=$false;automaticRetry=$false;windowsRebootOrLogonTested=$false;filesDeleted=0;productionReady=$false}|ConvertTo-Json -Compress));exit 1
}finally{
    if($pointer -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)}
    if($secure){$secure.Dispose()};if($credential){$credential.Password.Dispose()}
    $credential=$null;$plainPassword=$null;$secret=$null
}
