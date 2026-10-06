<?php
$root = dirname(__DIR__) . '/.runtime';
$paths = [];
foreach (['wpcs','phpcsutils','phpcsextra','wp-stubs'] as $name) {
    $target = $root . '/standards/' . $name;
    if (!is_dir($target)) mkdir($target,0777,true);
    $zip = new ZipArchive();
    if ($zip->open($root . '/' . $name . '.zip') !== true) throw new RuntimeException('Missing archive: '.$name);
    $zip->extractTo($target);
    $directory = glob($target.'/*',GLOB_ONLYDIR)[0];
    if ($name !== 'wp-stubs') $paths[] = $directory;
    else copy($directory.'/wordpress-stubs.php',$root.'/wordpress-stubs.php');
}
file_put_contents($root . '/standards-paths.txt',implode(',',$paths));
echo implode("\n",$paths)."\n";
