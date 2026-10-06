<?php
if (PHP_OS_FAMILY!=='Linux' || dirname(__DIR__)!=='/workspace') throw new RuntimeException('Isolated Linux container required.');
$version='7.1.2';
$path=dirname(__DIR__).'/.runtime/container-qa/php-'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'-wp-'.$version;
putenv('BPI_WP_PATH='.$path);
if (is_file($path.'/extended-results.json')) unlink($path.'/extended-results.json');
foreach ([['tools/container-runtime.php',$version,'--redis'],['tools/activate-runtime.php'],['tools/extended-verify.php']] as $args) {
    passthru(escapeshellarg(PHP_BINARY).' '.implode(' ',array_map('escapeshellarg',$args)),$code);
    if ($code) exit($code);
}
$report=is_file($path.'/extended-results.json')?json_decode(file_get_contents($path.'/extended-results.json'),true):null;
if (!$report || $report['checks']!==15 || !$report['real_redis']) throw new RuntimeException('Incomplete extended release verification evidence.');
