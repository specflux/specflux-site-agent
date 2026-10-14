<?php
/**
 * Test-only Belayline Agent Governance stand-in; see ../agent-safety-pack.php.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Belayline\Policy;

enum Tier: int {
	case Reversible    = 0;
	case SideEffecting = 1;
	case Irreversible  = 2;

	public function classSlug(): string {
		return 'tier' . $this->value;
	}
}
