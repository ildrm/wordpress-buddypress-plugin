<?php
namespace BuddyPressIntelligence;

/** Capability-based V1 contract; no vendor SDK or provider-owned authorization. */
interface IntelligenceProvider {
	public function process( string $capability, string $public_text ): array;
}
