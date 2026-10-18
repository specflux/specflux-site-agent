<?php
/**
 * Run lifetime housekeeping: the wall-clock expiry sweep and run retention.
 *
 * Two daily WP-Cron events (SPEC-SITE-AGENT-UNATTENDED 2.5):
 *
 *  - `specflux_site_agent_expire_runs` cancels non-terminal runs older than
 *    their `max_days` budget (`run_expired`). A run parked on a Belayline
 *    approval is ended here, but the Belayline row is left alone: it expires
 *    on its own.
 *  - `specflux_site_agent_prune_runs` deletes terminal runs, with their steps,
 *    older than the `specflux_site_agent_run_retention_days` filter.
 *
 * Both are bounded per run of the cron so a huge backlog is worked off over
 * several days rather than in one request.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Run;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Expiry sweep, retention prune and their schedules.
 */
final class RunLifecycle {

	/** Daily wall-clock expiry cron hook. */
	public const EXPIRE_HOOK = 'specflux_site_agent_expire_runs';

	/** Daily retention cron hook. */
	public const PRUNE_HOOK = 'specflux_site_agent_prune_runs';

	/** Page size of both jobs. */
	public const PAGE = 500;

	/** Most rows the expiry sweep inspects per cron run. */
	public const EXPIRE_SCAN_CAP = 20000;

	/** Most delete batches per retention cron run. */
	public const PRUNE_MAX_BATCHES = 20;

	/** Default days a terminal run is kept. */
	public const RETENTION_DAYS_DEFAULT = 90;

	public function __construct( private readonly RunStore $store, private readonly Runner $runner ) {
	}

	/**
	 * Cancel non-terminal runs past their `max_days`.
	 *
	 * The SQL prefilter is the smallest possible `max_days` (one day, since a
	 * budget can only be lowered to 1): each run's own ceiling is checked here.
	 *
	 * @return int Runs cancelled.
	 */
	public function expireOverdue(): int {
		$now       = Clock::now();
		$prefilter = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
		$cancelled = 0;
		$scanned   = 0;
		$cursor    = 0;

		while ( $scanned < self::EXPIRE_SCAN_CAP ) {
			$page = $this->store->findActiveCreatedBefore( $prefilter, min( self::PAGE, self::EXPIRE_SCAN_CAP - $scanned ), $cursor );
			if ( array() === $page ) {
				break;
			}

			foreach ( $page as $run ) {
				++$scanned;
				$cursor  = max( $cursor, $run->id );
				$created = null !== $run->createdAtUtc ? strtotime( $run->createdAtUtc . ' UTC' ) : false;
				$days    = max( 1, (int) ( $run->budget[ Budget::MAX_DAYS ] ?? Budget::defaults()[ Budget::MAX_DAYS ] ) );
				if ( false === $created || $created >= $now - $days * DAY_IN_SECONDS ) {
					continue;
				}
				// A tick is in flight on this run: it would overwrite the end. Tomorrow's sweep gets it.
				if ( null !== $run->lockUntilUtc && strtotime( $run->lockUntilUtc . ' UTC' ) > $now ) {
					continue;
				}

				$this->runner->endRun(
					$run,
					RunStatus::Cancelled,
					'run_expired',
					sprintf(
						// Translators: %d is the number of days a run may last.
						_n( 'The run was cancelled because it was still unfinished after %d day.', 'The run was cancelled because it was still unfinished after %d days.', $days, 'specflux-site-agent' ),
						$days
					)
				);
				++$cancelled;
			}

			if ( count( $page ) < self::PAGE ) {
				break;
			}
		}

		return $cancelled;
	}

	/**
	 * Delete old terminal runs and their steps.
	 *
	 * @return int Runs deleted.
	 */
	public function pruneOld(): int {
		/**
		 * Days a finished (completed, failed or cancelled) run is kept before it
		 * and its steps are deleted. 0 keeps runs forever.
		 *
		 * @param int $days Default 90.
		 */
		$days = apply_filters( 'specflux_site_agent_run_retention_days', self::RETENTION_DAYS_DEFAULT );
		$days = is_numeric( $days ) ? (int) $days : self::RETENTION_DAYS_DEFAULT;
		if ( $days < 1 ) {
			return 0;
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', Clock::now() - $days * DAY_IN_SECONDS );
		$deleted = 0;
		$cursor  = 0;

		for ( $batch = 0; $batch < self::PRUNE_MAX_BATCHES; ++$batch ) {
			$ids = $this->store->findTerminalIdsFinishedBefore( $cutoff, self::PAGE, $cursor );
			if ( array() === $ids ) {
				break;
			}
			$cursor = max( $ids );

			// A follow-up still in flight reads its source run: keep that one.
			$keep     = $this->store->idsFollowedUpByLiveRuns( $ids );
			$deleted += $this->store->deleteRunsWithSteps( array_values( array_diff( $ids, $keep ) ) );

			if ( count( $ids ) < self::PAGE ) {
				break;
			}
		}

		return $deleted;
	}

	/** Hook both jobs. The callbacks build the runner lazily, so a request that never fires them pays nothing. */
	public static function register( callable $factory ): void {
		add_action(
			self::EXPIRE_HOOK,
			static function () use ( $factory ): void {
				$lifecycle = $factory();
				if ( $lifecycle instanceof self ) {
					$lifecycle->expireOverdue();
				}
			}
		);
		add_action(
			self::PRUNE_HOOK,
			static function () use ( $factory ): void {
				$lifecycle = $factory();
				if ( $lifecycle instanceof self ) {
					$lifecycle->pruneOld();
				}
			}
		);
	}

	/** Make sure both daily events exist (activation, and self-healing on admin/cron loads after an upgrade). */
	public static function schedule(): void {
		foreach ( array( self::EXPIRE_HOOK, self::PRUNE_HOOK ) as $hook ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( Clock::now() + HOUR_IN_SECONDS, 'daily', $hook );
			}
		}
	}

	/** Remove both events (deactivation, uninstall). */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EXPIRE_HOOK );
		wp_clear_scheduled_hook( self::PRUNE_HOOK );
	}
}
