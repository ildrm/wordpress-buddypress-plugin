<?php
namespace BuddyPressIntelligence;

/** Verified public upstream APIs; raw native properties stay behind this adapter. */
final class NativeObjects implements SearchProvider {
	private Database $db;
	private Settings $settings;
	private array $friendships  = array();
	private array $objects      = array();
	private static int $reading = 0;
	public static function isReading(): bool {
		return self::$reading > 0;
	}
	public function __construct( Database $db, Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}
	public function active( string $component ): bool {
		return function_exists( 'bp_is_active' ) && bp_is_active( $component );
	}
	public function clear(): void {
		$this->objects     = array();
		$this->friendships = array();
	}
	/** Read-only bounded adapter to the verified BP 14 friendship schema. */
	public function primeFriends( int $viewer, array $objects ): void {
		if ( ! $viewer || ! $this->active( 'friends' ) ) {
			return;
		}
		$ids = array_values( array_unique( array_filter( array_map( static fn( $entity ) => (int) $entity['author'], $objects ) ) ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			$this->friendships[ "$viewer:$id" ] = false;
		}
		$table        = buddypress()->friends->table_name;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT friend_user_id AS member_id FROM $table WHERE initiator_user_id=%d AND is_confirmed=1 AND friend_user_id IN ($placeholders) UNION SELECT initiator_user_id AS member_id FROM $table WHERE friend_user_id=%d AND is_confirmed=1 AND initiator_user_id IN ($placeholders)";
		foreach ( $this->db->select( $sql, array_merge( array( $viewer ), $ids, array( $viewer ), $ids ) ) as $row ) {
			$this->friendships[ $viewer . ':' . $row['member_id'] ] = true;
		}
	}
	public function friend( int $viewer, int $member ): bool {
		if ( ! $viewer || ! $member || ! $this->active( 'friends' ) ) {
			return false;
		}
		$key = "$viewer:$member";
		if ( ! array_key_exists( $key, $this->friendships ) ) {
			$this->friendships[ $key ] = (bool) friends_check_friendship( $viewer, $member );
		}
		return $this->friendships[ $key ];
	}
	public function primeAuthors( array $objects ): void {
		$ids = array_values( array_unique( array_filter( array_map( static fn( $entity ) => (int) $entity['author'], $objects ) ) ) );
		if ( $ids ) {
			get_users(
				array(
					'include'     => $ids,
					'number'      => count( $ids ),
					'fields'      => 'all',
					'count_total' => false,
				)
			);
			update_meta_cache( 'user', $ids );
		}
	}
	public function primeEntries( array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', array_slice( $ids, 0, 500 ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$rows    = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'entries' ) . ' WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids );
		$parents = array();
		foreach ( $rows as $row ) {
			$this->objects[ $row['kind'] . ':' . $row['id'] ] = $this->entry( $row );
			if ( $row['parent_id'] ) {
				$parents[] = (int) $row['parent_id'];
			}
		}
		if ( $parents ) {
			$parents = array_values( array_unique( $parents ) );
			foreach ( $this->db->select( 'SELECT * FROM ' . $this->db->table( 'entries' ) . ' WHERE kind=%s AND id IN (' . implode( ',', array_fill( 0, count( $parents ), '%d' ) ) . ')', array_merge( array( 'question' ), $parents ) ) as $row ) {
				$this->objects[ 'question:' . $row['id'] ] = $this->entry( $row );
			}
		}
	}
	private function entry( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'type'        => $row['kind'],
			'author'      => (int) $row['author'],
			'group_id'    => (int) $row['group_id'],
			'title'       => $row['title'],
			'body'        => $row['body'],
			'date'        => $row['created_at'],
			'url'         => '',
			'hidden'      => 'published' !== $row['status'],
			'parent_id'   => (int) $row['parent_id'],
			'accepted_id' => (int) $row['accepted_id'],
		);
	}
	public function get( string $type, int $id ): ?array {
		++self::$reading;
		try {
			return $this->fetch( $type, $id );
		} finally {
			--self::$reading;
		}
	}
	private function fetch( string $type, int $id ): ?array {
		$key = "$type:$id";
		if ( array_key_exists( $key, $this->objects ) ) {
			return $this->objects[ $key ];
		}
		if ( $id < 1 ) {
			return null;
		}
		$entity = null;
		if ( 'member' === $type ) {
			$user = get_userdata( $id );
			if ( $user && ! $user->spam && ! $user->deleted && ! $user->user_status ) {
				$entity = array(
					'id'       => $id,
					'type'     => $type,
					'author'   => $id,
					'group_id' => 0,
					'title'    => $user->display_name,
					'body'     => '',
					'date'     => $user->user_registered,
					'url'      => bp_members_get_user_url( $id ),
					'hidden'   => (bool) get_user_meta( $id, '_bpi_hidden', true ),
				);
			}
		} elseif ( 'group' === $type && $this->active( 'groups' ) ) {
			$group = groups_get_group( $id );
			if ( ! empty( $group->id ) ) {
				$entity = array(
					'id'           => $id,
					'type'         => $type,
					'author'       => 0,
					'group_id'     => $id,
					'title'        => $group->name,
					'body'         => $group->description,
					'date'         => $group->date_created,
					'url'          => bp_get_group_url( $group ),
					'group_status' => $group->status,
					'hidden'       => (bool) groups_get_groupmeta( $id, '_bpi_hidden', true ),
				);
			}
		} elseif ( 'activity' === $type && $this->active( 'activity' ) ) {
			$data = bp_activity_get_specific(
				array(
					'activity_ids'     => array( $id ),
					'spam'             => 'all',
					'display_comments' => false,
				)
			);
			if ( ! empty( $data['activities'][0] ) ) {
				$entity = $this->activity( $data['activities'][0] );
			}
		} elseif ( in_array( $type, array( 'question', 'answer', 'knowledge' ), true ) ) {
			$row = $this->db->one(
				'entries',
				array(
					'id'   => $id,
					'kind' => $type,
				)
			);
			if ( $row ) {
				$entity = $this->entry( $row );
			}
		} elseif ( 'topic' === $type ) {
			$row = $this->db->one( 'topics', array( 'id' => $id ) );
			if ( $row ) {
				$entity = array(
					'id'       => $id,
					'type'     => $type,
					'author'   => 0,
					'group_id' => 0,
					'title'    => $row['name'],
					'body'     => $row['description'],
					'date'     => $row['created_at'],
					'url'      => '',
					'hidden'   => 'active' !== $row['status'],
				);
			}
		} elseif ( 'post' === $type && get_current_blog_id() === Core::root() ) {
			$post = get_post( $id );
			if ( $post && 'publish' === $post->post_status && ! $post->post_password && in_array( $post->post_type, $this->settings->all()['post_types'], true ) && is_post_type_viewable( $post->post_type ) ) {
				$entity = array(
					'id'       => $id,
					'type'     => $type,
					'author'   => (int) $post->post_author,
					'group_id' => 0,
					'title'    => $post->post_title,
					'body'     => $post->post_content,
					'date'     => $post->post_date_gmt,
					'url'      => get_permalink( $post ),
					'hidden'   => (bool) get_post_meta( $id, '_bpi_hidden', true ),
				);
			}
		}
		$this->objects[ $key ] = $entity;
		return $entity;
	}
	private function activity( $activity ): array {
		$group = 'groups' === $activity->component ? (int) $activity->item_id : 0;
		return array(
			'id'             => (int) $activity->id,
			'type'           => 'activity',
			'author'         => (int) $activity->user_id,
			'group_id'       => $group,
			'title'          => wp_strip_all_tags( $activity->action ),
			'body'           => $activity->content,
			'date'           => $activity->date_recorded,
			'url'            => bp_activity_get_permalink( $activity->id, $activity ),
			'hidden'         => (bool) $activity->is_spam || ( ! $group && (bool) $activity->hide_sitewide ) || (bool) bp_activity_get_meta( $activity->id, '_bpi_hidden', true ),
			'native_hidden'  => (bool) $activity->hide_sitewide,
			'comment_parent' => 'activity_comment' === $activity->type ? (int) $activity->item_id : 0,
		);
	}
	public function followedActivities( int $viewer, int $limit, int $page ): array {
		if ( ! $viewer || ! $this->active( 'activity' ) ) {
			return array();
		}
		$edges = $this->db->rows(
			'edges',
			array(
				'actor'       => $viewer,
				'kind'        => 'follow',
				'object_type' => 'member',
			),
			100
		);
		if ( ! $edges ) {
			return array();
		}
		++self::$reading;
		try {
			$data    = bp_activity_get(
				array(
					'per_page'         => min( 300, $limit ),
					'page'             => $page,
					'filter'           => array( 'user_id' => implode( ',', array_column( $edges, 'object_id' ) ) ),
					'show_hidden'      => true,
					'spam'             => 'ham_only',
					'display_comments' => false,
					'count_total'      => false,
				)
			);
			$objects = array();
			foreach ( $data['activities'] as $activity ) {
				$entity                                       = $this->activity( $activity );
				$this->objects[ 'activity:' . $activity->id ] = $entity;
				$objects[]                                    = $entity;
			}
			$this->primeAuthors( $objects );
			return $objects;
		} finally {
			--self::$reading;
		}
	}
	public function candidates( string $type, int $limit, int $page = 1, string $query = '' ): array {
		++self::$reading;
		try {
			return $this->collect( $type, $limit, $page, $query );
		} finally {
			--self::$reading;
		}
	}
	private function collect( string $type, int $limit, int $page, string $query ): array {
		$limit = min( 300, max( 1, $limit ) );
		$ids   = array();
		if ( in_array( $type, array( 'member', 'expert' ), true ) ) {
			$data = bp_core_get_users(
				array(
					'type'            => 'newest',
					'per_page'        => $limit,
					'page'            => $page,
					'search_terms'    => $query,
					'populate_extras' => false,
					'count_total'     => false,
				)
			);
			$ids  = array_map( static fn( $u ) => (int) $u->ID, $data['users'] );
			update_meta_cache( 'user', $ids );
			$type = 'member';
		} elseif ( 'group' === $type && $this->active( 'groups' ) ) {
			$data = groups_get_groups(
				array(
					'type'         => 'active',
					'per_page'     => $limit,
					'page'         => $page,
					'search_terms' => $query,
					'show_hidden'  => true,
				)
			);
			$ids  = array_map( static fn( $g ) => (int) $g->id, $data['groups'] );
		} elseif ( 'activity' === $type && $this->active( 'activity' ) ) {
			$data = bp_activity_get(
				array(
					'per_page'         => $limit,
					'page'             => $page,
					'search_terms'     => $query,
					'show_hidden'      => true,
					'spam'             => 'ham_only',
					'display_comments' => false,
					'count_total'      => false,
				)
			);
			foreach ( $data['activities'] as $activity ) {
				$this->objects[ 'activity:' . $activity->id ] = $this->activity( $activity );
				$ids[]                                        = (int) $activity->id;
			}
		} elseif ( 'topic' === $type ) {
			$rows = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'topics' ) . ' WHERE status=%s AND (name LIKE %s OR aliases LIKE %s) ORDER BY id ASC LIMIT %d OFFSET %d', array( 'active', '%' . $this->like( $query ) . '%', '%' . $this->like( $query ) . '%', $limit, ( $page - 1 ) * $limit ) );
			$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		} elseif ( in_array( $type, array( 'question', 'answer', 'knowledge' ), true ) ) {
			$rows = $this->db->select( 'SELECT id FROM ' . $this->db->table( 'entries' ) . ' WHERE kind=%s AND status=%s AND (title LIKE %s OR body LIKE %s) ORDER BY id DESC LIMIT %d OFFSET %d', array( $type, 'published', '%' . $this->like( $query ) . '%', '%' . $this->like( $query ) . '%', $limit, ( $page - 1 ) * $limit ) );
			$ids  = array_map( 'intval', array_column( $rows, 'id' ) );
		} elseif ( 'post' === $type && get_current_blog_id() === Core::root() ) {
			$posts = new \WP_Query(
				array(
					'post_type'           => $this->settings->all()['post_types'],
					'post_status'         => 'publish',
					'has_password'        => false,
					's'                   => $query,
					'posts_per_page'      => $limit,
					'paged'               => $page,
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'orderby'             => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
				)
			);
			$ids   = array_map( static fn( $p ) => (int) $p->ID, $posts->posts );
		}
		$result = array();
		foreach ( $ids as $id ) {
			$entity = $this->get( $type, $id );
			if ( $entity ) {
				$result[] = $entity;
			}
		}
		$this->primeAuthors( $result );
		return $result;
	}
	private function like( string $value ): string {
		global $wpdb;
		return $wpdb->esc_like( $value );
	}
	public function hide( string $type, int $id, bool $hidden ): void {
		if ( 'activity' === $type && $this->active( 'activity' ) ) {
			bp_activity_update_meta( $id, '_bpi_hidden', (int) $hidden );
		} elseif ( 'group' === $type && $this->active( 'groups' ) ) {
			groups_update_groupmeta( $id, '_bpi_hidden', (int) $hidden );
		} elseif ( 'member' === $type ) {
			update_user_meta( $id, '_bpi_hidden', (int) $hidden );
		} elseif ( 'post' === $type ) {
			update_post_meta( $id, '_bpi_hidden', (int) $hidden );
		} elseif ( in_array( $type, array( 'question', 'answer', 'knowledge' ), true ) ) {
			$this->db->update(
				'entries',
				array(
					'status'     => $hidden ? 'hidden' : 'published',
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array(
					'id'   => $id,
					'kind' => $type,
				)
			);
		} else {
			throw new \InvalidArgumentException( 'Unsupported moderation object.' );
		}
		$this->clear();
	}
}
