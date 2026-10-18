<?php
/**
 * The hand-off sits in Plugin::tick(), the one path ajax, REST and the
 * background driver all take.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Background;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Background\BackgroundDriver;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tests\Run\FakeGateway;
use Specflux\SiteAgent\Tests\Run\RecordingBridge;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WP_Error;
use wpdb;

final class PluginHandOffTest extends TestCase {

	private const T0 = 1_800_000_000;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( false );
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;
		$this->gateway   = new FakeGateway();
		$this->runner    = new Runner( new WpdbRunStore( $db ), new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $this->runner );

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_as']              = array();
		$GLOBALS['specflux_site_agent_test_as_version']      = '4.2.0';
		Clock::useFixed( self::T0 );
	}

	protected function tearDown(): void {
		Clock::reset();
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['specflux_site_agent_test_as'] = array();
	}

	public function test_a_tick_that_leaves_the_run_running_queues_one_background_action_per_driver(): void {
		$run_id = $this->runner->store()->createRun( 1, 'test-consumer', 'Go', array( 'agsafe-smoke/*' ), Budget::defaults() );
		// A transient model error leaves the run running with retry_after 2.
		$this->gateway->script[] = new WP_Error( 'gateway_failed', 'cURL error 28: Operation timed out', array( 'status' => 0 ) );

		$result = Plugin::instance()->tick( $run_id, 0, null, 'screen' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['continue'] );
		$queued = array_values( $GLOBALS['specflux_site_agent_test_as'] );
		$this->assertCount( 1, $queued );
		$this->assertSame( BackgroundDriver::HOOK, $queued[0]['hook'] );
		$this->assertSame( array( 'run_id' => $run_id ), $queued[0]['args'] );
		$this->assertSame( self::T0 + max( 20, $result['retry_after'] ), $queued[0]['when'] );
	}

	public function test_a_finished_run_queues_nothing(): void {
		$run_id                  = $this->runner->store()->createRun( 1, 'test-consumer', 'Go', array( 'agsafe-smoke/*' ), Budget::defaults() );
		$this->gateway->script[] = new \Specflux\SiteAgent\Model\ModelTurn( new \WordPress\AiClient\Messages\DTO\ModelMessage( array( new \WordPress\AiClient\Messages\DTO\MessagePart( 'Done.' ) ) ), 1, 1 );
		$this->gateway->script[] = $this->gateway->script[0];

		$result = Plugin::instance()->tick( $run_id, 0, null, 'screen' );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['continue'] );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_as'] );
	}
}
