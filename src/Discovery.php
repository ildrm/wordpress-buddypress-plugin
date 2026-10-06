<?php
namespace BuddyPressIntelligence;

final class Discovery {
	private Database $db;
	private NativeObjects $native;
	private Policy $policy;
	private Settings $settings;
	public function __construct( Database $db, NativeObjects $native, Policy $policy, Settings $settings ) {
		$this->db       = $db;
		$this->native   = $native;
		$this->policy   = $policy;
		$this->settings = $settings;
	}
	public function retrieve( int $viewer, array $args ): array {
		$kind  = $args['kind'] ?? 'feed';
		$mode  = $args['mode'] ?? 'for_you';
		$type  = $args['type'] ?? ( 'feed' === $kind ? 'activity' : 'member' );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$size  = min( 50, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$query = trim( (string) ( $args['q'] ?? '' ) );
		if ( str_starts_with( $query, '"' ) && str_ends_with( $query, '"' ) ) {
			$query = substr( $query, 1, -1 );
		}
		if ( ! in_array( $kind, array( 'feed', 'recommendations', 'search' ), true ) || ! in_array( $mode, array( 'latest', 'following', 'for_you', 'discover', 'trending' ), true ) || ! in_array( $type, array( 'all', 'activity', 'member', 'expert', 'group', 'topic', 'post', 'question', 'knowledge' ), true ) || mb_strlen( $query ) > 200 || $page > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid discovery request.' );
		}
		$module = 'feed' === $kind ? 'feed' : $kind;
		if ( ! $this->settings->enabled( $module ) || ( 'feed' === $kind && ! $this->native->active( 'activity' ) ) || ( 'group' === $type && ! $this->native->active( 'groups' ) ) ) {
			return array(
				'items'    => array(),
				'state'    => 'unavailable',
				'page'     => $page,
				'has_more' => false,
				'cursor'   => '',
			);
		}
		if ( 'feed' === $kind ) {
			$type = 'activity';
		}
		$window = (int) ( $args['window'] ?? 1 );
		if ( $window < 1 || $window > 1000 ) {
			throw new \InvalidArgumentException( 'Invalid result window.' );
		}
		$context  = hash( 'sha256', wp_json_encode( array( Core::root(), Core::option( 'bpi_cache_generation', '' ), $viewer, $kind, $mode, $type, $query, $args['topic_id'] ?? 0, $args['author'] ?? 0, $args['after'] ?? '', $args['before'] ?? '', $args['unanswered'] ?? false, $size, $window ) ) );
		$cursor   = (string) ( $args['cursor'] ?? '' );
		$snapshot = null;
		if ( $cursor ) {
			if ( ! preg_match( '/^[a-f0-9]{32}$/', $cursor ) ) {
				throw new \InvalidArgumentException( 'Invalid cursor.' );
			}
			$snapshot = Core::transient( 'bpi_' . hash( 'sha256', "$viewer:$cursor" ) );
			if ( ! is_array( $snapshot ) || ! hash_equals( $context, $snapshot['context'] ) ) {
				throw new \InvalidArgumentException( 'Cursor expired or belongs to another request.' );
			}
		} else {
			$limit        = (int) $this->settings->all()['candidate_limit'];
			$types        = 'all' === $type ? array( 'member', 'group', 'activity', 'question', 'knowledge', 'post', 'topic' ) : array( $type );
			$candidates   = array();
			$per_type     = max( 1, (int) floor( $limit / count( $types ) ) );
			$more_windows = false;
			foreach ( $types as $candidate_type ) {
				if ( ( in_array( $candidate_type, array( 'question', 'knowledge' ), true ) && ! $this->settings->enabled( 'qa' ) ) || ( 'topic' === $candidate_type && ! $this->settings->enabled( 'topics' ) ) || ( 'expert' === $candidate_type && ! $this->settings->enabled( 'expertise' ) ) ) {
					continue;
				}
				$provider = apply_filters( 'bpi_search_provider_v1', $this->native, $candidate_type );
				try {
					$found = $provider instanceof SearchProvider ? $provider->candidates( $candidate_type, $per_type, (int) ( $args['window'] ?? 1 ), $query ) : array();
				} catch ( \Throwable $e ) {
					$found = $this->native->candidates( $candidate_type, $per_type, (int) ( $args['window'] ?? 1 ), $query );
				}
				// Hydrate provider IDs through canonical source; provider content is never trusted.
				$more_windows = $more_windows || count( $found ) >= $per_type;
				foreach ( array_slice( $found, 0, $per_type ) as $candidate ) {
					$actual = $this->native->get( 'expert' === $candidate_type ? 'member' : $candidate_type, (int) ( $candidate['id'] ?? 0 ) );
					if ( $actual ) {
						$candidates[ $actual['type'] . ':' . $actual['id'] ] = $actual;
					}
				}
			}
			if ( 'feed' === $kind && in_array( $mode, array( 'following', 'for_you' ), true ) ) {
				$personal = array();
				foreach ( $this->native->followedActivities( $viewer, min( 50, $limit ), $window ) as $entity ) {
					$personal[ 'activity:' . $entity['id'] ] = $entity;
				}
				$candidates = array_slice( $personal + $candidates, 0, $limit, true );
			}
			$items    = $this->rank( $viewer, array_values( $candidates ), $kind, $mode, $type, $args );
			$cursor   = bin2hex( random_bytes( 16 ) );
			$snapshot = array(
				'context'      => $context,
				'more_windows' => $more_windows && $window < 1000,
				'items'        => array_map(
					static fn( $item ) => array(
						'type'   => $item['type'],
						'id'     => $item['id'],
						'reason' => $item['reason'],
					),
					$items
				),
			);
			Core::transient( 'bpi_' . hash( 'sha256', "$viewer:$cursor" ), $snapshot );
		}
		// Recheck permissions on every page, including after group/block/privacy changes.
		$visible = array();
		$objects = array();
		foreach ( $snapshot['items'] as $item ) {
			$entity = $this->native->get( $item['type'], (int) $item['id'] );
			if ( $entity ) {
				$objects[] = $entity;
			}
		}
		$this->policy->prime( $viewer, $objects );
		$rejected = array();
		if ( 'search' !== $kind && $objects ) {
			$clauses    = array();
			$parameters = array( $viewer, 'not_interested' );
			foreach ( $objects as $entity ) {
				$clauses[]    = '(object_type=%s AND object_id=%d)';
				$parameters[] = $entity['type'];
				$parameters[] = $entity['id'];
			}
			foreach ( $this->db->select( 'SELECT object_type,object_id FROM ' . $this->db->table( 'feedback' ) . ' WHERE actor=%d AND value=%s AND (' . implode( ' OR ', $clauses ) . ')', $parameters ) as $row ) {
				$rejected[ $row['object_type'] . ':' . $row['object_id'] ] = true;
			}
		}
		foreach ( $snapshot['items'] as $item ) {
			if ( ! isset( $rejected[ $item['type'] . ':' . $item['id'] ] ) && $this->policy->view( $viewer, $item['type'], (int) $item['id'], 'search' !== $kind ) ) {
				$entity    = $this->native->get( $item['type'], (int) $item['id'] );
				$visible[] = $this->serialize( $entity, $item['reason'] );
			}
		}
		$offset = ( $page - 1 ) * $size;
		return array(
			'items'        => array_slice( $visible, $offset, $size ),
			'state'        => $visible ? 'ready' : 'empty',
			'page'         => $page,
			'has_more'     => count( $visible ) > $offset + $size,
			'more_windows' => ! empty( $snapshot['more_windows'] ),
			'cursor'       => $cursor,
			'window'       => (int) ( $args['window'] ?? 1 ),
		);
	}
	private function rank( int $viewer, array $candidates, string $kind, string $mode, string $type, array $args ): array {
		$this->policy->prime( $viewer, $candidates );
		$prefs        = get_user_meta( $viewer, '_bpi_preferences', true );
		$interests    = is_array( $prefs ) ? ( $prefs['interests'] ?? array() ) : array();
		$edges        = $this->policy->edges( $viewer );
		$follow       = array();
		$muted_topics = array();
		foreach ( $edges as $edge ) {
			if ( 'follow' === $edge['kind'] ) {
				$follow[ $edge['object_type'] . ':' . $edge['object_id'] ] = true;
				if ( 'topic' === $edge['object_type'] ) {
					$interests[] = (int) $edge['object_id'];
				}
			} elseif ( in_array( $edge['kind'], array( 'mute', 'snooze' ), true ) && 'topic' === $edge['object_type'] ) {
				$muted_topics[] = (int) $edge['object_id'];
			}
		}
		$mapping          = $this->mapping( $candidates );
		$feedback         = $this->db->rows( 'feedback', array( 'actor' => $viewer ), 50 );
		$negative_topics  = array();
		$negative_authors = array();
		$positive_topics  = array();
		$values           = array();
		foreach ( $feedback as $row ) {
			$values[ $row['object_type'] . ':' . $row['object_id'] ] = $row['value'];
			$entity = $this->native->get( $row['object_type'], (int) $row['object_id'] );
			if ( $entity && $this->policy->view( $viewer, $row['object_type'], (int) $row['object_id'] ) ) {
				if ( in_array( $row['value'], array( 'less', 'not_interested' ), true ) ) {
					$negative_authors[] = $entity['author'];
				}
				$rows = $this->db->rows(
					'object_topics',
					array(
						'object_type' => $row['object_type'],
						'object_id'   => $row['object_id'],
					),
					20
				);
				foreach ( $rows as $topic ) {
					if ( 'more' === $row['value'] ) {
						$positive_topics[] = (int) $topic['topic_id'];
					} else {
						$negative_topics[] = (int) $topic['topic_id'];
					}
				}
			}
		}
		$interests    = array_unique( array_merge( $interests, $positive_topics ) );
		$engagement   = $this->engagement( $candidates );
		$evidence     = $this->evidence( $viewer, $candidates );
		$interactions = $this->interactions( $viewer );
		$answered     = ! empty( $args['unanswered'] ) ? $this->answered( $viewer, $candidates ) : array();
		$items        = array();
		$now          = time();
		foreach ( $candidates as $entity ) {
			$id  = (int) $entity['id'];
			$key = $entity['type'] . ':' . $id;
			if ( ! $this->policy->view( $viewer, $entity['type'], $id, 'search' !== $kind ) || ( 'recommendations' === $kind && 'member' === $entity['type'] && $id === $viewer ) || ( 'search' !== $kind && 'not_interested' === ( $values[ $key ] ?? '' ) ) ) {
				continue;
			}
			$topics = $mapping[ $key ] ?? array();
			if ( 'topic' === $entity['type'] ) {
				$topics[] = $id;
			}
			$author_prefs = get_user_meta( $entity['author'], '_bpi_preferences', true );
			$skills       = is_array( $author_prefs ) ? array_unique( array_merge( $author_prefs['skills'] ?? array(), $author_prefs['help_topics'] ?? array() ) ) : array();
			if ( 'expert' === $type ) {
				$skills = array_filter( $skills, fn( $topic ) => $this->policy->view( $viewer, 'topic', (int) $topic ) );
				if ( ! $skills ) {
					continue;
				}
				$topics = array_unique( array_merge( $topics, $skills ) );
			}
			if ( ( 'search' !== $kind && array_intersect( $topics, $muted_topics ) ) || ( ! empty( $args['topic_id'] ) && ! in_array( (int) $args['topic_id'], $topics, true ) ) || ( ! empty( $args['author'] ) && (int) $args['author'] !== $entity['author'] ) || ( ! empty( $args['after'] ) && $entity['date'] < $args['after'] ) || ( ! empty( $args['before'] ) && $entity['date'] > $args['before'] ) || ( ! empty( $args['unanswered'] ) && ( 'question' !== $entity['type'] || isset( $answered[ $id ] ) ) ) ) {
				continue;
			}
			$is_followed = isset( $follow[ $key ] ) || isset( $follow[ 'member:' . $entity['author'] ] ) || isset( $follow[ 'group:' . $entity['group_id'] ] );
			if ( 'following' === $mode && ! $is_followed && ! array_intersect( $topics, $interests ) ) {
				continue;
			}
			$affinity  = count( $interests ) ? min( 1, count( array_intersect( $topics, $interests ) ) / min( 3, count( $interests ) ) ) : 0;
			$affinity  = max( $affinity, in_array( $entity['group_id'], $prefs['groups'] ?? array(), true ) ? 0.5 : 0.0 );
			$timestamp = strtotime( $entity['date'] . ' UTC' ) ?: $now;
			$age       = max( 0, ( $now - $timestamp ) / 3600 );
			$e         = $engagement[ $key ] ?? array(
				'participants' => 0,
				'replies'      => 0,
			);
			$trend     = Ranking::trend( (int) $e['participants'], (int) $e['replies'], $age );
			$mutual    = $this->policy->relation( $entity['author'], 'follow', 'member', $viewer );
			$friend    = $this->native->friend( $viewer, $entity['author'] );
			$featured  = 'activity' === $entity['type'] && bp_activity_get_meta( $id, '_bpi_featured', true );
			$signals   = array(
				'recency'      => Ranking::recency( $timestamp, $now ),
				'follow'       => (float) $is_followed,
				'topic'        => $affinity,
				'relationship' => Ranking::strength( $is_followed, $mutual, (bool) $friend, $interactions[ $entity['author'] ] ?? 0, 1 ),
				'quality'      => min( 1.0, $trend + ( $featured ? 0.25 : 0.0 ) + ( $evidence[ $entity['author'] ] ?? 0.0 ) ),
				'exploration'  => $is_followed ? 0.0 : 1.0,
			);
			$penalty   = ( in_array( $entity['author'], $negative_authors, true ) ? 0.15 : 0 ) + ( array_intersect( $topics, $negative_topics ) ? 0.2 : 0 ) + ( 'less' === ( $values[ $key ] ?? '' ) ? 0.3 : 0 );
			$score     = Ranking::score( apply_filters( 'bpi_ranking_signals_v1', $signals, $entity, $viewer ), $this->settings->all()['weights'], $penalty );
			if ( 'expert' === $type ) {
				$score = 0.5 * $affinity + 0.4 * ( $evidence[ $entity['author'] ] ?? 0.0 ) + 0.1 * $signals['recency'];
			}
			$reason = $affinity > 0 ? 'interests' : ( $is_followed ? 'following' : 'new' );
			if ( 'latest' === $mode ) {
				$score  = (float) $timestamp;
				$reason = 'new';
			} elseif ( 'trending' === $mode ) {
				$score  = max( 0, $trend - $penalty );
				$reason = 'trending';
			} elseif ( 'discover' === $mode ) {
				$score = max( 0, $score + ( $is_followed ? -0.1 : 0.1 ) );
			}
			if ( 'search' === $kind && ! empty( $args['q'] ) ) {
				$q      = mb_strtolower( trim( (string) $args['q'], '" ' ) );
				$title  = mb_strtolower( wp_strip_all_tags( $entity['title'] ) );
				$body   = mb_strtolower( wp_strip_all_tags( $entity['body'] ) );
				$score  = ( $title === $q ? 3 : ( str_contains( $title, $q ) ? 2 : 0 ) ) + ( str_contains( $body, $q ) ? 1 : 0 ) + 0.1 * $signals['recency'];
				$reason = 'search';
			}
			$entity['score']    = $score;
			$entity['reason']   = $reason;
			$entity['topic_id'] = $topics[0] ?? 0;
			$items[]            = $entity;
		}
		$items = Ranking::sort( $items );
		return ( 'feed' === $kind && ! in_array( $mode, array( 'latest', 'following' ), true ) ) ? Ranking::diversify( $items, count( $items ) ) : $items;
	}
	private function answered( int $viewer, array $objects ): array {
		$ids = array_column( array_filter( $objects, static fn( $entity ) => 'question' === $entity['type'] ), 'id' );
		if ( ! $ids ) {
			return array();
		}
		$rows = $this->db->select( 'SELECT id,parent_id FROM ' . $this->db->table( 'entries' ) . ' WHERE kind=%s AND status=%s AND parent_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id DESC LIMIT 500', array_merge( array( 'answer', 'published' ), $ids ) );
		$this->native->primeEntries( array_column( $rows, 'id' ) );
		$answered = array();
		foreach ( $rows as $row ) {
			if ( $this->policy->view( $viewer, 'answer', (int) $row['id'] ) ) {
				$answered[ (int) $row['parent_id'] ] = true;
			}
		}
		// A saturated scan cannot certify that unexamined answers are invisible.
		// Omit uncertain questions rather than label an answered question unanswered.
		if ( count( $rows ) === 500 ) {
			$last  = (int) end( $rows )['id'];
			$older = $this->db->select( 'SELECT DISTINCT parent_id FROM ' . $this->db->table( 'entries' ) . ' WHERE kind=%s AND status=%s AND id<%d AND parent_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( 'answer', 'published', $last ), $ids ) );
			foreach ( $older as $row ) {
				$answered[ (int) $row['parent_id'] ] = true;
			}
		}
		return $answered;
	}
	private function interactions( int $viewer ): array {
		if ( ! $viewer ) {
			return array();
		}
		$rows   = $this->db->select( 'SELECT object_type,object_id,created_at FROM ' . $this->db->table( 'events' ) . " WHERE actor=%d AND created_at>=%s AND type IN (%s,%s) AND JSON_EXTRACT(metadata,'$.analytics')=true ORDER BY id DESC LIMIT 30", array( $viewer, gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ), 'activity_commented', 'answer_accepted' ) );
		$result = array();
		$seen   = array();
		foreach ( $rows as $row ) {
			$key    = $row['object_type'] . ':' . $row['object_id'];
			$entity = $this->native->get( $row['object_type'], (int) $row['object_id'] );
			if ( $entity && $entity['author'] !== $viewer && ! isset( $seen[ $key ] ) && $this->policy->view( $viewer, $row['object_type'], (int) $row['object_id'] ) ) {
				$result[ $entity['author'] ] = ( $result[ $entity['author'] ] ?? 0 ) + Ranking::recency( strtotime( $row['created_at'] . ' UTC' ), time(), 24 * 30 );
				$seen[ $key ]                = true;
			}
		}
		return $result;
	}
	private function mapping( array $objects ): array {
		if ( ! $objects ) {
			return array();
		}
		$clauses = array();
		$args    = array();
		foreach ( $objects as $entity ) {
			$clauses[] = '(object_type=%s AND object_id=%d)';
			$args[]    = $entity['type'];
			$args[]    = $entity['id'];
		}
		$rows   = $this->db->select( 'SELECT object_type,object_id,topic_id FROM ' . $this->db->table( 'object_topics' ) . ' WHERE ' . implode( ' OR ', $clauses ), $args );
		$result = array();
		foreach ( $rows as $row ) {
			$result[ $row['object_type'] . ':' . $row['object_id'] ][] = (int) $row['topic_id'];
		}
		return $result;
	}
	private function evidence( int $viewer, array $objects ): array {
		if ( ! $this->settings->enabled( 'expertise' ) ) {
			return array();
		}
		$authors = array_values( array_unique( array_filter( array_column( $objects, 'author' ) ) ) );
		if ( ! $authors ) {
			return array();
		}
		$rows = $this->db->select( 'SELECT actor,topic_id,object_id,points,created_at FROM ' . $this->db->table( 'ledger' ) . ' WHERE actor IN (' . implode( ',', array_fill( 0, count( $authors ), '%d' ) ) . ') AND created_at>%s ORDER BY id DESC LIMIT 500', array_merge( $authors, array( gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) ) );
		$this->native->primeEntries( array_column( $rows, 'object_id' ) );
		$result = array();
		foreach ( $rows as $row ) {
			if ( $this->policy->view( $viewer, 'answer', (int) $row['object_id'] ) && ( ! $row['topic_id'] || $this->policy->view( $viewer, 'topic', (int) $row['topic_id'] ) ) ) {
				$actor            = (int) $row['actor'];
				$result[ $actor ] = ( $result[ $actor ] ?? 0.0 ) + (int) $row['points'] * Ranking::recency( strtotime( $row['created_at'] . ' UTC' ), time(), 24 * 180 );
			}
		}
		foreach ( $result as $actor => $points ) {
			$result[ $actor ] = min( 1.0, log( 1 + max( 0, $points ) ) / log( 101 ) );
		}
		return $result;
	}
	private function engagement( array $objects ): array {
		if ( ! $objects ) {
			return array();
		}
		$clauses = array();
		$args    = array( gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) );
		foreach ( $objects as $entity ) {
			$clauses[] = '(object_type=%s AND object_id=%d)';
			$args[]    = $entity['type'];
			$args[]    = $entity['id'];
		}
		$rows   = $this->db->select( 'SELECT object_type,object_id,COUNT(DISTINCT actor) AS participants,SUM(type IN (\'activity_commented\',\'answer_created\')) AS replies FROM ' . $this->db->table( 'events' ) . " WHERE created_at>%s AND source<>'automation' AND type IN ('activity_commented','activity_favorited','answer_created','answer_accepted') AND (" . implode( ' OR ', $clauses ) . ') GROUP BY object_type,object_id', $args );
		$result = array();
		foreach ( $rows as $row ) {
			$result[ $row['object_type'] . ':' . $row['object_id'] ] = $row;
		}
		return $result;
	}
	private function serialize( array $entity, string $reason ): array {
		$reasons = array(
			'interests' => __( 'Matches your selected interests.', 'buddypress-intelligence' ),
			'following' => __( 'From people or groups you follow.', 'buddypress-intelligence' ),
			'new'       => __( 'Recent in your community.', 'buddypress-intelligence' ),
			'trending'  => __( 'Recent participation from different members.', 'buddypress-intelligence' ),
			'search'    => __( 'Matches your search.', 'buddypress-intelligence' ),
		);
		return array(
			'id'      => $entity['id'],
			'type'    => $entity['type'],
			'title'   => wp_strip_all_tags( $entity['title'] ),
			'excerpt' => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $entity['body'] ) ), 40 ),
			'url'     => esc_url_raw( $entity['url'] ),
			'date'    => $entity['date'],
			'reason'  => $reasons[ $reason ] ?? $reasons['new'],
		);
	}
	public function feedback( int $actor, string $type, int $id, string $value ): array {
		if ( ! in_array( $value, array( 'more', 'less', 'not_interested', 'clear' ), true ) || ! $this->policy->interact( $actor, $type, $id ) ) {
			throw new Denied();
		}
		$key = array(
			'actor'       => $actor,
			'object_type' => $type,
			'object_id'   => $id,
		);
		if ( 'clear' === $value ) {
			$this->db->delete( 'feedback', $key );
		} else {
			$this->db->unique(
				'feedback',
				$key + array(
					'value'      => $value,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$key
			);
			$this->db->update(
				'feedback',
				array(
					'value'      => $value,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$key
			);
		}
		return array( 'saved' => true );
	}
}
