<?php
namespace BuddyPressIntelligence;

final class Knowledge {
	private Database $db;
	private Policy $policy;
	private Events $events;
	private Settings $settings;
	private Topics $topics;
	public function __construct( Database $db, Policy $policy, Events $events, Settings $settings, Topics $topics ) {
		$this->db       = $db;
		$this->policy   = $policy;
		$this->events   = $events;
		$this->settings = $settings;
		$this->topics   = $topics;
	}
	public function create( int $actor, array $input ): int {
		if ( ! $actor || (int) get_user_meta( $actor, '_bpi_restricted_until', true ) >= time() || array_diff( array_keys( $input ), array( 'kind', 'parent_id', 'group_id', 'title', 'body', 'topics' ) ) ) {
			throw new Denied();
		}
		$kind  = $input['kind'] ?? 'question';
		$title = $input['title'] ?? '';
		$body  = $input['body'] ?? '';
		if ( ! in_array( $kind, array( 'question', 'answer', 'knowledge' ), true ) || ! is_string( $body ) || ! trim( $body ) || mb_strlen( $body ) > 20000 || ! is_string( $title ) || mb_strlen( $title ) > 200 || ( 'question' === $kind && ! trim( $title ) ) ) {
			throw new \InvalidArgumentException( 'Invalid question or answer.' );
		}
		$parent = (int) ( $input['parent_id'] ?? 0 );
		$group  = (int) ( $input['group_id'] ?? 0 );
		if ( 'question' !== $kind ) {
			$question = $this->policy->requireView( $actor, 'question', $parent );
			if ( ! $this->policy->interact( $actor, 'question', $parent ) ) {
				throw new Denied();
			}
			$group = $question['group_id'];
			if ( 'knowledge' === $kind ) {
				if ( ! bp_current_user_can( 'bpi_moderate' ) || ! $question['accepted_id'] ) {
					throw new Denied();
				}
				$answer = $this->policy->requireView( $actor, 'answer', $question['accepted_id'] );
				// Reviewed canonical entry keeps full native group context and provenance.
				$body  = $answer['body'];
				$title = $title ?: $question['title'];
			}
		} elseif ( $parent ) {
			throw new \InvalidArgumentException( 'Questions cannot have a parent.' );
		}
		if ( $group && ( ! $this->policy->group( $actor, $group ) || ! groups_is_user_member( $actor, $group ) ) ) {
			throw new Denied();
		}
		$topics = $input['topics'] ?? array();
		if ( ! is_array( $topics ) ) {
			throw new \InvalidArgumentException( 'Invalid topics.' );
		}
		return $this->db->transaction(
			function () use ( $actor, $kind, $parent, $group, $title, $body, $topics ): int {
				$id = $this->db->insert(
					'entries',
					array(
						'kind'        => $kind,
						'parent_id'   => $parent,
						'author'      => $actor,
						'group_id'    => $group,
						'title'       => sanitize_text_field( $title ),
						'body'        => wp_kses_post( $body ),
						'status'      => 'published',
						'accepted_id' => 0,
						'created_at'  => gmdate( 'Y-m-d H:i:s' ),
						'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->policy->clear();
				$this->topics->assign( $actor, $kind, $id, $topics );
				if ( 'knowledge' !== $kind ) {
					$this->events->record(
						'question' === $kind ? 'question_created' : 'answer_created',
						$actor,
						'question' === $kind ? 'question' : 'question',
						'question' === $kind ? $id : $parent,
						array(
							'parent_id' => $parent,
							'group_id'  => $group,
						)
					);
				}
				return $id;
			}
		);
	}
	public function detail( int $actor, string $kind, int $id, int $page = 1 ): array {
		$entity = $this->policy->requireView( $actor, $kind, $id );
		$result = array(
			'id'          => $id,
			'kind'        => $kind,
			'title'       => $entity['title'],
			'body'        => wp_kses_post( $entity['body'] ),
			'author'      => $entity['author'],
			'date'        => $entity['date'],
			'accepted_id' => $entity['accepted_id'] ?? 0,
			'parent_id'   => $entity['parent_id'] ?? 0,
			'can_accept'  => 'question' === $kind && $actor === $entity['author'],
		);
		if ( 'question' === $kind ) {
			$related                     = $this->db->select( 'SELECT DISTINCT e.id,e.title FROM ' . $this->db->table( 'entries' ) . ' e INNER JOIN ' . $this->db->table( 'object_topics' ) . ' mapped ON mapped.object_type=%s AND mapped.object_id=e.id INNER JOIN ' . $this->db->table( 'object_topics' ) . ' source ON source.topic_id=mapped.topic_id AND source.object_type=%s AND source.object_id=%d WHERE e.kind=%s AND e.status=%s AND e.id<>%d ORDER BY e.id DESC LIMIT 30', array( 'question', 'question', $id, 'question', 'published', $id ) );
			$result['related_questions'] = array();
			foreach ( $related as $row ) {
				if ( $this->policy->view( $actor, 'question', (int) $row['id'] ) ) {
					$result['related_questions'][] = array(
						'id'    => (int) $row['id'],
						'title' => $row['title'],
					);
				}
				if ( count( $result['related_questions'] ) >= 5 ) {
					break;
				}
			}
			if ( $result['accepted_id'] && ! $this->policy->view( $actor, 'answer', (int) $result['accepted_id'] ) ) {
				$result['accepted_id'] = 0;
			}
			$answers                    = $this->db->rows(
				'entries',
				array(
					'parent_id' => $id,
					'kind'      => 'answer',
					'status'    => 'published',
				),
				51,
				( max( 1, $page ) - 1 ) * 50,
				'id ASC'
			);
			$result['has_more_answers'] = count( $answers ) > 50;
			$answers                    = array_slice( $answers, 0, 50 );
			$result['answers']          = array();
			foreach ( $answers as $answer ) {
				if ( $this->policy->view( $actor, 'answer', (int) $answer['id'] ) ) {
					$result['answers'][] = $this->detail( $actor, 'answer', (int) $answer['id'] );
				}
			}
		}
		return $result;
	}
	public function accept( int $actor, int $question_id, int $answer_id ): array {
		$question = $this->policy->requireView( $actor, 'question', $question_id );
		$answer   = $this->policy->requireView( $actor, 'answer', $answer_id );
		if ( ! $this->policy->interact( $actor, 'question', $question_id ) || $question['author'] !== $actor || $answer['parent_id'] !== $question_id ) {
			throw new Denied();
		}
		return $this->db->transaction(
			function () use ( $actor, $question_id, $answer_id, $answer ): array {
				$rows     = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'entries' ) . ' WHERE id=%d FOR UPDATE', array( $question_id ) );
				$question = $rows[0];
				if ( (int) $question['accepted_id'] === $answer_id ) {
						return array( 'accepted_id' => $answer_id );
				}
				if ( (int) $question['accepted_id'] ) {
					throw new \InvalidArgumentException( 'An accepted answer is already recorded. A moderator must reverse it before changing it.' );
				}
				$this->db->update(
					'entries',
					array(
						'accepted_id' => $answer_id,
						'revision'    => (int) $question['revision'] + 1,
						'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => $question_id )
				);
				if ( $this->settings->enabled( 'expertise' ) && $actor !== $answer['author'] && $this->mature( $actor ) && $this->mature( $answer['author'] ) ) {
					$topics = $this->db->rows(
						'object_topics',
						array(
							'object_type' => 'question',
							'object_id'   => $question_id,
						),
						20
					);
					foreach ( $topics as $topic ) {
							$this->award( 'accepted:' . $question_id . ':' . ( (int) $question['revision'] + 1 ) . ':' . $topic['topic_id'], $answer['author'], (int) $topic['topic_id'], $answer_id, 'accepted_answer', (int) $this->settings->all()['accepted_points'], $actor );
					}
				}
				$this->events->record( 'answer_accepted', $actor, 'question', $question_id, array( 'parent_id' => $answer_id ), 'accepted:' . $question_id . ':' . ( (int) $question['revision'] + 1 ) );
				$this->policy->clear();
				return array( 'accepted_id' => $answer_id );
			}
		);
	}
	public function reverseAcceptance( int $actor, int $question_id ): array {
		if ( ! bp_current_user_can( 'bpi_manage_reputation' ) ) {
			throw new Denied();
		}
		$this->policy->requireView( $actor, 'question', $question_id );
		return $this->db->transaction(
			function () use ( $actor, $question_id ): array {
				$question = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'entries' ) . ' WHERE id=%d FOR UPDATE', array( $question_id ) )[0] ?? null;
				if ( ! $question ) {
					throw new Denied();
				}
				if ( (int) $question['accepted_id'] ) {
					global $wpdb;
					$prefix = $wpdb->esc_like( 'accepted:' . $question_id . ':' . $question['revision'] . ':' ) . '%';
					$rows   = $this->db->select( 'SELECT * FROM ' . $this->db->table( 'ledger' ) . ' WHERE event_key LIKE %s LIMIT 20', array( $prefix ) );
					foreach ( $rows as $row ) {
						$key = 'reversed:' . $row['id'];
						$this->db->unique(
							'ledger',
							array(
								'event_key'  => $key,
								'actor'      => $row['actor'],
								'peer_id'    => $actor,
								'topic_id'   => $row['topic_id'],
								'object_id'  => $row['object_id'],
								'kind'       => 'reversal',
								'points'     => - (int) $row['points'],
								'created_at' => $row['created_at'],
							),
							array( 'event_key' => $key )
						);
					}
					$this->db->update(
						'entries',
						array(
							'accepted_id' => 0,
							'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
						),
						array( 'id' => $question_id )
					);
					$this->events->record( 'moderation_action_applied', $actor, 'question', $question_id, array(), 'reversal:' . $question_id . ':' . $question['revision'], 'plugin', 'private' );
					$this->policy->clear();
				}
				return array( 'reversed' => true );
			}
		);
	}
	private function mature( int $actor ): bool {
		$user = get_userdata( $actor );
		return $user && strtotime( $user->user_registered . ' UTC' ) < time() - 7 * DAY_IN_SECONDS;
	}
	private function award( string $key, int $actor, int $topic, int $entity, string $kind, int $points, int $peer ): void {
		// Ledger insertion is unique; bounded credit is issued only to mature accounts.
		$bucket = hash( 'sha256', 'reputation:' . $actor );
		$this->db->unique(
			'limits',
			array(
				'bucket'     => $bucket,
				'hits'       => 0,
				'expires'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'bucket' => $bucket )
		);
		$this->db->select( 'SELECT id FROM ' . $this->db->table( 'limits' ) . ' WHERE bucket=%s FOR UPDATE', array( $bucket ) );
		$counts = $this->db->select( 'SELECT COUNT(DISTINCT object_id) AS total FROM ' . $this->db->table( 'ledger' ) . ' WHERE actor=%d AND kind=%s AND object_id<>%d AND created_at>%s', array( $actor, $kind, $entity, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		if ( (int) $counts[0]['total'] >= 10 ) {
			return;
		}
		$pair = $this->db->select( 'SELECT COUNT(DISTINCT object_id) AS total FROM ' . $this->db->table( 'ledger' ) . ' WHERE actor=%d AND peer_id=%d AND object_id<>%d AND kind=%s AND created_at>%s', array( $actor, $peer, $entity, $kind, gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		if ( (int) $pair[0]['total'] >= 3 ) {
			return;
		}
		$this->db->unique(
			'ledger',
			array(
				'event_key'  => $key,
				'actor'      => $actor,
				'peer_id'    => $peer,
				'topic_id'   => $topic,
				'object_id'  => $entity,
				'kind'       => $kind,
				'points'     => $points,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'event_key' => $key )
		);
	}
	public function vote( int $actor, int $answer_id ): array {
		$answer = $this->policy->requireView( $actor, 'answer', $answer_id );
		if ( $actor === $answer['author'] || ! $this->policy->interact( $actor, 'answer', $answer_id ) || ! $this->mature( $actor ) ) {
			throw new Denied();
		}
		$key = array(
			'actor'     => $actor,
			'object_id' => $answer_id,
		);
		$this->db->transaction(
			function () use ( $key ): void {
				$this->db->unique( 'votes', $key + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), $key );
			}
		);
		// Helpful votes are evidence, not point awards: avoids reciprocal vote farming.
		return array( 'voted' => true );
	}
	public function reputation( int $viewer, int $member ): array {
		$this->policy->requireView( $viewer, 'member', $member );
		$rows   = $this->db->select( 'SELECT topic_id,object_id,points,created_at FROM ' . $this->db->table( 'ledger' ) . ' WHERE actor=%d AND created_at>%s ORDER BY id DESC LIMIT 500', array( $member, gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) );
		$scores = array();
		foreach ( $rows as $row ) {
			if ( ! $this->policy->view( $viewer, 'answer', (int) $row['object_id'] ) || ! $this->policy->view( $viewer, 'topic', (int) $row['topic_id'] ) ) {
				continue;
			}
			$topic            = (int) $row['topic_id'];
			$scores[ $topic ] = ( $scores[ $topic ] ?? 0 ) + (int) $row['points'] * Ranking::recency( strtotime( $row['created_at'] . ' UTC' ), time(), 24 * 180 );
		}
		$result = array();
		foreach ( $scores as $topic => $score ) {
			$result[] = array(
				'topic_id' => $topic,
				'score'    => round( max( 0, $score ), 2 ),
				'evidence' => __( 'Community accepted answers; not a professional qualification.', 'buddypress-intelligence' ),
			);
		}
		return $result;
	}
}
