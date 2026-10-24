param([Parameter(Mandatory=$true)][string]$PlanPath,[ValidateSet('Start','Stop','Status','OpenTests')][string]$Action='Status',[switch]$Notify)
$ErrorActionPreference='Stop'
$planFile=(Resolve-Path -LiteralPath $PlanPath).Path;$plan=Get-Content -LiteralPath $planFile -Raw|ConvertFrom-Json
if($plan.profile -ne 'LOCAL_EAI_SUITE_V1' -or (Split-Path $planFile -Parent) -ne $plan.directory){throw 'SUITE_PLAN_REQUIRED'}
function Notice([string]$message){if($Notify){Add-Type -AssemblyName System.Windows.Forms;[Windows.Forms.MessageBox]::Show($message,'Local EAI API')|Out-Null}else{Write-Output $message}}
function Read-State([string]$name){try{return Get-Content -LiteralPath (Join-Path $plan.directory $name) -Raw|ConvertFrom-Json}catch{return $null}}
function State-Age($stamp){
    # PS5 retains an ISO JSON date as string; recent PS7 may deserialize it as
    # DateTime. Never round-trip the latter through locale-dependent text.
    try{
        if($stamp -is [DateTime]){$utc=$stamp.ToUniversalTime()}
        elseif($stamp -is [DateTimeOffset]){$utc=$stamp.UtcDateTime}
        else{$utc=[DateTimeOffset]::Parse([string]$stamp,[Globalization.CultureInfo]::InvariantCulture,[Globalization.DateTimeStyles]::AssumeUniversal).UtcDateTime}
        return ([DateTime]::UtcNow-$utc).TotalSeconds
    }catch{return [double]::PositiveInfinity}
}
function Test-LocalPort([int]$port){$socket=New-Object Net.Sockets.TcpClient;try{$pending=$socket.ConnectAsync('127.0.0.1',$port);if(-not $pending.Wait(700)){return $false};return $socket.Connected}catch{return $false}finally{$socket.Dispose()}}
if($Action -eq 'Status'){
    $state=Read-State 'service-status.json';$watchdog=Read-State 'supervisor-status.json'
    $httpOpen=Test-LocalPort $plan.apiPort;$sqlOpen=Test-LocalPort $plan.sqlPort
    $label='NOT STARTED - operation not confirmed';$lines=@()
    if($state){$age=State-Age $state.utc;$workerAlive=[bool](Get-Process -Id $state.pid -ErrorAction SilentlyContinue);$label=if($age -gt 30 -or $age -lt -5){'STALE STATUS - operation not confirmed'}elseif($state.stage -eq 'RUNNING' -and (-not $httpOpen -or -not $sqlOpen)){'UNAVAILABLE - HTTP or SQL port closed'}else{$state.stage};$lines+=('Last update UTC: '+$state.utc);if(-not $workerAlive -and $state.stage -eq 'RUNNING'){$lines+='PID not visible in this Windows context; check heartbeat and ports (a missing PID alone is inconclusive).'};foreach($entry in $state.clients.PSObject.Properties){$lines+=($entry.Name+' : '+$entry.Value.status+'; generation '+$entry.Value.generation)}}
    if($watchdog){$watchAge=State-Age $watchdog.utc;$watchLabel=if($watchAge -gt 30 -or $watchAge -lt -5){'STALE STATUS'}else{$watchdog.stage};$lines+=('Supervisor: '+$watchLabel+' ; '+$watchdog.reason)}
    if($state -and $label -eq 'RUNNING' -and $state.dataHealth -eq 'DEGRADED'){$label='DEGRADED - process running, synchronization needs attention'}
    if($state){$lines+=('Data health: '+$state.dataHealth);foreach($entry in $state.clients.PSObject.Properties){$lines+=($entry.Name+' publication: '+$entry.Value.publicationFreshness+'; age seconds: '+$entry.Value.publicationAgeSeconds);if($entry.Value.attention){$lines+=('Action required: '+$entry.Value.attention)}}}
    if($state){foreach($entry in $state.clients.PSObject.Properties){$lines+=('Last successful publication UTC: '+$entry.Value.capturedUtc)}}
    $lines=@(('State: '+$label),('Local HTTP: '+$httpOpen+'; Local MySQL: '+$sqlOpen),('Minimum EAI interval: '+$plan.intervalSeconds+' seconds; no automatic retry of ambiguous EAI attempts'))+$lines
    Notice ($lines -join [Environment]::NewLine);exit
}
if($Action -eq 'Stop'){[IO.File]::WriteAllText((Join-Path $plan.directory 'control.json'),'{"requested":"stop"}');Notice 'Clean stop requested from the supervisor. Use Status to confirm STOPPED. No forced MySQL process termination.';exit}
if($Action -eq 'OpenTests'){Start-Process -FilePath (Join-Path $plan.directory 'TESTER-API.html');exit}
if($plan.intervalSeconds -lt 60){throw 'ONE_MINUTE_MINIMUM_REQUIRED'}
if(Test-Path -LiteralPath (Join-Path $plan.directory 'registration.lock')){throw 'CLIENT_REGISTRATION_IN_PROGRESS_NO_START'}
[IO.File]::WriteAllText((Join-Path $plan.directory 'control.json'),'{"requested":"run"}')
$task=Read-State 'task.json'
if($task -and $task.method -eq 'ScheduledTask'){
    if($task.taskName -notmatch '^CWEaiSuite-[a-f0-9-]{36}$' -or $task.planPath -ne $planFile){throw 'OWN_SCHEDULED_TASK_REQUIRED'}
    $registered=Get-ScheduledTask -TaskName $task.taskName -ErrorAction Stop
    if($registered.Actions.Count -ne 1 -or $registered.Actions[0].Arguments -notlike ('*"'+$planFile+'"*') -or $registered.Actions[0].Arguments -notlike '*Start-Suite-Supervisor.ps1*'){throw 'OWN_SCHEDULED_TASK_REQUIRED'}
    Start-ScheduledTask -TaskName $task.taskName
    Notice 'Startup requested from Windows Task Scheduler. Confirm RUNNING and PUBLISHED in Status. No automatic EAI retry after an incident.'
}else{
    $arguments='"'+(Join-Path $PSScriptRoot 'suite-supervisor.mjs')+'" "'+$planFile+'"'
    Start-Process -FilePath $plan.node -ArgumentList $arguments -WorkingDirectory (Split-Path $PSScriptRoot -Parent) -WindowStyle Hidden
    Notice 'Local startup requested with supervisor. IT must configure and test the Windows task for unattended startup. No automatic EAI retry after an incident.'
}
