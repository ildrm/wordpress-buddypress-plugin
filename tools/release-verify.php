<?php
require __DIR__.'/release-bootstrap.php';
if(DB_NAME!=='bpi_tests' || $GLOBALS['wpdb']->prefix!=='bpit_release_') throw new RuntimeException('Isolated release prefix required.');
require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once buddypress()->plugin_dir.'bp-core/admin/bp-core-admin-schema.php';
bp_version_updater();
bp_update_option('_bp_db_version',bp_get_db_version());
wp_set_current_user(1);
function releaseCheck(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException('FAIL: '.$message);
    echo 'PASS: '.$message."\n";
}
$plugin='buddypress-intelligence/buddypress-intelligence.php';
$result=activate_plugin($plugin);
releaseCheck(!is_wp_error($result),'actual WordPress activation of extracted ZIP');
$db=new BuddyPressIntelligence\Database();
global $wpdb;
foreach(array_keys(BuddyPressIntelligence\Database::COLUMNS) as $table) {
    releaseCheck($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($db->table($table))))===$db->table($table),'table '.$table.' created');
}
releaseCheck((int)get_option('bpi_schema')===1,'schema committed');
releaseCheck(bp_current_user_can('bpi_manage_settings'),'root capabilities assigned');
releaseCheck((bool)wp_next_scheduled('bpi_maintenance'),'maintenance scheduled');
releaseCheck(!(new BuddyPressIntelligence\Settings())->enabled('ai'),'AI disabled on fresh activation');
deactivate_plugins($plugin);
releaseCheck(!wp_next_scheduled('bpi_maintenance'),'deactivation clears cron');
define('WP_UNINSTALL_PLUGIN',$plugin);
include WP_PLUGIN_DIR.'/buddypress-intelligence/uninstall.php';
releaseCheck($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($db->table('entries'))))===$db->table('entries'),'default uninstall preserves community data');
$settings=get_option('bpi_settings');$settings['delete_on_uninstall']=true;update_option('bpi_settings',$settings);
include WP_PLUGIN_DIR.'/buddypress-intelligence/uninstall.php';
foreach(array_keys(BuddyPressIntelligence\Database::COLUMNS) as $table) releaseCheck(!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($db->table($table)))),'explicit uninstall removes '.$table);
releaseCheck(!get_option('bpi_schema'),'explicit uninstall removes options');
releaseCheck(!bp_current_user_can('bpi_manage_settings'),'explicit uninstall removes root capabilities');
releaseCheck((bool)get_userdata(1),'native WordPress account survives uninstall');
echo "Release lifecycle verification complete.\n";
