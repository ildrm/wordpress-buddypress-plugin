<?php
// Test tooling only. Fetches official upstream source; never runs from plugin hooks.
$root = dirname(__DIR__) . '/.runtime';
if (!is_dir($root)) mkdir($root, 0777, true);
$c = curl_init('https://downloads.wordpress.org/plugin/buddypress.14.5.2.zip');
curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_FAILONERROR => true]);
$r = curl_exec($c);
if ($r === false || strlen($r) < 1000) { fwrite(STDERR, curl_error($c) . ' Download incomplete.'); exit(1); }
file_put_contents($root . '/buddypress.zip', $r);
$zip = new ZipArchive();
if ($zip->open($root . '/buddypress.zip') !== true) { exit(2); }
$zip->extractTo($root);
echo "Downloaded and extracted BuddyPress: $root; bytes=" . strlen($r) . "; files=" . $zip->numFiles . "; exists=" . (int)file_exists($root . '/buddypress.zip') . "\n";
echo implode("\n", glob($root . '/*')) . "\n";
