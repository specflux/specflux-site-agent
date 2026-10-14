<?php
/**
 * The built-in per-connection rate limit (K4).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * 60 calls a minute per connection, in built-in mode only (Belayline Agent Governance does its
 * own limiting). A fixed one-minute window keyed by connection: simple, cheap,
 * and good enough to stop a runaway loop. Counted in a transient per window,
 * so it is approximate under concurrency (a burst can overshoot by a few) and
 * a caller can fit up to twice the limit around a window boundary.
 */
final class RateLimit {

	/** Calls allowed per window. */
	public const LIMIT = 60;

	/** Window length in seconds. */
	public const WINDOW = 60;

	/**
	 * Count one call and say whether it may go on.
	 *
	 * @param string $uuid The connection's UUID.
	 * @return int|null Null when allowed; otherwise the seconds until the next window.
	 */
	public static function hit( string $uuid ): ?int {
		$now    = Clock::now();
		$window = intdiv( $now, self::WINDOW );
		$key    = 'specflux_site_agent_rl_' . md5( $uuid ) . '_' . $window;
		$count  = (int) get_transient( $key );

		if ( $count >= self::LIMIT ) {
			return ( $window + 1 ) * self::WINDOW - $now;
		}

		set_transient( $key, $count + 1, self::WINDOW * 2 );

		return null;
	}
}
