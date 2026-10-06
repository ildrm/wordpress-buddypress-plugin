<?php
namespace BuddyPressIntelligence;

final class Privacy {
	private Database $db;
	public function __construct( Database $db ) {
		$this->db = $db;
	}
	public function register(): void {
		add_action( 'bpi_erase_user', array( $this, 'deleteUser' ) );
		add_filter(
			'wp_privacy_personal_data_exporters',
			function ( array $exporters ): array {
				$exporters['bpi'] = array(
					'exporter_friendly_name' => __( 'BuddyPress Intelligence', 'buddypress-intelligence' ),
					'callback'               => array( $this, 'export' ),
				);
				return $exporters;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			function ( array $erasers ): array {
				$erasers['bpi'] = array(
					'eraser_friendly_name' => __( 'BuddyPress Intelligence', 'buddypress-intelligence' ),
					'callback'             => array( $this, 'erase' ),
				);
				return $erasers;
			}
		);
		add_action( 'delete_user', array( $this, 'deleteUser' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( $this, 'deleteUser' ), 10, 1 );
		add_action(
			'admin_init',
			static function (): void {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'BuddyPress Intelligence', __( 'Community tools store follows, blocks, interests, feedback, questions, answers, reputation and moderation records. Behavioral analytics require site and member consent. Events are retained for the configured period (90 days by default); aggregate metrics for 365 days. Private messages are excluded. Optional AI requires site and member consent and sends only limited public editorial text to the configured provider. Community discussions are anonymized during erasure; moderation subjects and notes are anonymized. Export and erasure are available through WordPress privacy tools.', 'buddypress-intelligence' ) );
				}
			}
		);
	}
	private function datasets(): array {
		return array(
			'edges'         => 'actor',
			'object_topics' => 'object_id',
			'events'        => 'actor',
			'feedback'      => 'actor',
			'ledger'        => 'actor',
			'entries'       => 'author',
			'votes'         => 'actor',
			'reports'       => 'actor',
			'appeals'       => 'actor',
			'assignments'   => 'actor',
		);
	}
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$data = array();
		$done = true;
		foreach ( $this->datasets() as $table => $column ) {
			$where = array( $column => $user->ID );
			if ( 'object_topics' === $table ) {
				$where['object_type'] = 'member';
			}
			$rows = $this->db->rows( $table, $where, 50, ( max( 1, $page ) - 1 ) * 50, 'id ASC' );
			$done = $done && count( $rows ) < 50;
			foreach ( $rows as $row ) {
				// An appeal exports the decision, never internal case notes or other reports.
				$data[] = array(
					'group_id'    => 'bpi-' . $table,
					'group_label' => __( 'Community data', 'buddypress-intelligence' ),
					'item_id'     => $table . '-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Record', 'buddypress-intelligence' ),
							'value' => wp_json_encode( $row ),
						),
					),
				);
			}
		}
		if ( 1 === $page ) {
			foreach ( array( '_bpi_preferences', '_bpi_states', '_bpi_notice' ) as $meta ) {
				$value = get_user_meta( $user->ID, $meta, true );
				if ( $value ) {
					$data[] = array(
						'group_id'    => 'bpi-preferences',
						'group_label' => __( 'Community preferences', 'buddypress-intelligence' ),
						'item_id'     => $meta . '-' . $user->ID,
						'data'        => array(
							array(
								'name'  => __( 'Preferences', 'buddypress-intelligence' ),
								'value' => wp_json_encode( $value ),
							),
						),
					);
				}
			}
		}
		return array(
			'data' => $data,
			'done' => $done,
		);
	}
	public function erase( string $email, int $page = 1 ): array {
		if ( $page < 1 ) {
			throw new \InvalidArgumentException( 'Invalid erasure page.' );
		}
		$user = get_user_by( 'email', $email );
		return $user ? $this->eraseId( (int) $user->ID ) : array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
	private function eraseId( int $actor ): array {
		$removed = false;
		$done    = true;
		foreach ( $this->datasets() as $table => $column ) {
			$where = array( $column => $actor );
			if ( 'object_topics' === $table ) {
				$where['object_type'] = 'member';
			}
			$rows = $this->db->rows( $table, $where, 50 );
			$done = $done && count( $rows ) < 50;
			foreach ( $rows as $row ) {
				if ( 'entries' === $table ) {
					$this->db->update(
						$table,
						array(
							'author'     => 0,
							'title'      => __( 'Anonymized community contribution', 'buddypress-intelligence' ),
							'body'       => '',
							'updated_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'id' => $row['id'] )
					);
				} else {
					$this->db->delete( $table, array( 'id' => $row['id'] ) );
				}
				$removed = true;
			}
		}
		foreach ( array( 'edges', 'object_topics', 'feedback', 'events' ) as $table ) {
			$count = $this->db->query( 'DELETE FROM ' . $this->db->table( $table ) . ' WHERE object_type=%s AND object_id=%d LIMIT 100', array( 'member', $actor ) );
			$done  = $done && $count < 100;
		}
		$this->db->query( 'UPDATE ' . $this->db->table( 'cases' ) . ' SET subject=0,evidence=%s WHERE subject=%d', array( '{}', $actor ) );
		$this->db->query( 'UPDATE ' . $this->db->table( 'cases' ) . ' SET assigned=0 WHERE assigned=%d', array( $actor ) );
		$this->db->query( 'UPDATE ' . $this->db->table( 'audit' ) . ' SET actor=0,note=%s,previous_state=%s WHERE actor=%d', array( '', '{}', $actor ) );
		$this->db->query( 'UPDATE ' . $this->db->table( 'appeals' ) . ' SET reviewer=0 WHERE reviewer=%d', array( $actor ) );
		$this->db->query( 'UPDATE ' . $this->db->table( 'ledger' ) . ' SET peer_id=0 WHERE peer_id=%d', array( $actor ) );
		foreach ( array( '_bpi_preferences', '_bpi_states', '_bpi_notice', '_bpi_hidden', '_bpi_restricted_until' ) as $meta ) {
			delete_user_meta( $actor, $meta );
		}
		// Invalidate persistent-cache snapshots too, without flushing other plugins' caches.
		Core::option( 'bpi_cache_generation', wp_generate_uuid4(), true );
		// ID-only snapshots still recheck authorization; remove stored SQL snapshots.
		global $wpdb;
		$switched = get_current_blog_id() !== Core::root();
		if ( $switched ) {
			switch_to_blog( Core::root() );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_bpi_' ) . '%', $wpdb->esc_like( '_transient_timeout_bpi_' ) . '%' ) );
		if ( $switched ) {
			restore_current_blog();
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => true,
			'messages'       => array( __( 'Discussion structure and anonymized moderation case history are retained to preserve community integrity. Anonymous aggregate statistics contain no member identifiers.', 'buddypress-intelligence' ) ),
			'done'           => $done,
		);
	}
	public function deleteUser( int $actor ): void {
		// Normal deletion is uncommon; bounded erasure batches avoid loading whole histories.
		for ( $batch = 0; $batch < 100; ++$batch ) {
			if ( $this->eraseId( $actor )['done'] ) {
				return;
			}
		}
		// Remaining rows are swept asynchronously, rather than blocking deletion indefinitely.
		Core::schedule( 'bpi_erase_user', array( $actor ), 30 );
	}
}
