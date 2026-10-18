<?php
/**
 * The background driver: advances a run through Action Scheduler when no
 * browser is doing it.
 *
 * Hand-off, not two competing drivers (SPEC-SITE-AGENT-UNATTENDED 2.3). Every
 * tick, from the screen or from here, that leaves a run still running (or
 * hold-parked) schedules ONE unique action a grace period out. If a tab is
 * still ticking when it fires, the action defers; once the tab is gone it
 * drives the run. The atomic run claim and the `step_count` echo make a double
 * drive impossible, so this class never has to coordinate beyond that.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Background;

use Specflux\SiteAgent\Admin\ScreenCapability;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\Resume;
use Specflux\SiteAgent\Run\Run;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\RunStore;
use Specflux\SiteAgent\Run\Runner;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Schedules, runs and sweeps background ticks.
 */
final class BackgroundDriver {

	/** The Action Scheduler hook one background tick fires. */
	public const HOOK = 'specflux_site_agent_background_tick';

	/** The hourly stall sweep's hook. */
	public const SWEEP_HOOK = 'specflux_site_agent_background_sweep';

	/** Action Scheduler group for everything this plugin queues. */
	public const GROUP = 'specflux-site-agent';

	/** Default seconds a screen tick keeps the background driver away. */
	public const GRACE_DEFAULT = 20;

	/** Seconds between retries of a hold-parked run. */
	public const HOLD_DELAY = 60;

	/** Seconds before retrying after a correlation conflict. */
	public const CONFLICT_DELAY = 60;

	/** Consecutive correlation conflicts before the run is failed. */
	public const CONFLICT_LIMIT = 10;

	/** A running run with no tick for this long is stalled. */
	public const STALL_SECONDS = 300;

	/** Most runs one sweep schedules. */
	public const SWEEP_LIMIT = 200;

	/** Most rows one sweep inspects, scheduled or not. */
	public const SWEEP_SCAN_CAP = 5000;

	/** Transient prefix of the per-run consecutive-conflict counter. */
	public const CONFLICT_TRANSIENT = 'specflux_site_agent_bgconf_';

	/** True while this process is inside {@see handle()}, i.e. inside a RUNNING queue action. */
	private bool $in_handler = false;

	/**
	 * @param RunStore                                          $store  Run storage.
	 * @param Runner                                            $runner The runner (hold detection, failing a run).
	 * @param callable(int,int,string,array<string,mixed>=):(array<string,mixed>|WP_Error) $tick `(run_id, step_count, driver, resume)` -> the plugin's tick; `resume` is passed only for an off-screen resume.
	 */
	public function __construct(
		private readonly RunStore $store,
		private readonly Runner $runner,
		private $tick
	) {
	}

	/** Seconds a screen tick defers the background driver (filterable, clamped 5-300). */
	public function grace(): int {
		/**
		 * Seconds after a tick before the background driver may take over a run.
		 *
		 * @param int $seconds Default 20; clamped to 5-300.
		 */
		$grace = apply_filters( 'specflux_site_agent_handoff_grace_seconds', self::GRACE_DEFAULT );

		return max( 5, min( 300, is_numeric( $grace ) ? (int) $grace : self::GRACE_DEFAULT ) );
	}

	/**
	 * Queue one background tick for a run, `$delay` seconds from now.
	 *
	 * Unique per (hook, args, group) so a poll storm cannot pile up actions.
	 * Inside a running action the action's own unique key is still held (Action
	 * Scheduler clears it only on completion), so there the pending check is
	 * done by hand and the insert is not unique.
	 *
	 * An off-screen resume rides in the same stored args: `resume` (the exact
	 * payload the Runs screen would post, `{action: approve|reject}`) and the
	 * `approval_id` the run must still be parked on when the action fires.
	 * Both are tiny and validated again in {@see handle()}.
	 *
	 * @param array<string,string>|null $resume      Park resolution to deliver, or null for a plain tick.
	 * @param string|null               $approval_id The approval the resume answers (required with `$resume`).
	 * @return bool True when an action was queued (false: one was already pending, or no queue).
	 */
	public function schedule( int $run_id, int $delay, ?array $resume = null, ?string $approval_id = null ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$when = Clock::now() + max( 0, $delay );
		$args = array( 'run_id' => $run_id );
		if ( null !== $resume && null !== $approval_id ) {
			$args['resume']      = $resume;
			$args['approval_id'] = $approval_id;
		}

		if ( $this->in_handler ) {
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				$pending = as_get_scheduled_actions(
					array(
						'hook'     => self::HOOK,
						'args'     => $args,
						'group'    => self::GROUP,
						'status'   => 'pending',
						'per_page' => 1,
					),
					'ids'
				);
				if ( array() !== $pending ) {
					return false;
				}
			}

			return 0 !== as_schedule_single_action( $when, self::HOOK, $args, self::GROUP, false );
		}

		return 0 !== as_schedule_single_action( $when, self::HOOK, $args, self::GROUP, true );
	}

	/**
	 * The hand-off: called once after EVERY tick (screen or background), so
	 * the ajax and REST paths share it and a poll never touches the queue by
	 * itself.
	 *
	 * @param int                           $run_id Run id.
	 * @param array<string,mixed>|WP_Error $result The tick's result.
	 * @param string                       $driver Who drove the tick: after a 'background' tick there is no screen to defer to, so no grace.
	 */
	public function handOff( int $run_id, array|WP_Error $result, string $driver = Runner::DRIVER_SCREEN ): void {
		if ( ! is_array( $result ) || ! Availability::available() ) {
			return;
		}

		$ui = $result['ui'] ?? null;
		if ( true === ( $result['continue'] ?? false ) ) {
			$retry_after = max( 0, (int) ( $result['retry_after'] ?? 0 ) );
			$this->schedule( $run_id, Runner::DRIVER_BACKGROUND === $driver ? $retry_after : max( $this->grace(), $retry_after ) );

			return;
		}

		$status = $result['run']['status'] ?? '';
		if ( RunStatus::AwaitingApproval->value === $status && is_array( $ui ) && is_array( $ui['hold'] ?? null ) ) {
			$this->schedule( $run_id, self::HOLD_DELAY );
		}
	}

	/**
	 * Queue the off-screen resume of a run parked on a Belayline approval that
	 * was just resolved elsewhere (the Pending Agent Actions screen, a phone).
	 *
	 * Idempotent by construction: the queued action carries the approval id and
	 * {@see handle()} re-checks the run is STILL parked on it, so a duplicate
	 * event, or a person who got to the Runs screen first, makes the second
	 * tick a no-op. With no usable queue nothing happens and the person
	 * resumes from the Runs screen as before.
	 *
	 * @param string $approval_id Belayline approval id.
	 * @param string $outcome     'approved' or 'rejected'.
	 * @return bool True when a resume was queued.
	 */
	public function resumeOnResolution( string $approval_id, string $outcome ): bool {
		if ( ! Availability::available() || ! in_array( $outcome, array( 'approved', 'rejected' ), true ) ) {
			return false;
		}

		foreach ( $this->store->findAwaitingByApprovalId( $approval_id ) as $run ) {
			if ( $this->runner->parkedApprovalId( $run ) !== $approval_id ) {
				continue; // Moved on to a later park.
			}

			$resume = array( 'action' => 'approved' === $outcome ? 'approve' : 'reject' );
			if ( true !== Resume::check( $run->status, $resume ) ) {
				continue;
			}

			return $this->schedule( $run->id, 0, $resume, $approval_id );
		}

		return false;
	}

	/**
	 * The queue callback. Action Scheduler passes the stored args array; under
	 * PHP 8 its string keys arrive as NAMED arguments, so take the variadic.
	 *
	 * @param mixed ...$args `run_id`, `resume`, `approval_id` (named) or the positional equivalents.
	 */
	public function handle( mixed ...$args ): void {
		$run_id      = (int) ( $args['run_id'] ?? $args[0] ?? 0 );
		$resume      = $args['resume'] ?? $args[1] ?? null;
		$approval_id = $args['approval_id'] ?? $args[2] ?? null;
		if ( $run_id < 1 || ! Availability::available() ) {
			return;
		}

		$run = $this->store->getRun( $run_id );
		if ( null === $run ) {
			return;
		}

		if ( null !== $resume ) {
			// An off-screen resume only ever answers the approval it was queued
			// for; anything else (already resumed on screen, a later park, a
			// malformed payload) is a silent skip, never a tick.
			if ( ! is_array( $resume ) || ! is_string( $approval_id ) || '' === $approval_id
				|| $this->runner->parkedApprovalId( $run ) !== $approval_id
				|| true !== Resume::check( $run->status, $resume ) ) {
				return;
			}
		} elseif ( ! $this->drivable( $run ) ) {
			return;
		}

		$this->in_handler = true;
		try {
			// A screen is (or just was) ticking: stay out of its way.
			if ( null === $resume && 'screen' === $run->lastDriver && $this->screenTickedRecently( $run ) ) {
				$this->schedule( $run_id, $this->grace() );

				return;
			}

			// The owner is not logged in under a queue: become them for this
			// one tick only, and ALWAYS stop being them (Action Scheduler runs
			// many actions in one process).
			wp_set_current_user( $run->userId );
			$member = ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'is_user_member_of_blog' ) || is_user_member_of_blog( $run->userId, get_current_blog_id() );
			if ( false === get_userdata( $run->userId ) || ! $member || ! ScreenCapability::held() ) {
				$this->runner->failRun(
					$run,
					'owner_unavailable',
					'The run\'s owner no longer exists or can no longer run Site Agent, so it cannot continue in the background.'
				);

				return;
			}

			$result = null === $resume
				? call_user_func( $this->tick, $run_id, $run->stepCount, Runner::DRIVER_BACKGROUND )
				: call_user_func( $this->tick, $run_id, $run->stepCount, Runner::DRIVER_BACKGROUND, $resume );
			$this->afterTick( $run, $result, is_array( $resume ) ? $resume : null, is_string( $approval_id ) ? $approval_id : null );
		} finally {
			wp_set_current_user( 0 );
			$this->in_handler = false;
		}
	}

	/**
	 * Hourly: queue any active run nobody is driving and nothing is queued for.
	 *
	 * @return int How many runs were queued.
	 */
	public function sweep(): int {
		if ( ! Availability::available() ) {
			return 0;
		}

		$now     = Clock::now();
		$queued  = 0;
		$scanned = 0;
		$cursor  = 0;
		$before  = gmdate( 'Y-m-d H:i:s', $now - self::STALL_SECONDS );
		$at      = gmdate( 'Y-m-d H:i:s', $now );

		// Keyset pages: stale human parks must not starve the runs behind them,
		// so the limit counts SCHEDULED runs, bounded by a scan cap.
		while ( $queued < self::SWEEP_LIMIT && $scanned < self::SWEEP_SCAN_CAP ) {
			$page = $this->store->findStalled( $before, $at, self::SWEEP_LIMIT, $cursor );
			if ( array() === $page ) {
				break;
			}

			foreach ( $page as $run ) {
				++$scanned;
				$cursor = max( $cursor, $run->id );
				if ( $queued >= self::SWEEP_LIMIT ) {
					break;
				}
				if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, array( 'run_id' => $run->id ), self::GROUP ) ) {
					continue;
				}
				// A hold park waits on the clock, not a person; any other park is not ours.
				$held = RunStatus::AwaitingApproval === $run->status;
				if ( $held && null === $this->runner->parkedHoldUi( $run ) ) {
					continue;
				}
				if ( $this->schedule( $run->id, $held ? self::HOLD_DELAY : 0 ) ) {
					++$queued;
				}
			}

			if ( count( $page ) < self::SWEEP_LIMIT ) {
				break;
			}
		}

		return $queued;
	}

	/** Make sure the hourly sweep exists: a queue action when available, WP-Cron otherwise. */
	public static function ensureSweepScheduled(): void {
		if ( Availability::available() && function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::SWEEP_HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( Clock::now() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::SWEEP_HOOK, array(), self::GROUP, true );
			}

			return;
		}

		if ( false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( Clock::now() + HOUR_IN_SECONDS, 'hourly', self::SWEEP_HOOK );
		}
	}

	/** Deactivation / uninstall: drop every queued action of ours and the cron fallback. */
	public static function unscheduleAll(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SWEEP_HOOK );
		}
	}

	/** Can a background tick act on this run at all? Terminal and human-parked runs: no. */
	private function drivable( Run $run ): bool {
		if ( $run->status->isTerminal() ) {
			return false;
		}
		if ( RunStatus::Pending === $run->status || RunStatus::Running === $run->status ) {
			return true;
		}

		// Only a Belayline pause / rate-limit hold waits on the clock, not a person.
		return RunStatus::AwaitingApproval === $run->status && null !== $this->runner->parkedHoldUi( $run );
	}

	private function screenTickedRecently( Run $run ): bool {
		if ( null === $run->lastTickAtUtc ) {
			return false;
		}
		$ticked = strtotime( $run->lastTickAtUtc . ' UTC' );

		return false !== $ticked && $ticked > Clock::now() - $this->grace();
	}

	/**
	 * Act on a background tick's outcome. The next hand-off itself already
	 * happened inside the tick ({@see handOff()}), so a normal result needs
	 * nothing here beyond resetting the conflict counter.
	 *
	 * @param array<string,mixed>|WP_Error $result      Tick result.
	 * @param array<string,string>|null    $resume      The resume this tick carried, so a retry carries it too.
	 * @param string|null                  $approval_id The approval that resume answers.
	 */
	private function afterTick( Run $run, array|WP_Error $result, ?array $resume = null, ?string $approval_id = null ): void {
		if ( is_array( $result ) ) {
			delete_transient( self::CONFLICT_TRANSIENT . $run->id );

			return;
		}

		$code = $result->get_error_code();
		if ( 'specflux_site_agent_conflict' === $code ) {
			// Someone else (a screen) holds or advanced the run: look again later.
			$this->schedule( $run->id, $this->grace(), $resume, $approval_id );

			return;
		}

		if ( Runner::CORRELATION_CONFLICT !== $code ) {
			return; // Not ours to retry; the hourly sweep still covers a live run.
		}

		$seen = (int) get_transient( self::CONFLICT_TRANSIENT . $run->id ) + 1;
		if ( $seen >= self::CONFLICT_LIMIT ) {
			delete_transient( self::CONFLICT_TRANSIENT . $run->id );
			$fresh = $this->store->getRun( $run->id );
			if ( null !== $fresh ) {
				$this->runner->failRun(
					$fresh,
					'background_conflict',
					'Another plugin kept the request\'s correlation id busy, so the run could not continue in the background.'
				);
			}

			return;
		}

		set_transient( self::CONFLICT_TRANSIENT . $run->id, $seen, DAY_IN_SECONDS );
		$this->schedule( $run->id, self::CONFLICT_DELAY, $resume, $approval_id );
	}
}
