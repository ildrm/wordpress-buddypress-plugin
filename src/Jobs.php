<?php
namespace BuddyPressIntelligence;

final class Jobs {
	private Database $db;
	private Settings $settings;
	private array $handlers = array();
	public function __construct( Database $db, Settings $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}
	public function handlers( array $handlers ): void {
		$this->handlers = $handlers;
	}
	public function enqueue( string $kind, array $payload, string $key ): int {
		if ( ! in_array( $kind, array( 'events', 'rollup' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported job.' );
		}
		$id = $this->db->unique(
			'jobs',
			array(
				'job_key'      => $key,
				'kind'         => $kind,
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'attempts'     => 0,
				'available_at' => gmdate( 'Y-m-d H:i:s' ),
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'job_key' => $key )
		);
		Core::schedule( 'bpi_work' );
		return $id;
	}
	public function register(): void {
		add_action( 'bpi_work', array( $this, 'work' ) );
		add_action( 'bpi_maintenance', array( $this, 'maintenance' ) );
	}
	public function work(): int {
		$switched = get_current_blog_id() !== Core::root();
		if ( $switched ) {
			switch_to_blog( Core::root() );
		}
		try {
			$table = $this->db->table( 'jobs' );
			$now   = gmdate( 'Y-m-d H:i:s' );
			$this->db->query( "UPDATE $table SET status='pending',locked_at=NULL WHERE status='running' AND locked_at<%s AND attempts<5 LIMIT 50", array( gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
			$this->db->query( "UPDATE $table SET status='failed',error='lease_exhausted' WHERE status='running' AND locked_at<%s AND attempts>=5 LIMIT 50", array( gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
			$jobs     = $this->db->select( "SELECT * FROM $table WHERE status='pending' AND available_at<=%s ORDER BY available_at ASC,id ASC LIMIT 25", array( $now ) );
			$count    = 0;
			$deadline = microtime( true ) + 15;
			foreach ( $jobs as $job ) {
				if ( microtime( true ) > $deadline ) {
					break;
				}
				if ( ! $this->db->query( "UPDATE $table SET status='running',locked_at=%s,attempts=attempts+1 WHERE id=%d AND status='pending' AND available_at<=%s AND attempts=%d", array( $now, $job['id'], $now, $job['attempts'] ) ) ) {
					continue;
				}
				try {
					if ( ! isset( $this->handlers[ $job['kind'] ] ) ) {
						throw new \RuntimeException( 'Unknown handler.' );
					}
					call_user_func( $this->handlers[ $job['kind'] ], json_decode( $job['payload'], true ) ?: array() );
					$this->db->update(
						'jobs',
						array(
							'status' => 'done',
							'error'  => '',
						),
						array(
							'id'        => $job['id'],
							'status'    => 'running',
							'locked_at' => $now,
							'attempts'  => (int) $job['attempts'] + 1,
						)
					);
				} catch ( \Throwable $e ) {
					$attempts = (int) $job['attempts'] + 1;
					$this->db->update(
						'jobs',
						array(
							'status'       => $attempts >= 5 ? 'failed' : 'pending',
							'available_at' => gmdate( 'Y-m-d H:i:s', time() + min( 3600, 30 * 2 ** $attempts ) ),
							'error'        => 'handler_failed',
							'locked_at'    => null,
						),
						array(
							'id'        => $job['id'],
							'status'    => 'running',
							'locked_at' => $now,
							'attempts'  => $attempts,
						)
					);
					do_action( 'bpi_job_failed', (int) $job['id'], $job['kind'], $attempts );
				}
				++$count;
			}
			if ( $this->db->one( 'jobs', array( 'status' => 'pending' ) ) && ! wp_next_scheduled( 'bpi_work' ) ) {
				wp_schedule_single_event( time() + 60, 'bpi_work' );
			}
			return $count;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	public function maintenance(): void {
		$settings = $this->settings->all();
		if ( $this->settings->enabled( 'analytics' ) && $settings['analytics_consent'] ) {
			$this->enqueue( 'rollup', array( 'day' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ), 'rollup:' . gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) );
		}
		foreach ( array(
			'events'     => $settings['event_days'],
			'aggregates' => $settings['analytics_days'],
			'jobs'       => 30,
			'limits'     => 1,
		) as $table => $days ) {
			$extra = 'jobs' === $table ? " AND status IN ('done','failed')" : '';
			$this->db->query( 'DELETE FROM ' . $this->db->table( $table ) . " WHERE created_at<%s$extra LIMIT 1000", array( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		}
		$this->db->query( 'DELETE FROM ' . $this->db->table( 'edges' ) . ' WHERE expires<%s LIMIT 1000', array( gmdate( 'Y-m-d H:i:s' ) ) );
		$this->work();
	}
	/** Atomic member buckets, independent of IP and persistent object caching. */
	public function rateLimit( int $actor, string $action, int $maximum = 60, int $seconds = 60 ): bool {
		$window = (int) floor( time() / $seconds );
		$bucket = hash( 'sha256', "$actor:$action:$window" );
		$table  = $this->db->table( 'limits' );
		$this->db->query( "INSERT INTO $table (bucket,hits,expires,created_at) VALUES (%s,1,%s,%s) ON DUPLICATE KEY UPDATE hits=hits+1", array( $bucket, gmdate( 'Y-m-d H:i:s', ( $window + 1 ) * $seconds ), gmdate( 'Y-m-d H:i:s' ) ) );
		$row = $this->db->one( 'limits', array( 'bucket' => $bucket ) );
		return $row && (int) $row['hits'] <= $maximum;
	}
}
