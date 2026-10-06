<?php
$root=dirname(__DIR__);
require __DIR__.'/package.php';
$first=hash_file('sha256',$root.'/dist/buddypress-intelligence-1.0.0.zip');
require __DIR__.'/package.php';
$second=hash_file('sha256',$root.'/dist/buddypress-intelligence-1.0.0.zip');
if(!hash_equals($first,$second)) throw new RuntimeException('Repeated release builds are not reproducible.');
echo "PASS: repeated builds produce an identical verified archive.\n";
