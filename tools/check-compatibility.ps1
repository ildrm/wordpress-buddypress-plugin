$ErrorActionPreference = 'Stop'
$env:BPI_WP_PATH = Join-Path $PSScriptRoot '..\.runtime\compat-6.8\wordpress'
rtk proxy php tools/activate-runtime.php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
rtk proxy php .runtime/phpunit.phar --log-junit .runtime/phpunit-wp68-results.xml
exit $LASTEXITCODE
