<?php
namespace BuddyPressIntelligence;

final class Settings {
	public const MODULES = array( 'graph', 'topics', 'feed', 'recommendations', 'search', 'expertise', 'qa', 'moderation', 'automations', 'analytics', 'experiments', 'ai' );
	public static function defaults(): array {
		return array(
			'modules'             => array_fill_keys( self::MODULES, true ) + array(),
			'event_days'          => 90,
			'analytics_days'      => 365,
			'candidate_limit'     => 150,
			'weights'             => array(
				'recency'      => 0.25,
				'follow'       => 0.25,
				'topic'        => 0.2,
				'relationship' => 0.1,
				'quality'      => 0.1,
				'exploration'  => 0.1,
			),
			'accepted_points'     => 10,
			'helpful_points'      => 1,
			'analytics_consent'   => false,
			'external_consent'    => false,
			'appeals'             => true,
			'delete_on_uninstall' => false,
			'post_types'          => array( 'post' ),
			'provider_url'        => '',
			'report_categories'   => array( 'spam', 'harassment', 'abuse', 'impersonation', 'fraud', 'inappropriate', 'threat', 'other' ),
		);
	}
	public function all(): array {
		$saved  = Core::option( 'bpi_settings' );
		$result = array_replace_recursive( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( ! is_array( $saved ) || ! isset( $saved['modules']['ai'] ) ) {
			$result['modules']['ai'] = false;
		}
		return $result;
	}
	public function enabled( string $module ): bool {
		return ! empty( $this->all()['modules'][ $module ] );
	}
	public function save( array $input ): array {
		$known = self::defaults();
		if ( array_diff( array_keys( $input ), array_keys( $known ) ) ) {
			throw new \InvalidArgumentException( 'Unknown settings.' );
		}
		$result = $this->all();
		foreach ( $input as $key => $value ) {
			if ( is_bool( $known[ $key ] ) ) {
				if ( ! is_bool( $value ) ) {
					throw new \InvalidArgumentException( 'Expected boolean.' );
				}
			} elseif ( in_array( $key, array( 'event_days', 'analytics_days', 'candidate_limit', 'accepted_points', 'helpful_points' ), true ) ) {
				$max = str_ends_with( $key, 'days' ) ? 730 : ( 'candidate_limit' === $key ? 300 : 100 );
				if ( ! is_int( $value ) || $value < 1 || $value > $max ) {
					throw new \InvalidArgumentException( 'Value outside supported range.' );
				}
			} elseif ( 'modules' === $key ) {
				if ( ! is_array( $value ) || array_diff( array_keys( $value ), self::MODULES ) || count( array_filter( $value, 'is_bool' ) ) !== count( $value ) ) {
					throw new \InvalidArgumentException( 'Invalid feature flags.' );
				}
				$value = array_replace( $result['modules'], $value );
			} elseif ( 'weights' === $key ) {
				if ( ! is_array( $value ) || array_diff( array_keys( $value ), array_keys( $known['weights'] ) ) ) {
					throw new \InvalidArgumentException( 'Invalid weights.' );
				}
				foreach ( $value as $weight ) {
					if ( ! is_numeric( $weight ) || ! is_finite( (float) $weight ) || $weight < 0 || $weight > 1 ) {
						throw new \InvalidArgumentException( 'Invalid weight.' );
					}
				}
				$value = array_replace( $result['weights'], $value );
				if ( array_sum( $value ) <= 0 ) {
					throw new \InvalidArgumentException( 'Weights must have a positive sum.' );
				}
			} elseif ( 'post_types' === $key ) {
				if ( ! is_array( $value ) || array_diff( $value, get_post_types( array( 'public' => true ) ) ) ) {
					throw new \InvalidArgumentException( 'Select public post types.' );
				}
			} elseif ( 'report_categories' === $key ) {
				if ( ! is_array( $value ) || count( $value ) > 20 || ! $value ) {
					throw new \InvalidArgumentException( 'Invalid categories.' );
				}
				foreach ( $value as $category ) {
					if ( ! is_string( $category ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $category ) ) {
						throw new \InvalidArgumentException( 'Invalid category.' );
					}
				}
			} elseif ( 'provider_url' === $key ) {
				if ( ! is_string( $value ) || ( $value && ! self::publicEndpoint( $value ) ) ) {
					throw new \InvalidArgumentException( 'Provider requires a public HTTPS URL.' );
				}
			}
			$result[ $key ] = $value;
		}
		Core::option( 'bpi_settings', $result, true );
		return $result;
	}
	public static function publicEndpoint( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = trim( $parts['host'], '[]' );
		$ips  = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : gethostbynamel( $host );
		if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && function_exists( 'dns_get_record' ) ) {
			foreach ( dns_get_record( $host, DNS_AAAA ) ?: array() as $record ) {
				if ( isset( $record['ipv6'] ) ) {
					$ips = array_merge( $ips ?: array(), array( $record['ipv6'] ) );
				}
			}
		}
		if ( ! $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
	}
}
