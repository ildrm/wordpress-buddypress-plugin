<?php
// Local test environment only; never connects to an existing WordPress database.
$repo = dirname(__DIR__);
$runtime = $repo . '/.runtime';
$wpRuntimePath = $runtime . '/wordpress';
if (!is_file($wpRuntimePath . '/wp-load.php') || !is_file($runtime . '/buddypress/bp-loader.php')) {
    fwrite(STDERR, "Supply WordPress source and run tools/download-runtime.php first.\n");
    exit(1);
}
function copyTree(string $source, string $target): void {
    if (!is_dir($target)) mkdir($target, 0777, true);
    foreach (new DirectoryIterator($source) as $file) {
        if ($file->isDot()) continue;
        $destination = $target . '/' . $file->getFilename();
        if ($file->isDir()) copyTree($file->getPathname(), $destination);
        else copy($file->getPathname(), $destination);
    }
}
copyTree($runtime . '/buddypress', $wpRuntimePath . '/wp-content/plugins/buddypress');
if (!is_dir($wpRuntimePath . '/wp-content/mu-plugins')) mkdir($wpRuntimePath . '/wp-content/mu-plugins', 0777, true);
$loader = "<?php\nwp_register_plugin_realpath(WP_PLUGIN_DIR . '/wordpress-buddypress-plugin/buddypress-intelligence.php');\nrequire_once " . var_export($repo . '/buddypress-intelligence.php', true) . ";\nadd_filter('pre_wp_mail', '__return_true');\n";
file_put_contents($wpRuntimePath . '/wp-content/mu-plugins/bpi-test.php', $loader);
$connection = new mysqli('127.0.0.1', 'root', 'bpi_test_local', '', 33307);
$connection->query('CREATE DATABASE IF NOT EXISTS bpi_tests CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$config = <<<'PHP'
<?php
define('DB_NAME', 'bpi_tests');
define('DB_USER', 'root');
define('DB_PASSWORD', 'bpi_test_local');
define('DB_HOST', '127.0.0.1:33307');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', __DIR__ . '/../debug.log');
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_HOME', 'http://127.0.0.1:8917');
define('WP_SITEURL', 'http://127.0.0.1:8917');
define('AUTH_KEY', 'isolated-test-auth-key-only');
define('SECURE_AUTH_KEY', 'isolated-test-secure-key-only');
define('LOGGED_IN_KEY', 'isolated-test-login-key-only');
define('NONCE_KEY', 'isolated-test-nonce-key-only');
$table_prefix = 'bpit_';
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once ABSPATH . 'wp-settings.php';
PHP;
file_put_contents($wpRuntimePath . '/wp-config.php', $config);
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8917';
$_SERVER['REQUEST_URI'] = '/';
require $wpRuntimePath . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) wp_install('BPI isolated test', 'bpi_admin', 'admin@example.invalid', true, '', 'bpi-test-password');
update_option('active_plugins', ['buddypress/bp-loader.php']);
update_option('bp-active-components', array_fill_keys(['activity','groups','friends','xprofile','notifications','settings','members'], 1));
echo "WordPress test database ready. Run tools/activate-runtime.php in a new process.\n";
