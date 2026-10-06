<?php
namespace BuddyPressIntelligence;

final class Denied extends \RuntimeException {
	public function __construct() {
		parent::__construct( 'Object unavailable.' );
	}
}
