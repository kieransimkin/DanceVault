$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskSource = [IO.File]::ReadAllText((Join-Path $taskRoot 'dancevault.php'))
$taskMatch = [regex]::Match($taskSource, '(?m)^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)\r?$')
if (!$taskMatch.Success) { throw 'Missing plugin version' }
$taskVersion = $taskMatch.Groups[1].Value
$taskDist = Join-Path $taskRoot 'dist'
New-Item -ItemType Directory -Force -Path $taskDist | Out-Null
Add-Type -AssemblyName System.IO.Compression
$taskZipName = "dancevault-$taskVersion-wordpress.zip"
$taskZipPath = Join-Path $taskDist $taskZipName
if (Test-Path -LiteralPath $taskZipPath) { throw 'Package already exists. Preserve immutable candidates; use a fresh version.' }
$taskStream = [IO.File]::Open($taskZipPath, [IO.FileMode]::CreateNew)
$taskZip = [IO.Compression.ZipArchive]::new($taskStream, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($taskRelative in @('dancevault.php','src/crypto.php','README.md','LICENSE.md','server/nginx.conf.example')) {
        $taskEntry = $taskZip.CreateEntry('dancevault/' + $taskRelative)
        $taskEntry.LastWriteTime = [DateTimeOffset]::new(2026,10,8,0,0,0,[TimeSpan]::Zero)
        $taskInput = [IO.File]::OpenRead((Join-Path $taskRoot $taskRelative))
        $taskOutput = $taskEntry.Open()
        try { $taskInput.CopyTo($taskOutput) } finally { $taskInput.Dispose(); $taskOutput.Dispose() }
    }
} finally { $taskZip.Dispose(); $taskStream.Dispose() }
$taskHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $taskZipPath).Hash.ToLowerInvariant()
[IO.File]::WriteAllText((Join-Path $taskDist "SHA256SUMS-$taskVersion.txt"), "$taskHash  $taskZipName`n", [Text.UTF8Encoding]::new($false))
Write-Output "$taskHash  $taskZipPath"
