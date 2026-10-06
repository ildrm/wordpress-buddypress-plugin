<?php
$root = dirname(__DIR__) . '/.runtime';
foreach ([
    'phpunit.phar' => 'https://phar.phpunit.de/phpunit-10.5.66.phar',
    'phpcs.phar' => 'https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/4.0.4/phpcs.phar',
    'phpcbf.phar' => 'https://github.com/PHPCSStandards/PHP_CodeSniffer/releases/download/4.0.4/phpcbf.phar',
    'phpstan.phar' => 'https://github.com/phpstan/phpstan/releases/download/2.2.17/phpstan.phar',
    'wpcs.zip' => 'https://api.github.com/repos/WordPress/WordPress-Coding-Standards/zipball/3.2.0',
    'phpcsutils.zip' => 'https://api.github.com/repos/PHPCSStandards/PHPCSUtils/zipball/1.1.2',
    'phpcsextra.zip' => 'https://api.github.com/repos/PHPCSStandards/PHPCSExtra/zipball/1.2.0',
    'wp-stubs.zip' => 'https://api.github.com/repos/php-stubs/wordpress-stubs/zipball/da405fa',
] as $file => $url) {
    if (is_file($root.'/'.$file) && !in_array($file,['phpcs.phar','phpcbf.phar'],true)) continue;
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_TIMEOUT=>45, CURLOPT_FAILONERROR=>true, CURLOPT_USERAGENT=>'BPI-local-tests']);
    $data = curl_exec($curl);
    if ($data === false || strlen($data) < 1000) { echo "$file: failed " . curl_error($curl) . "\n"; continue; }
    file_put_contents($root . '/' . $file, $data);
    echo "$file: " . strlen($data) . " bytes\n";
}
