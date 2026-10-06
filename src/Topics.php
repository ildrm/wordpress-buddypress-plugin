<?php
namespace BuddyPressIntelligence;

final class Topics {
	private Database $db;
	private Policy $policy;
	private Events $events;
	public function __construct( Database $db, Policy $policy, Events $events ) {
		$this->db     = $db;
		$this->policy = $policy;
		$this->events = $events;
	}
	public function save( array $data, int $id = 0 ): int {
		if ( ! bp_current_user_can( 'bpi_manage_topics' ) ) {
			throw new Denied();
		}
		$name = $data['name'] ?? '';
		if ( ! is_string( $name ) || ! trim( $name ) || mb_strlen( $name ) > 120 || array_diff( array_keys( $data ), array( 'name', 'slug', 'description', 'parent_id', 'aliases', 'status' ) ) || ( isset( $data['description'] ) && ( ! is_string( $data['description'] ) || mb_strlen( $data['description'] ) > 4000 ) ) ) {
			throw new \InvalidArgumentException( 'Invalid topic.' );
		}
		$parent = (int) ( $data['parent_id'] ?? 0 );
		if ( $parent && ( $parent === $id || ! $this->db->one( 'topics', array( 'id' => $parent ) ) ) ) {
			throw new \InvalidArgumentException( 'Invalid parent topic.' );
		}
		$seen   = array( $id );
		$cursor = $parent;
		while ( $cursor ) {
			if ( in_array( $cursor, $seen, true ) || count( $seen ) > 20 ) {
				throw new \InvalidArgumentException( 'Topic hierarchy cycle.' );
			}
			$seen[] = $cursor;
			$cursor = (int) ( $this->db->one( 'topics', array( 'id' => $cursor ) )['parent_id'] ?? 0 );
		}
		$aliases = $data['aliases'] ?? array();
		if ( ! is_array( $aliases ) || count( $aliases ) > 20 || count( array_filter( $aliases, 'is_string' ) ) !== count( $aliases ) ) {
			throw new \InvalidArgumentException( 'Invalid aliases.' );
		}
		$status = $data['status'] ?? 'active';
		$slug   = sanitize_title( $data['slug'] ?? $name );
		if ( ! $slug || strlen( $slug ) > 100 || ! in_array( $status, array( 'active', 'archived' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid topic status or slug.' );
		}
		$row = array(
			'name'        => sanitize_text_field( $name ),
			'slug'        => $slug,
			'description' => sanitize_textarea_field( $data['description'] ?? '' ),
			'parent_id'   => $parent,
			'aliases'     => wp_json_encode( array_map( 'sanitize_text_field', $aliases ) ),
			'status'      => $status,
		);
		if ( $id ) {
			if ( ! $this->db->one( 'topics', array( 'id' => $id ) ) ) {
				throw new Denied();
			}
			$this->db->update( 'topics', $row, array( 'id' => $id ) );
		} else {
			$id = $this->db->insert( 'topics', $row + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		$this->policy->clear();
		return $id;
	}
	public function assign( int $actor, string $type, int $id, array $topics ): void {
		$entity = $this->policy->requireView( $actor, $type, $id );
		if ( $entity['author'] !== $actor && ! bp_current_user_can( 'bpi_manage_topics' ) ) {
			throw new Denied();
		}
		if ( count( $topics ) > 20 ) {
			throw new \InvalidArgumentException( 'Too many topics.' );
		}
		foreach ( $topics as $topic ) {
			if ( ! is_int( $topic ) || ! $this->policy->view( $actor, 'topic', $topic ) ) {
				throw new \InvalidArgumentException( 'Invalid topic.' );
			}
		}
		$this->db->transaction(
			function () use ( $type, $id, $topics ): void {
				$this->db->delete(
					'object_topics',
					array(
						'object_type' => $type,
						'object_id'   => $id,
					)
				);
				foreach ( array_unique( $topics ) as $topic ) {
					$key = array(
						'object_type' => $type,
						'object_id'   => $id,
						'topic_id'    => $topic,
					);
						$this->db->unique(
							'object_topics',
							$key + array(
								'strength'   => 1,
								'created_at' => gmdate( 'Y-m-d H:i:s' ),
							),
							$key
						);
				}
			}
		);
	}
	public function preferences( int $actor, array $input ): array {
		$allowed = array( 'interests', 'skills', 'help_topics', 'goals', 'groups', 'visibility', 'visibility_groups', 'visibility_member_types', 'analytics', 'external_ai', 'onboarded' );
		if ( ! $actor || array_diff( array_keys( $input ), $allowed ) ) {
			throw new \InvalidArgumentException( 'Unknown preference.' );
		}
		foreach ( $input as $key => $value ) {
			if ( in_array( $key, array( 'interests', 'skills', 'help_topics', 'groups', 'visibility_groups' ), true ) ) {
				if ( ! is_array( $value ) || count( $value ) > 20 ) {
					throw new \InvalidArgumentException( 'Select at most twenty items.' );
				}
				$type = in_array( $key, array( 'groups', 'visibility_groups' ), true ) ? 'group' : 'topic';
				foreach ( $value as $id ) {
					if ( ! is_int( $id ) || ! $this->policy->view( $actor, $type, $id ) || ( 'visibility_groups' === $key && ! groups_is_user_member( $actor, $id ) ) ) {
						throw new Denied();
					}
				}
			} elseif ( 'visibility' === $key ) {
				if ( ! in_array( $value, array( 'everyone', 'members', 'friends', 'followers', 'following', 'groups', 'member_types', 'only_me' ), true ) ) {
					throw new \InvalidArgumentException( 'Invalid visibility.' );
				}
			} elseif ( 'goals' === $key ) {
				if ( ! is_string( $value ) || mb_strlen( $value ) > 500 ) {
					throw new \InvalidArgumentException( 'Invalid goals.' );
				}
				$input[ $key ] = sanitize_textarea_field( $value );
			} elseif ( 'visibility_member_types' === $key ) {
				if ( ! is_array( $value ) || count( $value ) > 20 || array_diff( $value, bp_get_member_types() ) ) {
					throw new \InvalidArgumentException( 'Invalid member types.' );
				}
			} elseif ( ! is_bool( $value ) ) {
				throw new \InvalidArgumentException( 'Expected boolean.' );
			}
		}
		$existing = get_user_meta( $actor, '_bpi_preferences', true );
		$result   = array_replace( is_array( $existing ) ? $existing : array(), $input );
		update_user_meta( $actor, '_bpi_preferences', $result );
		if ( isset( $input['interests'] ) ) {
			$this->assign( $actor, 'member', $actor, $input['interests'] );
		}
		$this->policy->clear();
		if ( ! empty( $input['onboarded'] ) && empty( $existing['onboarded'] ) ) {
			$this->events->record( 'onboarding_completed', $actor, 'member', $actor );
		}
		return $result;
	}
	public function detail( int $actor, int $id ): array {
		$this->policy->requireView( $actor, 'topic', $id );
		$topic            = $this->db->one( 'topics', array( 'id' => $id ) );
		$topic['aliases'] = json_decode( $topic['aliases'], true ) ?: array();
		$rows             = $this->db->rows( 'object_topics', array( 'topic_id' => $id ), 100 );
		$related          = array();
		$clauses          = array();
		$parameters       = array();
		$recent           = 0;
		$older            = 0;
		foreach ( $rows as $row ) {
			if ( ! $this->policy->view( $actor, $row['object_type'], (int) $row['object_id'] ) ) {
				continue;
			}
			$clauses[]    = '(object_type=%s AND object_id=%d)';
			$parameters[] = $row['object_type'];
			$parameters[] = (int) $row['object_id'];
			$age          = time() - strtotime( $row['created_at'] . ' UTC' );
			if ( $age <= 7 * DAY_IN_SECONDS ) {
				++$recent;
			} elseif ( $age <= 14 * DAY_IN_SECONDS ) {
				++$older;
			}
		}
		if ( $clauses ) {
			foreach ( $this->db->select( 'SELECT topic_id FROM ' . $this->db->table( 'object_topics' ) . ' WHERE ' . implode( ' OR ', $clauses ) . ' LIMIT 2000', $parameters ) as $mapping ) {
				$other = (int) $mapping['topic_id'];
				if ( $id !== $other && $this->policy->view( $actor, 'topic', $other ) ) {
					$related[ $other ] = ( $related[ $other ] ?? 0 ) + 1;
				}
			}
		}
		arsort( $related );
		$topic['related']                 = array();
		$topic['visible_sample_size']     = count( $clauses );
		$topic['recent_visible_mappings'] = $recent;
		$topic['trend_velocity']          = $recent - $older;
		$topic['statistics_definition']   = __( 'Viewer-visible sample of the latest 100 topic mappings. Velocity is mappings in the last seven days minus the previous seven days; it is not a global population count.', 'buddypress-intelligence' );
		foreach ( array_slice( $related, 0, 10, true ) as $other => $count ) {
			$entity             = $this->policy->object( 'topic', $other );
			$topic['related'][] = array(
				'id'   => $other,
				'name' => $entity['title'],
			);
		}
		return $topic;
	}
}
