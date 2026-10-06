<?php
/**
 * Test-only stand-ins for SenroGate 0.5's change-history API (read from
 * `plugin/src/Api/Changes.php` and core `src/Changes/Undo*.php`). Only the
 * shapes SenroFlux consumes; no SenroGate logic is reproduced.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\AgentSafety\Changes;

final class UndoScope {
	public const CHANGE      = 'change';
	public const CORRELATION = 'correlation';

	private function __construct( public readonly string $kind, public readonly string $id ) {}

	public static function change( int $id ): self {
		return new self( self::CHANGE, (string) $id );
	}

	public static function correlation( string $id ): self {
		return new self( self::CORRELATION, $id );
	}
}

final class UndoItem {
	public function __construct(
		public readonly int $changeId,
		public readonly string $objectType,
		public readonly string $objectId,
		public readonly string $status,
		public readonly ?string $reason = null,
		public readonly array $drift = array(),
		public readonly ?string $message = null,
	) {}
}

final class UndoResult {
	public function __construct(
		public readonly string $status,
		public readonly array $items = array(),
		public readonly ?string $eventId = null,
		public readonly ?string $reason = null,
	) {}
}
