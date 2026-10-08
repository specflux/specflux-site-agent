<?php
/**
 * Test-only SenroGate stand-in; see ../agent-safety-pack.php.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\AgentSafety\Packs;

use Specflux\AgentSafety\Policy\Tier;

final class Pack {

	/**
	 * @param list<string>       $allow           Exact ability ids (no globs needed here).
	 * @param array<string,bool> $approvalByClass Tier class slug => requires approval.
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $allow,
		public readonly array $denyClass = array(),
		public readonly array $approvalByClass = array(),
	) {}

	public function allows( string $verb ): bool {
		return in_array( $verb, $this->allow, true );
	}

	public function requiresApproval( Tier $tier ): bool {
		return $this->approvalByClass[ $tier->classSlug() ] ?? false;
	}
}
