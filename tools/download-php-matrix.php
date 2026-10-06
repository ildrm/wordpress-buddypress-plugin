<?php
// Optional Windows compatibility runtimes from the official PHP archive.
if(PHP_OS_FAMILY!=='Windows') throw new RuntimeException('Windows test helper only.');
$root=dirname(__DIR__).'/.runtime/php-matrix';
if(!is_dir($root)) mkdir($root,0777,true);
foreach(['8.1.31','8.3.17'] as $version) {
    $directory=$root.'/'.$version;
    if(is_file($directory.'/php.exe')) continue;
    $name='php-'.$version.'-nts-Win32-vs16-x64.zip';
    $curl=curl_init('https://windows.php.net/downloads/releases/archives/'.$name);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>90,CURLOPT_FAILONERROR=>true]);
    $data=curl_exec($curl);
    if($data===false || strlen($data)<1000) throw new RuntimeException('Official PHP download failed: '.curl_error($curl));
    file_put_contents($root.'/'.$name,$data);
    if(!is_dir($directory)) mkdir($directory,0777,true);
    $zip=new ZipArchive();
    if($zip->open($root.'/'.$name)!==true) throw new RuntimeException('Invalid PHP archive.');
    $zip->extractTo($directory);$zip->close();
    $ini="extension_dir=\"".$directory."/ext\"\n";
    foreach(['mysqli','curl','mbstring','openssl','zip','intl'] as $extension) $ini.='extension='.$extension."\n";
    $ini.="memory_limit=1G\ndisplay_errors=1\ndate.timezone=UTC\n";
    file_put_contents($directory.'/php.ini',$ini);
    echo 'Downloaded official PHP '.$version."\n";
}
