<?php
/**
 * The exposure set per connection scope and gate mode.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\ExposureSet;
use Specflux\SiteAgent\Run\GateMode;

final class ExposureSetTest extends ConnectorTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->standardAbilities();
	}

	/** @return list<string> */
	private function visible( Connection $connection, GateMode $mode ): array {
		return array_values(
			array_filter(
				ExposureSet::names( $mode ),
				static fn ( string $name ): bool => ExposureSet::visibleTo( $name, $connection, $mode )
			)
		);
	}

	public function test_a_read_only_connection_sees_only_tier_zero_tools(): void {
		$visible = $this->visible( new Connection( 1, 'u', Connection::SCOPE_READ ), GateMode::AgentSafety );

		$this->assertSame( array( 'specflux/read-thing' ), $visible );
	}

	public function test_a_read_write_connection_sees_writes_too(): void {
		$visible = $this->visible( new Connection( 1, 'u', Connection::SCOPE_READ_WRITE ), GateMode::AgentSafety );

		$this->assertContains( 'specflux/read-thing', $visible );
		$this->assertContains( 'specflux/write-thing', $visible );
	}

	public function test_built_in_mode_hides_commerce_writes_but_agent_governance_mode_shows_them(): void {
		$rw = new Connection( 1, 'u', Connection::SCOPE_READ_WRITE );

		$this->assertNotContains( 'shop/refund', $this->visible( $rw, GateMode::BuiltIn ) );
		$this->assertContains( 'shop/refund', $this->visible( $rw, GateMode::AgentSafety ) );
		// The ordinary write is still there in built-in mode, so the hiding is specific.
		$this->assertContains( 'specflux/write-thing', $this->visible( $rw, GateMode::BuiltIn ) );
	}

	public function test_an_unlisted_ability_is_never_visible(): void {
		$this->ability( 'other/thing' );

		$this->assertFalse( ExposureSet::visibleTo( 'other/thing', new Connection( 1, 'u', Connection::SCOPE_READ_WRITE ), GateMode::AgentSafety ) );
	}

	public function test_names_are_memoised_per_mode_until_reset(): void {
		$before = ExposureSet::names( GateMode::BuiltIn );
		$this->ability( 'specflux/late-thing' );

		$this->assertSame( $before, ExposureSet::names( GateMode::BuiltIn ) );

		ExposureSet::reset();
		$this->assertContains( 'specflux/late-thing', ExposureSet::names( GateMode::BuiltIn ) );
	}
}
