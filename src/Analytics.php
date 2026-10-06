<?php
namespace BuddyPressIntelligence;

final class Analytics {
	private Database $db;
	private Settings $settings;
	public function __construct( Database $db, Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}
	public function rollup( array $payload ): void {
		$day = $payload['day'] ?? gmdate( 'Y-m-d' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! $this->settings->enabled( 'analytics' ) || ! $this->settings->all()['analytics_consent'] ) {
			return;
		}
		$end     = strtotime( $day . ' 23:59:59 UTC' );
		$metrics = array();
		foreach ( array(
			'dau' => 1,
			'wau' => 7,
			'mau' => 30,
		) as $metric => $days ) {
			$rows               = $this->db->select( 'SELECT COUNT(DISTINCT actor) AS value FROM ' . $this->db->table( 'events' ) . ' WHERE actor>0 AND created_at>=%s AND created_at<=%s AND JSON_EXTRACT(metadata,\'$.analytics\')=true', array( gmdate( 'Y-m-d H:i:s', $end - $days * DAY_IN_SECONDS + 1 ), gmdate( 'Y-m-d H:i:s', $end ) ) );
			$metrics[ $metric ] = (int) $rows[0]['value'];
		}
		$rows = $this->db->select( 'SELECT type,COUNT(*) AS value,COUNT(DISTINCT actor) AS population FROM ' . $this->db->table( 'events' ) . ' WHERE created_at>=%s AND created_at<=%s AND actor>0 AND JSON_EXTRACT(metadata,\'$.analytics\')=true GROUP BY type', array( $day . ' 00:00:00', $day . ' 23:59:59' ) );
		foreach ( $rows as $row ) {
			$metrics[ $row['type'] ] = (int) $row['population'] >= 5 ? (int) $row['value'] : -1;
		}
		foreach ( $metrics as $metric => $value ) {
			if ( $value < 5 ) {
				$value = -1;
			}
			$key = array(
				'day'       => $day,
				'metric'    => $metric,
				'dimension' => 'community',
			);
			$this->db->unique(
				'aggregates',
				$key + array(
					'value'      => $value,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				$key
			);
			$this->db->update( 'aggregates', array( 'value' => $value ), $key );
		}
	}
	public function overview(): array {
		if ( ! bp_current_user_can( 'bpi_view_analytics' ) ) {
			throw new Denied();
		}
		$rows = $this->db->rows( 'aggregates', array( 'dimension' => 'community' ), 100 );
		foreach ( $rows as &$row ) {
			$row['value'] = (float) $row['value'] < 0 ? null : (float) $row['value'];
		}
		return array(
			'metrics'     => $rows,
			'health'      => $this->health(),
			'definitions' => array(
				'dau'         => __( 'Distinct consenting authenticated members generating a qualifying event in one UTC day.', 'buddypress-intelligence' ),
				'wau'         => __( 'Distinct consenting authenticated members generating a qualifying event in the trailing seven UTC days.', 'buddypress-intelligence' ),
				'mau'         => __( 'Distinct consenting authenticated members generating a qualifying event in the trailing thirty UTC days.', 'buddypress-intelligence' ),
				'suppression' => __( 'Counts involving fewer than five members are withheld. Nonconsenting members are excluded.', 'buddypress-intelligence' ),
			),
		);
	}
	private function health(): array {
		if ( ! $this->settings->all()['analytics_consent'] ) {
			return array();
		}
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$events    = $this->db->table( 'events' );
		$rows      = $this->db->select( "SELECT actor,COUNT(*) AS total FROM $events WHERE created_at>=%s AND actor>0 AND JSON_EXTRACT(metadata,'$.analytics')=true AND type IN (%s,%s,%s) GROUP BY actor ORDER BY total DESC LIMIT 1000", array( $cutoff, 'activity_created', 'question_created', 'answer_created' ) );
		$total     = array_sum( array_column( $rows, 'total' ) );
		$questions = $this->db->select( 'SELECT COUNT(DISTINCT e.object_id) AS total,COUNT(DISTINCT e.actor) AS population,COUNT(DISTINCT a.parent_id) AS answered FROM ' . $events . ' e LEFT JOIN ' . $this->db->table( 'entries' ) . " a ON a.parent_id=e.object_id AND a.kind='answer' AND a.status='published' WHERE e.type=%s AND e.created_at>=%s AND JSON_EXTRACT(e.metadata,'$.analytics')=true", array( 'question_created', $cutoff ) )[0];
		$returning = $this->db->select( "SELECT COUNT(*) AS cohort,COALESCE(SUM(EXISTS(SELECT 1 FROM $events later WHERE later.actor=earlier.actor AND later.created_at>=%s AND JSON_EXTRACT(later.metadata,'$.analytics')=true)),0) AS returned FROM (SELECT DISTINCT actor FROM $events WHERE created_at>=%s AND created_at<%s AND actor>0 AND JSON_EXTRACT(metadata,'$.analytics')=true) earlier", array( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) )[0];
		return array(
			'contributor_concentration' => array(
				'value'      => count( $rows ) >= 5 && $total ? round( 100 * (int) $rows[0]['total'] / $total, 2 ) : null,
				'definition' => __( 'Percentage of qualifying creations from the most active consenting contributor in the last 30 days; at most the 1,000 most active contributors.', 'buddypress-intelligence' ),
			),
			'unanswered_question_rate'  => array(
				'value'      => (int) $questions['population'] >= 5 && (int) $questions['total'] ? round( 100 * ( (int) $questions['total'] - (int) $questions['answered'] ) / (int) $questions['total'], 2 ) : null,
				'definition' => __( 'Percentage of questions created by consenting members in the last 30 days without a published answer. Withheld below five question authors.', 'buddypress-intelligence' ),
			),
			'weekly_cohort_return'      => array(
				'value'      => (int) $returning['cohort'] >= 5 && (int) $returning['returned'] >= 5 ? round( 100 * (int) $returning['returned'] / (int) $returning['cohort'], 2 ) : null,
				'definition' => __( 'Percentage of consenting members active 7–14 days ago who generated a qualifying event in the last seven days. Both populations must reach five.', 'buddypress-intelligence' ),
			),
		);
	}
	public function experiment( array $input, int $id = 0 ): int {
		if ( ! bp_current_user_can( 'bpi_manage_feed' ) || array_diff( array_keys( $input ), array( 'name', 'variants', 'metric', 'status', 'starts_at', 'ends_at' ) ) ) {
			throw new Denied();
		}
		$variants = $input['variants'] ?? array();
		if ( ! is_string( $input['name'] ?? null ) || ! trim( $input['name'] ) || mb_strlen( $input['name'] ) > 120 || ! is_array( $variants ) || count( $variants ) < 2 || count( $variants ) > 4 || ! in_array( $input['metric'] ?? '', array( 'recommendation_clicked', 'feedback_created' ), true ) || ! in_array( $input['status'] ?? '', array( 'draft', 'running', 'ended' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid experiment.' );
		}
		foreach ( $variants as $variant ) {
			if ( ! is_string( $variant ) || ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $variant ) ) {
				throw new \InvalidArgumentException( 'Invalid variant.' );
			}
		}
		$start = strtotime( $input['starts_at'] ?? '' );
		$end   = strtotime( $input['ends_at'] ?? '' );
		if ( ! $start || ! $end || $end <= $start || $end - $start > 90 * DAY_IN_SECONDS || count( array_unique( $variants ) ) !== count( $variants ) ) {
			throw new \InvalidArgumentException( 'Invalid experiment window.' );
		}
		$row = array(
			'name'      => sanitize_text_field( $input['name'] ),
			'variants'  => wp_json_encode( $variants ),
			'metric'    => $input['metric'],
			'status'    => $input['status'],
			'starts_at' => gmdate( 'Y-m-d H:i:s', $start ),
			'ends_at'   => gmdate( 'Y-m-d H:i:s', $end ),
		);
		if ( $id ) {
			$previous = $this->db->one( 'experiments', array( 'id' => $id ) );
			if ( ! $previous || 'ended' === $previous['status'] || ( 'running' === $previous['status'] && ( 'ended' !== $row['status'] || $row['variants'] !== $previous['variants'] || $row['metric'] !== $previous['metric'] || $row['starts_at'] !== $previous['starts_at'] ) ) ) {
				throw new Denied();
			}
			$this->db->update( 'experiments', $row, array( 'id' => $id ) );
			return $id;
		}
		return $this->db->insert( 'experiments', $row + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}
	public function results( int $id ): array {
		if ( ! bp_current_user_can( 'bpi_manage_feed' ) ) {
			throw new Denied();
		}
		$experiment = $this->db->one( 'experiments', array( 'id' => $id ) );
		if ( ! $experiment ) {
			throw new Denied();
		}
		$results = array();
		foreach ( json_decode( $experiment['variants'], true ) as $variant ) {
			$events    = $this->db->table( 'events' );
			$row       = $this->db->select( 'SELECT COUNT(*) AS population,COALESCE(SUM(EXISTS(SELECT 1 FROM ' . $events . " e WHERE e.actor=a.actor AND e.type=%s AND e.created_at>=a.created_at AND e.created_at<=%s AND JSON_EXTRACT(e.metadata,'$.analytics')=true AND JSON_EXTRACT(e.metadata,'$.experiment_id')=%d AND JSON_UNQUOTE(JSON_EXTRACT(e.metadata,'$.variant'))=a.variant)),0) AS responders FROM " . $this->db->table( 'assignments' ) . " a WHERE a.experiment_id=%d AND a.variant=%s AND EXISTS(SELECT 1 FROM $events consent WHERE consent.actor=a.actor AND consent.created_at>=a.created_at AND consent.created_at<=%s AND JSON_EXTRACT(consent.metadata,'$.analytics')=true)", array( $experiment['metric'], $experiment['ends_at'], $id, $id, $variant, $experiment['ends_at'] ) )[0];
			$visible   = $this->settings->all()['analytics_consent'] && (int) $row['population'] >= 5 && (int) $row['responders'] >= 5;
			$results[] = array(
				'variant'          => $variant,
				'observed_members' => $visible ? (int) $row['population'] : null,
				'responders'       => $visible ? (int) $row['responders'] : null,
				'response_rate'    => $visible ? round( (int) $row['responders'] / (int) $row['population'], 4 ) : null,
			);
		}
		return array(
			'experiment' => $experiment,
			'results'    => $results,
			'definition' => __( 'Distinct consenting assigned members with a metric event divided by assigned members observed after assignment. Small populations are withheld; this is descriptive, not a significance test.', 'buddypress-intelligence' ),
		);
	}
	public function assignment( int $actor, int $id ): array {
		$experiment = $this->db->one(
			'experiments',
			array(
				'id'     => $id,
				'status' => 'running',
			)
		);
		$now        = gmdate( 'Y-m-d H:i:s' );
		if ( ! $actor || ! $experiment || $experiment['starts_at'] > $now || $experiment['ends_at'] < $now ) {
			throw new Denied();
		}
		$variants = json_decode( $experiment['variants'], true );
		$index    = (int) hexdec( substr( hash_hmac( 'sha256', "$id:$actor", wp_salt( 'auth' ) ), 0, 8 ) ) % count( $variants );
		$key      = array(
			'experiment_id' => $id,
			'actor'         => $actor,
		);
		$this->db->unique(
			'assignments',
			$key + array(
				'variant'    => $variants[ $index ],
				'created_at' => $now,
			),
			$key
		);
		$assignment = $this->db->one( 'assignments', $key );
		return array(
			'experiment_id' => $id,
			'variant'       => $assignment['variant'],
		);
	}
}
