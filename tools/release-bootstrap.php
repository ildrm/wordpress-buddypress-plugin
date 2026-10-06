<?php
// Separate release-test prefix. No production credentials or table deletion.
$releaseRoot=dirname(__DIR__).'/.runtime/release-test';
$sourceRoot=getenv('BPI_WP_PATH') ?: dirname(__DIR__).'/.runtime/wordpress';
if(!is_file($sourceRoot.'/wp-settings.php')) throw new RuntimeException('Prepare the isolated WordPress source first.');
define('ABSPATH',$sourceRoot.'/');
define('WP_CONTENT_DIR',$releaseRoot.'/wp-content');
define('WP_CONTENT_URL','http://127.0.0.1:8917/release-content');
define('WP_PLUGIN_DIR',WP_CONTENT_DIR.'/plugins');
define('DB_NAME','bpi_tests');
define('DB_USER','root');
define('DB_PASSWORD','bpi_test_local');
define('DB_HOST','127.0.0.1:33307');
define('DB_CHARSET','utf8mb4');
define('DB_COLLATE','');
define('WP_DEBUG',true);
define('WP_DEBUG_DISPLAY',false);
define('WP_DEBUG_LOG',$releaseRoot.'/debug.log');
define('WP_HTTP_BLOCK_EXTERNAL',true);
define('DISABLE_WP_CRON',true);
define('WP_HOME','http://127.0.0.1:8917');
define('WP_SITEURL','http://127.0.0.1:8917');
define('AUTH_KEY','isolated-release-test-only');
define('NONCE_KEY','isolated-release-test-only');
$table_prefix='bpit_release_';
$_SERVER['HTTP_HOST']='127.0.0.1:8917';
$_SERVER['REQUEST_URI']='/';
require ABSPATH.'wp-settings.php';
add_filter('pre_wp_mail','__return_true');
