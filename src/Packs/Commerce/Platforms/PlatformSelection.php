<?php
/**
 * The outcome of picking a store platform for a run (C4-0).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Immutable selection result.
 *
 * @internal
 */
final class PlatformSelection {

	/** No active, governed platform. */
	public const NONE = 'none';

	/** Exactly one active, governed platform. */
	public const SINGLE = 'single';

	/** Several candidates; the admin's setting picked one. */
	public const CHOSEN = 'chosen';

	/** Several candidates and no (valid) setting: an admin must choose. */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * @param string             $status     One of the constants above.
	 * @param StorePlatform|null $platform   The picked platform; null for NONE/AMBIGUOUS.
	 * @param list<StorePlatform> $candidates Active, governed platforms.
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?StorePlatform $platform,
		public readonly array $candidates
	) {
	}
}
