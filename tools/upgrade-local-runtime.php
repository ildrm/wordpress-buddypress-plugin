<?php
// Only the repository-owned disposable WordPress source is upgraded, never an installed site.
$root=dirname(__DIR__).'/.runtime';
$target=$root.'/wordpress';
$config=file_get_contents($target.'/wp-config.php');
if (!str_contains($config,"'bpi_tests'") || !str_contains($config,"'bpit_'")) throw new RuntimeException('Expected isolated local test configuration.');
$zip=new ZipArchive();
if ($zip->open($root.'/compat-7.1.2/wordpress.zip')!==true) throw new RuntimeException('Prepare the official WordPress 7.1.2 test source first.');
$zip->extractTo($root);
$zip->close();
echo "Upgraded isolated WordPress core to official 7.1.2.\n";
