param(
    [ValidateRange(1, 65535)][int]$Port = 8000
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env'
if (!(Test-Path $envFile) -or !(Get-Content $envFile | Where-Object { $_ -match '^APP_ENV\s*=\s*local\s*$' })) {
    throw 'This launcher is for the local development environment only.'
}
$php = (Get-Command php -CommandType Application | Select-Object -First 1).Source
$extension = Join-Path (Split-Path $php) 'ext\php_opcache.dll'
if (!(Test-Path $extension)) { throw "OPcache extension not found: $extension" }
$router = Join-Path $root 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php'
$arguments = @(
    '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1',
    '-d', 'opcache.memory_consumption=128', '-d', 'opcache.max_accelerated_files=30000',
    '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0',
    '-d', 'xdebug.mode=off', '-S', "0.0.0.0:$Port", $router
)
if (!((& $php -m) -match 'Zend OPcache')) {
    $arguments = @('-d', "zend_extension=$extension") + $arguments
}
Push-Location (Join-Path $root 'public')
try { & $php @arguments; exit $LASTEXITCODE }
finally { Pop-Location }
