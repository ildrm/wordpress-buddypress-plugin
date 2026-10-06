<?php
namespace BuddyPressIntelligence;

/** Prepared, bounded persistence. Table/column names never come from requests. */
final class Database {
	public const COLUMNS = array(
		'edges'         => 'id actor kind object_type object_id expires created_at',
		'topics'        => 'id slug name description parent_id aliases status created_at',
		'object_topics' => 'id object_type object_id topic_id strength created_at',
		'events'        => 'id event_key type actor object_type object_id secondary_id context source privacy metadata created_at processed',
		'feedback'      => 'id actor object_type object_id value created_at',
		'ledger'        => 'id event_key actor peer_id topic_id object_id kind points created_at',
		'entries'       => 'id kind parent_id author group_id title body status accepted_id revision created_at updated_at',
		'votes'         => 'id actor object_id created_at',
		'cases'         => 'id object_type object_id subject status assigned priority severity evidence created_at updated_at',
		'reports'       => 'id case_id actor category description created_at',
		'audit'         => 'id case_id actor action note previous_state created_at',
		'appeals'       => 'id case_id actor reason status reviewer decision created_at',
		'rules'         => 'id name trigger_type conditions action config enabled dry_run created_at',
		'runs'          => 'id rule_id event_id status attempts error created_at updated_at',
		'jobs'          => 'id job_key kind payload status attempts available_at locked_at error created_at',
		'aggregates'    => 'id day metric dimension value created_at',
		'experiments'   => 'id name variants metric status starts_at ends_at created_at',
		'assignments'   => 'id experiment_id actor variant created_at',
		'limits'        => 'id bucket hits expires created_at',
	);
	private \wpdb $db;
	private string $prefix;
	private int $depth = 0;
	public function __construct() {
		global $wpdb;
		$this->db     = $wpdb;
		$this->prefix = $wpdb->get_blog_prefix( Core::root() ) . 'bpi_';
	}
	public function table( string $table ): string {
		if ( ! isset( self::COLUMNS[ $table ] ) ) {
			throw new \InvalidArgumentException( 'Unknown table.' );
		}
		return $this->prefix . $table;
	}
	private function columns( string $table, array $data ): void {
		$this->table( $table );
		if ( array_diff( array_keys( $data ), explode( ' ', self::COLUMNS[ $table ] ) ) ) {
			throw new \InvalidArgumentException( 'Unknown column.' );
		}
	}
	public function insert( string $table, array $data ): int {
		$this->columns( $table, $data );
		$result = $this->db->insert( $this->table( $table ), $data );
		if ( false === $result ) {
			throw new \RuntimeException( 'Database write failed.' );
		}
		return (int) $this->db->insert_id;
	}
	/** Return existing row on uniqueness conflict, without hiding other errors. */
	public function unique( string $table, array $data, array $key ): int {
		$this->columns( $table, $data );
		$this->columns( $table, $key );
		if ( ! $key || array_diff_assoc( $key, $data ) ) {
			throw new \InvalidArgumentException( 'Uniqueness key must match inserted values.' );
		}
		$fields = array_keys( $data );
		$sql    = 'INSERT INTO ' . $this->table( $table ) . ' (`' . implode( '`,`', $fields ) . '`) VALUES (' . implode( ',', array_fill( 0, count( $fields ), '%s' ) ) . ') ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)';
		if ( false === $this->db->query( $this->db->prepare( $sql, array_values( $data ) ) ) ) {
			throw new \RuntimeException( 'Database write failed.' );
		}
		return (int) $this->db->insert_id;
	}
	public function update( string $table, array $data, array $where ): bool {
		$this->columns( $table, $data );
		$this->columns( $table, $where );
		if ( ! $where ) {
			throw new \InvalidArgumentException( 'Unbounded update.' );
		}
		$result = $this->db->update( $this->table( $table ), $data, $where );
		if ( false === $result ) {
			throw new \RuntimeException( 'Database update failed.' );
		}
		return $result > 0;
	}
	public function delete( string $table, array $where ): void {
		$this->columns( $table, $where );
		if ( ! $where || false === $this->db->delete( $this->table( $table ), $where ) ) {
			throw new \RuntimeException( 'Database delete failed.' );
		}
	}
	public function rows( string $table, array $where = array(), int $limit = 50, int $offset = 0, string $order = 'id DESC' ): array {
		$this->columns( $table, $where );
		if ( ! in_array( $order, array( 'id DESC', 'id ASC', 'created_at DESC', 'created_at ASC' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid order.' );
		}
		$clauses = array();
		$args    = array();
		foreach ( $where as $column => $value ) {
			$clauses[] = "`$column` = %s";
			$args[]    = $value;
		}
		$args[] = min( 500, max( 1, $limit ) );
		$args[] = max( 0, $offset );
		$sql    = 'SELECT * FROM ' . $this->table( $table ) . ( $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '' ) . " ORDER BY $order LIMIT %d OFFSET %d";
		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}
	public function one( string $table, array $where ): ?array {
		return $this->rows( $table, $where, 1 )[0] ?? null;
	}
	/** Internal SQL only; callers supply constant fragments and prepared values. */
	public function query( string $sql, array $args = array() ): int {
		$result = $this->db->query( $args ? $this->db->prepare( $sql, $args ) : $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Database operation failed.' );
		}
		return (int) $result;
	}
	public function select( string $sql, array $args ): array {
		return $this->db->get_results( $this->db->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}
	public function transaction( callable $operation ) {
		$depth = $this->depth++;
		$this->query( $depth ? 'SAVEPOINT bpi_' . $depth : 'START TRANSACTION' );
		try {
			$result = $operation();
			$this->query( $depth ? 'RELEASE SAVEPOINT bpi_' . $depth : 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$this->query( $depth ? 'ROLLBACK TO SAVEPOINT bpi_' . $depth : 'ROLLBACK' );
			throw $e;
		} finally {
			--$this->depth;
		}
	}
	public function migrate(): void {
		if ( (int) Core::option( 'bpi_schema' ) > 1 ) {
			throw new \RuntimeException( 'A newer schema cannot be downgraded.' );
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$definitions = array(
			'edges'         => 'actor bigint unsigned NOT NULL, kind varchar(16) NOT NULL, object_type varchar(24) NOT NULL, object_id bigint unsigned NOT NULL, expires datetime NULL, UNIQUE KEY relation (actor,kind,object_type,object_id), KEY reverse_edge (object_type,object_id,kind,actor), KEY expires (expires)',
			'topics'        => "slug varchar(100) NOT NULL, name varchar(120) NOT NULL, description text NOT NULL, parent_id bigint unsigned NOT NULL DEFAULT 0, aliases text NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', UNIQUE KEY slug (slug), KEY status_parent (status,parent_id)",
			'object_topics' => 'object_type varchar(24) NOT NULL, object_id bigint unsigned NOT NULL, topic_id bigint unsigned NOT NULL, strength decimal(6,4) NOT NULL DEFAULT 1, UNIQUE KEY mapping (object_type,object_id,topic_id), KEY topic_object (topic_id,object_type,object_id)',
			'events'        => "event_key varchar(64) NOT NULL, type varchar(48) NOT NULL, actor bigint unsigned NOT NULL DEFAULT 0, object_type varchar(24) NOT NULL, object_id bigint unsigned NOT NULL DEFAULT 0, secondary_id bigint unsigned NOT NULL DEFAULT 0, context varchar(48) NOT NULL DEFAULT '', source varchar(24) NOT NULL, privacy varchar(16) NOT NULL, metadata text NOT NULL, processed tinyint NOT NULL DEFAULT 0, UNIQUE KEY event_key (event_key), KEY pending (processed,id), KEY actor_time (actor,created_at), KEY type_time (type,created_at)",
			'feedback'      => 'actor bigint unsigned NOT NULL, object_type varchar(24) NOT NULL, object_id bigint unsigned NOT NULL, value varchar(16) NOT NULL, UNIQUE KEY feedback (actor,object_type,object_id)',
			'ledger'        => 'event_key varchar(100) NOT NULL, actor bigint unsigned NOT NULL, peer_id bigint unsigned NOT NULL DEFAULT 0, topic_id bigint unsigned NOT NULL DEFAULT 0, object_id bigint unsigned NOT NULL DEFAULT 0, kind varchar(24) NOT NULL, points int NOT NULL, UNIQUE KEY event_key (event_key), KEY score (actor,topic_id,created_at), KEY peer_credit (actor,peer_id,created_at), KEY object_id (object_id)',
			'entries'       => "kind varchar(16) NOT NULL, parent_id bigint unsigned NOT NULL DEFAULT 0, author bigint unsigned NOT NULL, group_id bigint unsigned NOT NULL DEFAULT 0, title varchar(200) NOT NULL, body longtext NOT NULL, status varchar(16) NOT NULL DEFAULT 'published', accepted_id bigint unsigned NOT NULL DEFAULT 0, revision int unsigned NOT NULL DEFAULT 0, updated_at datetime NOT NULL, KEY listing (kind,status,id), KEY thread (parent_id,kind,id), KEY author (author), KEY group_id (group_id)",
			'votes'         => 'actor bigint unsigned NOT NULL, object_id bigint unsigned NOT NULL, UNIQUE KEY vote (actor,object_id), KEY object_id (object_id)',
			'cases'         => "object_type varchar(24) NOT NULL, object_id bigint unsigned NOT NULL, subject bigint unsigned NOT NULL DEFAULT 0, status varchar(16) NOT NULL DEFAULT 'open', assigned bigint unsigned NOT NULL DEFAULT 0, priority tinyint NOT NULL DEFAULT 2, severity tinyint NOT NULL DEFAULT 2, evidence text NOT NULL, updated_at datetime NOT NULL, UNIQUE KEY object_case (object_type,object_id), KEY workload (status,updated_at), KEY subject (subject)",
			'reports'       => 'case_id bigint unsigned NOT NULL, actor bigint unsigned NOT NULL, category varchar(32) NOT NULL, description text NOT NULL, UNIQUE KEY reporter_case (case_id,actor), KEY actor (actor)',
			'audit'         => 'case_id bigint unsigned NOT NULL, actor bigint unsigned NOT NULL, action varchar(32) NOT NULL, note text NOT NULL, previous_state text NOT NULL, KEY case_id (case_id,id), KEY actor (actor)',
			'appeals'       => "case_id bigint unsigned NOT NULL, actor bigint unsigned NOT NULL, reason text NOT NULL, status varchar(16) NOT NULL DEFAULT 'pending', reviewer bigint unsigned NOT NULL DEFAULT 0, decision text NOT NULL, UNIQUE KEY case_actor (case_id,actor)",
			'rules'         => 'name varchar(120) NOT NULL, trigger_type varchar(48) NOT NULL, conditions text NOT NULL, action varchar(32) NOT NULL, config text NOT NULL, enabled tinyint NOT NULL DEFAULT 0, dry_run tinyint NOT NULL DEFAULT 1, KEY triggers (enabled,trigger_type)',
			'runs'          => "rule_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL, status varchar(16) NOT NULL, attempts tinyint NOT NULL DEFAULT 0, error varchar(64) NOT NULL DEFAULT '', updated_at datetime NOT NULL, UNIQUE KEY execution (rule_id,event_id), KEY failures (status,id)",
			'jobs'          => "job_key varchar(100) NOT NULL, kind varchar(32) NOT NULL, payload text NOT NULL, status varchar(16) NOT NULL DEFAULT 'pending', attempts tinyint NOT NULL DEFAULT 0, available_at datetime NOT NULL, locked_at datetime NULL, error varchar(64) NOT NULL DEFAULT '', UNIQUE KEY job_key (job_key), KEY queue (status,available_at,id)",
			'aggregates'    => 'day date NOT NULL, metric varchar(48) NOT NULL, dimension varchar(100) NOT NULL, value decimal(18,4) NOT NULL, UNIQUE KEY aggregate_key (day,metric,dimension)',
			'experiments'   => 'name varchar(120) NOT NULL, variants text NOT NULL, metric varchar(32) NOT NULL, status varchar(16) NOT NULL, starts_at datetime NOT NULL, ends_at datetime NOT NULL',
			'assignments'   => 'experiment_id bigint unsigned NOT NULL, actor bigint unsigned NOT NULL, variant varchar(32) NOT NULL, UNIQUE KEY assignment (experiment_id,actor), KEY actor (actor)',
			'limits'        => 'bucket varchar(100) NOT NULL, hits int unsigned NOT NULL DEFAULT 0, expires datetime NOT NULL, UNIQUE KEY bucket (bucket), KEY expires (expires)',
		);
		foreach ( $definitions as $name => $definition ) {
			$lines = preg_replace( '/, (?=[a-z_]+ (?:bigint|varchar|text|longtext|datetime|tinyint|decimal|date|int)\b)/', ",\n ", $definition );
			$lines = str_replace( ', UNIQUE KEY', ",\n UNIQUE KEY", str_replace( ', KEY', ",\n KEY", $lines ) );
			dbDelta( 'CREATE TABLE ' . $this->table( $name ) . " (\n id bigint unsigned NOT NULL AUTO_INCREMENT,\n created_at datetime NOT NULL,\n $lines,\n PRIMARY KEY  (id),\n KEY retention (created_at)\n) ENGINE=InnoDB " . $this->db->get_charset_collate() . ';' );
			if ( $this->db->last_error ) {
				throw new \RuntimeException( 'Schema migration failed.' );
			}
		}
		Core::option( 'bpi_schema', 1, true );
		Core::option( 'bpi_migration_error', '', true );
	}
}
