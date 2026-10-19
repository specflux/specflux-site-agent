<?php
/**
 * Offers Belayline an identity provider for signed-in AI apps, when Belayline is there.
 *
 * Kept apart from {@see BelaylineIdentity} on purpose: that class implements
 * Belayline's interface, so merely autoloading it without Belayline would be a
 * fatal error. This class has no such dependency and is always safe to call.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The `belayline_identity_providers` registration.
 */
final class BelaylineSeam {

	/** Belayline's interface; its presence is the only signal that Belayline is active. */
	public const INTERFACE = 'Belayline\\Plugin\\Identity\\IdentityProvider';

	/** Is Belayline's identity seam available in this request? */
	public static function available(): bool {
		return interface_exists( self::INTERFACE );
	}

	/**
	 * `belayline_identity_providers` callback.
	 *
	 * @param mixed $providers Providers contributed so far.
	 * @return mixed The list, plus the AI-app provider when Belayline's interface exists.
	 */
	public static function contribute( mixed $providers ): mixed {
		if ( ! is_array( $providers ) || ! self::available() ) {
			return $providers;
		}
		$providers[] = new BelaylineIdentity();

		return $providers;
	}
}
