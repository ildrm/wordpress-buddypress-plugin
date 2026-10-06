<?php
namespace BuddyPressIntelligence;

/** Composition root. No domain service looks dependencies up from this class. */
final class Core {
	public const CAPS           = array( 'bpi_manage_settings', 'bpi_manage_feed', 'bpi_manage_topics', 'bpi_manage_reputation', 'bpi_moderate', 'bpi_view_cases', 'bpi_manage_automations', 'bpi_view_analytics', 'bpi_manage_ai' );
	private static bool $booted = false;
	public static function root(): int {
		return function_exists( 'bp_get_root_blog_id' ) ? (int) bp_get_root_blog_id() : (int) get_main_site_id();
	}
	public static function option( string $key, $value = null, bool $write = false ) {
		$switched = get_current_blog_id() !== self::root();
		if ( $switched ) {
			switch_to_blog( self::root() );
		}
		try {
			return $write ? update_option( $key, $value, false ) : get_option( $key, $value );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	public static function schedule( string $hook, array $args = array(), int $delay = 10 ): void {
		$switched = get_current_blog_id() !== self::root();
		if ( $switched ) {
			switch_to_blog( self::root() );
		}
		try {
			if ( ! wp_next_scheduled( $hook, $args ) ) {
				wp_schedule_single_event( time() + $delay, $hook, $args );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	/** Keep community caches in the same root scope as their canonical data. */
	public static function transient( string $key, ?array $value = null ) {
		$switched = get_current_blog_id() !== self::root();
		if ( $switched ) {
			switch_to_blog( self::root() );
		}
		try {
			return null === $value ? get_transient( $key ) : set_transient( $key, $value, 300 );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) || version_compare( get_bloginfo( 'version' ), '6.8', '<' ) ) {
			wp_die( esc_html__( 'BuddyPress Intelligence requires WordPress 6.8 and PHP 8.1 or later.', 'buddypress-intelligence' ) );
		}
		$switched = get_current_blog_id() !== self::root();
		if ( $switched ) {
			switch_to_blog( self::root() );
		}
		try {
			( new Database() )->migrate();
			$role = get_role( 'administrator' );
			if ( $role ) {
				foreach ( self::CAPS as $cap ) {
					$role->add_cap( $cap );
				}
			}
			if ( ! get_option( 'bpi_settings' ) ) {
				$defaults                  = Settings::defaults();
				$defaults['modules']['ai'] = false;
				add_option( 'bpi_settings', $defaults, '', false );
			}
			if ( ! wp_next_scheduled( 'bpi_maintenance' ) ) {
				wp_schedule_event( time() + 60, 'hourly', 'bpi_maintenance' );
			}
		} catch ( \Throwable $e ) {
			self::option( 'bpi_migration_error', 'schema_failed', true );
			wp_die( esc_html__( 'The community database could not be initialized. Check database permissions and try activation again.', 'buddypress-intelligence' ) );
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	public static function deactivate(): void {
		$switched = get_current_blog_id() !== self::root();
		if ( $switched ) {
			switch_to_blog( self::root() );
		}
		wp_clear_scheduled_hook( 'bpi_maintenance' );
		wp_clear_scheduled_hook( 'bpi_work' );
		wp_clear_scheduled_hook( 'bpi_erase_user' );
		if ( $switched ) {
			restore_current_blog();
		}
	}
	public static function boot(): void {
		if ( self::$booted || ! function_exists( 'buddypress' ) ) {
			return;
		}
		self::$booted = true;
		if ( version_compare( bp_get_version(), '14.5.2', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'BuddyPress Intelligence requires BuddyPress 14.5.2 or later.', 'buddypress-intelligence' ) . '</p></div>';
				}
			);
			return;
		}
		if ( 1 !== (int) self::option( 'bpi_schema' ) ) {
			add_action( 'admin_init', array( self::class, 'activate' ) );
			return;
		}
		$db         = new Database();
		$settings   = new Settings();
		$native     = new NativeObjects( $db, $settings );
		$policy     = new Policy( $db, $native );
		$jobs       = new Jobs( $db, $settings );
		$events     = new Events( $db, $jobs, $settings );
		$graph      = new Graph( $db, $policy, $events );
		$topics     = new Topics( $db, $policy, $events );
		$discovery  = new Discovery( $db, $native, $policy, $settings );
		$knowledge  = new Knowledge( $db, $policy, $events, $settings, $topics );
		$moderation = new Moderation( $db, $policy, $native, $events, $settings );
		$automation = new Automation( $db, $settings );
		$analytics  = new Analytics( $db, $settings );
		$ai         = new Intelligence( $policy, $settings );
		$jobs->handlers(
			array(
				'events' => array( $automation, 'process' ),
				'rollup' => array( $analytics, 'rollup' ),
			)
		);
		$rest = new REST( $db, $settings, $graph, $topics, $discovery, $knowledge, $moderation, $automation, $analytics, $ai, $events, $policy );
		$ui   = new UI( $rest, $settings, $db );
		( new Integration( $events, $policy, $settings, $ui ) )->register();
		( new Privacy( $db ) )->register();
		add_action( 'rest_api_init', array( $rest, 'register_routes' ) );
		$ui->register();
		$jobs->register();
		do_action(
			'bpi_ready',
			array(
				'events' => $events,
				'policy' => $policy,
				'topics' => $topics,
			)
		);
	}
}
