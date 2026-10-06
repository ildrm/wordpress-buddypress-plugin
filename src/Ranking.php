<?php
namespace BuddyPressIntelligence;

/** Pure deterministic math; callers provide already authorized candidates. */
final class Ranking {
	public static function score( array $signals, array $weights, float $penalty = 0 ): float {
		$total = 0.0;
		$sum   = 0.0;
		foreach ( $weights as $key => $weight ) {
			$weight = max( 0.0, (float) $weight );
			$value  = max( 0.0, min( 1.0, (float) ( $signals[ $key ] ?? 0 ) ) );
			$total += $weight * $value;
			$sum   += $weight;
		}
		return round( max( 0.0, ( $sum > 0 ? $total / $sum : 0.0 ) - max( 0.0, $penalty ) ), 8 );
	}
	public static function recency( int $timestamp, int $now, float $half_life_hours = 72 ): float {
		return 2 ** ( -max( 0, $now - $timestamp ) / max( 1, $half_life_hours * 3600 ) );
	}
	public static function strength( bool $follow, bool $mutual, bool $friend, float $distinct_interactions, float $decay ): float {
		return min( 1.0, ( 0.3 * (int) $follow + 0.2 * (int) $mutual + 0.3 * (int) $friend + 0.2 * min( 1, $distinct_interactions / 10 ) ) * max( 0, min( 1, $decay ) ) );
	}
	public static function trend( int $participants, int $replies, float $age_hours ): float {
		return min( 1.0, log( 1 + max( 0, $participants ) + min( max( 0, $replies ), 2 * max( 0, $participants ) ) ) / 6 ) * 2 ** ( -max( 0, $age_hours ) / 24 );
	}
	public static function sort( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				$score = $b['score'] <=> $a['score'];
				return $score ?: strcmp( $a['type'], $b['type'] ) ?: ( $b['id'] <=> $a['id'] );
			}
		);
		return $items;
	}
	public static function diversify( array $items, int $limit ): array {
		$result       = array();
		$authors      = array();
		$groups       = array();
		$topics       = array();
		$result_count = 0;
		while ( $items && $result_count < $limit ) {
			$chosen = null;
			foreach ( $items as $index => $item ) {
				$author = (int) ( $item['author'] ?? 0 );
				$group  = (int) ( $item['group_id'] ?? 0 );
				$topic  = (int) ( $item['topic_id'] ?? 0 );
				$length = count( $result );
				if ( $author && ( $authors[ $author ] ?? 0 ) >= max( 2, (int) ceil( $limit * 0.4 ) ) ) {
					continue;
				}
				if ( $length > 1 && $author && $result[ $length - 1 ]['author'] === $author && $result[ $length - 2 ]['author'] === $author ) {
					continue;
				}
				if ( $group && ( $groups[ $group ] ?? 0 ) >= max( 2, (int) ceil( $limit * 0.4 ) ) ) {
					continue;
				}
				if ( $topic && ( $topics[ $topic ] ?? 0 ) >= max( 2, (int) ceil( $limit * 0.6 ) ) ) {
					continue;
				}
				$chosen = $index;
				break;
			}
			if ( null === $chosen ) {
				break;
			}
			$item     = $items[ $chosen ];
			$result[] = $item;
			++$result_count;
			$authors[ $item['author'] ]  = ( $authors[ $item['author'] ] ?? 0 ) + 1;
			$groups[ $item['group_id'] ] = ( $groups[ $item['group_id'] ] ?? 0 ) + 1;
			$topic                       = $item['topic_id'] ?? 0;
			$topics[ $topic ]            = ( $topics[ $topic ] ?? 0 ) + 1;
			unset( $items[ $chosen ] );
		}
		return $result;
	}
}
