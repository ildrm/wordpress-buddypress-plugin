<?php
namespace BuddyPressIntelligence;

final class Moderation {
	private Database $db;
	private Policy $policy;
	private NativeObjects $native;
	private Events $events;
	private Settings $settings;
	public function __construct( Database $db, Policy $policy, NativeObjects $native, Events $events, Settings $settings ) {
		$this->db       = $db;
		$this->policy   = $policy;
		$this->native   = $native;
		$this->events   = $events;
		$this->settings = $settings;
	}
	public function report( int $actor, string $type, int $id, string $category, string $description ): int {
		if ( ! in_array( $category, $this->settings->all()['report_categories'], true ) || mb_strlen( $description ) > 2000 || ! $this->policy->interact( $actor, $type, $id ) ) {
			throw new Denied();
		}
		$entity = $this->policy->requireView( $actor, $type, $id );
		return $this->db->transaction(
			function () use ( $actor, $type, $id, $category, $description, $entity ): int {
				$key        = array(
					'object_type' => $type,
					'object_id'   => $id,
				);
				$case       = $this->db->unique(
					'cases',
					$key + array(
						'subject'    => $entity['author'],
						'status'     => 'open',
						'assigned'   => 0,
						'priority'   => 2,
						'severity'   => 2,
						'evidence'   => wp_json_encode(
							array(
								'title' => mb_substr( wp_strip_all_tags( $entity['title'] ), 0, 200 ),
								'text'  => mb_substr( wp_strip_all_tags( $entity['body'] ), 0, 2000 ),
							)
						),
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					$key
				);
				$report_key = array(
					'case_id' => $case,
					'actor'   => $actor,
				);
				$report     = $this->db->unique(
					'reports',
					$report_key + array(
						'category'    => $category,
						'description' => sanitize_textarea_field( $description ),
						'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					),
					$report_key
				);
				$this->events->record( 'report_created', $actor, $type, $id, array(), 'report:' . $report );
				return $case;
			}
		);
	}
	public function cases( int $page = 1, string $status = '' ): array {
		if ( ! bp_current_user_can( 'bpi_view_cases' ) ) {
			throw new Denied();
		}
		return $this->db->rows( 'cases', $status ? array( 'status' => $status ) : array(), 50, ( $page - 1 ) * 50 );
	}
	public function detail( int $id ): array {
		if ( ! bp_current_user_can( 'bpi_view_cases' ) ) {
			throw new Denied();
		}
		$case = $this->db->one( 'cases', array( 'id' => $id ) );
		if ( ! $case ) {
			throw new Denied();
		}
		$case['reports']         = $this->db->rows( 'reports', array( 'case_id' => $id ), 100 );
		$case['audit']           = $this->db->rows( 'audit', array( 'case_id' => $id ), 100 );
		$case['appeals']         = $this->db->rows( 'appeals', array( 'case_id' => $id ), 100 );
		$case['subject_history'] = $case['subject'] ? $this->db->rows( 'cases', array( 'subject' => $case['subject'] ), 20 ) : array();
		return $case;
	}
	public function act( int $actor, int $id, array $input ): array {
		if ( ! bp_current_user_can( 'bpi_moderate' ) || ! bp_current_user_can( 'bpi_view_cases' ) || array_diff( array_keys( $input ), array( 'action', 'note', 'status', 'assigned', 'priority', 'severity', 'days' ) ) ) {
			throw new Denied();
		}
		$action = $input['action'] ?? 'note';
		if ( ! in_array( $action, array( 'note', 'warn', 'hide', 'restore', 'restrict', 'unrestrict', 'dismiss', 'transition' ), true ) || ! is_string( $input['note'] ?? '' ) || mb_strlen( $input['note'] ?? '' ) > 4000 ) {
			throw new \InvalidArgumentException( 'Invalid moderation action.' );
		}
		return $this->db->transaction(
			function () use ( $actor, $id, $input, $action ): array {
				$case = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'cases' ) . ' WHERE id=%d FOR UPDATE', array( $id ) )[0] ?? null;
				if ( ! $case ) {
						throw new Denied();
				}
				$update   = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
				$previous = array( 'status' => $case['status'] );
				if ( in_array( $action, array( 'hide', 'restore' ), true ) ) {
					$entity = $this->native->get( $case['object_type'], (int) $case['object_id'] );
					if ( ! $entity ) {
						throw new Denied();
					}
					$previous['hidden'] = $entity['hidden'];
					$this->native->hide( $case['object_type'], (int) $case['object_id'], 'hide' === $action );
					$update['status'] = 'actioned';
				} elseif ( in_array( $action, array( 'restrict', 'unrestrict' ), true ) ) {
					$subject = (int) $case['subject'];
					$days    = (int) ( $input['days'] ?? 7 );
					if ( ! $subject || bp_user_can( $subject, 'bpi_moderate' ) || $days < 1 || $days > 90 ) {
						throw new Denied();
					}
					$previous['restricted_until'] = get_user_meta( $subject, '_bpi_restricted_until', true );
					update_user_meta( $subject, '_bpi_restricted_until', 'restrict' === $action ? time() + $days * DAY_IN_SECONDS : 0 );
					$update['status'] = 'actioned';
				} elseif ( 'dismiss' === $action ) {
					$update['status'] = 'no_action';
				} elseif ( 'warn' === $action && $case['subject'] ) {
					update_user_meta( (int) $case['subject'], '_bpi_notice', __( 'A community moderator has issued a warning. Review the community guidelines or submit an appeal from Reports and appeals.', 'buddypress-intelligence' ) );
					$update['status'] = 'actioned';
				} elseif ( 'transition' === $action ) {
					$transitions = array(
						'open'         => array( 'triaged', 'no_action' ),
						'triaged'      => array( 'under_review', 'no_action' ),
						'under_review' => array( 'actioned', 'no_action' ),
						'actioned'     => array( 'resolved' ),
						'no_action'    => array( 'resolved' ),
						'appealed'     => array( 'under_review', 'resolved' ),
						'resolved'     => array( 'open' ),
					);
					$status      = $input['status'] ?? '';
					if ( ! in_array( $status, $transitions[ $case['status'] ] ?? array(), true ) ) {
							throw new \InvalidArgumentException( 'Invalid case transition.' );
					}
					$update['status'] = $status;
				}
				foreach ( array( 'priority', 'severity' ) as $field ) {
					if ( isset( $input[ $field ] ) ) {
						if ( ! is_int( $input[ $field ] ) || $input[ $field ] < 1 || $input[ $field ] > 5 ) {
							throw new \InvalidArgumentException( 'Priority and severity must be 1–5.' );
						}
						$update[ $field ] = $input[ $field ];
					}
				}
				if ( isset( $input['assigned'] ) ) {
					if ( $input['assigned'] && ! bp_user_can( (int) $input['assigned'], 'bpi_moderate' ) ) {
						throw new Denied();
					}
					$update['assigned'] = (int) $input['assigned'];
				}
				$this->db->update( 'cases', $update, array( 'id' => $id ) );
				$this->db->insert(
					'audit',
					array(
						'case_id'        => $id,
						'actor'          => $actor,
						'action'         => $action,
						'note'           => sanitize_textarea_field( $input['note'] ?? '' ),
						'previous_state' => wp_json_encode( $previous ),
						'created_at'     => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->events->record( 'moderation_action_applied', $actor, $case['object_type'], (int) $case['object_id'], array(), '', 'plugin', 'private', $id );
				$this->policy->clear();
				return array(
					'case_id' => $id,
					'action'  => $action,
				);
			}
		);
	}
	public function appeal( int $actor, int $id, string $reason ): array {
		$case = $this->db->one( 'cases', array( 'id' => $id ) );
		if ( ! $this->settings->all()['appeals'] || ! $case || $actor !== (int) $case['subject'] || ! in_array( $case['status'], array( 'actioned', 'resolved' ), true ) || ! trim( $reason ) || mb_strlen( $reason ) > 2000 ) {
			throw new Denied();
		}
		$this->db->transaction(
			function () use ( $actor, $id, $reason ): void {
				$this->db->select( 'SELECT id FROM ' . $this->db->table( 'cases' ) . ' WHERE id=%d FOR UPDATE', array( $id ) );
				if ( $this->db->one(
					'appeals',
					array(
						'case_id' => $id,
						'actor'   => $actor,
					)
				) ) {
					throw new Denied();
				}
				$this->db->unique(
					'appeals',
					array(
						'case_id'    => $id,
						'actor'      => $actor,
						'reason'     => sanitize_textarea_field( $reason ),
						'status'     => 'pending',
						'reviewer'   => 0,
						'decision'   => '',
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array(
						'case_id' => $id,
						'actor'   => $actor,
					)
				);
				$this->db->update(
					'cases',
					array(
						'status'     => 'appealed',
						'updated_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => $id )
				);
			}
		);
		return array( 'submitted' => true );
	}
	public function reviewAppeal( int $actor, int $id, string $status, string $decision ): array {
		if ( ! bp_current_user_can( 'bpi_moderate' ) || ! in_array( $status, array( 'upheld', 'rejected' ), true ) || ! trim( $decision ) || mb_strlen( $decision ) > 2000 || ! $this->db->one(
			'appeals',
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		) ) {
			throw new Denied();
		}
		$this->db->transaction(
			function () use ( $actor, $id, $status, $decision ): void {
				$appeal = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'appeals' ) . ' WHERE id=%d FOR UPDATE', array( $id ) )[0] ?? null;
				if ( ! $appeal || 'pending' !== $appeal['status'] ) {
						throw new Denied();
				}
				$this->db->update(
					'appeals',
					array(
						'status'   => $status,
						'reviewer' => $actor,
						'decision' => sanitize_textarea_field( $decision ),
					),
					array( 'id' => $id )
				);
				// An upheld appeal reopens review. Restoration is a separate explicit, audited action.
				$this->act(
					$actor,
					(int) $appeal['case_id'],
					array(
						'action' => 'transition',
						'status' => 'upheld' === $status ? 'under_review' : 'resolved',
						'note'   => sanitize_textarea_field( $decision ),
					)
				);
			}
		);
		return array( 'reviewed' => true );
	}
}
