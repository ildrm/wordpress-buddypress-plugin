<?php
namespace BuddyPressIntelligence;

final class Intelligence {
	private Policy $policy;
	private Settings $settings;
	public function __construct( Policy $policy, Settings $settings ) {
		$this->policy   = $policy;
		$this->settings = $settings;
	}
	public function process( int $actor, string $type, int $id, string $capability ): array {
		if ( ! in_array( $capability, array( 'embed', 'classify', 'moderate', 'summarize' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported capability.' );
		}
		$entity = $this->policy->requireView( $actor, $type, $id );
		$prefs  = get_user_meta( $actor, '_bpi_preferences', true );
		$config = $this->settings->all();
		if ( ! $this->settings->enabled( 'ai' ) || ! $config['external_consent'] || empty( $prefs['external_ai'] ) ) {
			return ( new DisabledProvider() )->process( $capability, '' );
		}
		// Only public WordPress editorial content. Member/activity/Q&A text is never exported.
		if ( 'post' !== $type || ! $this->policy->view( 0, $type, $id ) ) {
			throw new Denied();
		}
		$failed_at = strtotime( (string) Core::option( 'bpi_provider_failure' ) );
		if ( $failed_at && $failed_at > time() - 60 ) {
			return array(
				'available' => false,
				'data'      => array(),
				'reason'    => 'provider_backoff',
			);
		}
		$text     = mb_substr( wp_strip_all_tags( strip_shortcodes( $entity['body'] ) ), 0, 4000 );
		$text     = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $text );
		$provider = apply_filters( 'bpi_intelligence_provider_v1', new DisabledProvider() );
		try {
			if ( $provider instanceof IntelligenceProvider && ! $provider instanceof DisabledProvider ) {
				$result = $provider->process( $capability, $text );
				if ( ! isset( $result['available'], $result['data'] ) || ! is_bool( $result['available'] ) || ! is_array( $result['data'] ) ) {
					throw new \RuntimeException( 'Invalid provider response.' );
				}
				return array(
					'available' => $result['available'],
					'data'      => $this->clean( $result['data'] ),
				);
			}
			if ( ! $config['provider_url'] ) {
				return ( new DisabledProvider() )->process( $capability, '' );
			}
			if ( ! Settings::publicEndpoint( $config['provider_url'] ) ) {
				throw new \RuntimeException( 'Unsafe provider endpoint.' );
			}
			if ( ! function_exists( 'curl_init' ) || ( new \WP_HTTP_Proxy() )->is_enabled() ) {
				throw new \RuntimeException( 'Pinned direct HTTPS transport required.' );
			}
			$host      = wp_parse_url( $config['provider_url'], PHP_URL_HOST );
			$addresses = gethostbynamel( $host );
			if ( ! $addresses || ! filter_var( $addresses[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				throw new \RuntimeException( 'Unsafe provider resolution.' );
			}
			$pin     = static function ( $handle, array $arguments, string $url ) use ( $host, $addresses ): void {
				if ( wp_parse_url( $url, PHP_URL_HOST ) === $host ) {
					// Verified WP Requests hook: prevent a DNS rebind between validation and connect.
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':443:' . $addresses[0] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- DNS pinning inside WP HTTP's verified cURL hook; the request still uses wp_safe_remote_post.
				}
			};
			$headers = array( 'Content-Type' => 'application/json' );
			if ( defined( 'BPI_PROVIDER_TOKEN' ) ) {
				$headers['Authorization'] = 'Bearer ' . BPI_PROVIDER_TOKEN;
			}
			add_action( 'http_api_curl', $pin, 100, 3 );
			try {
				$response = wp_safe_remote_post(
					$config['provider_url'],
					array(
						'timeout'             => 5,
						'redirection'         => 0,
						'limit_response_size' => 65536,
						'headers'             => $headers,
						'body'                => wp_json_encode(
							array(
								'capability' => $capability,
								'text'       => $text,
							)
						),
					)
				);
			} finally {
				remove_action( 'http_api_curl', $pin, 100 );
			}
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				throw new \RuntimeException( 'Provider unavailable.' );
			}
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				throw new \RuntimeException( 'Invalid provider response.' );
			}
			return array(
				'available' => true,
				'data'      => $this->clean( $data['data'] ),
			);
		} catch ( \Throwable $e ) {
			Core::option( 'bpi_provider_failure', gmdate( 'c' ), true );
			return array(
				'available' => false,
				'data'      => array(),
				'reason'    => 'provider_unavailable',
			);
		}
	}
	private function clean( array $data ): array {
		$result = array();
		foreach ( array_slice( $data, 0, 2048, true ) as $key => $value ) {
			if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
				$result[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$result[ $key ] = sanitize_textarea_field( mb_substr( $value, 0, 4000 ) );
			}
		}
		return $result;
	}
}
