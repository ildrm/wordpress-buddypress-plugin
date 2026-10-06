<?php
/** Preserve valuable community data unless the root administrator explicitly opted in. */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once __DIR__ . '/buddypress-intelligence.php';

$root     = BuddyPressIntelligence\Core::root();
$switched = get_current_blog_id() !== $root;
if ( $switched ) {
	switch_to_blog( $root );
}
BuddyPressIntelligence\Core::deactivate();
$settings = get_option( 'bpi_settings', array() );
if ( ! empty( $settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$db = new BuddyPressIntelligence\Database();
	foreach ( array_keys( BuddyPressIntelligence\Database::COLUMNS ) as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $db->table( $table ) ) );
	}
	foreach ( array( 'bpi_settings', 'bpi_schema', 'bpi_migration_error', 'bpi_provider_failure', 'bpi_cache_generation' ) as $option ) {
		delete_option( $option );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_bpi_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_bpi_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_bpi_' ) . '%', $wpdb->esc_like( '_transient_timeout_bpi_' ) . '%' ) );
	if ( function_exists( 'buddypress' ) ) {
		$bp = buddypress();
		foreach ( array( 'activity', 'groups' ) as $component ) {
			if ( ! empty( $bp->$component->table_name_meta ) ) {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $bp->$component->table_name_meta, $wpdb->esc_like( '_bpi_' ) . '%' ) );
			}
		}
	}
	foreach ( wp_roles()->role_objects as $bpi_role ) {
		foreach ( BuddyPressIntelligence\Core::CAPS as $cap ) {
			$bpi_role->remove_cap( $cap );
		}
	}
}
if ( $switched ) {
	restore_current_blog();
}
