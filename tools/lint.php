<?php
$root = dirname(__DIR__);
$files = array_merge(glob($root . '/src/*.php'), glob($root . '/*.php'), glob($root . '/tests/Unit/*.php'), glob($root . '/tests/Integration/*.php'), glob($root . '/tools/*.php'));
$failed = 0;
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);
    if ($code !== 0) { echo implode("\n", $output) . "\n"; ++$failed; }
    $output = [];
}
echo count($files) . ' PHP files checked; failures: ' . $failed . "\n";
exit($failed ? 1 : 0);
