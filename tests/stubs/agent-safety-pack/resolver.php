<?php
/**
 * Test-only Belayline Agent Governance stand-in; see ../agent-safety-pack.php.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Belayline\Plugin\Support;

use Belayline\Packs\Pack;

final class TestPackRegistry {

	/** @return array<string,string> */
	public function bindings(): array {
		return $GLOBALS['specflux_site_agent_test_as_bindings'] ?? array();
	}

	public function get( string $name ): ?Pack {
		return ( $GLOBALS['specflux_site_agent_test_as_packs'] ?? array() )[ $name ] ?? null;
	}

	public function resolve( ?string $token ): ?Pack {
		unset( $token );

		return null;
	}
}

if ( ! class_exists( PackResolver::class ) ) {
	final class PackResolver {
		public function registry(): TestPackRegistry {
			return new TestPackRegistry();
		}
	}
}
