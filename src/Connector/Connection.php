<?php
/**
 * One resolved connector connection (K1).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Who is calling and with what reach: the user, the Specflux Site Agent-created
 * application password's UUID, and its scope. Immutable.
 */
final class Connection {

	/** Reads only. The default, and where anything unrecognised lands. */
	public const SCOPE_READ = 'read';

	/** Reads and writes (writes still pass the connector gate). */
	public const SCOPE_READ_WRITE = 'read_write';

	public function __construct(
		public readonly int $userId,
		public readonly string $uuid,
		public readonly string $scope,
	) {
	}

	/** Coerce any stored value to a known scope; fail closed to read-only. */
	public static function sanitizeScope( mixed $scope ): string {
		return self::SCOPE_READ_WRITE === $scope ? self::SCOPE_READ_WRITE : self::SCOPE_READ;
	}

	/** May this connection call anything above a read? */
	public function mayWrite(): bool {
		return self::SCOPE_READ_WRITE === $this->scope;
	}
}
