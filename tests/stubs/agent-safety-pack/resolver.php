<?php
/**
 * Test-only SenroGate stand-in; see ../agent-safety-pack.php.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Packs\Pack;

final class TestPackRegistry {

	/** @return array<string,string> */
	public function bindings(): array {
		return $GLOBALS['senroflux_test_as_bindings'] ?? array();
	}

	public function get( string $name ): ?Pack {
		return ( $GLOBALS['senroflux_test_as_packs'] ?? array() )[ $name ] ?? null;
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
