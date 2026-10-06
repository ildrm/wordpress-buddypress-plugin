<?php
if (PHP_OS_FAMILY!=='Linux' || dirname(__DIR__)!=='/workspace') throw new RuntimeException('Isolated Linux container required.');
$minor=PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
$results=[];
foreach (['6.8','7.1','7.1.2'] as $version) {
    $started=microtime(true);
    $args=[PHP_BINARY,__DIR__.'/container-suite.php',$version];
    if ($minor==='8.3' && $version==='7.1.2') $args[]='--redis';
    $process=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot execute matrix cell.');
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($process);
    $path=dirname(__DIR__).'/.runtime/container-qa/php-'.$minor.'-wp-'.$version;
    file_put_contents($path.'/matrix.log',$output.$error);
    echo $output.$error;
    if ($code || trim($error)!=='') throw new RuntimeException('Matrix cell failed.');
    $xml=simplexml_load_file($path.'/phpunit.xml');
    if (!$xml || (int)$xml->testsuite['errors'] || (int)$xml->testsuite['failures'] || (int)$xml->testsuite['skipped']) throw new RuntimeException('Incomplete or failing matrix evidence.');
    $results[]=['php'=>PHP_VERSION,'wordpress'=>$version,'tests'=>(int)$xml->testsuite['tests'],'assertions'=>(int)$xml->testsuite['assertions'],'redis'=>$minor==='8.3' && $version==='7.1.2','seconds'=>round(microtime(true)-$started,3)];
}
file_put_contents(dirname(__DIR__).'/.runtime/container-qa/matrix-php-'.$minor.'.json',json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
