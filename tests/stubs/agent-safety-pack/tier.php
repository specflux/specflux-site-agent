<?php
/**
 * Test-only SenroGate stand-in; see ../agent-safety-pack.php.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\AgentSafety\Policy;

enum Tier: int {
	case Reversible    = 0;
	case SideEffecting = 1;
	case Irreversible  = 2;

	public function classSlug(): string {
		return 'tier' . $this->value;
	}
}
