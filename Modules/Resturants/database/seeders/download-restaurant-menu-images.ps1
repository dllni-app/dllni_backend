$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
Set-Location $PSScriptRoot
$data = Get-Content 'restaurant-menu-catalog.json' -Raw -Encoding UTF8 | ConvertFrom-Json
$dir = Join-Path $PSScriptRoot 'restaurant-menu-images'
New-Item -ItemType Directory -Force $dir | Out-Null
$cfg = @()
foreach ($p in $data.images.PSObject.Properties) {
    $out = Join-Path $dir ($p.Name + '.jpg')
    if ((Test-Path $out) -and (Get-Item $out).Length -gt 2000) { continue }
    $cfg += ('url = "' + $p.Value + '"')
    $cfg += ('output = "' + ($out -replace '\\','/') + '"')
    $cfg += 'next'
}
if ($cfg.Count -gt 0) {
    $temp = Join-Path $env:TEMP ('restaurant-images-' + [guid]::NewGuid() + '.cfg')
    [IO.File]::WriteAllLines($temp, $cfg[0..($cfg.Count-2)], [Text.UTF8Encoding]::new($false))
    try { & curl.exe --parallel --parallel-max 12 --location --fail --silent --show-error --retry 2 --connect-timeout 15 --max-time 60 --config $temp } finally { Remove-Item $temp -Force }
}
$missing = @($data.images.PSObject.Properties | Where-Object { $f = Join-Path $dir ($_.Name + '.jpg'); !(Test-Path $f) -or (Get-Item $f).Length -lt 2000 } | ForEach-Object Name)
Write-Output ('Downloaded: ' + (Get-ChildItem $dir -File | Measure-Object).Count + '; missing: ' + ($missing -join ', '))
if ($missing.Count -gt 0) { exit 1 }
