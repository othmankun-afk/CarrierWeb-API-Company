param(
    [Parameter(Mandatory=$true)][ValidateSet('Install','Start','Status','AddClient','Test','Stop')][string]$Action,
    [string]$PlanPath,[string]$OptionsPath,[string]$NodePath,[switch]$NoDialogs,[switch]$ValidateOnly
)
$ErrorActionPreference='Stop'
$codeRoot=Split-Path $PSScriptRoot -Parent
$reference=Join-Path $PSScriptRoot 'installation-reference.json'
function Show-Notice([string]$message,[bool]$failure=$false){
    if($NoDialogs){Write-Output $message;return}
    Add-Type -AssemblyName System.Windows.Forms
    $icon=if($failure){[Windows.Forms.MessageBoxIcon]::Warning}else{[Windows.Forms.MessageBoxIcon]::Information}
    [Windows.Forms.MessageBox]::Show($message,'EAI API suite - acceptance',[Windows.Forms.MessageBoxButtons]::OK,$icon)|Out-Null
}
function Choose-File([string]$title,[string]$filter='JSON files (*.json)|*.json'){
    if($NoDialogs){throw 'EXPLICIT_FILE_REQUIRED'}
    Add-Type -AssemblyName System.Windows.Forms
    $dialog=New-Object Windows.Forms.OpenFileDialog
    try{$dialog.Title=$title;$dialog.Filter=$filter;$dialog.CheckFileExists=$true
        if($dialog.ShowDialog() -ne [Windows.Forms.DialogResult]::OK){throw 'OPERATION_CANCELLED'}
        return $dialog.FileName
    }finally{$dialog.Dispose()}
}
function Choose-Folder([string]$title){
    if($NoDialogs){throw 'EXPLICIT_FOLDER_REQUIRED'}
    Add-Type -AssemblyName System.Windows.Forms
    $dialog=New-Object Windows.Forms.FolderBrowserDialog
    try{$dialog.Description=$title;$dialog.ShowNewFolderButton=$true
        if($dialog.ShowDialog() -ne [Windows.Forms.DialogResult]::OK){throw 'OPERATION_CANCELLED'}
        return $dialog.SelectedPath
    }finally{$dialog.Dispose()}
}
function Assert-File([string]$filename){
    if(-not [IO.Path]::IsPathRooted($filename) -or -not (Test-Path -LiteralPath $filename -PathType Leaf)){throw 'ABSOLUTE_EXISTING_FILE_REQUIRED'}
    return (Resolve-Path -LiteralPath $filename).Path
}
function Write-NewJson([string]$filename,$value){
    $stream=[IO.File]::Open($filename,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
    try{$bytes=[Text.UTF8Encoding]::new($false).GetBytes(($value|ConvertTo-Json -Depth 12));$stream.Write($bytes,0,$bytes.Length)}finally{$stream.Dispose()}
}
function Available-Ports{
    $listeners=@();$ports=@()
    try{for($i=0;$i -lt 4;$i++){$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0);$listener.Start();$listeners+=$listener;$ports+=$listener.LocalEndpoint.Port};return ,$ports}
    finally{foreach($listener in $listeners){$listener.Stop()}}
}
function Find-Node{
    if($NodePath){return Assert-File $NodePath}
    $found=Get-Command node.exe -ErrorAction SilentlyContinue
    if($found){return Assert-File $found.Source}
    return Assert-File (Choose-File 'Select node.exe (separately installed Node.js)' 'Node.js (node.exe)|node.exe')
}
function Read-Plan{
    $filename=$PlanPath
    $references=@(Get-ChildItem -LiteralPath $PSScriptRoot -Filter 'installation-reference*.json' -File)
    if(-not $filename -and $references.Count -eq 1 -and (Test-Path -LiteralPath $reference -PathType Leaf)){
        $saved=Get-Content -LiteralPath $reference -Raw|ConvertFrom-Json
        if($saved.profile -eq 'LOCAL_DELIVERY_REFERENCE_V1'){$filename=$saved.planPath}
    }
    if(-not $filename){$filename=Choose-File 'Select service-plan.json for THIS installation'}
    $filename=Assert-File $filename
    $plan=Get-Content -LiteralPath $filename -Raw|ConvertFrom-Json
    if($plan.profile -ne 'LOCAL_EAI_SUITE_V1' -or (Split-Path $filename -Parent) -ne $plan.directory -or $plan.intervalSeconds -lt 60){throw 'LOCAL_SUITE_PLAN_REQUIRED'}
    $null=Assert-File $plan.node
    return @{path=$filename;plan=$plan}
}
try{
    if($Action -eq 'Install'){
        if($OptionsPath){$optionsFile=Assert-File $OptionsPath;$options=Get-Content -LiteralPath $optionsFile -Raw|ConvertFrom-Json;$node=Assert-File $options.node}
        else{
            $node=Find-Node
            $php=Choose-File 'Select PHP CLI with mysqli (not the EAI SDK)' 'PHP CLI (php.exe)|php.exe'
            $mysqld=Choose-File 'Select the acceptance MySQL server' 'MySQL Server (mysqld.exe)|mysqld.exe'
            $sdk=Choose-Folder 'Select the unmodified official EAI S.1366 SDK directory'
            $parent=Choose-Folder 'Select a SHORT LOCAL parent directory for a NEW installation'
            if($parent.Length -gt 80){throw 'SHORT_LOCAL_INSTALLATION_PARENT_REQUIRED'}
            $ports=Available-Ports
            $options=@{directory=(Join-Path $parent ('cwvehicles-'+[Guid]::NewGuid().ToString()));node=$node;php=$php;mysqld=$mysqld;sdkDirectory=$sdk;sqlPort=$ports[0];apiPort=$ports[1];lockPort=$ports[2];supervisorPort=$ports[3]}
            $optionsFile=Join-Path $parent ('cw-install-options-'+[Guid]::NewGuid().ToString()+'.json');Write-NewJson $optionsFile $options
        }
        if($ValidateOnly){Show-Notice ('VALIDATION ONLY: options selected; no instance created. '+$optionsFile);exit 0}
        Write-Host 'New empty local initialization. No client account or EAI call; no deletion.'
        $raw=& $node (Join-Path $codeRoot 'tools/initialize-empty-suite.mjs') $optionsFile
        if($LASTEXITCODE -ne 0){throw 'INSTALLATION_NOT_CONFIRMED_REVIEW_NEW_DIAGNOSTICS'}
        $result=($raw -join [Environment]::NewLine)|ConvertFrom-Json
        if($result.result -ne 'PASS' -or -not $result.shutdownConfirmed){throw 'INSTALLATION_NOT_CONFIRMED_REVIEW_NEW_DIAGNOSTICS'}
        $ref=@{profile='LOCAL_DELIVERY_REFERENCE_V1';planPath=(Join-Path $result.directory 'service-plan.json')}
        # Preserve earlier references. Never overwrite another installation selection.
        if(Test-Path -LiteralPath $reference){$reference=Join-Path $PSScriptRoot ('installation-reference-'+[Guid]::NewGuid().ToString()+'.json')}
        Write-NewJson $reference $ref
        Show-Notice ('EMPTY INSTALLATION VERIFIED.'+[Environment]::NewLine+$result.directory+[Environment]::NewLine+'Next step: Add client. No synchronization started.'+[Environment]::NewLine+'Reference: '+$reference)
        exit 0
    }
    $selected=Read-Plan;$planFile=$selected.path;$plan=$selected.plan
    if($ValidateOnly){Show-Notice ('VALIDATION ONLY: '+$Action+' ; '+$planFile+'; no service started, no network request.');exit 0}
    switch($Action){
        'AddClient'{
            Write-Host 'One-off interactive operation, not a permanent terminal. The EAI password will be masked.'
            & (Join-Path $codeRoot 'tools/Add-Suite-Client.ps1') -PlanPath $planFile
            Show-Notice 'Read the result in this window. A private access sheet is created only after successful verification. Do not attach it to the code.'
        }
        'Test'{
            Write-Host 'LOCAL HTTP checks only. They consume API quotas; no direct EAI call.'
            $raw=& $plan.node (Join-Path $codeRoot 'tools/test-local-delivery.mjs') $planFile
            $result=($raw -join [Environment]::NewLine)|ConvertFrom-Json
            $message='Result: '+$result.result+[Environment]::NewLine+'Private report: '+$result.reportPath+[Environment]::NewLine+'Response bodies and passwords are not displayed.'
            Show-Notice $message ($result.result -ne 'PASS')
            if($result.result -ne 'PASS'){exit 1}
        }
        default{& (Join-Path $codeRoot 'service/Control-Suite.ps1') -Action $Action -PlanPath $planFile -Notify:(-not $NoDialogs)}
    }
}catch{
    $code=if($_.Exception.Message -match '^[A-Z0-9_]{1,100}$'){$_.Exception.Message}else{'OPERATION_NOT_CONFIRMED_CONSULT_LOCAL_DIAGNOSTICS'}
    Show-Notice ('NOT CONFIRMED: '+$code+[Environment]::NewLine+'No deletion or automatic EAI retry.') $true
    exit 1
}
