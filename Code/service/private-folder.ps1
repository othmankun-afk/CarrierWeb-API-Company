param([Parameter(Mandatory=$true)][string]$Directory)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$resolved=(Resolve-Path -LiteralPath $Directory).Path
if((Split-Path $resolved -Leaf) -notmatch '^cwvehicles-[a-f0-9-]{36}$'){throw 'NEW_SERVICE_FOLDER_REQUIRED'}
$sid=[Security.Principal.WindowsIdentity]::GetCurrent().User
$acl=[Security.AccessControl.DirectorySecurity]::new()
$acl.SetOwner($sid);$acl.SetAccessRuleProtection($true,$false)
foreach($principal in @($sid,[Security.Principal.SecurityIdentifier]::new('S-1-5-18'))){
    $rule=[Security.AccessControl.FileSystemAccessRule]::new($principal,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
    $acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $resolved -AclObject $acl
[Console]::Out.Write('{"result":"PASS"}')
