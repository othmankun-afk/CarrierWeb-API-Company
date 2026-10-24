param([string]$ArtifactParent)
# Validate syntax and default dry-run behavior without registering a task.
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$target=Join-Path $root 'service/Install-Server-Startup.ps1'
$checks=0
function Check([bool]$value){if(-not $value){throw 'SERVER_STARTUP_VALIDATION_CHECK'};$script:checks++}
$tokens=$null;$errors=$null
$ast=[Management.Automation.Language.Parser]::ParseFile($target,[ref]$tokens,[ref]$errors)
Check ($errors.Count -eq 0)
$text=[IO.File]::ReadAllText($target)
Check ($text.Contains("if(-not `$Register)"))
Check ($text.IndexOf("if(-not `$Register)") -lt $text.IndexOf('Get-Credential -UserName'))
Check ($text.IndexOf("if(-not `$Register)") -lt $text.IndexOf('Register-ScheduledTask -TaskName'))
Check ($text.Contains('-AtStartup') -and $text.Contains('-LogonType Password') -and $text.Contains('-RunLevel Limited'))
Check ($text.Contains('BOOTSTRAP_WINDOWS_IDENTITY_REQUIRED') -and $text.Contains('CREDENTIAL_MUST_MATCH_BOOTSTRAP_IDENTITY'))
Check ($text.Contains('OWN_SCHEDULED_TASK_ALREADY_EXISTS_NO_OVERWRITE'))
Check (-not ($text -match 'Register-ScheduledTask[^\r\n]*-Force|Start-ScheduledTask|Unregister-ScheduledTask|Remove-Item|Set-ExecutionPolicy|RunAs|ExecutionPolicy Bypass'))
Check ($text.Contains('SERVER_STARTUP_TRIGGER_VERIFICATION_FAILED') -and $text.Contains('SERVER_STARTUP_PRINCIPAL_VERIFICATION_FAILED'))
Check ($text.Contains('task-before-server-startup-') -and $text.Contains('[IO.FileMode]::CreateNew'))
Check ($text.Contains('passwordsInArguments=$false') -and $text.Contains('windowsRebootOrLogonTested=$false'))
if(-not $ArtifactParent){$ArtifactParent=Join-Path $root 'test-results'}
$null=[IO.Directory]::CreateDirectory($ArtifactParent)
$fixture=Join-Path $ArtifactParent ('startup-validation-'+[Guid]::NewGuid().ToString())
$directory=Join-Path $fixture ('cwvehicles-'+[Guid]::NewGuid().ToString())
$null=[IO.Directory]::CreateDirectory($directory)
$currentSid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$exe=Join-Path $env:WINDIR 'System32/WindowsPowerShell/v1.0/powershell.exe'
$planFile=Join-Path $directory 'service-plan.json'
$plan=@{profile='LOCAL_EAI_SUITE_V1';directory=$directory;intervalSeconds=60;node=$exe;windowsOwnerSid=$currentSid;clients=@()}
[IO.File]::WriteAllText($planFile,($plan|ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
$syntheticRoot=('a'*64)
$secure=ConvertTo-SecureString -String ((@{rootPassword=$syntheticRoot;clients=@{}})|ConvertTo-Json -Compress) -AsPlainText -Force
try{[IO.File]::WriteAllText((Join-Path $directory 'secrets.dpapi'),(ConvertFrom-SecureString -SecureString $secure),[Text.UTF8Encoding]::new($false))}finally{$secure.Dispose()}
function Files-Snapshot{
    return (@(Get-ChildItem -LiteralPath $fixture -Recurse -File|ForEach-Object{ $_.FullName+' '+(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash })|Sort-Object)-join "`n"
}
$before=Files-Snapshot
foreach($explicit in @($false,$true)){
    $arguments=@('-NoProfile','-NonInteractive','-File',$target,'-PlanPath',$planFile)
    if($explicit){$arguments+='-ValidateOnly'}
    $raw=& $exe @arguments
    Check ($LASTEXITCODE -eq 0)
    $result=($raw -join [Environment]::NewLine)|ConvertFrom-Json
    Check ($result.result -eq 'PASS' -and $result.phase -eq 'VALIDATION_ONLY')
    Check ($result.osConfigurationChanged -eq $false -and $result.scheduledTaskRegistered -eq $false -and $result.filesWritten -eq 0)
    Check ($result.credentialPrompted -eq $false -and $result.started -eq $false)
    Check (-not (($raw -join '') -match [regex]::Escape($syntheticRoot)))
    Check ((Files-Snapshot) -ceq $before)
}
$badFile=Join-Path $directory 'different-owner.json';$plan.windowsOwnerSid='S-1-5-21-111111111-222222222-333333333-9999'
[IO.File]::WriteAllText($badFile,($plan|ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false))
$before=Files-Snapshot
$raw=& $exe -NoProfile -NonInteractive -File $target -PlanPath $badFile -ValidateOnly
Check ($LASTEXITCODE -ne 0)
$result=($raw -join [Environment]::NewLine)|ConvertFrom-Json
Check ($result.result -eq 'FAIL' -and $result.category -eq 'BOOTSTRAP_WINDOWS_IDENTITY_REQUIRED')
Check ((Files-Snapshot) -ceq $before)
[Console]::Out.Write((@{result='PASS';suite='server-startup-validation-only';checks=$checks;actualRegistrationTested=$false;actualRebootTested=$false;tasksRegistered=0;servicesStarted=0;eaiCalls=0;filesDeleted=0;fixture=$fixture;productionReady=$false}|ConvertTo-Json -Compress))
