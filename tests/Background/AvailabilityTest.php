<?php
/**
 * Background-run availability and its reasons.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Background;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Background\Availability;

final class AvailabilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['specflux_site_agent_test_filters']    = array();
		$GLOBALS['specflux_site_agent_test_as_version'] = '4.2.0';
	}

	protected function tearDown(): void {
		$GLOBALS['specflux_site_agent_test_filters']    = array();
		$GLOBALS['specflux_site_agent_test_as_version'] = '4.2.0';
	}

	public function test_available_with_a_current_queue(): void {
		$this->assertTrue( Availability::available() );
		$this->assertSame( '', Availability::reason() );
		$this->assertSame(
			array(
				'available' => true,
				'reason'    => '',
			),
			Availability::status()
		);
	}

	public function test_the_kill_switch_reports_disabled_even_with_a_good_queue(): void {
		add_filter( 'specflux_site_agent_background_runs', static fn (): bool => false );

		$this->assertFalse( Availability::available() );
		$this->assertSame( 'disabled', Availability::reason() );
	}

	public function test_no_registered_version_reports_missing(): void {
		$GLOBALS['specflux_site_agent_test_as_version'] = false;

		$this->assertSame( 'missing', Availability::reason() );
	}

	public function test_a_queue_older_than_3_6_reports_too_old(): void {
		$GLOBALS['specflux_site_agent_test_as_version'] = '3.5.4';

		$this->assertSame( 'too_old', Availability::reason() );
		$this->assertFalse( Availability::available() );
	}

	public function test_3_6_0_exactly_is_enough(): void {
		$GLOBALS['specflux_site_agent_test_as_version'] = '3.6.0';

		$this->assertTrue( Availability::available() );
	}

	public function test_a_non_boolean_filter_result_is_not_a_yes(): void {
		add_filter( 'specflux_site_agent_background_runs', static fn (): string => '0' );

		$this->assertSame( 'disabled', Availability::reason() );
	}
}
