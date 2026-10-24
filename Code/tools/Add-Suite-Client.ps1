param([Parameter(Mandatory=$true)][string]$PlanPath,[string]$SdkManifestPath,[string]$SdkExtraPath,[string]$CapacityPolicyPath)
$ErrorActionPreference='Stop'
$planFile=(Resolve-Path -LiteralPath $PlanPath).Path
$plan=Get-Content -LiteralPath $planFile -Raw|ConvertFrom-Json
if($plan.profile -ne 'LOCAL_EAI_SUITE_V1' -or (Split-Path $planFile -Parent) -ne $plan.directory){throw 'SUITE_PLAN_REQUIRED'}
Write-Host 'Local onboarding: stop the service and confirm STOPPED. No CarrierWeb server changes.'
Write-Host 'One authorized EAI read; no automatic retry. API: at least 60 seconds per endpoint.'
$quiet=Read-Host 'No known call for THIS EAI account during the last minute? Answer YES'
if($quiet -cne 'YES'){throw 'EAI_QUIET_MINUTE_CONFIRMATION_REQUIRED'}
$name=Read-Host 'New client name'
$username=Read-Host 'EAI username'
$pending=@()
$stagingRoot=Join-Path $plan.directory 'client-onboarding'
if(Test-Path -LiteralPath $stagingRoot){
    foreach($folder in Get-ChildItem -LiteralPath $stagingRoot -Directory){
        if($folder.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'ONBOARDING_STATE_REVIEW_REQUIRED'}
        $state=Get-Content -LiteralPath (Join-Path $folder.FullName 'state.json') -Raw|ConvertFrom-Json
        if(-not $state.enabled -and $state.eaiUsername -ieq $username){$pending+=,$state}
    }
}
if($pending.Count -gt 1){throw 'ONBOARDING_MULTIPLE_STAGES_REVIEW_REQUIRED'}
if($pending.Count -eq 1){
    if($pending[0].registryReviewRequired -or $pending[0].phase -eq 'REGISTRY_COMMIT'){throw 'ONBOARDING_REGISTRY_REVIEW_REQUIRED'}
    Write-Host 'An unfinished registration exists. Resume preserves its client and database; a saved valid response is reused. At most one new EAI read, no automatic retry.'
    if((Read-Host 'Resume this registration? Type RESUME, or press Enter to cancel') -cne 'RESUME'){throw 'OPERATOR_CANCELLED'}
}
$password=Read-Host 'EAI password (masked; do not use the API password)' -AsSecureString
$pointer=[IntPtr]::Zero
try{
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    $request=@{name=$name;eaiUsername=$username;eaiPassword=[Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer);noKnownEaiCallInLastMinute=$true;approvedUnknownPolicy='legacy-markers-v1';approvedCounterPolicy='approved-compatibility-zero-empty-v1'}
    if($pending.Count -eq 1){$request.resumeApproval='RESUME_STAGED_CLIENT';$request.resumeTenant=$pending[0].tenant}
    if($SdkManifestPath){$request.sdkManifestPath=(Resolve-Path -LiteralPath $SdkManifestPath).Path}
    if($SdkExtraPath){$request.sdkExtraPath=(Resolve-Path -LiteralPath $SdkExtraPath).Path}
    if($CapacityPolicyPath){$request.capacityPolicy=Get-Content -LiteralPath (Resolve-Path -LiteralPath $CapacityPolicyPath).Path -Raw|ConvertFrom-Json}
    $json=$request|ConvertTo-Json -Compress -Depth 12
    $secure=ConvertTo-SecureString -String $json -AsPlainText -Force
    $encrypted=ConvertFrom-SecureString -SecureString $secure
    $inputFile=Join-Path $plan.directory ('onboarding-input-'+[Guid]::NewGuid().ToString()+'.dpapi')
    [IO.File]::WriteAllText($inputFile,$encrypted,[Text.UTF8Encoding]::new($false))
    $request=$null;$json=$null;$secure.Dispose()
}finally{if($pointer -ne [IntPtr]::Zero){[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)};$password.Dispose()}
& $plan.node (Join-Path $PSScriptRoot 'add-suite-client.mjs') $planFile $inputFile
if($LASTEXITCODE -ne 0){throw 'ONBOARDING_NOT_CONFIRMED_NO_AUTOMATIC_RETRY'}
Write-Host 'The access sheet is the PRIVATE file indicated in the result. Do not give EAI/MySQL access to the client.'
Write-Host 'Then use the Start launcher; confirm RUNNING and PUBLISHED.'
