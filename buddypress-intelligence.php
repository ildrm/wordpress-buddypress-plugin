<?php
/**
 * Plugin Name: BuddyPress Intelligence
 * Description: Privacy-aware feeds, discovery, knowledge and community operations for BuddyPress.
 * Version: 1.0.0
 * Author: Shahin Ilderemi
 * Author URI:  https://ildrm.com
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * Requires Plugins: buddypress
 * Text Domain: buddypress-intelligence
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
define( 'BPI_VERSION', '1.0.0' );
define( 'BPI_FILE', __FILE__ );
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BuddyPressIntelligence\\';
		if ( str_starts_with( $class_name, $prefix ) ) {
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);
register_activation_hook( __FILE__, array( BuddyPressIntelligence\Core::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( BuddyPressIntelligence\Core::class, 'deactivate' ) );
add_action( 'bp_loaded', array( BuddyPressIntelligence\Core::class, 'boot' ), 30 );
add_action(
	'admin_notices',
	static function (): void {
		if ( ! function_exists( 'buddypress' ) && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'BuddyPress Intelligence requires BuddyPress. Its community features are currently unavailable.', 'buddypress-intelligence' ) . '</p></div>';
		}
	}
);
