<?php
namespace BuddyPressIntelligence;

final class DisabledProvider implements IntelligenceProvider {
	public function process( string $capability, string $public_text ): array {
		return array(
			'available' => false,
			'data'      => array(),
		);
	}
}
