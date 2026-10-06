<?php
spl_autoload_register(static function (string $class): void {
    $prefix = 'BuddyPressIntelligence\\';
    if (str_starts_with($class, $prefix)) {
        require_once dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$runtime = getenv('BPI_WP_PATH') ?: dirname(__DIR__) . '/.runtime/wordpress';
if (is_file($runtime . '/wp-load.php')) {
    $_SERVER['HTTP_HOST'] = '127.0.0.1:8917';
    $_SERVER['REQUEST_URI'] = '/';
    require_once $runtime . '/wp-load.php';
    add_filter('bp_email_use_wp_mail', '__return_true');
    add_filter('pre_wp_mail', '__return_true');
    require_once __DIR__ . '/Integration/CommunityTestCase.php';
}
