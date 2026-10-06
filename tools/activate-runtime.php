<?php
$_SERVER['HTTP_HOST'] = '127.0.0.1:8917';
$_SERVER['REQUEST_URI'] = '/';
define('WP_ADMIN', true);
require (getenv('BPI_WP_PATH') ?: dirname(__DIR__) . '/.runtime/wordpress') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
bp_version_updater();
bp_update_option('_bp_db_version',bp_get_db_version());
bp_update_option('bp-active-components',array_fill_keys(['activity','groups','friends','xprofile','notifications','settings','members'],1));
bp_core_install(array_fill_keys(['activity','groups','friends','xprofile','notifications','members'], 1));
BuddyPressIntelligence\Core::activate();
wp_set_current_user(1);
$page = get_page_by_path('community');
if (!$page) wp_insert_post(['post_title'=>'Community', 'post_name'=>'community', 'post_status'=>'publish', 'post_type'=>'page', 'post_content'=>'[bpi_community]']);
echo 'Activated: WP ' . get_bloginfo('version') . ', BP ' . bp_get_version() . ', PHP ' . PHP_VERSION . "\n";
