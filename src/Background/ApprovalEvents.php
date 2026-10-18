<?php
/**
 * Belayline approval events, as Site Agent hears them: resolved (resume the
 * parked run off-screen), expired (end it) and the longer pending lifetime
 * Site Agent asks for its own approvals.
 *
 * Every hook here is feature-detected by construction: Belayline may be
 * absent, or older than 0.5, in which case these actions and the TTL filter
 * simply never fire and the person resumes from the Runs screen as before.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Background;

use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\RunStore;
use Specflux\SiteAgent\Run\Runner;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Reacts to Belayline's approval lifecycle hooks.
 */
final class ApprovalEvents {

	/** Prefix of every correlation id Site Agent runs under (GrantBridge::correlationFor). */
	public const CORRELATION_PREFIX = 'specflux:run:';

	/** Default days a Site Agent approval may wait for a person. */
	public const WAIT_DAYS_DEFAULT = 7;

	public function __construct(
		private readonly RunStore $store,
		private readonly Runner $runner,
		private readonly BackgroundDriver $driver
	) {
	}

	/**
	 * `belayline_pending_ttl_seconds( int $ttl, string $verb, string $correlationId )`.
	 *
	 * Site Agent's own approvals wait days, not Belayline's default hour: the
	 * owner may be away. Every other correlation id keeps Belayline's value.
	 *
	 * @param mixed $ttl            Belayline's TTL so far.
	 * @param mixed $verb           The verb awaiting approval (unused).
	 * @param mixed $correlation_id The request's correlation id.
	 * @return mixed The TTL: days * 86400 for a Site Agent run, `$ttl` unchanged otherwise.
	 */
	public static function pendingTtl( mixed $ttl, mixed $verb = '', mixed $correlation_id = '' ): mixed {
		unset( $verb );
		if ( ! is_string( $correlation_id ) || ! str_starts_with( $correlation_id, self::CORRELATION_PREFIX ) ) {
			return $ttl;
		}

		/**
		 * Days a Site Agent approval may wait for a person before Belayline expires it
		 * (and the run ends with `approval_expired`).
		 *
		 * @param int $days Default 7; clamped to 1-30.
		 */
		$days = apply_filters( 'specflux_site_agent_approval_wait_days', self::WAIT_DAYS_DEFAULT );
		$days = is_numeric( $days ) ? (int) $days : self::WAIT_DAYS_DEFAULT;

		return max( 1, min( 30, $days ) ) * DAY_IN_SECONDS;
	}

	/**
	 * `belayline_approval_resolved( string $id, 'approved'|'rejected', int $byUserId )`.
	 *
	 * @param mixed $approval_id Approval id.
	 * @param mixed $outcome     'approved' or 'rejected'.
	 */
	public function onResolved( mixed $approval_id, mixed $outcome = '' ): void {
		if ( is_string( $approval_id ) && '' !== $approval_id && is_string( $outcome ) ) {
			$this->driver->resumeOnResolution( $approval_id, $outcome );
		}
	}

	/**
	 * `belayline_approval_expired( string $id, string $verb )`: nobody approved
	 * in time, so a run still parked on that approval can never continue.
	 *
	 * @param mixed $approval_id Approval id.
	 * @param mixed $verb        The verb that was awaiting approval.
	 */
	public function onExpired( mixed $approval_id, mixed $verb = '' ): void {
		if ( ! is_string( $approval_id ) || '' === $approval_id ) {
			return;
		}

		$verb = is_string( $verb ) ? $verb : '';
		foreach ( $this->store->findAwaitingByApprovalId( $approval_id ) as $run ) {
			if ( RunStatus::AwaitingApproval !== $run->status || $this->runner->parkedApprovalId( $run ) !== $approval_id ) {
				continue;
			}

			$this->runner->endRun(
				$run,
				RunStatus::Failed,
				'approval_expired',
				sprintf(
					// Translators: %s is the name of the action that was waiting for approval.
					__( 'The approval for %s expired before anyone approved it.', 'specflux-site-agent' ),
					'' !== $verb ? $verb : __( 'an action', 'specflux-site-agent' )
				)
			);
		}
	}
}
