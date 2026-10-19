<?php
/**
 * Names Site Agent's connector identities for Belayline's Overview map.
 *
 * Belayline asks `belayline_map_who` about any identity token it does not
 * recognise itself (it already names application passwords, users, roles and
 * WooCommerce keys). A signed-in AI app is `oauth:<grant id>` here, so the map
 * can say "Claude" instead of a raw token. Only the app's display name is
 * returned: never the grant id, a token or a secret.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Connector\OAuth\Clients;
use Specflux\SiteAgent\Connector\OAuth\Store;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The `belayline_map_who` answer for signed-in AI apps.
 */
final class BelaylineWho {

	/**
	 * Filter callback.
	 *
	 * @param mixed $named Name another plugin already gave, or null.
	 * @param mixed $token The raw identity token.
	 * @return mixed `array{label: string, sub: string}` for a token that is ours, otherwise $named unchanged.
	 */
	public static function name( mixed $named, mixed $token = '' ): mixed {
		if ( is_array( $named ) || ! is_string( $token ) || ! str_starts_with( $token, 'oauth:' ) ) {
			return $named;
		}

		$grant = Store::grant( substr( $token, 6 ) );
		$label = null === $grant
			? __( 'AI app (signed out)', 'specflux-site-agent' )
			: Clients::resolveStoredName( (string) $grant['client'] );

		return array(
			'label' => $label,
			'sub'   => __( 'AI app', 'specflux-site-agent' ),
		);
	}
}
