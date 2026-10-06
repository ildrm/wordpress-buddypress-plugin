<?php
$report=json_decode(file_get_contents(dirname(__DIR__).'/.runtime/phpcs-after.json'),true);
$sources=[];
foreach($report['files'] as $file=>$result) foreach($result['messages'] as $message) $sources[$message['source']][]=basename($file).':'.$message['line'].' '.$message['message'];
foreach($sources as $source=>$messages) echo $source.' ('.count($messages).")\n".implode("\n",array_slice($messages,0,5))."\n";
