param([Parameter(Mandatory=$true)][string]$Directory,[string]$ExpectedSid)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$resolved=(Resolve-Path -LiteralPath $Directory).Path
if((Split-Path $resolved -Leaf) -notmatch '^cwvehicles-[a-f0-9-]{36}$'){throw 'OWN_BOOTSTRAP_INSTALLATION_REQUIRED'}
$currentSid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
$ownerSid=(Get-Acl -LiteralPath $resolved).GetOwner([Security.Principal.SecurityIdentifier]).Value
if($ownerSid -ne $currentSid){throw 'CURRENT_WINDOWS_OWNER_REQUIRED'}
if($ExpectedSid -and $ExpectedSid -ne $currentSid){throw 'PLAN_WINDOWS_OWNER_MISMATCH'}
[Console]::Out.Write('{"result":"PASS","currentWindowsOwnerVerified":true}')
