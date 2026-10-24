param([Parameter(Mandatory=$true)][string]$PlanPath)
$ErrorActionPreference='Stop';$file=(Resolve-Path -LiteralPath $PlanPath).Path;$plan=Get-Content -LiteralPath $file -Raw|ConvertFrom-Json
if($plan.profile -ne 'LOCAL_EAI_SUITE_V1' -or (Split-Path $file -Parent) -ne $plan.directory -or $plan.intervalSeconds -lt 60){throw 'SUITE_PLAN_REQUIRED'}
if(Test-Path -LiteralPath (Join-Path $plan.directory 'registration.lock')){throw 'CLIENT_REGISTRATION_IN_PROGRESS_NO_START'}
# Synchronous launcher: Task Scheduler owns this hidden process independently of a shell.
& $plan.node (Join-Path $PSScriptRoot 'suite-supervisor.mjs') $file
exit $LASTEXITCODE
