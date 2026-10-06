<?php
namespace BuddyPressIntelligence;

/** Most restrictive rule wins; capabilities never bypass native group visibility. */
final class Policy {
	private Database $db;
	private NativeObjects $native;
	private array $edge_cache  = array();
	private array $relations   = array();
	private array $memberships = array();
	public function __construct( Database $db, NativeObjects $native ) {
		$this->db     = $db;
		$this->native = $native;
	}
	public function clear(): void {
		$this->edge_cache  = array();
		$this->relations   = array();
		$this->memberships = array();
		$this->native->clear();
	}
	public function object( string $type, int $id ): ?array {
		return $this->native->get( $type, $id );
	}
	public function edges( int $actor ): array {
		if ( ! isset( $this->edge_cache[ $actor ] ) ) {
			$this->edge_cache[ $actor ] = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'edges' ) . ' WHERE actor=%d AND (expires IS NULL OR expires>%s) LIMIT 5000', array( $actor, gmdate( 'Y-m-d H:i:s' ) ) );
		}
		return $this->edge_cache[ $actor ];
	}
	public function relation( int $actor, string $kind, string $type, int $id ): bool {
		if ( ! $actor || ! $id ) {
			return false;
		}
		$key = "$actor:$kind:$type:$id";
		if ( array_key_exists( $key, $this->relations ) ) {
			return $this->relations[ $key ];
		}
		// Direct indexed lookup avoids silently dropping privacy edges after a cap.
		$row                     = $this->db->one(
			'edges',
			array(
				'actor'       => $actor,
				'kind'        => $kind,
				'object_type' => $type,
				'object_id'   => $id,
			)
		);
		$this->relations[ $key ] = $row && ( ! $row['expires'] || strtotime( $row['expires'] . ' UTC' ) > time() );
		return $this->relations[ $key ];
	}
	/** Batch exactly the candidate privacy edges, including incoming blocks without a row cap. */
	public function prime( int $viewer, array $objects ): void {
		if ( ! $viewer || ! $objects ) {
			return;
		}
		$this->native->primeFriends( $viewer, $objects );
		$targets = array();
		$authors = array();
		foreach ( $objects as $entity ) {
			$targets[ $entity['type'] . ':' . $entity['id'] ] = array( $entity['type'], $entity['id'] );
			if ( $entity['author'] ) {
				$authors[ $entity['author'] ]             = (int) $entity['author'];
				$targets[ 'member:' . $entity['author'] ] = array( 'member', $entity['author'] );
			}
			if ( $entity['group_id'] ) {
				$targets[ 'group:' . $entity['group_id'] ] = array( 'group', $entity['group_id'] );
			}
		}
		$clauses = array();
		$args    = array( $viewer );
		foreach ( $targets as list( $type, $id ) ) {
			$clauses[] = '(object_type=%s AND object_id=%d)';
			$args[]    = $type;
			$args[]    = $id;
			foreach ( array( 'block', 'follow', 'mute', 'snooze' ) as $kind ) {
				$this->relations[ "$viewer:$kind:$type:$id" ] = false;
			}
		}
		$sql = 'SELECT * FROM ' . $this->db->table( 'edges' ) . ' WHERE (actor=%d AND (' . implode( ' OR ', $clauses ) . '))';
		if ( $authors ) {
			$sql .= ' OR (actor IN (' . implode( ',', array_fill( 0, count( $authors ), '%d' ) ) . ") AND object_type='member' AND object_id=%d AND kind IN ('block','follow'))";
			$args = array_merge( $args, array_values( $authors ), array( $viewer ) );
			foreach ( $authors as $author ) {
				$this->relations[ "$author:block:member:$viewer" ]  = false;
				$this->relations[ "$author:follow:member:$viewer" ] = false;
			}
		}
		foreach ( $this->db->select( $sql, $args ) as $row ) {
			$key                     = $row['actor'] . ':' . $row['kind'] . ':' . $row['object_type'] . ':' . $row['object_id'];
			$this->relations[ $key ] = ! $row['expires'] || strtotime( $row['expires'] . ' UTC' ) > time();
		}
	}
	public function blocked( int $a, int $b ): bool {
		return $a > 0 && $b > 0 && ( $this->relation( $a, 'block', 'member', $b ) || $this->relation( $b, 'block', 'member', $a ) );
	}
	public function group( int $viewer, int $id, bool $listing = false ): bool {
		$group = $this->native->get( 'group', $id );
		if ( ! $group || $group['hidden'] || ! $this->native->active( 'groups' ) ) {
			return false;
		}
		if ( $viewer && groups_is_user_banned( $viewer, $id ) ) {
			return false;
		}
		if ( 'public' === $group['group_status'] || ( $listing && 'private' === $group['group_status'] ) ) {
			return true;
		}
		$key = "$viewer:$id";
		if ( ! array_key_exists( $key, $this->memberships ) ) {
			$this->memberships[ $key ] = $viewer > 0 && ( groups_is_user_member( $viewer, $id ) || bp_user_can( $viewer, 'bp_moderate' ) );
		}
		return $this->memberships[ $key ];
	}
	public function view( int $viewer, string $type, int $id, bool $recommend = false ): bool {
		$entity = $this->native->get( $type, $id );
		if ( ! $entity || $entity['hidden'] || $this->blocked( $viewer, $entity['author'] ) ) {
			return false;
		}
		if ( 'group' === $type && ! $this->group( $viewer, $id, true ) ) {
			return false;
		}
		if ( 'group' !== $type && $entity['group_id'] && ! $this->group( $viewer, $entity['group_id'] ) ) {
			return false;
		}
		if ( ! empty( $entity['comment_parent'] ) && ! $this->view( $viewer, 'activity', $entity['comment_parent'] ) ) {
			return false;
		}
		if ( in_array( $type, array( 'answer', 'knowledge' ), true ) && ! empty( $entity['parent_id'] ) && ! $this->view( $viewer, 'question', $entity['parent_id'] ) ) {
			return false;
		}
		$author = (int) $entity['author'];
		if ( $author ) {
			$user = get_userdata( $author );
			if ( ! $user || $user->spam || $user->deleted || $user->user_status || get_user_meta( $author, '_bpi_hidden', true ) ) {
				return false;
			}
			$prefs = get_user_meta( $author, '_bpi_preferences', true );
			$scope = is_array( $prefs ) ? ( $prefs['visibility'] ?? 'everyone' ) : 'everyone';
			if ( $viewer !== $author ) {
				$allowed = match ( $scope ) {
					'everyone' => true,
					'members' => $viewer > 0,
					'friends' => $this->native->friend( $viewer, $author ),
					'followers' => $this->relation( $viewer, 'follow', 'member', $author ),
					'following' => $this->relation( $author, 'follow', 'member', $viewer ),
					'groups' => $this->inSelectedGroups( $viewer, $prefs['visibility_groups'] ?? array() ),
					'member_types' => $viewer > 0 && (bool) array_intersect( (array) bp_get_member_type( $viewer, false ), $prefs['visibility_member_types'] ?? array() ),
					default => false,
				};
				if ( ! $allowed ) {
					return false;
				}
			}
		}
		if ( $recommend ) {
			if ( $this->relation( $viewer, 'mute', $type, $id ) || $this->relation( $viewer, 'snooze', $type, $id ) || ( $author && $this->relation( $viewer, 'mute', 'member', $author ) ) || ( $author && $this->relation( $viewer, 'snooze', 'member', $author ) ) || ( $entity['group_id'] && ( $this->relation( $viewer, 'mute', 'group', $entity['group_id'] ) || $this->relation( $viewer, 'snooze', 'group', $entity['group_id'] ) ) ) ) {
				return false;
			}
		}
		// Extensions may tighten access only. A false core decision never reaches this hook.
		return (bool) apply_filters( 'bpi_policy_allow', true, $viewer, $entity, $recommend );
	}
	private function inSelectedGroups( int $viewer, array $ids ): bool {
		if ( ! $viewer || ! $this->native->active( 'groups' ) ) {
			return false;
		}
		foreach ( array_slice( $ids, 0, 20 ) as $id ) {
			if ( $this->group( $viewer, (int) $id ) && groups_is_user_member( $viewer, (int) $id ) ) {
				return true;
			}
		}
		return false;
	}
	public function interact( int $viewer, string $type, int $id ): bool {
		$restricted = (int) get_user_meta( $viewer, '_bpi_restricted_until', true );
		return $viewer > 0 && $restricted < time() && $this->view( $viewer, $type, $id );
	}
	public function requireView( int $viewer, string $type, int $id ): array {
		if ( ! $this->view( $viewer, $type, $id ) ) {
			throw new Denied();
		}
		return $this->native->get( $type, $id );
	}
}
