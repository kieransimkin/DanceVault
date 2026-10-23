param([string]$Parent = './dist')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskParent = [IO.Path]::GetFullPath((Join-Path $taskRoot $Parent))
[IO.Directory]::CreateDirectory($taskParent) | Out-Null
$taskProbe = Join-Path $taskParent ('.temp-access-' + [Guid]::NewGuid().ToString('N'))
$taskRenamed = $taskProbe + '-renamed'
try {
    [IO.Directory]::CreateDirectory($taskProbe) | Out-Null
    $taskFile = Join-Path $taskProbe 'probe.txt'
    [IO.File]::WriteAllText($taskFile, 'cipherparcel-temp-access', [Text.UTF8Encoding]::new($false))
    if ([IO.File]::ReadAllText($taskFile) -cne 'cipherparcel-temp-access') { throw 'Temporary-file readback mismatch' }
    [IO.Directory]::Move($taskProbe, $taskRenamed)
    [IO.File]::Delete((Join-Path $taskRenamed 'probe.txt'))
    [IO.Directory]::Delete($taskRenamed)
    Write-Output "Temporary access verified: $taskParent"
} finally {
    if ([IO.Directory]::Exists($taskProbe)) { [IO.Directory]::Delete($taskProbe, $true) }
    if ([IO.Directory]::Exists($taskRenamed)) { [IO.Directory]::Delete($taskRenamed, $true) }
}
