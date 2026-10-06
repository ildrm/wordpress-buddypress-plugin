<?php
namespace BuddyPressIntelligence;

final class Graph {
	private Database $db;
	private Policy $policy;
	private Events $events;
	public function __construct( Database $db, Policy $policy, Events $events ) {
		$this->db     = $db;
		$this->policy = $policy;
		$this->events = $events;
	}
	public function change( int $actor, string $kind, string $type, int $id, bool $remove = false, int $days = 0 ): array {
		if ( $actor < 1 || ! in_array( $kind, array( 'follow', 'mute', 'snooze', 'block' ), true ) || ! in_array( $type, array( 'member', 'group', 'topic', 'post' ), true ) || ( 'block' === $kind && 'member' !== $type ) || ( 'member' === $type && $actor === $id ) || ( 'snooze' === $kind && ! $remove && ( $days < 1 || $days > 90 ) ) ) {
			throw new \InvalidArgumentException( 'Invalid relationship.' );
		}
		$key = array(
			'actor'       => $actor,
			'kind'        => $kind,
			'object_type' => $type,
			'object_id'   => $id,
		);
		// Removal remains possible after native visibility or blocking changes.
		if ( $remove ) {
			$this->db->delete( 'edges', $key );
		} else {
			if ( ! $this->policy->interact( $actor, $type, $id ) ) {
				throw new Denied();
			}
			$count = $this->db->select( 'SELECT COUNT(*) AS total FROM ' . $this->db->table( 'edges' ) . ' WHERE actor=%d', array( $actor ) );
			if ( (int) $count[0]['total'] >= 5000 && ! $this->db->one( 'edges', $key ) ) {
				throw new \InvalidArgumentException( 'Relationship limit reached.' );
			}
			$expires = 'snooze' === $kind ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null;
			$this->db->unique(
				'edges',
				$key + array(
					'expires'    => $expires,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$key
			);
			$this->db->update( 'edges', array( 'expires' => $expires ), $key );
		}
		$this->policy->clear();
		$type_event = 'follow' === $kind ? ( 'topic' === $type ? 'topic_' : 'member_' ) . ( $remove ? 'unfollowed' : 'followed' ) : ( 'block' === $kind ? 'member_blocked' : 'member_muted' );
		$this->events->record( $type_event, $actor, $type, $id, array( 'value' => $remove ? 'removed' : $kind ) );
		return array(
			'kind'   => $kind,
			'type'   => $type,
			'id'     => $id,
			'active' => ! $remove,
		);
	}
	public function list( int $actor, int $page = 1 ): array {
		return $this->db->rows( 'edges', array( 'actor' => $actor ), 50, ( $page - 1 ) * 50 );
	}
}
