param([ValidateSet('Create','Expand')][string]$Action,[Parameter(Mandatory=$true)][string]$Source,[Parameter(Mandatory=$true)][string]$Target)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
if(-not [IO.Path]::IsPathRooted($Source) -or -not [IO.Path]::IsPathRooted($Target)){throw 'ABSOLUTE_ARCHIVE_PATHS_REQUIRED'}
if(Test-Path -LiteralPath $Target){throw 'ARCHIVE_TARGET_EXISTS'}
Add-Type -AssemblyName System.IO.Compression.FileSystem
if($Action -eq 'Create'){
    if(-not (Test-Path -LiteralPath $Source -PathType Container)){throw 'PACKAGE_DIRECTORY_REQUIRED'}
    [IO.Compression.ZipFile]::CreateFromDirectory($Source,$Target,[IO.Compression.CompressionLevel]::Optimal,$false)
}else{
    if(-not (Test-Path -LiteralPath $Source -PathType Leaf)){throw 'PACKAGE_ARCHIVE_REQUIRED'}
    # Refuse path traversal before extracting the explicit code-only archive.
    $archive=[IO.Compression.ZipFile]::OpenRead($Source)
    try{
        $absolute=[IO.Path]::GetFullPath($Target).TrimEnd([IO.Path]::DirectorySeparatorChar)+[IO.Path]::DirectorySeparatorChar
        foreach($entry in $archive.Entries){
            if([IO.Path]::IsPathRooted($entry.FullName) -or -not [IO.Path]::GetFullPath((Join-Path $Target $entry.FullName)).StartsWith($absolute,[StringComparison]::OrdinalIgnoreCase)){throw 'ARCHIVE_ENTRY_PATH_REFUSED'}
        }
    }finally{$archive.Dispose()}
    [IO.Compression.ZipFile]::ExtractToDirectory($Source,$Target)
}
[Console]::Out.Write('{"result":"PASS"}')
