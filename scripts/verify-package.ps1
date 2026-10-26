param([string]$ExpectedTag = '')
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskSource = [IO.File]::ReadAllText((Join-Path $taskRoot 'dancevault.php'))
$taskVersion = [regex]::Match($taskSource, '(?m)^ \* Version: ([0-9]+\.[0-9]+\.[0-9]+)\r?$').Groups[1].Value
if (!$taskVersion -or ($ExpectedTag -and $ExpectedTag -cne "v$taskVersion")) { throw 'Tag/version mismatch' }
$taskReadme = [IO.File]::ReadAllText((Join-Path $taskRoot 'readme.txt'))
if (![regex]::IsMatch($taskReadme, '(?m)^Stable tag: ' + [regex]::Escape($taskVersion) + '\r?$')) { throw 'Directory readme/version mismatch' }
$taskName = "cipherparcel-$taskVersion-wordpress.zip"
$taskPath = Join-Path $taskRoot "dist/$taskName"
$taskHash = (Get-FileHash -LiteralPath $taskPath -Algorithm SHA256).Hash.ToLowerInvariant()
$taskManifest = [IO.File]::ReadAllText((Join-Path $taskRoot "dist/SHA256SUMS-$taskVersion.txt"))
if ($taskManifest -cne "$taskHash  $taskName`n") { throw 'Checksum mismatch' }
Add-Type -AssemblyName System.IO.Compression
$taskZip = [IO.Compression.ZipArchive]::new([IO.File]::OpenRead($taskPath))
try {
    $taskExpected = @('dancevault.php','src/crypto.php','readme.txt','license.txt','README.md','AGENTS.md','CONTRIBUTING.md','LICENSE.md','CHANGELOG.md','docs/hosting-acceptance.md','docs/branding/logo.svg','docs/branding/logo-monochrome.svg','docs/branding/logo.png','docs/branding/README.md')
    if ($taskZip.Entries.Count -ne $taskExpected.Count) { throw 'Unexpected archive entries' }
    foreach ($taskEntry in $taskZip.Entries) {
        if ($taskEntry.FullName -like 'cipherparcel/server/*' -or $taskEntry.FullName -like '*.conf' -or $taskEntry.FullName -like '*.example') {
            throw "Source-only server configuration in WordPress.org package: $($taskEntry.FullName)"
        }
    }
    foreach ($taskRelative in $taskExpected) {
        $taskEntry = $taskZip.GetEntry('cipherparcel/' + $taskRelative)
        if (!$taskEntry) { throw "Missing $taskRelative" }
        $taskInput = $taskEntry.Open()
        $taskSha = [Security.Cryptography.SHA256]::Create()
        try { $taskEntryHash = [BitConverter]::ToString($taskSha.ComputeHash($taskInput)).Replace('-','').ToLowerInvariant() } finally { $taskInput.Dispose(); $taskSha.Dispose() }
        if ($taskEntryHash -cne (Get-FileHash -LiteralPath (Join-Path $taskRoot $taskRelative) -Algorithm SHA256).Hash.ToLowerInvariant()) { throw 'Packaged source mismatch' }
    }
} finally { $taskZip.Dispose() }
Write-Output "Verified $taskName ($taskHash)"
