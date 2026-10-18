<?php
/**
 * Bounded tick slice: a tick stops between loop iterations once its
 * wall-clock slice is spent and returns with the run still `running`.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class TickSliceTest extends TestCase {

	private const T0 = 1_800_000_000;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$this->store     = new WpdbRunStore( $db );
		$this->gateway   = new FakeGateway();
		$this->runner    = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_abilities']       = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		Clock::useFixed( self::T0 );
	}

	protected function tearDown(): void {
		Clock::reset();
		$GLOBALS['specflux_site_agent_test_filters'] = array();
	}

	private static function callTurn(): ModelTurn {
		return new ModelTurn(
			new ModelMessage(
				array(
					new MessagePart( 'Working on it...' ),
					new MessagePart( new FunctionCall( 'call_x', 'wpab__nope__missing', array() ) ),
				)
			),
			10,
			5
		);
	}

	private function createRun(): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Keep going',
			array( 'agsafe-smoke/*' ),
			array_merge(
				Budget::defaults(),
				array(
					'max_steps'      => 500,
					'max_tool_calls' => 500,
				)
			)
		);
	}

	/** Every model call burns $seconds of the pinned clock. */
	private function modelCallsTake( int $seconds ): void {
		$this->gateway->onCall = static function () use ( $seconds ): void {
			Clock::useFixed( Clock::now() + $seconds );
		};
	}

	public function test_a_tick_returns_after_the_slice_with_the_run_still_running(): void {
		$run_id = $this->createRun();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->gateway->script[] = self::callTurn();
		}
		$this->modelCallsTake( 15 );

		$first = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $first );
		$this->assertSame( 'running', $first['run']['status'] );
		$this->assertTrue( $first['continue'] );
		$this->assertSame( 0, $first['retry_after'] );
		$this->assertCount( 3, $this->gateway->calls, '15s + 15s + 15s crosses the 40s slice after the third model call + its tool batch' );
		$steps_after_first = $first['run']['step_count'];
		$this->assertGreaterThan( 1, $steps_after_first );
		$this->assertNull( $this->store->getRun( $run_id )?->lockToken, 'the claim is released between slices' );

		$second = $this->runner->tick( $run_id, $steps_after_first, null );

		$this->assertSame( 'running', $second['run']['status'] );
		$this->assertGreaterThan( $steps_after_first, $second['run']['step_count'], 'the next tick continues the same run' );
		$this->assertCount( 6, $this->gateway->calls );
	}

	public function test_a_slice_never_cuts_a_tool_batch_short(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new ModelTurn(
			new ModelMessage(
				array(
					new MessagePart( new FunctionCall( 'call_a', 'wpab__nope__missing', array() ) ),
					new MessagePart( new FunctionCall( 'call_b', 'wpab__nope__missing', array() ) ),
				)
			),
			10,
			5
		);
		$this->modelCallsTake( 500 ); // The slice is long gone once the model returns.

		$result = $this->runner->tick( $run_id, 0, null );

		$tool_results = array_filter( $result['new_steps'], static fn ( array $s ): bool => 'tool_result' === $s['kind'] );
		$this->assertCount( 2, $tool_results, 'both calls of the batch get their result before the tick yields' );
		$this->assertSame( 'running', $result['run']['status'] );
	}

	public function test_the_slice_defaults_to_forty_seconds(): void {
		$this->assertSame( 40, Runner::tickSliceSeconds() );
	}

	public function test_the_slice_filter_is_clamped_to_ten_through_two_hundred_forty(): void {
		add_filter( 'specflux_site_agent_tick_slice_seconds', static fn (): int => 3 );
		$this->assertSame( 10, Runner::tickSliceSeconds() );

		$GLOBALS['specflux_site_agent_test_filters'] = array();
		add_filter( 'specflux_site_agent_tick_slice_seconds', static fn (): int => 9999 );
		$this->assertSame( 240, Runner::tickSliceSeconds() );

		$GLOBALS['specflux_site_agent_test_filters'] = array();
		add_filter( 'specflux_site_agent_tick_slice_seconds', static fn (): int => 90 );
		$this->assertSame( 90, Runner::tickSliceSeconds() );

		$GLOBALS['specflux_site_agent_test_filters'] = array();
		add_filter( 'specflux_site_agent_tick_slice_seconds', static fn (): string => 'soon' );
		$this->assertSame( 40, Runner::tickSliceSeconds(), 'a non-numeric filter result falls back to the default' );
	}

	public function test_a_shorter_filtered_slice_yields_sooner(): void {
		add_filter( 'specflux_site_agent_tick_slice_seconds', static fn (): int => 10 );
		$run_id = $this->createRun();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->gateway->script[] = self::callTurn();
		}
		$this->modelCallsTake( 15 );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertCount( 1, $this->gateway->calls );
		$this->assertSame( 'running', $result['run']['status'] );
	}

	public function test_last_tick_at_and_driver_are_written_on_every_tick(): void {
		$run_id = $this->createRun();
		for ( $i = 0; $i < 20; $i++ ) {
			$this->gateway->script[] = self::callTurn();
		}
		$this->modelCallsTake( 15 );

		$first = $this->runner->tick( $run_id, 0, null, 'background' );
		$this->assertSame( 'background', $this->store->getRun( $run_id )?->lastDriver );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::T0 ), $this->store->getRun( $run_id )?->lastTickAtUtc );

		$this->runner->tick( $run_id, $first['run']['step_count'], null );
		$this->assertSame( 'screen', $this->store->getRun( $run_id )?->lastDriver );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', self::T0 + 45 ), $this->store->getRun( $run_id )?->lastTickAtUtc );
	}
}
