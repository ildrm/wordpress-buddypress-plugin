<?php
$repo=dirname(__DIR__);
$path=$repo.'/.runtime/compat-6.8/wordpress';
if(!is_file($path.'/wp-load.php')) throw new RuntimeException('Prepare the compatibility runtime first.');
putenv('BPI_WP_PATH='.$path);
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/tools/activate-runtime.php'),$code);
if($code) exit($code);
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/.runtime/phpunit.phar').' --log-junit '.escapeshellarg($repo.'/.runtime/phpunit-wp68-results.xml'),$code);
exit($code);
