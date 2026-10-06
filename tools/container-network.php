<?php
if (PHP_OS_FAMILY!=='Linux' || dirname(__DIR__)!=='/workspace') throw new RuntimeException('Isolated Linux container required.');
$zip=new ZipArchive();
foreach ([dirname(__DIR__).'/.runtime/compat-7.1.2/wordpress.zip'=>'/tmp/bpi-network',dirname(__DIR__).'/.runtime/buddypress.zip'=>'/tmp/bpi-network',dirname(__DIR__).'/.runtime/redis-cache/redis-cache.zip'=>'/tmp/bpi-network'] as $file=>$target) {
    if (!is_dir($target)) mkdir($target,0777,true);
    if ($zip->open($file)!==true) throw new RuntimeException('Prepare official QA sources first.');
    $zip->extractTo($target);$zip->close();
}
$content=dirname(__DIR__).'/.runtime/container-network-qa/wp-content';
foreach (['/plugins','/mu-plugins','/themes'] as $path) if (!is_dir($content.$path)) mkdir($content.$path,0777,true);
foreach (['buddypress'=>'/tmp/bpi-network/buddypress','redis-cache'=>'/tmp/bpi-network/redis-cache'] as $slug=>$target) if (!file_exists($content.'/plugins/'.$slug)) symlink($target,$content.'/plugins/'.$slug);
copy('/tmp/bpi-network/redis-cache/includes/object-cache.php',$content.'/object-cache.php');
file_put_contents($content.'/mu-plugins/bpi-test.php',"<?php\nrequire_once ".var_export(dirname(__DIR__).'/buddypress-intelligence.php',true).";\nadd_filter('pre_wp_mail','__return_true');\nadd_filter('bp_email_use_wp_mail','__return_true');\n");
putenv('BPI_NETWORK_SOURCE=/tmp/bpi-network/wordpress');
putenv('BPI_NETWORK_CONTENT='.$content);
putenv('BPI_QA_DB_HOST=bpi-qa-mariadb:3306');
$log='';
foreach ([['tools/network-setup.php','--install'],['tools/network-verify.php']] as $args) {
    $process=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start network check.');
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    $code=proc_close($process);
    $log.=$output.$error;
    echo $output.$error;
    $marker=$args[0]==='tools/network-setup.php'?'Isolated network installed':'Network verification complete.';
    if ($code || trim($error)!=='' || !str_contains($output,$marker)) throw new RuntimeException('Redis Multisite verification did not complete.');
}
if (substr_count($log,'PASS: ')!==15) throw new RuntimeException('Incomplete network verification evidence.');
file_put_contents(dirname($content).'/network-results.log',$log);
