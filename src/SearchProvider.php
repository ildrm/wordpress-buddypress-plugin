<?php
namespace BuddyPressIntelligence;

/** V1 providers return normalized objects; central policy still filters all results. */
interface SearchProvider {
	public function candidates( string $type, int $limit, int $page, string $query ): array;
}
