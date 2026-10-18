<?php
/**
 * Wall-clock expiry (`max_days`), run retention and their schedules (0.4 U3).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\RunLifecycle;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Schema;
use Specflux\SiteAgent\Tools\ToolExecutor;
use wpdb;

final class RunLifecycleTest extends TestCase {

	private const T0 = 1_800_000_000;

	private wpdb $db;

	private WpdbRunStore $store;

	private RunLifecycle $lifecycle;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$GLOBALS['wpdb']       = $this->db;
		$this->store           = new WpdbRunStore( $this->db );
		$runner                = new Runner( $this->store, new ToolExecutor(), new FakeGateway(), new RecordingBridge() );
		$this->lifecycle       = new RunLifecycle( $this->store, $runner );

		$GLOBALS['specflux_site_agent_test_cron']    = array();
		$GLOBALS['specflux_site_agent_test_filters'] = array();
		Clock::useFixed( self::T0 );
		// A long-installed site by default: the clock started well before every fixture.
		$GLOBALS['specflux_site_agent_test_options'][ Schema::WALLCLOCK_OPTION ] = self::T0 - 1000 * 86400;
	}

	protected function tearDown(): void {
		Clock::reset();
		remove_all_filters();
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['specflux_site_agent_test_cron'] = array();
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private static function ago( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', self::T0 - $days * 86400 - 60 );
	}

	/**
	 * @param array<string,mixed> $columns Columns patched straight into the stored row.
	 */
	private function makeRun( string $status, int $created_days_ago, array $columns = array(), ?int $max_days = null, ?int $follow_up_of = null ): int {
		$budget = Budget::defaults();
		if ( null !== $max_days ) {
			$budget['max_days'] = $max_days;
		}
		$id = $this->store->createRun( 1, 'test-consumer', 'Goal', array( 'agsafe-smoke/*' ), $budget, null, null, null, \Specflux\SiteAgent\Run\GateMode::AgentSafety, array(), $follow_up_of );
		$this->store->updateRun( $id, array( 'status' => $status ) );
		$this->patch( $id, array( 'created_at' => self::ago( $created_days_ago ) ) + $columns );

		return $id;
	}

	/** @param array<string,mixed> $columns Columns. */
	private function patch( int $id, array $columns ): void {
		$table = Schema::runsTable( $this->db );
		foreach ( $this->db->tables[ $table ] as $index => $row ) {
			if ( (int) $row['id'] === $id ) {
				$this->db->tables[ $table ][ $index ] = $columns + $row;
			}
		}
	}

	private function runStatus( int $id ): ?RunStatus {
		return $this->store->getRun( $id )?->status;
	}

	private function stepCount(): int {
		return count( $this->db->tables[ Schema::stepsTable( $this->db ) ] ?? array() );
	}

	// ------------------------------------------------------------------
	// max_days budget key
	// ------------------------------------------------------------------

	public function test_max_days_defaults_to_14(): void {
		$this->assertSame( 14, Budget::defaults()[ Budget::MAX_DAYS ] );
	}

	public function test_max_days_may_be_lowered_but_a_consumer_cannot_raise_it(): void {
		$this->assertSame( 3, Budget::clamp( array( 'max_days' => 3 ), Budget::defaults() )['max_days'] );
		$this->assertSame( 14, Budget::clamp( array( 'max_days' => 99 ), Budget::defaults() )['max_days'] );
		$this->assertSame( 14, Budget::clamp( array( 'max_days' => 0 ), Budget::defaults() )['max_days'], 'zero is not a ceiling' );
	}

	public function test_the_default_budget_filter_applies_to_max_days(): void {
		add_filter( 'specflux_site_agent_default_budget', static fn ( array $budget ): array => array_merge( $budget, array( 'max_days' => 30 ) ) );

		$this->assertSame( 30, Budget::defaults()['max_days'] );
	}

	public function test_a_pack_baseline_caps_max_days_lower_only(): void {
		add_filter( 'specflux_site_agent_default_budget', static fn ( array $budget ): array => array_merge( $budget, array( 'max_days' => 90 ) ) );

		$this->assertSame( 7, Budget::defaults( array( 'max_days' => 7 ) )['max_days'] );
	}

	// ------------------------------------------------------------------
	// Expiry sweep
	// ------------------------------------------------------------------

	public function test_the_sweep_cancels_only_runs_older_than_their_max_days(): void {
		$old_running = $this->makeRun( 'running', 20 );
		$old_parked  = $this->makeRun( 'awaiting_user', 15 );
		$young       = $this->makeRun( 'running', 13 );
		$lowered_old = $this->makeRun( 'running', 4, array(), 3 );
		$lowered_ok  = $this->makeRun( 'running', 2, array(), 3 );
		$old_done    = $this->makeRun( 'completed', 40 );

		$this->assertSame( 3, $this->lifecycle->expireOverdue() );

		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $old_running ) );
		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $old_parked ) );
		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $lowered_old ) );
		$this->assertSame( RunStatus::Running, $this->runStatus( $young ) );
		$this->assertSame( RunStatus::Running, $this->runStatus( $lowered_ok ) );
		$this->assertSame( RunStatus::Completed, $this->runStatus( $old_done ) );
	}

	public function test_an_upgrade_does_not_cancel_a_run_that_was_already_old(): void {
		$GLOBALS['specflux_site_agent_test_options'][ Schema::WALLCLOCK_OPTION ] = self::T0; // Upgraded now.
		$old = $this->makeRun( 'awaiting_user', 30 );

		$this->assertSame( 0, $this->lifecycle->expireOverdue(), 'first sweep after the upgrade' );
		$this->assertSame( RunStatus::AwaitingUser, $this->runStatus( $old ) );

		Clock::useFixed( self::T0 + 13 * 86400 );
		$this->assertSame( 0, $this->lifecycle->expireOverdue() );

		Clock::useFixed( self::T0 + 14 * 86400 + 120 );
		$this->assertSame( 1, $this->lifecycle->expireOverdue(), '14 days after the upgrade' );
		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $old ) );
	}

	public function test_a_fresh_install_follows_created_at_and_records_the_start_once(): void {
		$GLOBALS['specflux_site_agent_test_options'] = array();
		\Specflux\SiteAgent\Schema::maybe_upgrade( $this->db );
		$this->assertSame( self::T0, $GLOBALS['specflux_site_agent_test_options'][ Schema::WALLCLOCK_OPTION ] );

		Clock::useFixed( self::T0 + 20 * 86400 );
		$run = $this->makeRun( 'running', 0, array( 'created_at' => gmdate( 'Y-m-d H:i:s', self::T0 + 1 ) ) );
		$this->assertSame( 1, $this->lifecycle->expireOverdue(), 'created after the upgrade: created_at rules' );
		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $run ) );

		$GLOBALS['specflux_site_agent_test_options'][ Schema::WALLCLOCK_OPTION ]       = 123;
		$GLOBALS['specflux_site_agent_test_options']['specflux_site_agent_db_version'] = 0;
		\Specflux\SiteAgent\Schema::maybe_upgrade( $this->db );
		$this->assertSame( 123, $GLOBALS['specflux_site_agent_test_options'][ Schema::WALLCLOCK_OPTION ], 'never overwritten' );
	}

	public function test_an_expired_run_carries_run_expired_and_a_report_line(): void {
		$id = $this->makeRun( 'awaiting_user', 20 );

		$this->lifecycle->expireOverdue();

		$run = $this->store->getRun( $id );
		$this->assertSame( 'run_expired', $run->error['code'] ?? '' );
		$this->assertSame( 'The run was cancelled because it was still unfinished after 14 days.', $run->error['message'] ?? '' );
		$this->assertSame( $run->error['message'], $run->result['unverified'] ?? '' );
		$this->assertNotNull( $run->finishedAtUtc );
	}

	public function test_a_run_parked_on_a_belayline_approval_is_cancelled_without_touching_belayline(): void {
		$id = $this->makeRun( 'awaiting_approval', 20 );
		$this->store->appendStep(
			$id,
			StepKind::Approval,
			array(
				'parked'      => true,
				'approval_id' => 'ap-1',
			),
			'x',
			'ap-1',
			'parked'
		);
		$GLOBALS['specflux_site_agent_test_agent_safety'] = null;

		$this->lifecycle->expireOverdue();

		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $id ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_as'] ?? array(), 'no Belayline call, no queue action' );
	}

	public function test_a_run_with_a_tick_in_flight_is_left_for_the_next_sweep(): void {
		$id = $this->makeRun( 'running', 20, array( 'lock_until' => gmdate( 'Y-m-d H:i:s', self::T0 + 120 ) ) );

		$this->assertSame( 0, $this->lifecycle->expireOverdue() );
		$this->assertSame( RunStatus::Running, $this->runStatus( $id ) );
	}

	public function test_the_sweep_pages_by_id_past_runs_that_are_not_yet_due(): void {
		// 501 young runs that pass the one-day prefilter but not their own ceiling,
		// then an overdue one behind them: paging must reach it.
		for ( $i = 0; $i < RunLifecycle::PAGE + 1; ++$i ) {
			$this->makeRun( 'running', 5 );
		}
		$overdue = $this->makeRun( 'running', 30 );

		$this->assertSame( 1, $this->lifecycle->expireOverdue() );
		$this->assertSame( RunStatus::Cancelled, $this->runStatus( $overdue ) );
	}

	public function test_the_sweep_stops_at_the_scan_cap(): void {
		// 20,000 runs past the one-day prefilter but inside their own 14 days,
		// then one overdue run behind them: the scan cap stops before it.
		$table = Schema::runsTable( $this->db );
		$rows  = array();
		for ( $i = 1; $i <= RunLifecycle::EXPIRE_SCAN_CAP; ++$i ) {
			$rows[] = array(
				'id'          => $i,
				'user_id'     => 1,
				'consumer'    => 'c',
				'goal'        => 'g',
				'status'      => 'running',
				'allow_json'  => '[]',
				'budget_json' => '{}',
				'created_at'  => self::ago( 5 ),
			);
		}
		$rows[]                     = array(
			'id'          => RunLifecycle::EXPIRE_SCAN_CAP + 1,
			'user_id'     => 1,
			'consumer'    => 'c',
			'goal'        => 'g',
			'status'      => 'running',
			'allow_json'  => '[]',
			'budget_json' => '{}',
			'created_at'  => self::ago( 40 ),
		);
		$this->db->tables[ $table ] = $rows;
		$this->db->queries          = array();

		$this->assertSame( 0, $this->lifecycle->expireOverdue() );

		$pages = array_filter( $this->db->queries, static fn ( string $q ): bool => str_starts_with( $q, 'SELECT * FROM' ) );
		$this->assertCount( 40, $pages, '20,000 / 500 pages, then stop' );
		$this->assertSame( 'running', $this->db->tables[ $table ][ RunLifecycle::EXPIRE_SCAN_CAP ]['status'], 'the run past the cap waits for tomorrow' );
	}

	// ------------------------------------------------------------------
	// Retention
	// ------------------------------------------------------------------

	public function test_retention_zero_keeps_everything(): void {
		add_filter( 'specflux_site_agent_run_retention_days', static fn (): int => 0 );
		$id = $this->makeRun( 'completed', 400, array( 'finished_at' => self::ago( 400 ) ) );

		$this->assertSame( 0, $this->lifecycle->pruneOld() );
		$this->assertSame( RunStatus::Completed, $this->runStatus( $id ) );
	}

	public function test_the_default_is_90_days_and_only_old_terminal_runs_go_with_their_steps(): void {
		$old_done   = $this->makeRun( 'completed', 200, array( 'finished_at' => self::ago( 100 ) ) );
		$old_failed = $this->makeRun( 'failed', 200, array( 'finished_at' => self::ago( 91 ) ) );
		$old_cancel = $this->makeRun(
			'cancelled',
			200,
			array(
				'finished_at' => null,
				'updated_at'  => self::ago( 95 ),
			)
		);
		$recent     = $this->makeRun( 'completed', 200, array( 'finished_at' => self::ago( 30 ) ) );
		$live_old   = $this->makeRun( 'running', 200 );
		foreach ( array( $old_done, $old_failed, $old_cancel, $recent, $live_old ) as $id ) {
			$this->store->appendStep( $id, StepKind::User, array( 'role' => 'user' ) );
		}
		$this->assertSame( 5, $this->stepCount() );
		$this->patch( $old_cancel, array( 'updated_at' => self::ago( 95 ) ) ); // Appending a step touched it.

		$this->assertSame( 3, $this->lifecycle->pruneOld() );

		$this->assertNull( $this->runStatus( $old_done ) );
		$this->assertNull( $this->runStatus( $old_failed ) );
		$this->assertNull( $this->runStatus( $old_cancel ) );
		$this->assertSame( RunStatus::Completed, $this->runStatus( $recent ) );
		$this->assertSame( RunStatus::Running, $this->runStatus( $live_old ) );
		$this->assertSame( 2, $this->stepCount(), 'steps of deleted runs are gone; the others stay' );
		$remaining = array_map( static fn ( array $row ): int => (int) $row['run_id'], $this->db->tables[ Schema::stepsTable( $this->db ) ] );
		$this->assertEqualsCanonicalizing( array( $recent, $live_old ), $remaining );
	}

	public function test_the_retention_filter_is_honoured(): void {
		add_filter( 'specflux_site_agent_run_retention_days', static fn (): int => 10 );
		$gone = $this->makeRun( 'completed', 100, array( 'finished_at' => self::ago( 11 ) ) );
		$kept = $this->makeRun( 'completed', 100, array( 'finished_at' => self::ago( 9 ) ) );

		$this->assertSame( 1, $this->lifecycle->pruneOld() );
		$this->assertNull( $this->runStatus( $gone ) );
		$this->assertNotNull( $this->runStatus( $kept ) );
	}

	public function test_a_run_a_live_follow_up_points_at_is_kept_until_the_follow_up_ends(): void {
		$source = $this->makeRun( 'completed', 200, array( 'finished_at' => self::ago( 120 ) ) );
		$other  = $this->makeRun( 'completed', 200, array( 'finished_at' => self::ago( 120 ) ) );
		$live   = $this->makeRun( 'running', 1, array(), null, $source );

		$this->assertSame( 1, $this->lifecycle->pruneOld() );
		$this->assertNotNull( $this->runStatus( $source ) );
		$this->assertNull( $this->runStatus( $other ) );

		$this->store->updateRun( $live, array( 'status' => 'completed' ) );
		$this->patch( $live, array( 'finished_at' => self::ago( 1 ) ) );
		$this->assertSame( 1, $this->lifecycle->pruneOld() );
		$this->assertNull( $this->runStatus( $source ), 'a finished follow-up no longer protects its source' );
	}

	public function test_a_cron_run_deletes_at_most_20_batches_of_500(): void {
		$table = Schema::runsTable( $this->db );
		$total = RunLifecycle::PAGE * RunLifecycle::PRUNE_MAX_BATCHES + 300;
		$rows  = array();
		for ( $i = 1; $i <= $total; ++$i ) {
			$rows[] = array(
				'id'          => $i,
				'status'      => 'completed',
				'finished_at' => self::ago( 200 ),
				'updated_at'  => self::ago( 200 ),
			);
		}
		$this->db->tables[ $table ] = $rows;

		$this->assertSame( 10000, $this->lifecycle->pruneOld() );
		$this->assertCount( 300, $this->db->tables[ $table ] );

		$this->assertSame( 300, $this->lifecycle->pruneOld(), 'the next day finishes the backlog' );
		$this->assertCount( 0, $this->db->tables[ $table ] );
	}

	public function test_each_delete_batch_is_steps_first_then_runs(): void {
		$this->makeRun( 'completed', 200, array( 'finished_at' => self::ago( 100 ) ) );
		$this->db->queries = array();

		$this->lifecycle->pruneOld();

		$deletes = array_values( array_filter( $this->db->queries, static fn ( string $q ): bool => str_starts_with( $q, 'DELETE' ) ) );
		$this->assertCount( 2, $deletes );
		$this->assertStringContainsString( 'steps WHERE run_id IN (1)', $deletes[0] );
		$this->assertStringContainsString( 'runs WHERE id IN (1)', $deletes[1] );
	}

	// ------------------------------------------------------------------
	// Schedules
	// ------------------------------------------------------------------

	public function test_schedule_registers_both_daily_events_once_and_unschedule_clears_them(): void {
		RunLifecycle::schedule();
		RunLifecycle::schedule();

		$cron = $GLOBALS['specflux_site_agent_test_cron'];
		$this->assertSame( 'daily', $cron['specflux_site_agent_expire_runs']['recurrence'] );
		$this->assertSame( 'daily', $cron['specflux_site_agent_prune_runs']['recurrence'] );

		RunLifecycle::unschedule();
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_cron'] );
	}

	public function test_activation_deactivation_and_uninstall_handle_both_crons(): void {
		$root = dirname( __DIR__, 2 );
		$main = (string) file_get_contents( $root . '/specflux-site-agent.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file in a test.
		$this->assertSame( 1, preg_match( '/function specflux_site_agent_activate\(.*?SiteLifecycle::installCurrentSite\(\)/s', $main ), 'activation schedules' );
		$this->assertSame( 1, preg_match( '/function specflux_site_agent_deactivate\(.*?SiteLifecycle::unscheduleCurrentSite\(\)/s', $main ), 'deactivation clears' );
		$lifecycle = (string) file_get_contents( $root . '/src/SiteLifecycle.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file in a test.
		$this->assertStringContainsString( 'RunLifecycle::schedule()', $lifecycle );
		$this->assertStringContainsString( 'RunLifecycle::unschedule()', $lifecycle );

		$uninstall = (string) file_get_contents( $root . '/src/Uninstaller.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file in a test.
		$this->assertStringContainsString( "'specflux_site_agent_expire_runs'", $uninstall );
		$this->assertStringContainsString( "'specflux_site_agent_prune_runs'", $uninstall );
		$this->assertSame( 'specflux_site_agent_expire_runs', RunLifecycle::EXPIRE_HOOK );
		$this->assertSame( 'specflux_site_agent_prune_runs', RunLifecycle::PRUNE_HOOK );
	}

	public function test_boot_wires_the_crons_and_belaylines_hooks(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( false );
		$GLOBALS['specflux_site_agent_test_actions'] = array();

		Plugin::instance()->boot();

		foreach ( array( RunLifecycle::EXPIRE_HOOK, RunLifecycle::PRUNE_HOOK, 'belayline_approval_resolved', 'belayline_approval_expired' ) as $hook ) {
			$this->assertNotEmpty( $GLOBALS['specflux_site_agent_test_actions'][ $hook ] ?? array(), "$hook is hooked" );
		}
		$this->assertTrue( has_filter( 'belayline_pending_ttl_seconds' ) );
		Plugin::reset();
	}
}
