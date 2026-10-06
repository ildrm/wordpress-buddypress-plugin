<?php
// Explicit release allowlist avoids traversing test runtimes and their junctions.
$root=dirname(__DIR__);
$files=['buddypress-intelligence.php','uninstall.php','readme.txt','README.md','CHANGELOG.md','LICENSE'];
foreach(['src','assets','languages','docs'] as $directory) {
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,FilesystemIterator::SKIP_DOTS)) as $file) {
        if($file->isLink()) throw new RuntimeException('Symlink in release tree.');
        if($file->isFile()) $files[]=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    }
}
sort($files);
$dist=$root.'/dist';if(!is_dir($dist)) mkdir($dist);
$zip=new ZipArchive();$path=$dist.'/buddypress-intelligence-1.0.0.zip';
if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Could not create archive.');
$manifest=[];
foreach($files as $file) {
    if(!is_file($root.'/'.$file)) throw new RuntimeException('Missing release file: '.$file);
    $name='buddypress-intelligence/'.$file;
    $zip->addFile($root.'/'.$file,$name);
    $zip->setMtimeName($name,946684800); // Reproducible ZIP timestamps.
    $manifest[$file]=hash_file('sha256',$root.'/'.$file);
}
$zip->close();
$verify=new ZipArchive();
if($verify->open($path)!==true || $verify->numFiles!==count($files)) throw new RuntimeException('Archive verification failed.');
foreach($manifest as $file=>$hash) if(hash('sha256',$verify->getFromName('buddypress-intelligence/'.$file))!==$hash) throw new RuntimeException('Archive content mismatch: '.$file);
$verify->close();
file_put_contents($dist.'/manifest.json',json_encode(['version'=>'1.0.0','archive_sha256'=>hash_file('sha256',$path),'files'=>$manifest],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo count($files).' release files verified. SHA-256: '.hash_file('sha256',$path)."\n";
