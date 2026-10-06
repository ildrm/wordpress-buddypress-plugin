$ErrorActionPreference = 'Stop'
$env:COMPOSER_CACHE_DIR = Join-Path $PSScriptRoot '..\.runtime\composer-cache'
$env:COMPOSER_HOME = Join-Path $PSScriptRoot '..\.runtime\composer-home'
$env:COMPOSER_IPRESOLVE = '4'
rtk proxy composer install --no-interaction --prefer-dist
exit $LASTEXITCODE
