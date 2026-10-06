<?php
// Each PHP / WordPress matrix cell owns a separate table prefix and report.
if (PHP_OS_FAMILY !== 'Linux' || dirname(__DIR__) !== '/workspace') throw new RuntimeException('Container test helper only.');
$version = $argv[1] ?? '7.1';
if (!in_array($version,['6.8','7.1','7.1.2'],true)) throw new RuntimeException('Invalid test version.');
$minor=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
$path=dirname(__DIR__).'/.runtime/container-qa/php-'.$minor.'-wp-'.$version;
putenv('BPI_WP_PATH='.$path);
$prepare=['tools/container-runtime.php',$version];
if (in_array('--redis',$argv,true)) $prepare[]='--redis';
foreach ([$prepare,['tools/activate-runtime.php'],['.runtime/phpunit.phar','--log-junit',$path.'/phpunit.xml','--display-notices','--display-warnings']] as $args) {
    if ($args[0]==='.runtime/phpunit.phar' && is_file($path.'/phpunit.xml')) unlink($path.'/phpunit.xml');
    $process=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot execute matrix check.');
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($process);
    echo $output.$error;
    $marker=$args[0]===$prepare[0]?'Prepared ':($args[0]==='tools/activate-runtime.php'?'Activated: WP ':'OK (');
    if ($code || trim($error)!=='' || !str_contains($output,$marker)) throw new RuntimeException('Release verification step did not complete successfully.');
}
