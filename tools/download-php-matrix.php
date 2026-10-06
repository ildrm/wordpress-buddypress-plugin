<?php
// Official Windows binaries, integrity checked before extracting into the isolated test directory.
if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Windows test helper only.');
$root = dirname(__DIR__) . '/.runtime/php-matrix';
if (!is_dir($root)) mkdir($root, 0777, true);
function downloadPhpFile(string $url): string {
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>50, CURLOPT_FAILONERROR=>true]);
    $data = curl_exec($curl);
    if ($data === false) throw new RuntimeException('Official PHP download failed: ' . curl_error($curl));
    return $data;
}
$releases = json_decode(downloadPhpFile('https://downloads.php.net/~windows/releases/releases.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['8.1', '8.3'] as $minor) {
    $release = $releases[$minor];
    $version = $release['version'];
    $archive = $release['nts-vs16-x64']['zip'];
    if (!preg_match('/^8\.[13]\.\d+$/', $version) || basename($archive['path']) !== $archive['path']) throw new RuntimeException('Invalid official release metadata.');
    $directory = $root . '/' . $version;
    $file = $root . '/' . $archive['path'];
    if (!is_file($file) || hash_file('sha256', $file) !== $archive['sha256']) {
        echo 'Downloading official PHP ' . $version . "\n";
        $data = downloadPhpFile('https://downloads.php.net/~windows/releases/' . $archive['path']);
        if (hash('sha256', $data) !== $archive['sha256']) throw new RuntimeException('PHP archive checksum mismatch.');
        file_put_contents($file, $data);
    }
    if (!is_dir($directory)) mkdir($directory, 0777, true);
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('Invalid PHP archive.');
    $zip->extractTo($directory);
    $zip->close();
    $ini = 'extension_dir="' . $directory . "/ext\"\n";
    foreach (['mysqli', 'curl', 'mbstring', 'openssl', 'zip', 'intl'] as $extension) $ini .= 'extension=' . $extension . "\n";
    $ini .= "memory_limit=2G\ndisplay_errors=1\ndate.timezone=UTC\n";
    file_put_contents($directory . '/php.ini', $ini);
    echo 'Verified PHP ' . $version . ': ' . $archive['sha256'] . "\n";
}
