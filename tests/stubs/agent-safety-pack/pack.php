<?php
/**
 * Test-only Belayline Agent Governance stand-in; see ../agent-safety-pack.php.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Belayline\Packs;

use Belayline\Policy\Tier;

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
