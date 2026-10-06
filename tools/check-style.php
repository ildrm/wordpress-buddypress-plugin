<?php
$root=dirname(__DIR__);
$paths=trim(file_get_contents($root.'/.runtime/standards-paths.txt'));
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/.runtime/phpcs.phar').' --config-set installed_paths '.escapeshellarg($paths),$status);
exit($status);
