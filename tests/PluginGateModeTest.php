<?php
/**
 * Plugin-level tests for 0.3 S3: a third-party consumer may not drive a
 * built-in-mode run, on either start() or tick().
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Model\ModelGatewayInterface;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\ToolRegistry;
use WP_Error;
use wpdb;

final class PluginGateModeTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_agent_safety']    = null;
	}

	protected function tearDown(): void {
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	/** Wires a real Runner (no model calls expected) into the singleton. */
	private function seedRunnerGraph(): Runner {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );

		return $runner;
	}

	public function test_a_third_party_consumer_cannot_start_a_built_in_mode_run(): void {
		Plugin::set_dependency_probe( false ); // Agent Safety absent => built-in mode.
		$this->seedRunnerGraph();

		$result = specflux_site_agent()->start( 'marketing-analytics-chat', 'Do a thing', array( 'agsafe-smoke/*' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_ungoverned', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? null );
	}

	public function test_the_runs_screen_s_own_consumer_can_start_a_built_in_mode_run(): void {
		Plugin::set_dependency_probe( false );
		$this->seedRunnerGraph();

		$result = specflux_site_agent()->start( RunsScreen::CONSUMER, 'Do a thing', array( 'agsafe-smoke/*' ) );

		$this->assertIsArray( $result );
	}

	public function test_agent_safety_mode_never_refuses_on_consumer_identity(): void {
		Plugin::set_dependency_probe( true ); // Agent Safety present => AS mode.
		$this->seedRunnerGraph();

		$result = specflux_site_agent()->start( 'marketing-analytics-chat', 'Do a thing', array( 'agsafe-smoke/*' ) );

		$this->assertIsArray( $result );
	}

	public function test_a_third_party_consumer_cannot_tick_a_built_in_mode_run(): void {
		Plugin::set_dependency_probe( false );
		$runner = $this->seedRunnerGraph();

		// Started by a third party directly against the store (bypassing the
		// start() refusal on purpose, the way a stale/misbehaving consumer
		// that already has a run id could) — tick() must refuse it too.
		$run_id = $runner->store()->createRun(
			1,
			'marketing-analytics-chat',
			'Do a thing',
			array( 'agsafe-smoke/*' ),
			\Specflux\SiteAgent\Run\Budget::defaults(),
			null,
			null,
			null,
			\Specflux\SiteAgent\Run\GateMode::BuiltIn
		);

		$result = specflux_site_agent()->tick( $run_id, 0, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_ungoverned', $result->get_error_code() );
	}
}
