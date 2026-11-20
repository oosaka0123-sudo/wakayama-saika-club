# Windows / PC04: create a safe Lolipop upload package for /azuma/
# Run from this repository: powershell -ExecutionPolicy Bypass -File tools/package-azuma.ps1
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$out = Join-Path $repo 'dist'
$stage = Join-Path $out 'azuma'
$zip = Join-Path $out 'azuma-lolipop.zip'
if (!(Test-Path (Join-Path $repo 'catch\index.html'))) {
    throw 'This is not the WSC LINE staging branch.'
}
New-Item -ItemType Directory -Force -Path $out | Out-Null
if (Test-Path $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
New-Item -ItemType Directory -Force -Path $stage | Out-Null

# Only the site. No GitHub verification keys, development tests, notes, or repository metadata.
foreach ($file in @('index.html','.htaccess')) {
    Copy-Item -LiteralPath (Join-Path $repo $file) -Destination (Join-Path $stage $file) -Force
}
foreach ($dir in @('about','assets','catch','contact','css','gallery','join','js','line-api')) {
    Copy-Item -LiteralPath (Join-Path $repo $dir) -Destination (Join-Path $stage $dir) -Recurse -Force
}
# Never ship secrets, working state, previous uploads or any local private data.
$privatePaths = @(
    'line-api\config.local.php',
    'line-api\data\state.json'
)
foreach ($path in $privatePaths) {
    $target = Join-Path $stage $path
    if (Test-Path $target) { Remove-Item -LiteralPath $target -Force }
}
$uploads = Join-Path $stage 'line-api\uploads'
Get-ChildItem -LiteralPath $uploads -Force -File |
    Where-Object { $_.Name -ne '.htaccess' } |
    Remove-Item -Force
$data = Join-Path $stage 'line-api\data'
Get-ChildItem -LiteralPath $data -Force -File |
    Where-Object { $_.Name -ne '.htaccess' } |
    Remove-Item -Force

if (Test-Path (Join-Path $stage 'line-api\config.local.php')) { throw 'Secret file leaked into build' }
if (Test-Path (Join-Path $stage 'line-api\data\state.json')) { throw 'Private data leaked into build' }
foreach ($path in @('.htaccess','index.html','catch\index.html','line-api\webhook.php','line-api\admin\index.php','line-api\data\.htaccess','line-api\uploads\.htaccess')) {
    if (!(Test-Path (Join-Path $stage $path))) { throw "Missing required deployment file: $path" }
}
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path $zip) { Remove-Item -LiteralPath $zip -Force }
[System.IO.Compression.ZipFile]::CreateFromDirectory($stage, $zip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
Write-Host "Created $zip"
Write-Host "Upload the CONTENTS of this ZIP to the Lolipop folder /azuma/ (not /azuma/azuma/)."
Write-Host 'Do NOT expose line-api/config.local.php or line-api/data/state.json.'
