<?php
namespace BuddyPressIntelligence;

final class Events {
	public const TYPES = array( 'member_registered', 'member_profile_updated', 'member_followed', 'member_unfollowed', 'member_blocked', 'member_muted', 'friendship_created', 'friendship_removed', 'group_joined', 'group_left', 'activity_created', 'activity_viewed', 'activity_commented', 'activity_favorited', 'activity_hidden', 'activity_not_interested', 'activity_reported', 'topic_followed', 'topic_unfollowed', 'search_performed', 'search_result_clicked', 'question_created', 'answer_created', 'answer_accepted', 'reputation_awarded', 'moderation_action_applied', 'report_created', 'onboarding_completed', 'recommendation_clicked', 'feedback_created' );
	private Database $db;
	private Jobs $jobs;
	private Settings $settings;
	public function __construct( Database $db, Jobs $jobs, Settings $settings ) {
		$this->db       = $db;
		$this->jobs     = $jobs;
		$this->settings = $settings;
	}
	public function record( string $type, int $actor, string $object_type, int $id, array $metadata = array(), string $key = '', string $source = 'plugin', string $privacy = 'private', int $secondary = 0, string $context = '' ): int {
		if ( ! in_array( $type, self::TYPES, true ) || ! in_array( $privacy, array( 'public', 'members', 'private' ), true ) || ! in_array( $source, array( 'plugin', 'buddypress', 'automation' ), true ) || $actor < 0 || $id < 0 || $secondary < 0 || strlen( $context ) > 48 || ! preg_match( '/^[a-z_]{1,24}$/', $object_type ) ) {
			throw new \InvalidArgumentException( 'Invalid event.' );
		}
		// No request payloads, query text, names, email, secrets, or message content.
		$clean = array();
		foreach ( array_intersect_key( $metadata, array_flip( array( 'topic_id', 'group_id', 'parent_id', 'value', 'variant', 'experiment_id', 'analytics' ) ) ) as $field => $value ) {
			if ( is_int( $value ) || is_bool( $value ) || ( is_string( $value ) && strlen( $value ) <= 32 && preg_match( '/^[a-z0-9_-]+$/i', $value ) ) ) {
				$clean[ $field ] = $value;
			}
		}
		$prefs              = get_user_meta( $actor, '_bpi_preferences', true );
		$clean['analytics'] = $actor > 0 && $this->settings->enabled( 'analytics' ) && $this->settings->all()['analytics_consent'] && ! empty( $prefs['analytics'] );
		if ( ! $clean['analytics'] && ! $this->settings->enabled( 'automations' ) ) {
			return 0;
		}
		$tracking = in_array( $type, array( 'activity_viewed', 'search_performed', 'search_result_clicked', 'recommendation_clicked' ), true );
		if ( $tracking && ! $clean['analytics'] ) {
			return 0;
		}
		$event_key = $key ? hash( 'sha256', $key ) : wp_generate_uuid4();
		return $this->db->transaction(
			function () use ( $event_key, $type, $actor, $object_type, $id, $secondary, $context, $source, $privacy, $clean ): int {
				$event = $this->db->unique(
					'events',
					array(
						'event_key'    => $event_key,
						'type'         => $type,
						'actor'        => $actor,
						'object_type'  => $object_type,
						'object_id'    => $id,
						'secondary_id' => $secondary,
						'context'      => $context,
						'source'       => $source,
						'privacy'      => $privacy,
						'metadata'     => wp_json_encode( $clean ),
						'created_at'   => gmdate( 'Y-m-d H:i:s' ),
						'processed'    => 0,
					),
					array( 'event_key' => $event_key )
				);
				if ( $this->settings->enabled( 'automations' ) ) {
					$this->jobs->enqueue( 'events', array( 'event_id' => $event ), 'event:' . $event );
				}
				return $event;
			}
		);
	}
}
