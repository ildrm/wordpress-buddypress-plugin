<?php
// Official source is isolated under .runtime; never changes an installed site.
$version = getenv('BPI_WP_VERSION') ?: '6.8';
if (!preg_match('/^\d+\.\d+(?:\.\d+)?$/', $version)) throw new RuntimeException('Invalid version.');
$runtime = dirname(__DIR__) . '/.runtime';
if (!is_dir($runtime)) mkdir($runtime, 0777, true);
$curl = curl_init('https://wordpress.org/wordpress-' . $version . '.zip');
curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>120, CURLOPT_FAILONERROR=>true]);
$archive = curl_exec($curl);
if ($archive === false || strlen($archive)<1000) throw new RuntimeException('WordPress download failed: '.curl_error($curl));
$file = $runtime . '/wordpress.zip';
file_put_contents($file, $archive);
$zip = new ZipArchive();
if ($zip->open($file)!==true) throw new RuntimeException('Invalid WordPress archive.');
$zip->extractTo($runtime);
$zip->close();
echo "Official WordPress $version extracted under .runtime.\n";
