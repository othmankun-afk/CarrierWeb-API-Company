param([ValidateSet('Read','Write')][string]$Operation,[Parameter(Mandatory=$true)][string]$Path)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
if($Operation -eq 'Write'){
    if(Test-Path -LiteralPath $Path){throw 'SECRET_TARGET_EXISTS'}
    $value=[Console]::In.ReadToEnd()
    if($value.Length -gt 1048576){throw 'SECRET_INPUT_BOUND'}
    $secure=ConvertTo-SecureString -String $value -AsPlainText -Force
    $encrypted=ConvertFrom-SecureString -SecureString $secure
    [IO.File]::WriteAllText($Path,$encrypted,[Text.UTF8Encoding]::new($false))
    $value=$null;$secure.Dispose()
    [Console]::Out.Write('{"result":"PASS","protection":"Windows DPAPI CurrentUser"}')
}else{
    $secure=ConvertTo-SecureString -String ([IO.File]::ReadAllText($Path))
    $pointer=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
    try{[Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer))}
    finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer);$secure.Dispose()}
}
