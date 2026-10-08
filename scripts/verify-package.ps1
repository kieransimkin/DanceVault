param([string]$ExpectedTag = '')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskSource = [IO.File]::ReadAllText((Join-Path $taskRoot 'dancevault.php'))
$taskVersion = [regex]::Match($taskSource, '(?m)^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)\r?$').Groups[1].Value
if (!$taskVersion -or ($ExpectedTag -and $ExpectedTag -cne "v$taskVersion")) { throw 'Tag/version mismatch' }
$taskName = "dancevault-$taskVersion-wordpress.zip"
$taskPath = Join-Path $taskRoot "dist/$taskName"
$taskHash = (Get-FileHash -LiteralPath $taskPath -Algorithm SHA256).Hash.ToLowerInvariant()
$taskManifest = [IO.File]::ReadAllText((Join-Path $taskRoot "dist/SHA256SUMS-$taskVersion.txt"))
if ($taskManifest -cne "$taskHash  $taskName`n") { throw 'Checksum mismatch' }
Add-Type -AssemblyName System.IO.Compression
$taskZip = [IO.Compression.ZipArchive]::new([IO.File]::OpenRead($taskPath))
try {
    $taskExpected = @('dancevault.php','src/crypto.php','README.md','AGENTS.md','CONTRIBUTING.md','LICENSE.md','server/nginx.conf.example','docs/branding/logo.svg','docs/branding/logo-monochrome.svg','docs/branding/logo.png','docs/branding/README.md')
    if ($taskZip.Entries.Count -ne $taskExpected.Count) { throw 'Unexpected archive entries' }
    foreach ($taskRelative in $taskExpected) {
        $taskEntry = $taskZip.GetEntry('dancevault/' + $taskRelative)
        if (!$taskEntry) { throw "Missing $taskRelative" }
        $taskInput = $taskEntry.Open()
        $taskSha = [Security.Cryptography.SHA256]::Create()
        try { $taskEntryHash = [BitConverter]::ToString($taskSha.ComputeHash($taskInput)).Replace('-','').ToLowerInvariant() } finally { $taskInput.Dispose(); $taskSha.Dispose() }
        if ($taskEntryHash -cne (Get-FileHash -LiteralPath (Join-Path $taskRoot $taskRelative) -Algorithm SHA256).Hash.ToLowerInvariant()) { throw 'Packaged source mismatch' }
    }
} finally { $taskZip.Dispose() }
Write-Output "Verified $taskName ($taskHash)"
