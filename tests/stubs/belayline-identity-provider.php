<?php
/**
 * Declaration-only copy of Belayline's identity provider interface, for PHPStan
 * and for the tests that exercise the integration. Belayline is not installed
 * in this checkout; the real interface is plugin/src/Identity/IdentityProvider.php
 * in Belayline Agent Governance 0.5.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Belayline\Plugin\Identity;

if ( ! interface_exists( IdentityProvider::class, false ) ) {
	interface IdentityProvider {

		/** @return list<string> */
		public function currentTokens(): array;

		/** @return array<string, string> */
		public function bindableTokens(): array;

		public function label(): string;
	}
}
