<?php
namespace BuddyPressIntelligence;

/** Server-rendered flows share the exact REST permission and validation boundary. */
final class UI {
	private REST $rest;
	private Settings $settings;
	private Database $db;
	private bool $assets = false;
	public function __construct( REST $rest, Settings $settings, Database $db ) {
		$this->rest     = $rest;
		$this->settings = $settings;
		$this->db       = $db;
	}
	public function register(): void {
		add_action(
			'template_redirect',
			static function (): void {
				$post         = get_queried_object();
				$personalized = ( $post instanceof \WP_Post && ( has_shortcode( $post->post_content, 'bpi_community' ) || str_contains( $post->post_content, 'wp:buddypress-intelligence/' ) ) ) || bp_is_current_component( 'intelligence' );
				if ( $personalized ) {
					defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
					nocache_headers();
				}
			}
		);
		add_shortcode(
			'bpi_community',
			function ( array $attributes = array() ): string {
				ob_start();
				$this->member( (string) ( $attributes['view'] ?? 'feed' ) );
				return (string) ob_get_clean();
			}
		);
		add_action( 'admin_post_bpi_action', array( $this, 'submit' ) );
		add_action( 'admin_post_nopriv_bpi_action', array( $this, 'submit' ) );
		add_action(
			'admin_menu',
			function (): void {
				add_menu_page( __( 'Community Intelligence', 'buddypress-intelligence' ), __( 'Community Intelligence', 'buddypress-intelligence' ), 'bpi_manage_settings', 'bpi', array( $this, 'admin' ), 'dashicons-groups', 58 );
				foreach ( array(
					'overview'    => 'bpi_manage_settings',
					'topics'      => 'bpi_manage_topics',
					'moderation'  => 'bpi_view_cases',
					'automations' => 'bpi_manage_automations',
					'analytics'   => 'bpi_view_analytics',
					'experiments' => 'bpi_manage_feed',
					'settings'    => 'bpi_manage_settings',
					'tools'       => 'bpi_manage_settings',
				) as $section => $cap ) {
					$labels = $this->adminLabels();
					add_submenu_page( 'bpi', $labels[ $section ], $labels[ $section ], $cap, 'bpi-' . $section, array( $this, 'admin' ) );
				}
			}
		);
		add_action(
			'init',
			function (): void {
				wp_register_script( 'bpi-editor', plugins_url( 'assets/editor.js', BPI_FILE ), array( 'wp-blocks', 'wp-element', 'wp-i18n', 'wp-server-side-render' ), BPI_VERSION, true );
				wp_set_script_translations( 'bpi-editor', 'buddypress-intelligence', dirname( BPI_FILE ) . '/languages' );
				foreach ( array( 'feed', 'people', 'groups', 'topics', 'questions', 'experts', 'knowledge', 'reputation' ) as $block ) {
					register_block_type(
						'buddypress-intelligence/' . $block,
						array(
							'api_version'     => 3,
							'editor_script'   => 'bpi-editor',
							'render_callback' => function () use ( $block ): string {
										ob_start();
										$this->assets();
								if ( 'reputation' === $block ) {
									$this->table( $this->call( 'reputation/' . get_current_user_id() ) );
								} else {
									$this->listing( $block );
								}
								return (string) ob_get_clean();
							},
						)
					);
				}
			}
		);
	}
	private function assets(): void {
		if ( $this->assets ) {
			return;
		}
		$this->assets = true;
		wp_enqueue_style( 'bpi-community', plugins_url( 'assets/community.css', BPI_FILE ), array(), BPI_VERSION );
		wp_enqueue_script( 'bpi-community', plugins_url( 'assets/community.js', BPI_FILE ), array( 'wp-i18n' ), BPI_VERSION, true );
		wp_set_script_translations( 'bpi-community', 'buddypress-intelligence', dirname( BPI_FILE ) . '/languages' );
		$prefs = get_user_meta( get_current_user_id(), '_bpi_preferences', true );
		wp_localize_script(
			'bpi-community',
			'bpiConfig',
			array(
				'root'      => rest_url( 'buddypress-intelligence/v1/' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'analytics' => $this->settings->enabled( 'analytics' ) && $this->settings->all()['analytics_consent'] && ! empty( $prefs['analytics'] ),
			)
		);
		// Shortcodes/blocks may render after wp_head; enqueueing alone would miss late styles.
		if ( did_action( 'wp_head' ) && ! wp_style_is( 'bpi-community', 'done' ) ) {
			wp_print_styles( 'bpi-community' );
		}
	}
	public function call( string $path, string $method = 'GET', array $input = array() ) {
		$request = new \WP_REST_Request( $method, '/buddypress-intelligence/v1/' . $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( $input );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $input ) );
		}
		$response = $this->rest->handle( $request );
		return is_wp_error( $response ) ? $response : $response->get_data();
	}
	public static function payload( array $base, array $input, array $types ): array {
		foreach ( $input as $key => $value ) {
			$type = $types[ $key ] ?? 'text';
			if ( in_array( $type, array( 'number', 'select_number' ), true ) ) {
				if ( ! is_string( $value ) || ! preg_match( '/^\d+$/', $value ) ) {
					throw new \InvalidArgumentException( 'Expected integer.' );
				}
				$value = (int) $value;
			} elseif ( 'checkbox' === $type ) {
				$value = '1' === $value;
			} elseif ( 'topic_ids' === $type || 'ids' === $type ) {
				if ( ! is_array( $value ) ) {
					throw new \InvalidArgumentException( 'Expected IDs.' );
				}
				$value = array_map(
					static function ( $v ): int {
						if ( ! is_string( $v ) || ! ctype_digit( $v ) ) {
							throw new \InvalidArgumentException( 'Expected IDs.' );
						}
						return (int) $v;
					},
					$value
				);
			} elseif ( 'json' === $type ) {
				$value = json_decode( (string) $value, true, 16, JSON_THROW_ON_ERROR );
			}
			if ( str_contains( $key, ':' ) ) {
				list( $parent, $child )    = explode( ':', $key, 2 );
				$base[ $parent ][ $child ] = $value;
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
	public function submit(): void {
		if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bpi_action' ) ) {
			wp_die( esc_html__( 'Your session expired. Sign in and try again.', 'buddypress-intelligence' ), '', array( 'response' => 403 ) );
		}
		$result = new \WP_Error( 'bpi_invalid', __( 'Please check the form values.', 'buddypress-intelligence' ) );
		$path   = '';
		try {
			$path   = sanitize_text_field( wp_unslash( $_POST['bpi_path'] ?? '' ) );
			$method = sanitize_text_field( wp_unslash( $_POST['bpi_method'] ?? 'POST' ) );
			if ( ! preg_match( '#^[a-z-]+(?:/\d+)?(?:/(?:retry|reverse))?$#', $path ) || ! in_array( $method, array( 'POST', 'DELETE' ), true ) ) {
				throw new Denied();
			}
			$base   = json_decode( wp_unslash( $_POST['bpi_payload'] ?? '{}' ), true, 16, JSON_THROW_ON_ERROR );
			$types  = json_decode( wp_unslash( $_POST['bpi_types'] ?? '{}' ), true, 16, JSON_THROW_ON_ERROR );
			$input  = wp_unslash( $_POST['input'] ?? array() );
			$result = $this->call( $path, $method, self::payload( $base, $input, $types ) );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'bpi_invalid', __( 'Please check the form values.', 'buddypress-intelligence' ) );
		}
		$redirect = wp_get_referer() ?: home_url( '/' );
		$redirect = remove_query_arg( array( 'bpi_notice', 'bpi_cursor', 'bpi_page', 'bpi_window' ), $redirect );
		$redirect = add_query_arg( 'bpi_notice', is_wp_error( $result ) ? 'error' : 'saved', $redirect );
		if ( ! is_wp_error( $result ) && isset( $result['id'] ) && in_array( $path, array( 'questions', 'answers', 'knowledge' ), true ) ) {
			$redirect = add_query_arg(
				array(
					'bpi_view'  => 'questions',
					'bpi_entry' => 'answers' === $path ? ( $base['parent_id'] ?? 0 ) : $result['id'],
				),
				$redirect
			);
		}
		wp_safe_redirect( $redirect );
		exit;
	}
	private function form( string $path, array $payload, array $fields, string $button, string $method = 'POST' ): void {
		$types = array();
		foreach ( $fields as $key => $field ) {
			$types[ $key ] = $field['type'] ?? 'text';
		}
		echo '<form class="bpi-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" data-bpi-form>';
		wp_nonce_field( 'bpi_action' );
		foreach ( array(
			'action'      => 'bpi_action',
			'bpi_path'    => $path,
			'bpi_method'  => $method,
			'bpi_payload' => wp_json_encode( $payload ),
			'bpi_types'   => wp_json_encode( $types ),
		) as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		foreach ( $fields as $key => $field ) {
			$id    = wp_unique_id( 'bpi-field-' );
			$type  = $field['type'] ?? 'text';
			$value = $field['value'] ?? '';
			echo '<div class="bpi-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
			$name = 'input[' . $key . ']';
			if ( 'textarea' === $type || 'json' === $type ) {
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4" maxlength="20000">' . esc_textarea( is_array( $value ) || is_object( $value ) ? wp_json_encode( $value, JSON_PRETTY_PRINT ) : $value ) . '</textarea>';
			} elseif ( in_array( $type, array( 'select', 'select_number', 'topic_ids', 'ids' ), true ) ) {
				$multiple = in_array( $type, array( 'topic_ids', 'ids' ), true );
				if ( $multiple ) {
					$payload[ $key ] = array();
				}
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name . ( $multiple ? '[]' : '' ) ) . '"' . ( $multiple ? ' multiple size="5"' : '' ) . '>';
				foreach ( $field['options'] ?? array() as $option => $label ) {
					$selected = $multiple ? in_array( (int) $option, (array) $value, true ) : (string) $option === (string) $value;
					echo '<option value="' . esc_attr( $option ) . '"' . ( $selected ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			} elseif ( 'checkbox' === $type ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '>';
			} else {
				echo '<input id="' . esc_attr( $id ) . '" type="' . esc_attr( 'number' === $type ? 'number' : 'text' ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="200"' . ( 'number' === $type ? ' min="0" step="1"' : '' ) . '>';
			}
			if ( ! empty( $field['help'] ) ) {
				echo '<p class="bpi-help">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</div>';
		}
		echo '<button type="submit" class="bpi-button">' . esc_html( $button ) . '</button><p class="bpi-form-status" role="status" aria-live="polite"></p></form>';
	}
	private function query( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; mutations use nonce-protected POST/REST.
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $fallback;
	}
	private function notice(): void {
		$notice = $this->query( 'bpi_notice' );
		if ( $notice ) {
			echo '<p class="bpi-alert" role="status">' . esc_html( 'saved' === $notice ? __( 'Your changes were saved.', 'buddypress-intelligence' ) : __( 'The action could not be completed. Check your permissions and form values, then try again.', 'buddypress-intelligence' ) ) . '</p>';
		}
	}
	private function error( \WP_Error $error ): void {
		echo '<p class="bpi-alert">' . esc_html( $error->get_error_message() ) . '</p>';
	}
	public function member( string $default_view = 'feed' ): void {
		$this->assets();
		echo '<section class="bpi-community" aria-label="' . esc_attr__( 'Community tools', 'buddypress-intelligence' ) . '">';
		if ( ! is_user_logged_in() ) {
			echo '<p><a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Sign in to use community tools.', 'buddypress-intelligence' ) . '</a></p></section>';
			return;
		}
		$this->notice();
		$member_notice = get_user_meta( get_current_user_id(), '_bpi_notice', true );
		if ( is_string( $member_notice ) && $member_notice ) {
			echo '<p class="bpi-alert" role="status">' . esc_html( $member_notice ) . '</p>';
		}
		$views = array(
			'feed'          => __( 'Feed', 'buddypress-intelligence' ),
			'people'        => __( 'People to meet', 'buddypress-intelligence' ),
			'groups'        => __( 'Groups', 'buddypress-intelligence' ),
			'topics'        => __( 'Topics', 'buddypress-intelligence' ),
			'experts'       => __( 'Experts', 'buddypress-intelligence' ),
			'questions'     => __( 'Questions', 'buddypress-intelligence' ),
			'knowledge'     => __( 'Knowledge', 'buddypress-intelligence' ),
			'search'        => __( 'Search', 'buddypress-intelligence' ),
			'preferences'   => __( 'Interests and privacy', 'buddypress-intelligence' ),
			'relationships' => __( 'Following, muted and blocked', 'buddypress-intelligence' ),
			'reputation'    => __( 'Reputation', 'buddypress-intelligence' ),
			'appeals'       => __( 'Reports and appeals', 'buddypress-intelligence' ),
		);
		$view  = $this->query( 'bpi_view', $default_view );
		if ( ! isset( $views[ $view ] ) ) {
			$view = 'feed';
		}
		echo '<nav class="bpi-nav" aria-label="' . esc_attr__( 'Community sections', 'buddypress-intelligence' ) . '">';
		foreach ( $views as $key => $label ) {
			echo '<a href="' . esc_url( add_query_arg( array( 'bpi_view' => $key ), remove_query_arg( array( 'bpi_entry', 'bpi_page', 'bpi_cursor', 'bpi_notice', 'bpi_window' ) ) ) ) . '"' . ( $view === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav><h2>' . esc_html( $views[ $view ] ) . '</h2>';
		if ( 'preferences' === $view ) {
			$this->preferences();
		} elseif ( 'relationships' === $view ) {
			$this->relationships();
		} elseif ( 'reputation' === $view ) {
			$this->table( $this->call( 'reputation/' . get_current_user_id() ) );
		} elseif ( 'appeals' === $view ) {
			$this->table( $this->call( 'reports' ) );
			$this->table( $this->call( 'appeals' ) );
			$this->form(
				'appeals',
				array(),
				array(
					'case_id' => array(
						'label' => __( 'Case number', 'buddypress-intelligence' ),
						'type'  => 'number',
					),
					'reason'  => array(
						'label' => __( 'Reason for appeal', 'buddypress-intelligence' ),
						'type'  => 'textarea',
					),
				),
				__( 'Submit appeal', 'buddypress-intelligence' )
			);
		} elseif ( $this->query( 'bpi_entry' ) && in_array( $view, array( 'questions', 'knowledge' ), true ) ) {
			$this->entry( (int) $this->query( 'bpi_entry' ), 'knowledge' === $view ? 'knowledge' : 'question' );
		} else {
			$this->listing( $view );
			if ( 'questions' === $view ) {
				$this->questionForm();
			}
		}
		echo '</section>';
	}
	private function options( string $type ): array {
		$data    = $this->call(
			'recommendations',
			'GET',
			array(
				'type'     => $type,
				'per_page' => 50,
			)
		);
		$options = array();
		if ( ! is_wp_error( $data ) ) {
			foreach ( $data['items'] as $item ) {
				$options[ $item['id'] ] = $item['title'];
			}
		}
		return $options;
	}
	private function preferences(): void {
		$prefs = $this->call( 'preferences' );
		if ( is_wp_error( $prefs ) ) {
			$this->error( $prefs );
			return;
		}
		$topics = $this->options( 'topic' );
		$fields = array();
		foreach ( array(
			'interests'   => __( 'Interests', 'buddypress-intelligence' ),
			'skills'      => __( 'Skills you declare', 'buddypress-intelligence' ),
			'help_topics' => __( 'Topics you can help with', 'buddypress-intelligence' ),
		) as $key => $label ) {
			$fields[ $key ] = array(
				'label'   => $label,
				'type'    => 'topic_ids',
				'options' => $topics,
				'value'   => $prefs[ $key ] ?? array(),
			);
		}
		$fields['goals']                                 = array(
			'label' => __( 'Goals (optional)', 'buddypress-intelligence' ),
			'type'  => 'textarea',
			'value' => $prefs['goals'] ?? '',
		);
		$fields['visibility']                            = array(
			'label'   => __( 'Community discovery visibility', 'buddypress-intelligence' ),
			'type'    => 'select',
			'value'   => $prefs['visibility'] ?? 'everyone',
			'options' => array(
				'everyone'  => __( 'Everyone', 'buddypress-intelligence' ),
				'members'   => __( 'Signed-in members', 'buddypress-intelligence' ),
				'friends'   => __( 'Friends', 'buddypress-intelligence' ),
				'followers' => __( 'My followers', 'buddypress-intelligence' ),
				'following' => __( 'People I follow', 'buddypress-intelligence' ),
				'only_me'   => __( 'Only me', 'buddypress-intelligence' ),
			),
			'help'    => __( 'BuddyPress group restrictions still apply. Selected interests are optional.', 'buddypress-intelligence' ),
		);
		$fields['analytics']                             = array(
			'label' => __( 'Allow my activity to contribute to anonymous community analytics', 'buddypress-intelligence' ),
			'type'  => 'checkbox',
			'value' => $prefs['analytics'] ?? false,
		);
		$fields['visibility']['options']['groups']       = __( 'Members of selected groups', 'buddypress-intelligence' );
		$fields['visibility']['options']['member_types'] = __( 'Selected member types', 'buddypress-intelligence' );
		foreach ( array(
			'groups'            => __( 'Groups of interest', 'buddypress-intelligence' ),
			'visibility_groups' => __( 'Groups allowed to see my community content', 'buddypress-intelligence' ),
		) as $key => $label ) {
			$fields[ $key ] = array(
				'label'   => $label,
				'type'    => 'ids',
				'options' => $this->options( 'group' ),
				'value'   => $prefs[ $key ] ?? array(),
			);
		}
		$fields['visibility_member_types'] = array(
			'label' => __( 'Member types allowed to see my community content (JSON list)', 'buddypress-intelligence' ),
			'type'  => 'json',
			'value' => $prefs['visibility_member_types'] ?? array(),
		);
		$fields['external_ai']             = array(
			'label' => __( 'Allow optional AI requests involving public editorial content', 'buddypress-intelligence' ),
			'type'  => 'checkbox',
			'value' => $prefs['external_ai'] ?? false,
		);
		echo '<p>' . esc_html__( 'Choose only what you want to share. You can skip onboarding and still use community tools.', 'buddypress-intelligence' ) . '</p>';
		$this->form(
			'preferences',
			array(
				'onboarded'         => true,
				'interests'         => array(),
				'skills'            => array(),
				'help_topics'       => array(),
				'groups'            => array(),
				'visibility_groups' => array(),
			),
			$fields,
			__( 'Save preferences', 'buddypress-intelligence' )
		);
	}
	public function relationship( string $type, int $id ): void {
		if ( ! is_user_logged_in() || ! $id || ( 'member' === $type && get_current_user_id() === $id ) || ! $this->settings->enabled( 'graph' ) ) {
			return;
		}
		$this->assets();
		echo '<div class="bpi-community bpi-actions">';
		$key       = array(
			'actor'       => get_current_user_id(),
			'kind'        => 'follow',
			'object_type' => $type,
			'object_id'   => $id,
		);
		$following = (bool) $this->db->one( 'edges', $key );
		$this->form(
			'graph',
			array(
				'kind' => 'follow',
				'type' => $type,
				'id'   => $id,
			),
			array(),
			$following ? __( 'Unfollow', 'buddypress-intelligence' ) : __( 'Follow', 'buddypress-intelligence' ),
			$following ? 'DELETE' : 'POST'
		);
		echo '<details><summary>' . esc_html__( 'Member preferences', 'buddypress-intelligence' ) . '</summary>';
		foreach ( array(
			'mute'   => __( 'Mute', 'buddypress-intelligence' ),
			'snooze' => __( 'Snooze for 7 days', 'buddypress-intelligence' ),
			'block'  => __( 'Block member', 'buddypress-intelligence' ),
		) as $kind => $label ) {
			if ( 'block' === $kind && 'member' !== $type ) {
				continue;
			}
			$this->form(
				'graph',
				array(
					'kind' => $kind,
					'type' => $type,
					'id'   => $id,
					'days' => 7,
				),
				array(),
				$label
			);
		}
		echo '</details></div>';
	}
	public function itemActions( string $type, int $id ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		echo '<details class="bpi-community bpi-actions"><summary>' . esc_html__( 'Feedback and reporting', 'buddypress-intelligence' ) . '</summary>';
		if ( $this->settings->enabled( 'recommendations' ) ) {
			foreach ( array(
				'more'           => __( 'Show more like this', 'buddypress-intelligence' ),
				'less'           => __( 'Show less like this', 'buddypress-intelligence' ),
				'not_interested' => __( 'Not interested', 'buddypress-intelligence' ),
			) as $value => $label ) {
				$this->form(
					'feedback',
					array(
						'type'  => $type,
						'id'    => $id,
						'value' => $value,
					),
					array(),
					$label
				);
			}
		}
		if ( $this->settings->enabled( 'moderation' ) ) {
			$categories = array();
			foreach ( $this->settings->all()['report_categories'] as $category ) {
				$categories[ $category ] = apply_filters( 'bpi_report_category_label', ucfirst( str_replace( '_', ' ', $category ) ), $category );
			}
			$this->form(
				'reports',
				array(
					'type' => $type,
					'id'   => $id,
				),
				array(
					'category'    => array(
						'label'   => __( 'Report category', 'buddypress-intelligence' ),
						'type'    => 'select',
						'options' => $categories,
					),
					'description' => array(
						'label' => __( 'What happened?', 'buddypress-intelligence' ),
						'type'  => 'textarea',
					),
				),
				__( 'Submit report', 'buddypress-intelligence' )
			);
		}
		echo '</details>';
	}
	private function relationships(): void {
		$rows = $this->call( 'graph', 'GET', array( 'page' => max( 1, (int) $this->query( 'bpi_page', '1' ) ) ) );
		if ( is_wp_error( $rows ) ) {
			$this->error( $rows );
			return;
		}
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'You have no saved relationships yet.', 'buddypress-intelligence' ) . '</p>';
		}
		foreach ( $rows as $row ) {
			echo '<article class="bpi-card"><p>' . esc_html( $row['kind'] . ' · ' . $row['object_type'] . ' #' . $row['object_id'] ) . '</p>';
			$this->form(
				'graph',
				array(
					'kind' => $row['kind'],
					'type' => $row['object_type'],
					'id'   => (int) $row['object_id'],
				),
				array(),
				__( 'Remove preference', 'buddypress-intelligence' ),
				'DELETE'
			);
			echo '</article>';
		}
	}
	private function listing( string $view ): void {
		$map                 = array(
			'feed'      => array( 'feed', 'activity' ),
			'people'    => array( 'recommendations', 'member' ),
			'groups'    => array( 'recommendations', 'group' ),
			'topics'    => array( 'topics', 'topic' ),
			'experts'   => array( 'recommendations', 'expert' ),
			'questions' => array( 'questions', 'question' ),
			'knowledge' => array( 'knowledge', 'knowledge' ),
			'search'    => array( 'search', 'all' ),
		);
		list( $path, $type ) = $map[ $view ] ?? $map['feed'];
		$mode                = $this->query( 'bpi_mode', 'for_you' );
		if ( 'feed' === $view ) {
			echo '<nav class="bpi-nav" aria-label="' . esc_attr__( 'Feed order', 'buddypress-intelligence' ) . '">';
			foreach ( array(
				'latest'    => __( 'Latest', 'buddypress-intelligence' ),
				'following' => __( 'Following', 'buddypress-intelligence' ),
				'for_you'   => __( 'For you', 'buddypress-intelligence' ),
				'discover'  => __( 'Discover', 'buddypress-intelligence' ),
				'trending'  => __( 'Trending', 'buddypress-intelligence' ),
			) as $key => $label ) {
				echo '<a href="' . esc_url( add_query_arg( 'bpi_mode', $key, remove_query_arg( array( 'bpi_page', 'bpi_cursor', 'bpi_window' ) ) ) ) . '"' . ( $key === $mode ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}
		if ( 'search' === $view ) {
			echo '<form class="bpi-search" method="get"><input type="hidden" name="bpi_view" value="search">';
			if ( is_page() ) {
				echo '<input type="hidden" name="page_id" value="' . esc_attr( (string) get_queried_object_id() ) . '">';
			}
			echo '<label>' . esc_html__( 'Search your community', 'buddypress-intelligence' ) . '<input name="bpi_q" type="search" maxlength="200" value="' . esc_attr( $this->query( 'bpi_q' ) ) . '"></label>';
			echo '<label>' . esc_html__( 'Search type', 'buddypress-intelligence' ) . '<select name="bpi_type">';
			foreach ( array(
				'all'       => __( 'Everything', 'buddypress-intelligence' ),
				'member'    => __( 'People', 'buddypress-intelligence' ),
				'group'     => __( 'Groups', 'buddypress-intelligence' ),
				'activity'  => __( 'Activity', 'buddypress-intelligence' ),
				'question'  => __( 'Questions', 'buddypress-intelligence' ),
				'knowledge' => __( 'Knowledge', 'buddypress-intelligence' ),
				'post'      => __( 'Posts', 'buddypress-intelligence' ),
				'topic'     => __( 'Topics', 'buddypress-intelligence' ),
			) as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '" ' . selected( $this->query( 'bpi_type', 'all' ), $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></label><label>' . esc_html__( 'Topic number (optional)', 'buddypress-intelligence' ) . '<input name="bpi_topic" type="number" min="1" value="' . esc_attr( $this->query( 'bpi_topic' ) ) . '"></label><label>' . esc_html__( 'Author member number (optional)', 'buddypress-intelligence' ) . '<input name="bpi_author" type="number" min="1" value="' . esc_attr( $this->query( 'bpi_author' ) ) . '"></label><label>' . esc_html__( 'Created after (UTC)', 'buddypress-intelligence' ) . '<input name="bpi_after" type="date" value="' . esc_attr( $this->query( 'bpi_after' ) ) . '"></label><label>' . esc_html__( 'Created before (UTC)', 'buddypress-intelligence' ) . '<input name="bpi_before" type="date" value="' . esc_attr( $this->query( 'bpi_before' ) ) . '"></label><label><input name="bpi_unanswered" type="checkbox" value="1" ' . checked( $this->query( 'bpi_unanswered' ), '1', false ) . '>' . esc_html__( 'Questions without a visible answer', 'buddypress-intelligence' ) . '</label><button type="submit" class="bpi-button">' . esc_html__( 'Search', 'buddypress-intelligence' ) . '</button></form>';
		}
		$args = array(
			'type'   => $type,
			'mode'   => $mode,
			'page'   => max( 1, (int) $this->query( 'bpi_page', '1' ) ),
			'window' => max( 1, (int) $this->query( 'bpi_window', '1' ) ),
			'q'      => $this->query( 'bpi_q' ),
		);
		if ( 'search' === $view ) {
			$args['type'] = $this->query( 'bpi_type', 'all' );
			foreach ( array(
				'topic_id'   => 'bpi_topic',
				'author'     => 'bpi_author',
				'after'      => 'bpi_after',
				'before'     => 'bpi_before',
				'unanswered' => 'bpi_unanswered',
			) as $field => $query_key ) {
				if ( $this->query( $query_key ) ) {
					$args[ $field ] = $this->query( $query_key );
				}
			}
		}
		if ( $this->query( 'bpi_cursor' ) ) {
			$args['cursor'] = $this->query( 'bpi_cursor' );
		}
		$data = $this->call( $path, 'GET', $args );
		if ( is_wp_error( $data ) ) {
			$this->error( $data );
			return;
		}
		if ( ! $data['items'] ) {
			echo '<p class="bpi-alert">' . esc_html( 'unavailable' === $data['state'] ? __( 'This feature requires an enabled BuddyPress component.', 'buddypress-intelligence' ) : __( 'No matching items yet. Try other interests or a broader search.', 'buddypress-intelligence' ) ) . '</p>';
		}
		echo '<div class="bpi-grid">';
		foreach ( $data['items'] as $item ) {
			$url = $item['url'];
			if ( in_array( $item['type'], array( 'question', 'knowledge' ), true ) ) {
				$url = add_query_arg(
					array(
						'bpi_view'  => 'question' === $item['type'] ? 'questions' : 'knowledge',
						'bpi_entry' => $item['id'],
					),
					remove_query_arg( array( 'bpi_cursor', 'bpi_page' ) )
				);
			}
			$event = 'search' === $path ? 'search_result_clicked' : 'recommendation_clicked';
			echo '<article class="bpi-card"><h3>' . ( $url ? '<a data-bpi-event="' . esc_attr( $event ) . '" data-bpi-type="' . esc_attr( $item['type'] ) . '" data-bpi-id="' . esc_attr( $item['id'] ) . '" href="' . esc_url( $url ) . '">' . esc_html( $item['title'] ) . '</a>' : esc_html( $item['title'] ) ) . '</h3><p>' . esc_html( $item['excerpt'] ) . '</p><p class="bpi-help">' . esc_html( $item['reason'] ) . '</p>';
			if ( in_array( $item['type'], array( 'member', 'group', 'topic', 'post' ), true ) ) {
				$this->relationship( $item['type'], (int) $item['id'] );
			}
			$this->itemActions( $item['type'], (int) $item['id'] );
			echo '</article>';
		}
		echo '</div><nav class="bpi-nav" aria-label="' . esc_attr__( 'Result pages', 'buddypress-intelligence' ) . '">';
		if ( $args['page'] > 1 ) {
			echo '<a href="' . esc_url(
				add_query_arg(
					array(
						'bpi_page'   => $args['page'] - 1,
						'bpi_cursor' => $data['cursor'],
					)
				)
			) . '">' . esc_html__( 'Previous', 'buddypress-intelligence' ) . '</a>';
		}
		if ( $data['has_more'] ) {
			echo '<a href="' . esc_url(
				add_query_arg(
					array(
						'bpi_page'   => $args['page'] + 1,
						'bpi_cursor' => $data['cursor'],
					)
				)
			) . '">' . esc_html__( 'Next', 'buddypress-intelligence' ) . '</a>';
		} elseif ( ! empty( $data['more_windows'] ) ) {
			echo '<a href="' . esc_url(
				add_query_arg(
					array(
						'bpi_page'   => 1,
						'bpi_window' => $args['window'] + 1,
					),
					remove_query_arg( 'bpi_cursor' )
				)
			) . '">' . esc_html__( 'Explore more results', 'buddypress-intelligence' ) . '</a>';
		}
		echo '</nav>';
	}
	private function questionForm(): void {
		$this->form(
			'questions',
			array(
				'topics'   => array(),
				'group_id' => 0,
			),
			array(
				'title'    => array( 'label' => __( 'Question title', 'buddypress-intelligence' ) ),
				'body'     => array(
					'label' => __( 'Question details', 'buddypress-intelligence' ),
					'type'  => 'textarea',
				),
				'topics'   => array(
					'label'   => __( 'Topics', 'buddypress-intelligence' ),
					'type'    => 'topic_ids',
					'options' => $this->options( 'topic' ),
				),
				'group_id' => array(
					'label'   => __( 'Group (optional; membership required)', 'buddypress-intelligence' ),
					'type'    => 'select_number',
					'options' => array( 0 => __( 'Community', 'buddypress-intelligence' ) ) + $this->options( 'group' ),
				),
			),
			__( 'Ask a question', 'buddypress-intelligence' )
		);
	}
	private function entry( int $id, string $kind ): void {
		$page  = max( 1, (int) $this->query( 'bpi_answer_page', '1' ) );
		$entry = $this->call(
			'entries/' . $id,
			'GET',
			array(
				'kind' => $kind,
				'page' => $page,
			)
		);
		if ( is_wp_error( $entry ) ) {
			$this->error( $entry );
			return;
		}
		echo '<article class="bpi-card"><h3>' . esc_html( $entry['title'] ) . '</h3><div>' . wp_kses_post( wpautop( $entry['body'] ) ) . '</div></article>';
		foreach ( $entry['answers'] ?? array() as $answer ) {
			echo '<article class="bpi-card"><h3>' . esc_html( (int) $entry['accepted_id'] === $answer['id'] ? __( 'Accepted answer', 'buddypress-intelligence' ) : __( 'Answer', 'buddypress-intelligence' ) ) . '</h3><div>' . wp_kses_post( wpautop( $answer['body'] ) ) . '</div>';
			if ( get_current_user_id() !== $answer['author'] ) {
				$this->form( 'votes', array( 'answer_id' => $answer['id'] ), array(), __( 'Helpful', 'buddypress-intelligence' ) );
			}
			if ( $entry['can_accept'] && ! $entry['accepted_id'] ) {
				$this->form(
					'accept',
					array(
						'question_id' => $id,
						'answer_id'   => $answer['id'],
					),
					array(),
					__( 'Accept this answer', 'buddypress-intelligence' )
				);
			}
			$this->itemActions( 'answer', $answer['id'] );
			echo '</article>';
		}
		if ( 'question' === $kind ) {
			echo '<nav class="bpi-pagination" aria-label="' . esc_attr__( 'Answer pages', 'buddypress-intelligence' ) . '">';
			if ( $page > 1 ) {
				echo '<a href="' . esc_url( add_query_arg( 'bpi_answer_page', $page - 1 ) ) . '">' . esc_html__( 'Previous answers', 'buddypress-intelligence' ) . '</a>';
			}
			if ( ! empty( $entry['has_more_answers'] ) ) {
				echo '<a href="' . esc_url( add_query_arg( 'bpi_answer_page', $page + 1 ) ) . '">' . esc_html__( 'Next answers', 'buddypress-intelligence' ) . '</a>';
			}
			echo '</nav>';
			if ( ! empty( $entry['related_questions'] ) ) {
				echo '<h3>' . esc_html__( 'Related questions', 'buddypress-intelligence' ) . '</h3><ul>';
				foreach ( $entry['related_questions'] as $related ) {
					echo '<li><a href="' . esc_url( add_query_arg( 'bpi_entry', $related['id'] ) ) . '">' . esc_html( $related['title'] ) . '</a></li>';
				}
				echo '</ul>';
			}
			if ( bp_current_user_can( 'bpi_manage_reputation' ) && $entry['accepted_id'] ) {
				$this->form( 'accept/reverse', array( 'question_id' => $id ), array(), __( 'Reverse acceptance and its reputation credit', 'buddypress-intelligence' ) );
			}
			$this->form(
				'answers',
				array( 'parent_id' => $id ),
				array(
					'body' => array(
						'label' => __( 'Your answer', 'buddypress-intelligence' ),
						'type'  => 'textarea',
					),
				),
				__( 'Post answer', 'buddypress-intelligence' )
			);
			if ( bp_current_user_can( 'bpi_moderate' ) && $entry['accepted_id'] ) {
				$this->form(
					'knowledge',
					array(
						'parent_id' => $id,
						'body'      => 'reviewed',
					),
					array(
						'title' => array(
							'label' => __( 'Knowledge title', 'buddypress-intelligence' ),
							'value' => $entry['title'],
						),
					),
					__( 'Publish reviewed knowledge', 'buddypress-intelligence' )
				);
			}
		}
	}
	private function adminLabels(): array {
		return array(
			'overview'    => __( 'Overview', 'buddypress-intelligence' ),
			'topics'      => __( 'Topics', 'buddypress-intelligence' ),
			'moderation'  => __( 'Moderation', 'buddypress-intelligence' ),
			'automations' => __( 'Automations', 'buddypress-intelligence' ),
			'analytics'   => __( 'Analytics', 'buddypress-intelligence' ),
			'experiments' => __( 'Experiments', 'buddypress-intelligence' ),
			'settings'    => __( 'Settings and privacy', 'buddypress-intelligence' ),
			'tools'       => __( 'Diagnostics', 'buddypress-intelligence' ),
		);
	}
	public function admin(): void {
		$this->assets();
		$admin_records = array();
		$section       = str_replace( 'bpi-', '', $this->query( 'page', 'overview' ) );
		$labels        = $this->adminLabels();
		if ( ! isset( $labels[ $section ] ) ) {
			$section = 'overview';
		}
		$cap = match ( $section ) {
			'topics' => 'bpi_manage_topics',
			'moderation' => 'bpi_view_cases',
			'automations' => 'bpi_manage_automations',
			'analytics' => 'bpi_view_analytics',
			'experiments' => 'bpi_manage_feed',
			default => 'bpi_manage_settings',
		};
		if ( ! bp_current_user_can( $cap ) ) {
			$this->error( new \WP_Error( 'bpi_forbidden', __( 'You do not have permission to manage this community feature.', 'buddypress-intelligence' ) ) );
			return;
		}
		echo '<div class="wrap bpi-community"><h1>' . esc_html( $labels[ $section ] ) . '</h1>';
		$this->notice();
		if ( 'settings' === $section ) {
			$this->settingsForm();
		} elseif ( 'topics' === $section ) {
			$topics = $this->call( 'topics' );
			if ( is_wp_error( $topics ) ) {
				$this->error( $topics );
				echo '</div>';
				return;
			}
			$topics        = $this->db->rows( 'topics', array(), 50, ( max( 1, (int) $this->query( 'bpi_admin_page', '1' ) ) - 1 ) * 50 );
			$admin_records = $topics;
			$this->table( $topics );
			foreach ( $topics as $topic ) {
				echo '<p><a href="' . esc_url( add_query_arg( 'bpi_edit_topic', $topic['id'] ) ) . '">' . esc_html__( 'Edit topic', 'buddypress-intelligence' ) . ': ' . esc_html( $topic['name'] ) . '</a></p>';
			}
			$edit  = (int) $this->query( 'bpi_edit_topic', '0' );
			$topic = $edit ? $this->db->one( 'topics', array( 'id' => $edit ) ) : array();
			if ( $edit && ! $topic ) {
				$this->error( new \WP_Error( 'bpi_missing', __( 'Topic unavailable.', 'buddypress-intelligence' ) ) );
				echo '</div>';
				return;
			}
			$this->form(
				$edit ? 'topics/' . $edit : 'topics',
				$edit ? array( 'slug' => $topic['slug'] ) : array(),
				array(
					'name'        => array(
						'label' => __( 'Topic name', 'buddypress-intelligence' ),
						'value' => $topic['name'] ?? '',
					),
					'description' => array(
						'label' => __( 'Description', 'buddypress-intelligence' ),
						'type'  => 'textarea',
						'value' => $topic['description'] ?? '',
					),
					'parent_id'   => array(
						'label' => __( 'Parent topic number (0 for none)', 'buddypress-intelligence' ),
						'type'  => 'number',
						'value' => (int) ( $topic['parent_id'] ?? 0 ),
					),
					'aliases'     => array(
						'label' => __( 'Aliases (JSON list)', 'buddypress-intelligence' ),
						'type'  => 'json',
						'value' => json_decode( $topic['aliases'] ?? '[]', true ) ?: array(),
					),
					'status'      => array(
						'label'   => __( 'Topic status', 'buddypress-intelligence' ),
						'type'    => 'select',
						'value'   => $topic['status'] ?? 'active',
						'options' => array(
							'active'   => __( 'Active', 'buddypress-intelligence' ),
							'archived' => __( 'Archived', 'buddypress-intelligence' ),
						),
					),
				),
				$edit ? __( 'Save topic', 'buddypress-intelligence' ) : __( 'Create topic', 'buddypress-intelligence' )
			);
		} elseif ( 'moderation' === $section ) {
			$this->moderationScreen();
		} elseif ( 'automations' === $section ) {
			$rules = $this->call( 'rules', 'GET', array( 'page' => max( 1, (int) $this->query( 'bpi_admin_page', '1' ) ) ) );
			$this->table( $rules );
			if ( is_wp_error( $rules ) ) {
				echo '</div>';
				return;
			}
			$admin_records = $rules;
			foreach ( $rules as $rule ) {
				echo '<p><a href="' . esc_url( add_query_arg( 'bpi_edit_rule', $rule['id'] ) ) . '">' . esc_html__( 'Edit automation', 'buddypress-intelligence' ) . ': ' . esc_html( $rule['name'] ) . '</a></p>';
			}
			$edit = (int) $this->query( 'bpi_edit_rule', '0' );
			$rule = $edit ? $this->db->one( 'rules', array( 'id' => $edit ) ) : array();
			if ( $edit && ! $rule ) {
				$this->error( new \WP_Error( 'bpi_missing', __( 'Automation unavailable.', 'buddypress-intelligence' ) ) );
				echo '</div>';
				return;
			}
			$this->form(
				$edit ? 'rules/' . $edit : 'rules',
				array(
					'conditions' => array(),
					'config'     => array(),
				),
				array(
					'name'       => array(
						'label' => __( 'Rule name', 'buddypress-intelligence' ),
						'value' => $rule['name'] ?? '',
					),
					'trigger'    => array(
						'label'   => __( 'When this happens', 'buddypress-intelligence' ),
						'type'    => 'select',
						'options' => array_combine( Events::TYPES, Events::TYPES ),
						'value'   => $rule['trigger_type'] ?? 'member_registered',
					),
					'action'     => array(
						'label'   => __( 'Action', 'buddypress-intelligence' ),
						'type'    => 'select',
						'value'   => $rule['action'] ?? 'notify',
						'options' => array(
							'notify'  => __( 'Notify member', 'buddypress-intelligence' ),
							'review'  => __( 'Queue moderation review', 'buddypress-intelligence' ),
							'feature' => __( 'Feature activity', 'buddypress-intelligence' ),
							'state'   => __( 'Save internal state', 'buddypress-intelligence' ),
						),
					),
					'conditions' => array(
						'label' => __( 'Conditions (JSON object; advanced)', 'buddypress-intelligence' ),
						'type'  => 'json',
						'value' => json_decode( $rule['conditions'] ?? '{}' ) ?: new \stdClass(),
					),
					'config'     => array(
						'label' => __( 'Action options (JSON object; advanced)', 'buddypress-intelligence' ),
						'type'  => 'json',
						'value' => json_decode( $rule['config'] ?? '{}' ) ?: new \stdClass(),
					),
					'enabled'    => array(
						'label' => __( 'Enabled', 'buddypress-intelligence' ),
						'type'  => 'checkbox',
						'value' => ! empty( $rule['enabled'] ),
					),
					'dry_run'    => array(
						'label' => __( 'Test without performing actions', 'buddypress-intelligence' ),
						'type'  => 'checkbox',
						'value' => ! $edit || ! empty( $rule['dry_run'] ),
					),
				),
				$edit ? __( 'Save automation', 'buddypress-intelligence' ) : __( 'Create automation', 'buddypress-intelligence' )
			);
			$this->table( $this->call( 'runs' ) );
		} elseif ( 'analytics' === $section ) {
			$data = $this->call( 'analytics' );
			if ( is_wp_error( $data ) ) {
				$this->error( $data );
			} else {
				foreach ( $data['definitions'] as $definition ) {
					echo '<p>' . esc_html( $definition ) . '</p>';
				}
				$this->table( $data['metrics'] );
				$this->table( array_values( $data['health'] ) );
			}
		} elseif ( 'experiments' === $section ) {
			$experiments = $this->call( 'experiments' );
			$this->table( $experiments );
			if ( is_array( $experiments ) ) {
				foreach ( $experiments as $experiment ) {
					$result = $this->call( 'experiments/' . $experiment['id'] );
					if ( ! is_wp_error( $result ) ) {
						echo '<p>' . esc_html( $result['definition'] ) . '</p>';
						$this->table( $result['results'] );
					}
					if ( 'ended' !== $experiment['status'] ) {
						$base             = array_intersect_key( $experiment, array_flip( array( 'name', 'metric', 'starts_at', 'ends_at' ) ) );
						$base['variants'] = json_decode( $experiment['variants'], true );
						$base['status']   = 'draft' === $experiment['status'] ? 'running' : 'ended';
						$this->form( 'experiments/' . $experiment['id'], $base, array(), 'draft' === $experiment['status'] ? __( 'Start experiment', 'buddypress-intelligence' ) : __( 'End experiment', 'buddypress-intelligence' ) );
					}
				}
			}
			$this->form(
				'experiments',
				array(
					'status' => 'draft',
					'metric' => 'recommendation_clicked',
				),
				array(
					'name'      => array( 'label' => __( 'Experiment name', 'buddypress-intelligence' ) ),
					'variants'  => array(
						'label' => __( 'Variant names (JSON list)', 'buddypress-intelligence' ),
						'type'  => 'json',
						'value' => array( 'control', 'alternate' ),
					),
					'starts_at' => array(
						'label' => __( 'Start (UTC)', 'buddypress-intelligence' ),
						'value' => gmdate( 'Y-m-d H:i:s' ),
					),
					'ends_at'   => array(
						'label' => __( 'End (UTC)', 'buddypress-intelligence' ),
						'value' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
					),
				),
				__( 'Create draft experiment', 'buddypress-intelligence' )
			);
		} else {
			$data = $this->call( 'diagnostics' );
			if ( is_wp_error( $data ) ) {
				$this->error( $data );
			} else {
				$this->table( array( $data ) );
			}
			echo '<p>' . esc_html__( 'Place [bpi_community] on a community page or use the Community tools member tab. Enable only the modules you need. Analytics and external providers require explicit consent.', 'buddypress-intelligence' ) . '</p>';
			foreach ( $this->db->rows( 'jobs', array( 'status' => 'failed' ), 20 ) as $job ) {
				echo '<p>' . esc_html( $job['kind'] . ' #' . $job['id'] . ' · ' . $job['error'] ) . '</p>';
				$this->form( 'jobs/' . $job['id'] . '/retry', array(), array(), __( 'Retry failed job', 'buddypress-intelligence' ) );
			}
		}
		if ( in_array( $section, array( 'topics', 'automations' ), true ) ) {
			$page = min( 1000, max( 1, (int) $this->query( 'bpi_admin_page', '1' ) ) );
			echo '<nav class="bpi-pagination" aria-label="' . esc_attr__( 'Administration pages', 'buddypress-intelligence' ) . '">';
			if ( $page > 1 ) {
				echo '<a href="' . esc_url( add_query_arg( 'bpi_admin_page', $page - 1, remove_query_arg( array( 'bpi_edit_topic', 'bpi_edit_rule' ) ) ) ) . '">' . esc_html__( 'Previous', 'buddypress-intelligence' ) . '</a>';
			}
			if ( count( $admin_records ) === 50 && $page < 1000 ) {
				echo '<a href="' . esc_url( add_query_arg( 'bpi_admin_page', $page + 1, remove_query_arg( array( 'bpi_edit_topic', 'bpi_edit_rule' ) ) ) ) . '">' . esc_html__( 'Next', 'buddypress-intelligence' ) . '</a>';
			}
			echo '</nav>';
		}
		echo '</div>';
	}
	private function settingsForm(): void {
		$settings = $this->call( 'settings' );
		if ( is_wp_error( $settings ) ) {
			$this->error( $settings );
			return;
		}
		$fields = array();
		$labels = array(
			'graph'           => __( 'Following and blocking', 'buddypress-intelligence' ),
			'topics'          => __( 'Topics and interests', 'buddypress-intelligence' ),
			'feed'            => __( 'Personalized feeds', 'buddypress-intelligence' ),
			'recommendations' => __( 'Recommendations', 'buddypress-intelligence' ),
			'search'          => __( 'Unified search', 'buddypress-intelligence' ),
			'expertise'       => __( 'Expertise and reputation', 'buddypress-intelligence' ),
			'qa'              => __( 'Questions and knowledge', 'buddypress-intelligence' ),
			'moderation'      => __( 'Moderation tools', 'buddypress-intelligence' ),
			'automations'     => __( 'Automations', 'buddypress-intelligence' ),
			'analytics'       => __( 'Analytics', 'buddypress-intelligence' ),
			'experiments'     => __( 'Experiments', 'buddypress-intelligence' ),
			'ai'              => __( 'Optional AI', 'buddypress-intelligence' ),
		);
		foreach ( $labels as $module => $label ) {
			$fields[ 'modules:' . $module ] = array(
				'label' => $label,
				'type'  => 'checkbox',
				'value' => $settings['modules'][ $module ],
			);
		}
		foreach ( array(
			'event_days'      => __( 'Keep events for this many days', 'buddypress-intelligence' ),
			'analytics_days'  => __( 'Keep aggregate analytics for this many days', 'buddypress-intelligence' ),
			'candidate_limit' => __( 'Maximum candidates per request', 'buddypress-intelligence' ),
			'accepted_points' => __( 'Points per accepted topic answer', 'buddypress-intelligence' ),
		) as $key => $label ) {
			$fields[ $key ] = array(
				'label' => $label,
				'type'  => 'number',
				'value' => $settings[ $key ],
			);
		}
		foreach ( array(
			'analytics_consent'   => __( 'Enable consent-based analytics collection', 'buddypress-intelligence' ),
			'external_consent'    => __( 'Allow data transfers to the configured AI provider', 'buddypress-intelligence' ),
			'appeals'             => __( 'Allow moderation appeals', 'buddypress-intelligence' ),
			'delete_on_uninstall' => __( 'Permanently delete plugin data on uninstall', 'buddypress-intelligence' ),
		) as $key => $label ) {
			$fields[ $key ] = array(
				'label' => $label,
				'type'  => 'checkbox',
				'value' => $settings[ $key ],
			);
		}
		$fields['provider_url'] = array(
			'label' => __( 'Public HTTPS AI provider endpoint', 'buddypress-intelligence' ),
			'value' => $settings['provider_url'],
			'help'  => __( 'Only up to 4,000 characters of public editorial text are sent. Store credentials in BPI_PROVIDER_TOKEN in wp-config.php. Member and site consent are both required.', 'buddypress-intelligence' ),
		);
		$fields['weights']      = array(
			'label' => __( 'Ranking weights (advanced JSON)', 'buddypress-intelligence' ),
			'type'  => 'json',
			'value' => $settings['weights'],
		);
		$fields['post_types']   = array(
			'label' => __( 'Supported public post types (JSON list)', 'buddypress-intelligence' ),
			'type'  => 'json',
			'value' => $settings['post_types'],
		);
		$this->form( 'settings', array(), $fields, __( 'Save settings', 'buddypress-intelligence' ) );
	}
	private function moderationScreen(): void {
		$id = (int) $this->query( 'bpi_case' );
		if ( ! $id ) {
			$cases = $this->call( 'cases' );
			$this->table( $cases );
			if ( is_array( $cases ) ) {
				foreach ( $cases as $case ) {
					echo '<p><a href="' . esc_url( add_query_arg( 'bpi_case', $case['id'] ) ) . '">' . esc_html__( 'Review case', 'buddypress-intelligence' ) . ' #' . esc_html( $case['id'] ) . '</a></p>';
				}
			}
			return;
		}
		$case = $this->call( 'cases/' . $id );
		if ( is_wp_error( $case ) ) {
			$this->error( $case );
			return;
		}
		$this->table( array( array_diff_key( $case, array_flip( array( 'reports', 'audit', 'appeals', 'subject_history' ) ) ) ) );
		$this->table( $case['subject_history'] );
		$this->table( $case['reports'] );
		$this->table( $case['audit'] );
		$this->form(
			'cases/' . $id,
			array(),
			array(
				'action'   => array(
					'label'   => __( 'Moderation action', 'buddypress-intelligence' ),
					'type'    => 'select',
					'options' => array(
						'note'       => __( 'Add internal note', 'buddypress-intelligence' ),
						'warn'       => __( 'Record warning', 'buddypress-intelligence' ),
						'hide'       => __( 'Hide from community surfaces', 'buddypress-intelligence' ),
						'restore'    => __( 'Restore visibility', 'buddypress-intelligence' ),
						'restrict'   => __( 'Restrict community interactions', 'buddypress-intelligence' ),
						'unrestrict' => __( 'Remove restriction', 'buddypress-intelligence' ),
						'dismiss'    => __( 'Dismiss report', 'buddypress-intelligence' ),
						'transition' => __( 'Change workflow status', 'buddypress-intelligence' ),
					),
				),
				'status'   => array(
					'label'   => __( 'New workflow status', 'buddypress-intelligence' ),
					'type'    => 'select',
					'options' => array_combine( array( 'triaged', 'under_review', 'actioned', 'no_action', 'resolved', 'open' ), array( __( 'Triaged', 'buddypress-intelligence' ), __( 'Under review', 'buddypress-intelligence' ), __( 'Actioned', 'buddypress-intelligence' ), __( 'No action', 'buddypress-intelligence' ), __( 'Resolved', 'buddypress-intelligence' ), __( 'Open', 'buddypress-intelligence' ) ) ),
				),
				'note'     => array(
					'label' => __( 'Internal note (visible to moderators only)', 'buddypress-intelligence' ),
					'type'  => 'textarea',
				),
				'days'     => array(
					'label' => __( 'Restriction duration in days', 'buddypress-intelligence' ),
					'type'  => 'number',
					'value' => 7,
				),
				'priority' => array(
					'label' => __( 'Priority (1–5)', 'buddypress-intelligence' ),
					'type'  => 'number',
					'value' => (int) $case['priority'],
				),
				'severity' => array(
					'label' => __( 'Severity (1–5)', 'buddypress-intelligence' ),
					'type'  => 'number',
					'value' => (int) $case['severity'],
				),
				'assigned' => array(
					'label' => __( 'Assigned moderator member number (0 to unassign)', 'buddypress-intelligence' ),
					'type'  => 'number',
					'value' => (int) $case['assigned'],
				),
			),
			__( 'Apply action', 'buddypress-intelligence' )
		);
		foreach ( $case['appeals'] as $appeal ) {
			$this->table( array( $appeal ) );
			if ( 'pending' === $appeal['status'] ) {
				$this->form(
					'appeals/' . $appeal['id'],
					array(),
					array(
						'status'   => array(
							'label'   => __( 'Appeal decision', 'buddypress-intelligence' ),
							'type'    => 'select',
							'options' => array(
								'upheld'   => __( 'Uphold appeal', 'buddypress-intelligence' ),
								'rejected' => __( 'Reject appeal', 'buddypress-intelligence' ),
							),
						),
						'decision' => array(
							'label' => __( 'Decision shared with the member', 'buddypress-intelligence' ),
							'type'  => 'textarea',
						),
					),
					__( 'Record appeal decision', 'buddypress-intelligence' )
				);
			}
		}
	}
	private function table( $rows ): void {
		if ( is_wp_error( $rows ) ) {
			$this->error( $rows );
			return;
		}
		if ( ! $rows ) {
			echo '<p class="bpi-alert">' . esc_html__( 'No records yet.', 'buddypress-intelligence' ) . '</p>';
			return;
		}
		echo '<div class="bpi-records">';
		foreach ( $rows as $row ) {
			echo '<dl class="bpi-card">';
			foreach ( $row as $key => $value ) {
				echo '<dt>' . esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ) . '</dt><dd>' . esc_html( null === $value ? __( 'Withheld', 'buddypress-intelligence' ) : ( is_array( $value ) ? wp_json_encode( $value ) : (string) $value ) ) . '</dd>';
			}
			echo '</dl>';
		}
		echo '</div>';
	}
}
