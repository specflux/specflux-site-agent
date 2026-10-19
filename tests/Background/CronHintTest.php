<?php
/**
 * The Runs-screen real-cron hint: shown only on a default WP-Cron site that
 * has handed a run to the background, until the person dismisses it.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Background;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Background\BackgroundDriver;
use Specflux\SiteAgent\Background\CronHint;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tests\Run\FakeGateway;
use Specflux\SiteAgent\Tests\Run\RecordingBridge;
use wpdb;

final class CronHintTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wpdb']                                = new wpdb();
		$GLOBALS['specflux_site_agent_test_options']    = array();
		$GLOBALS['specflux_site_agent_test_user_meta']  = array();
		$GLOBALS['specflux_site_agent_test_as']         = array();
		$GLOBALS['specflux_site_agent_test_as_version'] = '4.2.0';
	}

	public function test_hidden_until_a_run_has_been_handed_to_the_background(): void {
		$this->assertFalse( CronHint::shouldShow( 1, false ) );
	}

	public function test_shown_once_background_was_used_on_a_default_wp_cron_site(): void {
		CronHint::markBackgroundUsed();

		$this->assertTrue( CronHint::shouldShow( 1, false ) );
	}

	public function test_hidden_when_wp_cron_is_disabled(): void {
		CronHint::markBackgroundUsed();

		$this->assertFalse( CronHint::shouldShow( 1, true ) );
	}

	public function test_dismiss_hides_it_for_that_user_only(): void {
		CronHint::markBackgroundUsed();
		CronHint::dismiss( 1 );

		$this->assertFalse( CronHint::shouldShow( 1, false ) );
		$this->assertTrue( CronHint::shouldShow( 2, false ), 'another user still sees it' );
	}

	public function test_queueing_a_background_tick_marks_it_used(): void {
		$db     = new wpdb();
		$store  = new WpdbRunStore( $db );
		$runner = new Runner( $store, new ToolExecutor(), new FakeGateway(), new RecordingBridge() );
		$driver = new BackgroundDriver( $store, $runner, static fn (): array => array() );

		$this->assertFalse( CronHint::shouldShow( 1, false ) );
		$this->assertTrue( $driver->schedule( 5, 0 ) );
		$this->assertTrue( CronHint::shouldShow( 1, false ) );
	}
}
