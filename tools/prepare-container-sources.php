<?php
// Archive unmodified local WP core for fast extraction on the container filesystem.
$root=dirname(__DIR__).'/.runtime';
$source=$root.'/wordpress';
$zip=new ZipArchive();
if (is_file($root.'/wordpress-core-7.1.zip') && $zip->open($root.'/wordpress-core-7.1.zip')===true) {
    $version=$zip->getFromName('wordpress/wp-includes/version.php');
    $zip->close();
    if (is_string($version) && preg_match('/\$wp_version\s*=\s*[\'\"]7\.1[\'\"]/',$version)) { echo "Verified existing WordPress 7.1 source archive.\n"; exit; }
    throw new RuntimeException('Existing source archive does not contain WordPress 7.1.');
}
if (!preg_match('/\$wp_version\s*=\s*[\'\"]7\.1[\'\"]/',file_get_contents($source.'/wp-includes/version.php'))) {
    $curl=curl_init('https://wordpress.org/wordpress-7.1.zip');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>50,CURLOPT_FAILONERROR=>true]);
    $data=curl_exec($curl);
    if ($data===false) throw new RuntimeException('Official WordPress 7.1 download failed: '.curl_error($curl));
    file_put_contents($root.'/wordpress-core-7.1.zip',$data);
    echo "Downloaded isolated official WordPress 7.1 source archive.\n";
    exit;
}
if ($zip->open($root.'/wordpress-core-7.1.zip',ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create test-source archive.');
foreach (['wp-admin','wp-includes'] as $directory) foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) $zip->addFile($file->getPathname(),'wordpress/'.str_replace('\\','/',substr($file->getPathname(),strlen($source)+1)));
}
foreach (new DirectoryIterator($source) as $file) if ($file->isFile() && $file->getFilename()!=='wp-config.php') $zip->addFile($file->getPathname(),'wordpress/'.$file->getFilename());
$zip->close();
echo "Prepared isolated container source archive.\n";
