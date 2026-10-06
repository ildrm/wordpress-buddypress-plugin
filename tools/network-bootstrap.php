<?php
// Separate network table prefix within the isolated local test database.
$network_install = in_array('--install', $argv ?? [], true);
define('DB_NAME', 'bpi_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', 'bpi_test_local');
define('DB_HOST', getenv('BPI_QA_DB_HOST') ?: '127.0.0.1:33307');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', dirname(__DIR__) . '/.runtime/network-debug.log');
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('AUTH_KEY', 'isolated-network-test-key');
define('SECURE_AUTH_KEY', 'isolated-network-test-secure-key');
define('LOGGED_IN_KEY', 'isolated-network-login-key');
define('NONCE_KEY', 'isolated-network-nonce-key');
define('ABSPATH', (getenv('BPI_NETWORK_SOURCE') ?: dirname(__DIR__) . '/.runtime/wordpress') . '/');
if (getenv('BPI_NETWORK_CONTENT')) {
    $content=getenv('BPI_NETWORK_CONTENT');
    if (!str_starts_with($content,dirname(__DIR__).'/.runtime/')) throw new RuntimeException('Network test content must stay within the project runtime.');
    define('WP_CONTENT_DIR',$content);
    define('WP_CONTENT_URL','http://127.0.0.1/wp-content');
    define('WP_REDIS_HOST','bpi-qa-redis');
    define('WP_REDIS_PORT',6379);
    define('WP_REDIS_CLIENT','predis');
    define('WP_REDIS_PREFIX','bpin_qa_');
}
$table_prefix = 'bpin_';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
if ($network_install) {
    define('WP_INSTALLING', true);
} else {
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', false);
    define('DOMAIN_CURRENT_SITE', '127.0.0.1');
    define('PATH_CURRENT_SITE', '/');
    define('SITE_ID_CURRENT_SITE', 1);
    define('BLOG_ID_CURRENT_SITE', 1);
}
require ABSPATH . 'wp-settings.php';
