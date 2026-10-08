<?php
/**
 * A fixed-window counter.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

use Specflux\SenroFlux\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Counts hits per key inside a window that does NOT slide, so steady traffic cannot pin a key blocked forever. */
final class RateLimit {

	/**
	 * Record one hit; false when the key is over its limit for the current window.
	 *
	 * @param string $key    Counter name (hashed into the transient key).
	 * @param int    $limit  Hits allowed per window.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( string $key, int $limit, int $window ): bool {
		$name  = 'senroflux_oauth_rl_' . md5( $key );
		$now   = Clock::now();
		$state = get_transient( $name );
		if ( ! is_array( $state ) || (int) ( $state['until'] ?? 0 ) <= $now ) {
			$state = array(
				'n'     => 0,
				'until' => $now + $window,
			);
		}
		if ( (int) $state['n'] >= $limit ) {
			return false;
		}
		++$state['n'];
		set_transient( $name, $state, max( 1, (int) $state['until'] - $now ) );

		return true;
	}
}
