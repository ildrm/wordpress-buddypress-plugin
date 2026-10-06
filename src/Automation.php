<?php
namespace BuddyPressIntelligence;

final class Automation {
	private Database $db;
	private Settings $settings;
	public function __construct( Database $db, Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}
	public function save( array $input, int $id = 0 ): int {
		if ( ! bp_current_user_can( 'bpi_manage_automations' ) ) {
			throw new Denied();
		}
		if ( array_diff( array_keys( $input ), array( 'name', 'trigger', 'conditions', 'action', 'config', 'enabled', 'dry_run' ) ) || ! is_string( $input['name'] ?? null ) || ! trim( $input['name'] ) || mb_strlen( $input['name'] ) > 120 || ! in_array( $input['trigger'] ?? '', Events::TYPES, true ) || ! in_array( $input['action'] ?? '', array( 'state', 'feature', 'review', 'notify' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid automation.' );
		}
		$conditions = $input['conditions'] ?? array();
		$config     = $input['config'] ?? array();
		if ( ! is_array( $conditions ) || array_diff( array_keys( $conditions ), array( 'group_id', 'topic_id', 'member_type', 'minimum_reputation' ) ) || ! is_array( $config ) || array_diff( array_keys( $config ), array( 'state', 'value' ) ) || ! is_bool( $input['enabled'] ?? false ) || ! is_bool( $input['dry_run'] ?? true ) ) {
			throw new \InvalidArgumentException( 'Invalid automation configuration.' );
		}
		foreach ( $conditions as $key => $value ) {
			if ( 'member_type' === $key ? ! is_string( $value ) || ! in_array( $value, bp_get_member_types(), true ) : ! is_int( $value ) || $value < 0 || $value > 100000000 ) {
				throw new \InvalidArgumentException( 'Invalid condition.' );
			}
		}
		if ( 'state' === $input['action'] && ( ! is_string( $config['state'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $config['state'] ) || ! is_string( $config['value'] ?? null ) || mb_strlen( $config['value'] ) > 100 ) ) {
			throw new \InvalidArgumentException( 'Invalid internal state.' );
		}
		$row = array(
			'name'         => sanitize_text_field( $input['name'] ),
			'trigger_type' => $input['trigger'],
			'conditions'   => wp_json_encode( $conditions ),
			'action'       => $input['action'],
			'config'       => wp_json_encode( $config ),
			'enabled'      => (int) ( $input['enabled'] ?? false ),
			'dry_run'      => (int) ( $input['dry_run'] ?? true ),
		);
		if ( $id ) {
			if ( ! $this->db->one( 'rules', array( 'id' => $id ) ) ) {
				throw new Denied();
			}
			$this->db->update( 'rules', $row, array( 'id' => $id ) );
		} else {
			$id = $this->db->transaction(
				function () use ( $row ): int {
					$this->db->unique(
						'limits',
						array(
							'bucket'     => 'automation:configuration',
							'hits'       => 0,
							'expires'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
							'created_at' => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'bucket' => 'automation:configuration' )
					);
					$this->db->select( 'SELECT id FROM ' . $this->db->table( 'limits' ) . ' WHERE bucket=%s FOR UPDATE', array( 'automation:configuration' ) );
					$count = $this->db->select( 'SELECT COUNT(*) AS total FROM ' . $this->db->table( 'rules' ) . ' WHERE id>%d', array( 0 ) )[0]['total'];
					if ( (int) $count >= 100 ) {
						throw new \InvalidArgumentException( 'The community supports at most 100 automation rules.' );
					}
					return $this->db->insert( 'rules', $row + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				}
			);
		}
		return $id;
	}
	public function process( array $payload ): void {
		$event = $this->db->one( 'events', array( 'id' => (int) ( $payload['event_id'] ?? 0 ) ) );
		if ( ! $event || ! $this->settings->enabled( 'automations' ) || 'automation' === $event['source'] ) {
			return;
		}
		$rules = $this->db->rows(
			'rules',
			array(
				'enabled'      => 1,
				'trigger_type' => $event['type'],
			),
			100
		);
		foreach ( $rules as $rule ) {
			$key = array(
				'rule_id'  => $rule['id'],
				'event_id' => $event['id'],
			);
			$run = $this->db->unique(
				'runs',
				$key + array(
					'status'     => 'pending',
					'attempts'   => 0,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$key
			);
			try {
				$this->db->transaction(
					function () use ( $run, $rule, $event ): void {
						$current = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'runs' ) . ' WHERE id=%d FOR UPDATE', array( $run ) )[0];
						if ( in_array( $current['status'], array( 'done', 'dry_run', 'skipped' ), true ) || (int) $current['attempts'] >= 5 ) {
								return;
						}
						$matches = $this->matches( $event, json_decode( $rule['conditions'], true ) ?: array() );
						$status  = ! $matches ? 'skipped' : ( $rule['dry_run'] ? 'dry_run' : 'done' );
						if ( $matches && ! $rule['dry_run'] ) {
							$this->execute( $rule, $event );
						}
						$this->db->update(
							'runs',
							array(
								'status'     => $status,
								'attempts'   => (int) $current['attempts'] + 1,
								'error'      => '',
								'updated_at' => gmdate( 'Y-m-d H:i:s' ),
							),
							array( 'id' => $run )
						);
					}
				);
			} catch ( \Throwable $e ) {
				$this->db->query( 'UPDATE ' . $this->db->table( 'runs' ) . " SET attempts=attempts+1,status='failed',error='action_failed',updated_at=%s WHERE id=%d", array( gmdate( 'Y-m-d H:i:s' ), $run ) );
				throw new \RuntimeException( 'Automation action failed.' );
			}
		}
		$this->db->update( 'events', array( 'processed' => 1 ), array( 'id' => $event['id'] ) );
	}
	private function matches( array $event, array $conditions ): bool {
		$metadata = json_decode( $event['metadata'], true ) ?: array();
		foreach ( $conditions as $field => $value ) {
			if ( in_array( $field, array( 'group_id', 'topic_id' ), true ) && (int) ( $metadata[ $field ] ?? 0 ) !== $value ) {
				return false;
			}
			if ( 'member_type' === $field && ! in_array( $value, (array) bp_get_member_type( (int) $event['actor'], false ), true ) ) {
				return false;
			}
			if ( 'minimum_reputation' === $field ) {
				$score = $this->db->select( 'SELECT COALESCE(SUM(points),0) AS score FROM ' . $this->db->table( 'ledger' ) . ' WHERE actor=%d', array( $event['actor'] ) )[0]['score'];
				if ( (float) $score < $value ) {
					return false;
				}
			}
		}
		return true;
	}
	private function execute( array $rule, array $event ): void {
		$actor  = (int) $event['actor'];
		$config = json_decode( $rule['config'], true ) ?: array();
		if ( ! $actor || ! get_userdata( $actor ) ) {
			return;
		}
		switch ( $rule['action'] ) {
			case 'state':
				$states                     = get_user_meta( $actor, '_bpi_states', true );
				$states                     = is_array( $states ) ? $states : array();
				$states[ $config['state'] ] = sanitize_text_field( $config['value'] );
				update_user_meta( $actor, '_bpi_states', array_slice( $states, -50, null, true ) );
				break;
			case 'feature':
				if ( 'activity' === $event['object_type'] && bp_is_active( 'activity' ) ) {
					bp_activity_update_meta( (int) $event['object_id'], '_bpi_featured', true );
				}
				break;
			case 'review':
				if ( ! $this->settings->enabled( 'moderation' ) ) {
					return;
				}
				$key = array(
					'object_type' => $event['object_type'],
					'object_id'   => $event['object_id'],
				);
				$this->db->unique(
					'cases',
					$key + array(
						'subject'    => $actor,
						'status'     => 'open',
						'assigned'   => 0,
						'priority'   => 2,
						'severity'   => 2,
						'evidence'   => '{}',
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					$key
				);
				break;
			case 'notify':
				if ( bp_is_active( 'notifications' ) ) {
					if ( ! bp_notifications_add_notification(
						array(
							'user_id'           => $actor,
							'item_id'           => (int) $rule['id'],
							'secondary_item_id' => (int) $event['id'],
							'component_name'    => 'bpi',
							'component_action'  => 'community_update',
							'is_new'            => 1,
						)
					) ) {
						throw new \RuntimeException( 'Notification failed.' );
					}
				} else {
					update_user_meta( $actor, '_bpi_notice', __( 'Your community preferences have been updated.', 'buddypress-intelligence' ) );
				}
				break;
		}
	}
}
