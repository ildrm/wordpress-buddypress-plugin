<?php
// Official upstream distributions for release acceptance; never overwrite an installed site.
$root=dirname(__DIR__).'/.runtime';
foreach (['redis-cache'=>'https://downloads.wordpress.org/plugin/redis-cache.2.7.0.zip','compat-7.1.2'=>'https://wordpress.org/wordpress-7.1.2.zip'] as $directory=>$url) {
    $target=$root.'/'.$directory;
    if (!is_dir($target)) mkdir($target,0777,true);
    $file=$target.'/'.($directory==='redis-cache'?'redis-cache.zip':'wordpress.zip');
    if (!is_file($file)) {
        $curl=curl_init($url);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>50,CURLOPT_FAILONERROR=>true]);
        $data=curl_exec($curl);
        if ($data===false) throw new RuntimeException('Official test source download failed: '.curl_error($curl));
        file_put_contents($file,$data);
    }
    $zip=new ZipArchive();
    if ($zip->open($file)!==true) throw new RuntimeException('Invalid test archive.');
    $zip->extractTo($target);
    $zip->close();
    echo $directory.': '.hash_file('sha256',$file)."\n";
}
