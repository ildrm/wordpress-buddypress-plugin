<?php
// Disposable Linux container runtime; reuses unmodified source with isolated content and prefixes.
if (PHP_OS_FAMILY !== 'Linux' || dirname(__DIR__) !== '/workspace') throw new RuntimeException('Run only in the documented isolated test container.');
$repo = dirname(__DIR__);
$version = $argv[1] ?? '7.1';
if (!in_array($version, ['6.8','7.1','7.1.2'], true)) throw new RuntimeException('Unsupported test source.');
$archive = $version === '7.1' ? $repo.'/.runtime/wordpress-core-7.1.zip' : $repo.'/.runtime/compat-'.$version.'/wordpress.zip';
$source = '/tmp/bpi-sources/wp-'.$version.'/wordpress';
foreach ([$archive=>dirname($source),$repo.'/.runtime/buddypress.zip'=>'/tmp/bpi-sources'] as $file=>$target) {
    if (!is_dir($target)) mkdir($target,0777,true);
    $zip=new ZipArchive();
    if ($zip->open($file)!==true) throw new RuntimeException('Prepare the specified test-source archive first.');
    $zip->extractTo($target);
    $zip->close();
}
if (!is_file($source.'/wp-settings.php')) throw new RuntimeException('Prepare the specified official source first.');
$versionSource=file_get_contents($source.'/wp-includes/version.php');
if (!preg_match('/\$wp_version\s*=\s*[\'\"]([^\'\"]+)[\'\"]/',$versionSource,$match) || $match[1]!==$version) throw new RuntimeException('WordPress source does not match the claimed matrix version.');
$minor = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
$runtime = $repo.'/.runtime/container-qa/php-'.$minor.'-wp-'.$version;
$prefix = 'bpit_c'.str_replace('.','',$minor).'_'.str_replace('.','',$version).'_';
foreach (['','/wp-content/plugins','/wp-content/mu-plugins','/wp-content/themes'] as $path) if (!is_dir($runtime.$path)) mkdir($runtime.$path,0777,true);
foreach (['buddypress'=>'/tmp/bpi-sources/buddypress','wordpress-buddypress-plugin'=>$repo] as $slug=>$target) {
    $link=$runtime.'/wp-content/plugins/'.$slug;
    if (is_link($link) && readlink($link)!==$target) unlink($link);
    if (!file_exists($link)) symlink($target,$link);
}
if (in_array('--redis',$argv,true)) {
    $zip=new ZipArchive();
    if ($zip->open($repo.'/.runtime/redis-cache/redis-cache.zip')!==true) throw new RuntimeException('Prepare the official Redis cache test package first.');
    $zip->extractTo('/tmp/bpi-sources');
    $zip->close();
    $link=$runtime.'/wp-content/plugins/redis-cache';
    if (!file_exists($link)) symlink('/tmp/bpi-sources/redis-cache',$link);
    copy('/tmp/bpi-sources/redis-cache/includes/object-cache.php',$runtime.'/wp-content/object-cache.php');
} elseif (is_file($runtime.'/wp-content/object-cache.php')) {
    unlink($runtime.'/wp-content/object-cache.php');
}
$constants = [
    'DB_NAME'=>'bpi_tests','DB_USER'=>'root','DB_PASSWORD'=>'bpi_test_local','DB_HOST'=>'bpi-qa-mariadb:3306',
    'DB_CHARSET'=>'utf8mb4','DB_COLLATE'=>'','WP_DEBUG'=>true,'WP_DEBUG_DISPLAY'=>false,'WP_DEBUG_LOG'=>$runtime.'/debug.log',
    'DISABLE_WP_CRON'=>true,'WP_ENVIRONMENT_TYPE'=>'local','WP_HTTP_BLOCK_EXTERNAL'=>true,
    'WP_HOME'=>'http://127.0.0.1:8917','WP_SITEURL'=>'http://127.0.0.1:8917',
    'AUTH_KEY'=>'isolated-test-auth-key-only','SECURE_AUTH_KEY'=>'isolated-test-secure-key-only',
    'LOGGED_IN_KEY'=>'isolated-test-login-key-only','NONCE_KEY'=>'isolated-test-nonce-key-only',
    'ABSPATH'=>$source.'/','WP_CONTENT_DIR'=>$runtime.'/wp-content','WP_CONTENT_URL'=>'http://127.0.0.1:8917/wp-content',
    'WP_REDIS_HOST'=>'bpi-qa-redis','WP_REDIS_PORT'=>6379,'WP_REDIS_PREFIX'=>$prefix,'WP_REDIS_CLIENT'=>'predis',
];
$config = "<?php\n";
foreach ($constants as $name=>$value) $config .= 'define('.var_export($name,true).','.var_export($value,true).");\n";
$config .= '$table_prefix='.var_export($prefix,true).";\nrequire_once ABSPATH.'wp-settings.php';\n";
file_put_contents($runtime.'/wp-load.php',$config);
file_put_contents($runtime.'/wp-content/mu-plugins/bpi-test.php',"<?php\nwp_register_plugin_realpath(WP_PLUGIN_DIR.'/wordpress-buddypress-plugin/buddypress-intelligence.php');\nrequire_once ".var_export($repo.'/buddypress-intelligence.php',true).";\nadd_filter('pre_wp_mail','__return_true');\n");
define('WP_INSTALLING',true);
$_SERVER['HTTP_HOST']='127.0.0.1:8917';
$_SERVER['REQUEST_URI']='/';
require $runtime.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) wp_install('BPI container tests','bpi_admin','container@example.invalid',true,'','bpi-test-password');
else wp_upgrade();
update_option('active_plugins',['buddypress/bp-loader.php']);
update_option('bp-active-components',array_fill_keys(['activity','groups','friends','xprofile','notifications','settings','members'],1));
echo 'Prepared '.$runtime.' ('.$prefix.")\n";
