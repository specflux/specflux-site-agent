<?php
/**
 * The background driver: hand-off scheduling, the queue handler, the
 * correlation-conflict policy, the stall sweep and unscheduling.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Background;

use Belayline\Plugin\Support\RequestContext;
use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Background\BackgroundDriver;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Schema;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tests\Run\FakeGateway;
use Specflux\SiteAgent\Tests\Run\RecordingBridge;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WP_Error;
use wpdb;

final class BackgroundDriverTest extends TestCase {

	private const T0 = 1_800_000_000;

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	private BackgroundDriver $driver;

	/** @var list<array{int,int,string}> Every tick the handler asked for: run, step_count, driver. */
	private array $ticks = array();

	/** @var callable(int,int,string):(array<string,mixed>|WP_Error) */
	private $tickResult;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$GLOBALS['wpdb']       = $this->db;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 0;
		$GLOBALS['specflux_site_agent_test_user_history']    = array();
		$GLOBALS['specflux_site_agent_test_as']              = array();
		$GLOBALS['specflux_site_agent_test_as_version']      = '4.2.0';
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_cron']            = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		$GLOBALS['specflux_site_agent_test_users']           = array( 1 => (object) array( 'ID' => 1 ) );
		$GLOBALS['specflux_site_agent_test_user_caps']       = array( 'manage_options' => true );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
		Clock::useFixed( self::T0 );
		RequestContext::reset();

		$this->ticks      = array();
		$this->tickResult = static fn (): array => array(
			'run'         => array( 'status' => 'running' ),
			'continue'    => true,
			'retry_after' => 0,
		);
		$this->driver     = new BackgroundDriver(
			$this->store,
			$this->runner,
			function ( int $run_id, int $step_count, string $driver ): array|WP_Error {
				$this->ticks[] = array( $run_id, $step_count, $driver );

				return ( $this->tickResult )( $run_id, $step_count, $driver );
			}
		);
	}

	protected function tearDown(): void {
		Clock::reset();
		remove_all_filters();
		RequestContext::reset();
		specflux_site_agent_test_no_agent_safety();
		$GLOBALS['specflux_site_agent_test_as']              = array();
		$GLOBALS['specflux_site_agent_test_user_caps']       = array();
		$GLOBALS['specflux_site_agent_test_users']           = array();
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 0;
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * `created_at` and the claim columns are not writable through updateRun()
	 * (only claimRun() touches the claim), so those are patched into the row.
	 *
	 * @param array<string,mixed> $fields Columns to set on the stored row.
	 */
	private function createRun( string $status = 'running', array $fields = array() ): int {
		$id = $this->store->createRun( 1, 'test-consumer', 'Keep going', array( 'agsafe-smoke/*' ), Budget::defaults() );
		$this->store->updateRun( $id, array( 'status' => $status ) + array_diff_key( $fields, array_flip( array( 'created_at', 'lock_until' ) ) ) );

		$table = Schema::runsTable( $this->db );
		foreach ( $this->db->tables[ $table ] as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->db->tables[ $table ][ $index ]['created_at'] = $fields['created_at'] ?? self::utc( self::T0 );
				if ( isset( $fields['lock_until'] ) ) {
					$this->db->tables[ $table ][ $index ]['lock_until'] = $fields['lock_until'];
				}
			}
		}

		return $id;
	}

	/** @return list<array<string,mixed>> */
	private function queued(): array {
		return array_values( $GLOBALS['specflux_site_agent_test_as'] );
	}

	private function continuing( int $retry_after = 0 ): array {
		return array(
			'run'         => array( 'status' => 'running' ),
			'continue'    => true,
			'retry_after' => $retry_after,
		);
	}

	private static function utc( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	// ------------------------------------------------------------------
	// Hand-off
	// ------------------------------------------------------------------

	public function test_a_continuing_tick_schedules_exactly_one_unique_action_a_grace_out(): void {
		$this->driver->handOff( 7, $this->continuing() );
		$this->driver->handOff( 7, $this->continuing() );
		$this->driver->handOff( 7, $this->continuing() );

		$queued = $this->queued();
		$this->assertCount( 1, $queued, 'polls hand off repeatedly but the queue holds one action' );
		$this->assertSame( BackgroundDriver::HOOK, $queued[0]['hook'] );
		$this->assertSame( array( 'run_id' => 7 ), $queued[0]['args'] );
		$this->assertSame( 'specflux-site-agent', $queued[0]['group'] );
		$this->assertTrue( $queued[0]['unique'] );
		$this->assertSame( self::T0 + 20, $queued[0]['when'] );
	}

	public function test_the_delay_is_the_larger_of_grace_and_retry_after(): void {
		$this->driver->handOff( 1, $this->continuing( 45 ) );
		$this->driver->handOff( 2, $this->continuing( 5 ) );

		$whens = array_map( static fn ( array $row ): int => $row['when'], $this->queued() );
		$this->assertSame( array( self::T0 + 45, self::T0 + 20 ), $whens );
	}

	public function test_the_grace_filter_is_honoured_and_clamped(): void {
		add_filter( 'specflux_site_agent_handoff_grace_seconds', static fn (): int => 1 );
		$this->assertSame( 5, $this->driver->grace() );

		remove_all_filters( 'specflux_site_agent_handoff_grace_seconds' );
		add_filter( 'specflux_site_agent_handoff_grace_seconds', static fn (): int => 9999 );
		$this->assertSame( 300, $this->driver->grace() );

		remove_all_filters( 'specflux_site_agent_handoff_grace_seconds' );
		add_filter( 'specflux_site_agent_handoff_grace_seconds', static fn (): int => 33 );
		$this->driver->handOff( 3, $this->continuing() );
		$this->assertSame( self::T0 + 33, $this->queued()[0]['when'] );
	}

	public function test_parked_terminal_and_error_results_schedule_nothing(): void {
		foreach ( array( 'awaiting_user', 'awaiting_plan', 'awaiting_approval', 'completed', 'failed', 'cancelled' ) as $status ) {
			$this->driver->handOff(
				4,
				array(
					'run'         => array( 'status' => $status ),
					'continue'    => false,
					'retry_after' => 0,
					'ui'          => array( 'approval' => array() ),
				)
			);
		}
		$this->driver->handOff( 4, new WP_Error( 'specflux_site_agent_conflict', 'x', array( 'status' => 409 ) ) );

		$this->assertSame( array(), $this->queued() );
	}

	public function test_a_hold_parked_run_is_retried_in_60_seconds(): void {
		$this->driver->handOff(
			5,
			array(
				'run'         => array( 'status' => 'awaiting_approval' ),
				'continue'    => false,
				'retry_after' => 0,
				'ui'          => array( 'hold' => array( 'kind' => 'paused' ) ),
			)
		);

		$this->assertCount( 1, $this->queued() );
		$this->assertSame( self::T0 + 60, $this->queued()[0]['when'] );
	}

	public function test_no_hand_off_when_the_queue_is_unavailable(): void {
		$GLOBALS['specflux_site_agent_test_as_version'] = '3.5.0';
		$this->driver->handOff( 6, $this->continuing() );

		add_filter( 'specflux_site_agent_background_runs', static fn (): bool => false );
		$GLOBALS['specflux_site_agent_test_as_version'] = '4.2.0';
		$this->driver->handOff( 6, $this->continuing() );

		$this->assertSame( array(), $this->queued() );
	}

	// ------------------------------------------------------------------
	// Handler
	// ------------------------------------------------------------------

	public function test_the_handler_skips_terminal_runs(): void {
		foreach ( array( 'completed', 'failed', 'cancelled' ) as $status ) {
			$this->driver->handle( run_id: $this->createRun( $status ) );
		}

		$this->assertSame( array(), $this->ticks );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_user_history'], 'the user is never switched for a run it will not tick' );
	}

	public function test_the_handler_skips_runs_parked_on_a_person(): void {
		foreach ( array( 'awaiting_user', 'awaiting_plan', 'awaiting_approval' ) as $status ) {
			$this->driver->handle( run_id: $this->createRun( $status ) );
		}

		$this->assertSame( array(), $this->ticks );
	}

	public function test_the_handler_does_drive_a_hold_parked_run(): void {
		$run_id = $this->createRun( 'awaiting_approval' );
		$this->store->appendStep(
			$run_id,
			StepKind::Approval,
			array(
				'parked'           => true,
				'approval_id'      => null,
				'hold'             => 'rate_limited_minute',
				'verb'             => 'x',
				'tool_name'        => 'x',
				'function_call_id' => 'c1',
				'args'             => array(),
				'remaining'        => array(),
			),
			'x',
			null,
			'parked'
		);

		$this->driver->handle( run_id: $run_id );

		$this->assertCount( 1, $this->ticks );
	}

	public function test_it_defers_when_a_screen_ticked_within_the_grace_period(): void {
		$run_id = $this->createRun(
			'running',
			array(
				'last_driver'  => 'screen',
				'last_tick_at' => self::utc( self::T0 - 5 ),
			)
		);

		$this->driver->handle( run_id: $run_id );

		$this->assertSame( array(), $this->ticks );
		$this->assertCount( 1, $this->queued() );
		$this->assertSame( self::T0 + 20, $this->queued()[0]['when'] );
	}

	public function test_it_takes_over_once_the_screen_has_gone_quiet(): void {
		$run_id = $this->createRun(
			'running',
			array(
				'last_driver'  => 'screen',
				'last_tick_at' => self::utc( self::T0 - 60 ),
			)
		);

		$this->driver->handle( run_id: $run_id );

		$this->assertCount( 1, $this->ticks );
	}

	public function test_it_does_not_defer_to_its_own_previous_tick(): void {
		$run_id = $this->createRun(
			'running',
			array(
				'last_driver'  => 'background',
				'last_tick_at' => self::utc( self::T0 - 1 ),
			)
		);

		$this->driver->handle( run_id: $run_id );

		$this->assertCount( 1, $this->ticks );
	}

	public function test_it_ticks_as_background_with_the_current_step_count_and_resets_the_user(): void {
		$run_id = $this->createRun( 'running', array( 'step_count' => 7 ) );

		$this->driver->handle( run_id: $run_id );

		$this->assertSame( array( array( $run_id, 7, 'background' ) ), $this->ticks );
		$this->assertSame( array( 1, 0 ), $GLOBALS['specflux_site_agent_test_user_history'], 'became the owner, then user 0' );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_the_user_is_reset_even_when_the_tick_throws(): void {
		$run_id           = $this->createRun();
		$this->tickResult = static function (): array {
			throw new \RuntimeException( 'boom' );
		};

		try {
			$this->driver->handle( run_id: $run_id );
			$this->fail( 'the exception must propagate' );
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'boom', $error->getMessage() );
		}

		$this->assertSame( 0, get_current_user_id() );
		$this->assertSame( 0, end( $GLOBALS['specflux_site_agent_test_user_history'] ) );
	}

	public function test_a_deleted_owner_fails_the_run_with_owner_unavailable(): void {
		$GLOBALS['specflux_site_agent_test_users'] = array();
		$run_id                                    = $this->createRun();

		$this->driver->handle( run_id: $run_id );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'owner_unavailable', $run->error['code'] ?? '' );
		$this->assertSame( array(), $this->ticks );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_an_owner_without_the_screen_capability_fails_the_run(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => false );
		$run_id                                        = $this->createRun();

		$this->driver->handle( run_id: $run_id );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'owner_unavailable', $run->error['code'] ?? '' );
		$this->assertSame( array(), $this->ticks );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_a_409_from_a_screen_holding_the_run_reschedules_at_grace(): void {
		$run_id           = $this->createRun();
		$this->tickResult = static fn (): WP_Error => new WP_Error( 'specflux_site_agent_conflict', 'busy', array( 'status' => 409 ) );

		$this->driver->handle( run_id: $run_id );

		$this->assertCount( 1, $this->queued() );
		$this->assertSame( self::T0 + 20, $this->queued()[0]['when'] );
		$this->assertSame( RunStatus::Running, $this->store->getRun( $run_id )->status );
	}

	public function test_rescheduling_works_from_inside_a_running_action_that_holds_the_unique_key(): void {
		$run_id = $this->createRun();
		as_schedule_single_action( self::T0, BackgroundDriver::HOOK, array( 'run_id' => $run_id ), 'specflux-site-agent', true );
		$GLOBALS['specflux_site_agent_test_as'][1]['status'] = 'running'; // This very action, mid-flight.

		$this->tickResult = function ( int $id ): array {
			$this->driver->handOff( $id, $this->continuing() ); // What Plugin::tick does after a tick.

			return $this->continuing();
		};
		$this->driver->handle( run_id: $run_id );
		$this->driver->handle( run_id: $run_id ); // A second pass must not pile up a duplicate pending action.

		$pending = array_filter( $this->queued(), static fn ( array $row ): bool => 'pending' === $row['status'] );
		$this->assertCount( 1, $pending, 'one pending successor, despite the running action blocking a unique insert' );
	}

	public function test_the_handler_does_not_schedule_after_a_normal_tick_itself(): void {
		$run_id = $this->createRun();

		$this->driver->handle( run_id: $run_id );

		$this->assertSame( array(), $this->queued(), 'the hand-off belongs to the tick path, not the handler (no double schedule)' );
	}

	// ------------------------------------------------------------------
	// Correlation conflict
	// ------------------------------------------------------------------

	private function useRealTick(): void {
		specflux_site_agent_test_grants( true );
		$this->tickResult = fn ( int $id, int $count, string $driver ): array|WP_Error => $this->runner->tick( $id, $count, null, $driver );
	}

	public function test_a_correlation_conflict_reschedules_in_60_seconds_without_failing_the_run(): void {
		$this->useRealTick();
		$run_id                   = $this->createRun();
		RequestContext::$conflict = true;

		$this->driver->handle( run_id: $run_id );

		$this->assertSame( RunStatus::Running, $this->store->getRun( $run_id )->status );
		$this->assertCount( 1, $this->queued() );
		$this->assertSame( self::T0 + 60, $this->queued()[0]['when'] );
		$this->assertSame( 1, get_transient( BackgroundDriver::CONFLICT_TRANSIENT . $run_id ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_the_tenth_consecutive_conflict_fails_the_run_with_background_conflict(): void {
		$this->useRealTick();
		$run_id                   = $this->createRun();
		RequestContext::$conflict = true;

		for ( $i = 1; $i <= 9; ++$i ) {
			$GLOBALS['specflux_site_agent_test_as'] = array();
			$this->driver->handle( run_id: $run_id );
			$this->assertSame( RunStatus::Running, $this->store->getRun( $run_id )->status, "still alive after conflict $i" );
			$this->assertSame( $i, get_transient( BackgroundDriver::CONFLICT_TRANSIENT . $run_id ) );
		}

		$GLOBALS['specflux_site_agent_test_as'] = array();
		$this->driver->handle( run_id: $run_id );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'background_conflict', $run->error['code'] ?? '' );
		$this->assertSame( array(), $this->queued(), 'nothing is rescheduled for a failed run' );
		$this->assertFalse( get_transient( BackgroundDriver::CONFLICT_TRANSIENT . $run_id ) );
	}

	public function test_a_successful_tick_resets_the_conflict_counter(): void {
		$this->useRealTick();
		$run_id                   = $this->createRun();
		RequestContext::$conflict = true;
		$this->driver->handle( run_id: $run_id );
		$GLOBALS['specflux_site_agent_test_as'] = array();
		$this->driver->handle( run_id: $run_id );
		$this->assertSame( 2, get_transient( BackgroundDriver::CONFLICT_TRANSIENT . $run_id ) );

		RequestContext::$conflict = false;
		$this->gateway->script[]  = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );
		$this->gateway->script[]  = new ModelTurn( new ModelMessage( array( new MessagePart( 'Done.' ) ) ), 10, 5 );
		$this->driver->handle( run_id: $run_id );

		$this->assertFalse( get_transient( BackgroundDriver::CONFLICT_TRANSIENT . $run_id ) );
		$this->assertSame( RunStatus::Completed, $this->store->getRun( $run_id )->status );
	}

	// ------------------------------------------------------------------
	// Stall sweep
	// ------------------------------------------------------------------

	public function test_the_sweep_picks_only_stalled_runs_without_a_pending_action(): void {
		$old   = self::utc( self::T0 - 600 );
		$fresh = self::utc( self::T0 - 30 );
		$past  = self::utc( self::T0 - 10 );
		$later = self::utc( self::T0 + 200 );

		$stalled         = $this->createRun( 'running', array( 'last_tick_at' => $old ) );
		$never_ticked    = $this->createRun( 'pending', array( 'created_at' => $old ) );
		$expired_claim   = $this->createRun(
			'running',
			array(
				'last_tick_at' => $old,
				'lock_until'   => $past,
			)
		);
		$recent          = $this->createRun( 'running', array( 'last_tick_at' => $fresh ) );
		$claimed         = $this->createRun(
			'running',
			array(
				'last_tick_at' => $old,
				'lock_until'   => $later,
			)
		);
		$has_action      = $this->createRun( 'running', array( 'last_tick_at' => $old ) );
		$finished        = $this->createRun( 'completed', array( 'last_tick_at' => $old ) );
		$parked          = $this->createRun( 'awaiting_user', array( 'last_tick_at' => $old ) );
		$young_untouched = $this->createRun( 'pending', array( 'created_at' => $fresh ) );
		as_schedule_single_action( self::T0 + 500, BackgroundDriver::HOOK, array( 'run_id' => $has_action ), 'specflux-site-agent', true );
		unset( $recent, $claimed, $finished, $parked, $young_untouched );

		$queued = $this->driver->sweep();

		$scheduled = array_map( static fn ( array $row ): int => $row['args']['run_id'], array_slice( $this->queued(), 1 ) );
		sort( $scheduled );
		$this->assertSame( array( $stalled, $never_ticked, $expired_claim ), $scheduled );
		$this->assertSame( 3, $queued );
	}

	public function test_after_a_background_tick_there_is_no_grace(): void {
		$this->driver->handOff( 1, $this->continuing(), 'background' );
		$this->driver->handOff( 2, $this->continuing( 8 ), 'background' );
		$this->driver->handOff( 3, $this->continuing( 8 ), 'screen' );
		$this->driver->handOff( 4, $this->continuing(), 'screen' );

		$whens = array_map( static fn ( array $row ): int => $row['when'], $this->queued() );
		$this->assertSame( array( self::T0, self::T0 + 8, self::T0 + 20, self::T0 + 20 ), $whens );
	}

	private function holdParkedRun( array $fields ): int {
		$run_id = $this->createRun( 'awaiting_approval', $fields );
		$this->store->appendStep(
			$run_id,
			StepKind::Approval,
			array(
				'parked'           => true,
				'approval_id'      => null,
				'hold'             => 'rate_limited_minute',
				'verb'             => 'x',
				'tool_name'        => 'x',
				'function_call_id' => 'c1',
				'args'             => array(),
				'remaining'        => array(),
			),
			'x',
			null,
			'parked'
		);

		return $run_id;
	}

	public function test_the_sweep_recovers_a_stale_hold_parked_run_at_the_hold_delay_but_not_a_human_park(): void {
		$old   = self::utc( self::T0 - 600 );
		$hold  = $this->holdParkedRun( array( 'last_tick_at' => $old ) );
		$human = $this->createRun( 'awaiting_approval', array( 'last_tick_at' => $old ) );
		$this->store->appendStep(
			$human,
			StepKind::Approval,
			array(
				'parked'      => true,
				'approval_id' => 'a1',
			),
			'x',
			'a1',
			'parked'
		);
		$fresh = $this->holdParkedRun( array( 'last_tick_at' => self::utc( self::T0 - 30 ) ) );
		unset( $fresh );

		$this->assertSame( 1, $this->driver->sweep() );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( array( 'run_id' => $hold ), $queued[0]['args'] );
		$this->assertSame( self::T0 + 60, $queued[0]['when'] );
	}

	public function test_the_sweep_batch_is_limited_to_200_and_the_store_honours_a_limit(): void {
		for ( $i = 0; $i < 5; ++$i ) {
			$this->createRun( 'running', array( 'last_tick_at' => self::utc( self::T0 - 600 ) ) );
		}

		$found = $this->store->findStalled( self::utc( self::T0 - 300 ), self::utc( self::T0 ), 3 );
		$this->assertCount( 3, $found );

		$this->driver->sweep();
		$this->assertStringContainsString( 'LIMIT 200', (string) end( $this->db->queries ) );
		$this->assertSame( 200, BackgroundDriver::SWEEP_LIMIT );
	}

	public function test_the_sweep_does_nothing_without_a_usable_queue(): void {
		$this->createRun( 'running', array( 'last_tick_at' => self::utc( self::T0 - 600 ) ) );
		$GLOBALS['specflux_site_agent_test_as_version'] = '3.0.0';

		$this->assertSame( 0, $this->driver->sweep() );
		$this->assertSame( array(), $this->queued() );
	}

	public function test_the_sweep_is_scheduled_hourly_once_through_the_queue(): void {
		BackgroundDriver::ensureSweepScheduled();
		BackgroundDriver::ensureSweepScheduled();

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( BackgroundDriver::SWEEP_HOOK, $queued[0]['hook'] );
		$this->assertSame( HOUR_IN_SECONDS, $queued[0]['recurring'] );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_cron'] );
	}

	public function test_the_sweep_falls_back_to_wp_cron_without_a_usable_queue(): void {
		$GLOBALS['specflux_site_agent_test_as_version'] = '3.0.0';

		BackgroundDriver::ensureSweepScheduled();

		$this->assertSame( array(), $this->queued() );
		$this->assertSame( 'hourly', $GLOBALS['specflux_site_agent_test_cron'][ BackgroundDriver::SWEEP_HOOK ]['recurrence'] );
	}

	// ------------------------------------------------------------------
	// Deactivation
	// ------------------------------------------------------------------

	public function test_deactivation_unschedules_everything_in_our_group_and_the_cron_fallback(): void {
		$this->driver->schedule( 1, 10 );
		$this->driver->schedule( 2, 10 );
		BackgroundDriver::ensureSweepScheduled();
		as_schedule_single_action( self::T0, 'woocommerce_other_thing', array(), 'woocommerce', false );
		wp_schedule_event( self::T0, 'hourly', BackgroundDriver::SWEEP_HOOK );

		BackgroundDriver::unscheduleAll();

		$left = $this->queued();
		$this->assertCount( 1, $left, 'only the unrelated action survives' );
		$this->assertSame( 'woocommerce_other_thing', $left[0]['hook'] );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_cron'] );
	}
}
