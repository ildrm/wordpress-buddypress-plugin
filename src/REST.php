<?php
namespace BuddyPressIntelligence;

final class REST extends \WP_REST_Controller {
	private Database $db;
	private Settings $settings;
	private Graph $graph;
	private Topics $topics;
	private Discovery $discovery;
	private Knowledge $knowledge;
	private Moderation $moderation;
	private Automation $automation;
	private Analytics $analytics;
	private Intelligence $ai;
	private Events $events;
	private Policy $policy;
	public function __construct( Database $db, Settings $settings, Graph $graph, Topics $topics, Discovery $discovery, Knowledge $knowledge, Moderation $moderation, Automation $automation, Analytics $analytics, Intelligence $ai, Events $events, Policy $policy ) {
		$this->namespace  = 'buddypress-intelligence/v1';
		$this->db         = $db;
		$this->settings   = $settings;
		$this->graph      = $graph;
		$this->topics     = $topics;
		$this->discovery  = $discovery;
		$this->knowledge  = $knowledge;
		$this->moderation = $moderation;
		$this->automation = $automation;
		$this->analytics  = $analytics;
		$this->ai         = $ai;
		$this->events     = $events;
		$this->policy     = $policy;
	}
	private const ROUTES = array(
		'graph'                              => array( 'GET', 'POST', 'DELETE' ),
		'preferences'                        => array( 'GET', 'POST' ),
		'topics'                             => array( 'GET', 'POST' ),
		'topics/(?P<id>\d+)'                 => array( 'GET', 'POST' ),
		'object-topics'                      => array( 'POST' ),
		'feed'                               => array( 'GET' ),
		'recommendations'                    => array( 'GET' ),
		'search'                             => array( 'GET' ),
		'autocomplete'                       => array( 'GET' ),
		'feedback'                           => array( 'POST' ),
		'questions'                          => array( 'GET', 'POST' ),
		'answers'                            => array( 'POST' ),
		'knowledge'                          => array( 'GET', 'POST' ),
		'entries/(?P<id>\d+)'                => array( 'GET' ),
		'accept'                             => array( 'POST' ),
		'accept/reverse'                     => array( 'POST' ),
		'votes'                              => array( 'POST' ),
		'reputation/(?P<id>\d+)'             => array( 'GET' ),
		'reports'                            => array( 'POST', 'GET' ),
		'cases'                              => array( 'GET' ),
		'cases/(?P<id>\d+)'                  => array( 'GET', 'POST' ),
		'appeals'                            => array( 'GET', 'POST' ),
		'appeals/(?P<id>\d+)'                => array( 'POST' ),
		'rules'                              => array( 'GET', 'POST' ),
		'rules/(?P<id>\d+)'                  => array( 'POST' ),
		'runs'                               => array( 'GET' ),
		'analytics'                          => array( 'GET' ),
		'experiments'                        => array( 'GET', 'POST' ),
		'experiments/(?P<id>\d+)'            => array( 'GET', 'POST' ),
		'experiments/(?P<id>\d+)/assignment' => array( 'GET' ),
		'intelligence'                       => array( 'POST' ),
		'events'                             => array( 'POST' ),
		'settings'                           => array( 'GET', 'POST' ),
		'diagnostics'                        => array( 'GET' ),
		'jobs/(?P<id>\d+)/retry'             => array( 'POST' ),
	);
	public function register_routes(): void {
		foreach ( self::ROUTES as $route => $methods ) {
			foreach ( $methods as $method ) {
				register_rest_route(
					$this->namespace,
					'/' . $route,
					array(
						'methods'             => $method,
						'permission_callback' => array( $this, 'permission' ),
						'callback'            => array( $this, 'handle' ),
						'args'                => array(
							'id' => array(
								'type'              => 'integer',
								'minimum'           => 1,
								'validate_callback' => static fn( $value ) => ctype_digit( (string) $value ) && (int) $value > 0,
							),
						),
					)
				);
			}
		}
	}
	public function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'bpi_auth_required', __( 'Sign in to use community tools.', 'buddypress-intelligence' ), array( 'status' => 401 ) );
		}
		$path    = substr( $request->get_route(), strlen( '/' . $this->namespace . '/' ) );
		$section = explode( '/', $path )[0];
		$cap     = match ( $section ) {
			'accept' => 'accept/reverse' === $path ? 'bpi_manage_reputation' : '',
			'settings', 'diagnostics', 'jobs' => 'bpi_manage_settings',
			'cases' => 'GET' === $request->get_method() ? 'bpi_view_cases' : 'bpi_moderate',
			'rules', 'runs' => 'bpi_manage_automations',
			'analytics' => 'bpi_view_analytics',
			'experiments' => str_ends_with( $path, '/assignment' ) ? '' : 'bpi_manage_feed',
			'topics' => 'GET' === $request->get_method() ? '' : 'bpi_manage_topics',
			'appeals' => str_contains( $path, '/' ) ? 'bpi_moderate' : '',
			default => '',
		};
		if ( $cap && ! bp_current_user_can( $cap ) ) {
			return new \WP_Error( 'bpi_forbidden', __( 'You do not have permission for this action.', 'buddypress-intelligence' ), array( 'status' => 403 ) );
		}
		$module = match ( $section ) {
			'graph' => 'graph', 'topics', 'object-topics' => 'topics', 'feed' => 'feed', 'recommendations', 'feedback' => 'recommendations', 'search', 'autocomplete' => 'search', 'questions', 'answers', 'knowledge', 'entries', 'accept', 'votes' => 'qa', 'reputation' => 'expertise', 'reports', 'cases', 'appeals' => 'moderation', 'rules', 'runs' => 'automations', 'analytics' => 'analytics', 'experiments' => 'experiments', 'intelligence' => 'ai', default => '',
		};
		if ( $module && ! $this->settings->enabled( $module ) && 'intelligence' !== $section ) {
			return new \WP_Error( 'bpi_module_disabled', __( 'This community feature is currently unavailable.', 'buddypress-intelligence' ), array( 'status' => 503 ) );
		}
		return true;
	}
	public function handle( \WP_REST_Request $request ) {
		$this->policy->clear();
		$permission = $this->permission( $request );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}
		$path    = substr( $request->get_route(), strlen( '/' . $this->namespace . '/' ) );
		$parts   = explode( '/', $path );
		$section = $parts[0];
		$id      = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$method  = $request->get_method();
		$methods = array();
		foreach ( self::ROUTES as $pattern => $allowed_methods ) {
			if ( preg_match( '#^' . $pattern . '$#', $path ) ) {
				$methods = $allowed_methods;
				break;
			}
		}
		if ( ! in_array( $method, $methods, true ) ) {
			return new \WP_Error( 'bpi_method_not_allowed', __( 'This action does not support that request method.', 'buddypress-intelligence' ), array( 'status' => 405 ) );
		}
		$input = 'GET' === $method ? $request->get_query_params() : $request->get_json_params();
		$input = is_array( $input ) ? $input : array();
		$actor = get_current_user_id();
		try {
			foreach ( array( 'kind', 'type', 'q', 'mode', 'cursor', 'value', 'title', 'body', 'name', 'slug', 'description', 'category', 'reason', 'decision', 'note', 'action', 'trigger', 'status', 'capability', 'event', 'starts_at', 'ends_at' ) as $field ) {
				if ( isset( $input[ $field ] ) && ! is_string( $input[ $field ] ) ) {
					throw new \InvalidArgumentException( 'Expected text.' );
				}
			}
			foreach ( array( 'group_id', 'parent_id', 'assigned', 'days', 'question_id', 'answer_id', 'case_id', 'severity', 'priority' ) as $field ) {
				if ( isset( $input[ $field ] ) && ( ( ! is_int( $input[ $field ] ) && ! ( is_string( $input[ $field ] ) && ctype_digit( $input[ $field ] ) ) ) || (int) $input[ $field ] < 0 ) ) {
					throw new \InvalidArgumentException( 'Expected nonnegative integer.' );
				}
				if ( isset( $input[ $field ] ) ) {
					$input[ $field ] = (int) $input[ $field ];
				}
			}
			if ( 'GET' !== $method || in_array( $section, array( 'search', 'autocomplete', 'feed', 'recommendations' ), true ) ) {
				$maximum = 'reports' === $section ? 5 : 60;
				if ( ! ( new Jobs( $this->db, $this->settings ) )->rateLimit( $actor, $section, $maximum ) ) {
					return new \WP_Error( 'bpi_rate_limited', __( 'Please wait before trying again.', 'buddypress-intelligence' ), array( 'status' => 429 ) );
				}
			}
			$result   = $this->dispatch( $section, $id, $method, $actor, $input, $path );
			$response = new \WP_REST_Response( $result, 'POST' === $method && in_array( $section, array( 'questions', 'answers', 'knowledge', 'reports' ), true ) ? 201 : 200 );
			$response->header( 'Cache-Control', 'private, no-store' );
			return $response;
		} catch ( Denied $e ) {
			return new \WP_Error( 'bpi_unavailable', __( 'This item is unavailable or you do not have permission.', 'buddypress-intelligence' ), array( 'status' => 404 ) );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'bpi_invalid', __( 'Some values are invalid. Check your selection and try again.', 'buddypress-intelligence' ), array( 'status' => 400 ) );
		} catch ( \Throwable $e ) {
			do_action( 'bpi_operation_failed', $section );
			return new \WP_Error( 'bpi_operation_failed', __( 'The action could not be completed. Please try again.', 'buddypress-intelligence' ), array( 'status' => 500 ) );
		}
	}
	private function validate( array $input, array $allowed ): void {
		$keys = array_diff( array_keys( $input ), array( '_fields', '_embed', '_locale' ) );
		if ( array_diff( $keys, $allowed ) ) {
			throw new \InvalidArgumentException( 'Unknown field.' );
		}
	}
	private function positive( array $input, string $key, int $fallback = 0 ): int {
		$value = $input[ $key ] ?? $fallback;
		if ( ! is_int( $value ) && ! ( is_string( $value ) && ctype_digit( $value ) ) ) {
			throw new \InvalidArgumentException( 'Expected positive ID.' );
		}
		if ( (int) $value < 1 ) {
			throw new \InvalidArgumentException( 'Expected positive ID.' );
		}
		return (int) $value;
	}
	private function dispatch( string $section, int $id, string $method, int $actor, array $input, string $path ) {
		$page = isset( $input['page'] ) ? $this->positive( $input, 'page' ) : 1;
		if ( $page > 1000 ) {
			throw new \InvalidArgumentException( 'Page out of range.' );
		}
		switch ( $section ) {
			case 'graph':
				$this->validate( $input, array( 'page', 'kind', 'type', 'id', 'days' ) );
				return 'GET' === $method ? $this->graph->list( $actor, $page ) : $this->graph->change( $actor, (string) ( $input['kind'] ?? '' ), (string) ( $input['type'] ?? '' ), $this->positive( $input, 'id' ), 'DELETE' === $method, (int) ( $input['days'] ?? 0 ) );
			case 'preferences':
				return 'GET' === $method ? ( get_user_meta( $actor, '_bpi_preferences', true ) ?: array() ) : $this->topics->preferences( $actor, $input );
			case 'topics':
				if ( $id && 'GET' === $method ) {
					return $this->topics->detail( $actor, $id );
				}
				if ( 'POST' === $method ) {
					return array( 'id' => $this->topics->save( $input, $id ) );
				}
				$input['kind'] = 'recommendations';
				$input['type'] = 'topic';
				return $this->discovery->retrieve( $actor, $input );
			case 'object-topics':
				$this->validate( $input, array( 'type', 'id', 'topics' ) );
				$this->topics->assign( $actor, (string) ( $input['type'] ?? '' ), $this->positive( $input, 'id' ), $input['topics'] ?? array() );
				return array( 'saved' => true );
			case 'feed':
			case 'recommendations':
			case 'search':
			case 'autocomplete':
				$this->validate( $input, array( 'mode', 'type', 'q', 'page', 'per_page', 'cursor', 'topic_id', 'author', 'after', 'before', 'unanswered', 'window' ) );
				foreach ( array( 'per_page', 'topic_id', 'author', 'window' ) as $field ) {
					if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) {
						$input[ $field ] = $this->positive( $input, $field );
					}
				}
				foreach ( array( 'after', 'before' ) as $field ) {
					if ( ! empty( $input[ $field ] ) && ( ! is_string( $input[ $field ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $input[ $field ] ) ) ) {
						throw new \InvalidArgumentException( 'Invalid date.' );
					}
				}
				$input['kind'] = 'autocomplete' === $section ? 'search' : $section;
				if ( 'autocomplete' === $section ) {
					$input['per_page'] = 5;
				}
				$result = $this->discovery->retrieve( $actor, $input );
				if ( 'search' === $section && empty( $input['cursor'] ) ) {
					$this->events->record( 'search_performed', $actor, 'search', 0 );
				}
				return $result;
			case 'feedback':
				$this->validate( $input, array( 'type', 'id', 'value', 'experiment_id' ) );
				$metadata = isset( $input['experiment_id'] ) ? $this->analytics->assignment( $actor, $this->positive( $input, 'experiment_id' ) ) : array();
				$result   = $this->discovery->feedback( $actor, (string) ( $input['type'] ?? '' ), $this->positive( $input, 'id' ), (string) ( $input['value'] ?? '' ) );
				$this->events->record( 'feedback_created', $actor, (string) $input['type'], (int) $input['id'], $metadata + array( 'value' => $input['value'] ) );
				return $result;
			case 'questions':
			case 'answers':
			case 'knowledge':
				if ( 'POST' === $method ) {
					$input['kind'] = array(
						'questions' => 'question',
						'answers'   => 'answer',
						'knowledge' => 'knowledge',
					)[ $section ];
					return array( 'id' => $this->knowledge->create( $actor, $input ) );
				}
				$input['kind'] = 'search';
				$input['type'] = 'questions' === $section ? 'question' : 'knowledge';
				return $this->discovery->retrieve( $actor, $input );
			case 'entries':
				$this->validate( $input, array( 'kind', 'page' ) );
				return $this->knowledge->detail( $actor, (string) ( $input['kind'] ?? 'question' ), $id, $page );
			case 'accept':
				if ( 'accept/reverse' === $path ) {
					$this->validate( $input, array( 'question_id' ) );
					return $this->knowledge->reverseAcceptance( $actor, $this->positive( $input, 'question_id' ) );
				}
				$this->validate( $input, array( 'question_id', 'answer_id' ) );
				return $this->knowledge->accept( $actor, $this->positive( $input, 'question_id' ), $this->positive( $input, 'answer_id' ) );
			case 'votes':
				$this->validate( $input, array( 'answer_id' ) );
				return $this->knowledge->vote( $actor, $this->positive( $input, 'answer_id' ) );
			case 'reputation':
				return $this->knowledge->reputation( $actor, $id );
			case 'reports':
				$this->validate( $input, array( 'type', 'id', 'category', 'description', 'page' ) );
				if ( 'GET' === $method ) {
					$reports = $this->db->rows( 'reports', array( 'actor' => $actor ), 50, ( $page - 1 ) * 50 );
					return array_map( static fn( $row ) => array_intersect_key( $row, array_flip( array( 'id', 'case_id', 'category', 'created_at' ) ) ), $reports );
				}
				return array( 'case_id' => $this->moderation->report( $actor, (string) ( $input['type'] ?? '' ), $this->positive( $input, 'id' ), (string) ( $input['category'] ?? '' ), (string) ( $input['description'] ?? '' ) ) );
			case 'cases':
				return $id ? ( 'GET' === $method ? $this->moderation->detail( $id ) : $this->moderation->act( $actor, $id, $input ) ) : $this->moderation->cases( $page, (string) ( $input['status'] ?? '' ) );
			case 'appeals':
				$this->validate( $input, array( 'case_id', 'reason', 'status', 'decision', 'page' ) );
				if ( $id ) {
					return $this->moderation->reviewAppeal( $actor, $id, (string) ( $input['status'] ?? '' ), (string) ( $input['decision'] ?? '' ) );
				}
				if ( 'GET' === $method ) {
					$appeals = $this->db->rows( 'appeals', array( 'actor' => $actor ), 50, ( $page - 1 ) * 50 );
					return array_map( static fn( $row ) => array_intersect_key( $row, array_flip( array( 'case_id', 'status', 'decision', 'created_at' ) ) ), $appeals );
				}
				return $this->moderation->appeal( $actor, $this->positive( $input, 'case_id' ), (string) ( $input['reason'] ?? '' ) );
			case 'rules':
				return 'GET' === $method ? $this->db->rows( 'rules', array(), 50, ( $page - 1 ) * 50 ) : array( 'id' => $this->automation->save( $input, $id ) );
			case 'runs':
				return $this->db->rows( 'runs', array(), 50, ( $page - 1 ) * 50 );
			case 'analytics':
				return $this->analytics->overview();
			case 'experiments':
				if ( str_ends_with( $path, '/assignment' ) ) {
					return $this->analytics->assignment( $actor, $id );
				}
				return 'GET' === $method ? ( $id ? $this->analytics->results( $id ) : $this->db->rows( 'experiments', array(), 50, ( $page - 1 ) * 50 ) ) : array( 'id' => $this->analytics->experiment( $input, $id ) );
			case 'intelligence':
				$this->validate( $input, array( 'type', 'id', 'capability' ) );
				return $this->ai->process( $actor, (string) ( $input['type'] ?? '' ), $this->positive( $input, 'id' ), (string) ( $input['capability'] ?? '' ) );
			case 'events':
				$this->validate( $input, array( 'type', 'id', 'event', 'experiment_id' ) );
				$type  = (string) ( $input['type'] ?? '' );
				$id    = $this->positive( $input, 'id' );
				$event = (string) ( $input['event'] ?? '' );
				if ( ! in_array( $event, array( 'activity_viewed', 'search_result_clicked', 'recommendation_clicked' ), true ) || ! $this->policy->view( $actor, $type, $id ) ) {
					throw new Denied();
				}
				$metadata = isset( $input['experiment_id'] ) ? $this->analytics->assignment( $actor, $this->positive( $input, 'experiment_id' ) ) : array();
				return array( 'recorded' => $this->events->record( $event, $actor, $type, $id, $metadata ) > 0 );
			case 'settings':
				if ( 'POST' === $method ) {
					if ( ( isset( $input['provider_url'] ) || isset( $input['external_consent'] ) || isset( $input['modules']['ai'] ) ) && ! bp_current_user_can( 'bpi_manage_ai' ) ) {
						throw new Denied();
					}
					return $this->settings->save( $input );
				}
				return $this->settings->all();
			case 'diagnostics':
				return array(
					'version'          => BPI_VERSION,
					'php'              => PHP_VERSION,
					'wordpress'        => get_bloginfo( 'version' ),
					'buddypress'       => bp_get_version(),
					'root_blog'        => Core::root(),
					'schema'           => Core::option( 'bpi_schema' ),
					'jobs'             => $this->db->select( 'SELECT status,COUNT(*) AS total FROM ' . $this->db->table( 'jobs' ) . ' WHERE created_at>%s GROUP BY status', array( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ),
					'provider_failure' => Core::option( 'bpi_provider_failure' ),
					'next_maintenance' => wp_next_scheduled( 'bpi_maintenance' ),
				);
			case 'jobs':
				$this->validate( $input, array() );
				if ( ! $this->db->one(
					'jobs',
					array(
						'id'     => $id,
						'status' => 'failed',
					)
				) ) {
					throw new Denied();
				}
				$this->db->update(
					'jobs',
					array(
						'status'       => 'pending',
						'attempts'     => 0,
						'error'        => '',
						'available_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array(
						'id'     => $id,
						'status' => 'failed',
					)
				);
				Core::schedule( 'bpi_work' );
				return array( 'queued' => true );
		}
		throw new Denied();
	}
}
