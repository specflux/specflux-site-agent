<?php
/**
 * Tells Belayline which AI app (OAuth grant) is making the current call.
 *
 * Without this, a call authenticated by an OAuth bearer token is recorded as the
 * person who signed the app in. This file declares a class implementing
 * Belayline's interface, so it must only ever be loaded after
 * {@see BelaylineSeam::available()} said the interface exists; only
 * {@see BelaylineSeam::contribute()} instantiates it.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

use Belayline\Plugin\Identity\IdentityProvider;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Belayline identity provider for signed-in AI apps: token `oauth:<grant id>`.
 */
final class BelaylineIdentity implements IdentityProvider {

	/**
	 * The grant token for this request only when the OAuth bearer authenticated it
	 * (never from request input), otherwise nothing.
	 *
	 * @return list<string>
	 */
	public function currentTokens(): array {
		$grant = Bearer::activeGrant();

		return null === $grant ? array() : array( 'oauth:' . $grant );
	}

	/**
	 * Every live grant on this site, so an admin can bind an AI app to a pack.
	 *
	 * @return array<string,string> Token => app name.
	 */
	public function bindableTokens(): array {
		$out = array();
		foreach ( Store::grantIndex() as $id => $meta ) {
			$out[ 'oauth:' . $id ] = Clients::resolveStoredName( $meta['client'] );
		}

		return $out;
	}

	public function label(): string {
		return __( 'AI apps (Specflux Site Agent)', 'specflux-site-agent' );
	}
}
