<?php
namespace BuddyPressIntelligence;

final class Integration {
	private Events $events;
	private Policy $policy;
	private Settings $settings;
	private UI $ui;
	public function __construct( Events $events, Policy $policy, Settings $settings, UI $ui ) {
		$this->events   = $events;
		$this->policy   = $policy;
		$this->settings = $settings;
		$this->ui       = $ui;
	}
	public function register(): void {
		add_action(
			'template_redirect',
			function (): void {
				$member = (int) bp_displayed_user_id();
				if ( $member && ! $this->policy->view( get_current_user_id(), 'member', $member ) ) {
					nocache_headers();
					wp_die( esc_html__( 'This community item is unavailable.', 'buddypress-intelligence' ), '', array( 'response' => 404 ) );
				}
			},
			0
		);
		if ( bp_is_active( 'messages' ) ) {
			// Verified BP 14 send() aborts before any insert when recipients are empty.
			add_action(
				'messages_message_before_save',
				function ( $message ): void {
					foreach ( (array) $message->recipients as $recipient ) {
						if ( ! $this->policy->interact( (int) $message->sender_id, 'member', (int) $recipient->user_id ) ) {
							$message->recipients = array();
							break;
						}
					}
				},
				100
			);
		}
		if ( bp_is_active( 'friends' ) ) {
			$this->friendships();
		}
		if ( bp_is_active( 'activity' ) ) {
			add_action(
				'bp_activity_before_save',
				function ( $activity ): void {
					$actor   = (int) $activity->user_id;
					$allowed = (int) get_user_meta( $actor, '_bpi_restricted_until', true ) < time();
					if ( 'activity_comment' === $activity->type ) {
						$allowed = $allowed && $this->policy->interact( $actor, 'activity', (int) $activity->item_id );
					}
					if ( ! $allowed ) {
						$activity->error_type = 'wp_error';
						$activity->errors->add( 'bpi_unavailable', __( 'This community interaction is unavailable.', 'buddypress-intelligence' ) );
					}
				},
				100
			);
		}
		add_action(
			'bp_setup_nav',
			function (): void {
				bp_core_new_nav_item(
					array(
						'name'                    => __( 'Community tools', 'buddypress-intelligence' ),
						'slug'                    => 'intelligence',
						'position'                => 80,
						'show_for_displayed_user' => bp_is_my_profile(),
						'screen_function'         => function (): void {
									add_action( 'bp_template_content', array( $this->ui, 'member' ) );
									bp_core_load_template( array( 'members/single/plugins' ) );
						},
					)
				);
			},
			90
		);
		if ( $this->settings->enabled( 'graph' ) ) {
			add_action(
				'bp_member_header_actions',
				function (): void {
					$this->ui->relationship( 'member', (int) bp_displayed_user_id() );
				}
			);
			if ( bp_is_active( 'groups' ) ) {
				add_action(
					'bp_group_header_actions',
					function (): void {
						$this->ui->relationship( 'group', (int) bp_get_current_group_id() );
					}
				);
			}
		}
		if ( bp_is_active( 'activity' ) && $this->settings->enabled( 'feed' ) ) {
			add_action(
				'bp_activity_entry_meta',
				function (): void {
					$this->ui->itemActions( 'activity', (int) bp_get_activity_id() );
				}
			);
		}
		// Native list surfaces use the same policy, with a guarded adapter to avoid recursion.
		add_filter(
			'bp_activity_get',
			function ( array $data ): array {
				if ( NativeObjects::isReading() || empty( $data['activities'] ) ) {
					return $data;
				}
				$before             = count( $data['activities'] );
				$data['activities'] = array_values( array_filter( $data['activities'], fn( $a ) => $this->policy->view( get_current_user_id(), 'activity', (int) $a->id ) ) );
				foreach ( $data['activities'] as $key => $activity ) {
					$data['activities'][ $key ] = clone $activity;
					if ( ! empty( $activity->children ) ) {
						$data['activities'][ $key ]->children = $this->comments( $activity->children );
					}
				}
				if ( count( $data['activities'] ) !== $before ) {
					$data['total'] = count( $data['activities'] );
				}
				return $data;
			},
			99
		);
		// BP's specific-object reader and serialized nested comments bypass the list hook.
		add_filter(
			'bp_activity_get_specific',
			function ( array $data ): array {
				if ( NativeObjects::isReading() || empty( $data['activities'] ) ) {
					return $data;
				}
				$before             = count( $data['activities'] );
				$data['activities'] = array_values( array_filter( $data['activities'], fn( $activity ) => $this->policy->view( get_current_user_id(), 'activity', (int) $activity->id ) ) );
				foreach ( $data['activities'] as $key => $activity ) {
					$data['activities'][ $key ] = clone $activity;
					if ( ! empty( $activity->children ) ) {
						$data['activities'][ $key ]->children = $this->comments( $activity->children );
					}
				}
				if ( count( $data['activities'] ) !== $before ) {
					$data['total'] = count( $data['activities'] );
				}
				return $data;
			},
			99
		);
		add_filter(
			'bp_rest_activity_prepare_comments',
			function ( array $data ): array {
				return array_values( array_filter( $data, fn( $comment ) => $this->policy->view( get_current_user_id(), 'activity', (int) ( $comment['id'] ?? 0 ) ) ) );
			},
			99
		);
		add_filter(
			'bp_core_get_users',
			function ( array $data ): array {
				if ( NativeObjects::isReading() || empty( $data['users'] ) ) {
					return $data;
				}
				$before        = count( $data['users'] );
				$data['users'] = array_values( array_filter( $data['users'], fn( $u ) => $this->policy->view( get_current_user_id(), 'member', (int) $u->ID ) ) );
				if ( count( $data['users'] ) !== $before ) {
					$data['total'] = count( $data['users'] );
				}
				return $data;
			},
			99
		);
		add_filter(
			'groups_get_groups',
			function ( array $data ): array {
				if ( NativeObjects::isReading() || empty( $data['groups'] ) ) {
					return $data;
				}
				$before         = count( $data['groups'] );
				$data['groups'] = array_values( array_filter( $data['groups'], fn( $g ) => $this->policy->view( get_current_user_id(), 'group', is_object( $g ) ? (int) $g->id : (int) $g ) ) );
				if ( count( $data['groups'] ) !== $before ) {
					$data['total'] = count( $data['groups'] );
				}
				return $data;
			},
			99
		);
		add_filter(
			'rest_pre_dispatch',
			function ( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
				if ( preg_match( '#^/buddypress/v[12]/(members|groups|activity)/(\d+)#', $request->get_route(), $matches ) ) {
					$type = array(
						'members'  => 'member',
						'groups'   => 'group',
						'activity' => 'activity',
					)[ $matches[1] ];
					$can  = 'GET' === $request->get_method() ? $this->policy->view( get_current_user_id(), $type, (int) $matches[2] ) : $this->policy->interact( get_current_user_id(), $type, (int) $matches[2] );
					if ( ! $can ) {
						return new \WP_Error( 'bpi_unavailable', __( 'This community item is unavailable.', 'buddypress-intelligence' ), array( 'status' => 404 ) );
					}
				}
				return $result;
			},
			10,
			3
		);
		add_filter(
			'bp_activity_can_comment',
			function ( bool $allowed ): bool {
				return $allowed && $this->policy->interact( get_current_user_id(), 'activity', (int) bp_get_activity_id() );
			},
			100
		);
		add_filter(
			'bp_activity_can_comment_reply',
			function ( bool $allowed, $comment ): bool {
				return $allowed && $this->policy->interact( get_current_user_id(), 'activity', (int) ( $comment->id ?? 0 ) );
			},
			100,
			2
		);
		if ( $this->settings->enabled( 'automations' ) || $this->settings->enabled( 'analytics' ) ) {
			$this->events();
		}
		add_filter(
			'bp_notifications_get_registered_components',
			static function ( array $components ): array {
				$components[] = 'bpi';
				return array_unique( $components );
			}
		);
		add_filter(
			'bp_notifications_get_notifications_for_user',
			static function ( $content, int $item, int $secondary, int $count, string $format, string $action, string $component ) {
				if ( 'bpi' !== $component ) {
					return $content;
				}
				$text = __( 'You have a community update.', 'buddypress-intelligence' );
				$url  = bp_members_get_user_url( get_current_user_id(), array( 'single_item_component' => 'intelligence' ) );
				return 'string' === $format ? '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' : array(
					'text' => $text,
					'link' => $url,
				);
			},
			10,
			7
		);
	}
	/** Guard verified native HTTP writers; direct PHP writers use the policy contract. */
	private function friendships(): void {
		add_filter(
			'rest_post_dispatch',
			function ( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
				if ( 'GET' !== $request->get_method() || ! preg_match( '#^/buddypress/v[12]/friends(?:/(\d+))?$#', $request->get_route(), $match ) || ! $response instanceof \WP_REST_Response || $response->get_status() >= 400 ) {
					return $response;
				}
				$data = $response->get_data();
				if ( ! is_array( $data ) ) {
					return $response;
				}
				$visible = array_values(
					array_filter(
						$data,
						fn( $friendship ) => is_array( $friendship ) && $this->policy->view( get_current_user_id(), 'member', (int) ( $friendship['initiator_id'] ?? 0 ) ) && $this->policy->view( get_current_user_id(), 'member', (int) ( $friendship['friend_id'] ?? 0 ) )
					)
				);
				if ( ! empty( $match[1] ) && ! $visible ) {
					return new \WP_REST_Response(
						array(
							'code'    => 'bpi_unavailable',
							'message' => __( 'This community item is unavailable.', 'buddypress-intelligence' ),
						),
						404
					);
				}
				if ( count( $visible ) !== count( $data ) ) {
					$response = clone $response;
					$response->set_data( $visible );
					$response->header( 'X-WP-Total', (string) count( $visible ) );
					$response->header( 'X-WP-TotalPages', $visible ? '1' : '0' );
				}
				$response->header( 'Cache-Control', 'private, no-store' );
				return $response;
			},
			100,
			3
		);
		add_filter(
			'bp_rest_friends_create_item_permissions_check',
			function ( $allowed, \WP_REST_Request $request ) {
				if ( true !== $allowed ) {
					return $allowed;
				}
				$actor  = (int) $request->get_param( 'initiator_id' );
				$target = (int) $request->get_param( 'friend_id' );
				return $this->friendshipAllowed( $actor, $target ) ? $allowed : $this->friendshipError();
			},
			100,
			2
		);
		add_filter(
			'bp_rest_friends_update_item_permissions_check',
			function ( $allowed, \WP_REST_Request $request ) {
				// BP's update endpoint receives the other member ID, not a friendship ID.
				return true !== $allowed || $this->friendshipAllowed( get_current_user_id(), (int) $request->get_param( 'id' ) ) ? $allowed : $this->friendshipError();
			},
			100,
			2
		);
		add_filter(
			'bp_get_add_friend_button',
			function ( array $button ): array {
				if ( 'not_friends' === ( $button['id'] ?? '' ) && preg_match( '/^friend-(\d+)$/', $button['link_id'] ?? '', $match ) && ! $this->friendshipAllowed( get_current_user_id(), (int) $match[1] ) ) {
					return array();
				}
				return $button;
			}
		);
		foreach ( array( 'friends_add_friend', 'friends_accept_friendship', 'addremove_friend', 'accept_friendship' ) as $action ) {
			add_action(
				'wp_ajax_' . $action,
				function () use ( $action ): void {
					// No mutation occurs here. The native handler retains its nonce and ownership checks.
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Policy-only preflight; native handlers verify nonces.
					$input  = wp_unslash( $_POST );
					$target = absint( $input['item_id'] ?? $input['fid'] ?? $input['id'] ?? 0 );
					$accept = in_array( $action, array( 'friends_accept_friendship', 'accept_friendship' ), true );
					if ( $accept ) {
						$target = $this->friendshipOther( $target );
					} elseif ( 'addremove_friend' === $action && 'not_friends' !== friends_check_friendship_status( get_current_user_id(), $target ) ) {
						return; // Native legacy handler can remove or withdraw an existing relationship.
					}
					if ( ! $this->friendshipAllowed( get_current_user_id(), $target ) ) {
						wp_send_json_error( array( 'feedback' => esc_html__( 'This community interaction is unavailable.', 'buddypress-intelligence' ) ), 403 );
					}
				},
				0
			);
		}
		$screen = function (): void {
			if ( ! bp_is_friends_component() ) {
				return;
			}
			$target = 0;
			if ( bp_is_current_action( 'add-friend' ) ) {
				$target = absint( bp_action_variable( 0 ) );
			} elseif ( bp_is_current_action( 'requests' ) && bp_is_action_variable( 'accept', 0 ) ) {
				$target = $this->friendshipOther( absint( bp_action_variable( 1 ) ) );
			} else {
				return;
			}
			if ( ! $this->friendshipAllowed( get_current_user_id(), $target ) ) {
				wp_die( esc_html__( 'This community interaction is unavailable.', 'buddypress-intelligence' ), '', array( 'response' => 403 ) );
			}
		};
		add_action( 'bp_actions', $screen, 0 );
		add_action( 'bp_screens', $screen, 0 );
	}
	private function friendshipOther( int $id ): int {
		$friendship = new \BP_Friends_Friendship( $id, false, false );
		$viewer     = get_current_user_id();
		if ( ! $friendship->id || ! in_array( $viewer, array( (int) $friendship->initiator_user_id, (int) $friendship->friend_user_id ), true ) ) {
			return 0;
		}
		return $viewer === (int) $friendship->initiator_user_id ? (int) $friendship->friend_user_id : (int) $friendship->initiator_user_id;
	}
	private function friendshipAllowed( int $actor, int $target ): bool {
		return $actor > 0 && $target > 0 && $actor !== $target && $this->policy->interact( $actor, 'member', $target ) && $this->policy->interact( $target, 'member', $actor );
	}
	private function friendshipError(): \WP_Error {
		return new \WP_Error( 'bpi_unavailable', __( 'This community interaction is unavailable.', 'buddypress-intelligence' ), array( 'status' => 403 ) );
	}
	private function comments( array $comments, int $depth = 0 ): array {
		if ( $depth > 50 ) {
			return array();
		}
		$result = array();
		foreach ( $comments as $key => $comment ) {
			if ( $this->policy->view( get_current_user_id(), 'activity', (int) $comment->id ) ) {
				$result[ $key ] = clone $comment;
				if ( ! empty( $comment->children ) ) {
					$result[ $key ]->children = $this->comments( $comment->children, $depth + 1 );
				}
			}
		}
		return $result;
	}
	private function safeEvent( string $type, int $actor, string $object_type, int $id, array $metadata = array(), string $key = '' ): void {
		try {
			$this->events->record( $type, $actor, $object_type, $id, $metadata, $key, 'buddypress' );
		} catch ( \Throwable $e ) {
			do_action( 'bpi_event_failed', $type );
		}
	}
	private function events(): void {
		add_action( 'user_register', fn( int $id ) => $this->safeEvent( 'member_registered', $id, 'member', $id, array(), 'registered:' . $id ) );
		add_action( 'profile_update', fn( int $id ) => $this->safeEvent( 'member_profile_updated', $id, 'member', $id ) );
		if ( bp_is_active( 'xprofile' ) ) {
			add_action( 'xprofile_updated_profile', fn( int $id ) => $this->safeEvent( 'member_profile_updated', $id, 'member', $id ) );
		}
		if ( bp_is_active( 'activity' ) ) {
			add_action(
				'bp_activity_after_save',
				function ( $activity ): void {
					if ( ! $activity->is_spam && 'activity_comment' !== $activity->type ) {
						$this->safeEvent( 'activity_created', (int) $activity->user_id, 'activity', (int) $activity->id, array( 'group_id' => 'groups' === $activity->component ? (int) $activity->item_id : 0 ), 'activity:' . $activity->id );
					}
				},
				20
			);
			add_action(
				'bp_activity_comment_posted',
				function ( int $id, array $args ): void {
					$this->safeEvent( 'activity_commented', (int) $args['user_id'], 'activity', (int) $args['activity_id'], array( 'parent_id' => $id ), 'comment:' . $id );
				},
				20,
				2
			);
			add_action( 'bp_activity_add_user_favorite', fn( int $id, int $actor ) => $this->safeEvent( 'activity_favorited', $actor, 'activity', $id, array(), 'favorite:' . $actor . ':' . $id ), 20, 2 );
		}
		if ( bp_is_active( 'groups' ) ) {
			foreach ( array(
				'groups_join_group'  => 'group_joined',
				'groups_leave_group' => 'group_left',
			) as $hook => $type ) {
				add_action( $hook, fn( int $group, int $actor ) => $this->safeEvent( $type, $actor, 'group', $group, array( 'group_id' => $group ) ), 20, 2 );
			}
		}
		if ( bp_is_active( 'friends' ) ) {
			add_action( 'friends_friendship_accepted', fn( int $id, int $actor, int $other ) => $this->safeEvent( 'friendship_created', $actor, 'member', $other ), 20, 3 );
			// The similarly named "deleted" hook fires before deletion and can contain a null ID.
			add_action( 'friends_friendship_post_delete', fn( int $actor, int $other ) => $this->safeEvent( 'friendship_removed', $actor, 'member', $other ), 20, 2 );
		}
	}
}
