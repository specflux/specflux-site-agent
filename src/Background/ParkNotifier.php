<?php
/**
 * The park email: tells a run's owner a run they are not watching is waiting
 * on them.
 *
 * Sent at most once per park, only after a BACKGROUND tick (a screen tick means
 * the owner is looking at the run), and never for a Belayline-mode approval
 * (Belayline already emails those) or a hold (a hold waits on the clock). The
 * mail carries the site, the goal and the kind of park, plus a deep link:
 * never tool arguments and never model output.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Background;

use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\Run;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\RunStore;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\Step;
use Specflux\SiteAgent\Run\StepKind;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a tick's end warrants a park email and sends it.
 */
final class ParkNotifier {

	/** Longest goal excerpt put in the mail. */
	public const GOAL_CHARS = 200;

	public function __construct( private readonly RunStore $store ) {
	}

	/**
	 * Called once after EVERY tick, next to the hand-off.
	 *
	 * @param array<string,mixed>|WP_Error $result The tick's result.
	 * @param string                       $driver Who drove the tick.
	 * @return bool True when an email was handed to wp_mail.
	 */
	public function afterTick( int $run_id, array|WP_Error $result, string $driver ): bool {
		if ( Runner::DRIVER_BACKGROUND !== $driver || ! is_array( $result ) ) {
			return false;
		}

		$status = (string) ( $result['run']['status'] ?? '' );
		if ( ! in_array( $status, array( RunStatus::AwaitingApproval->value, RunStatus::AwaitingUser->value, RunStatus::AwaitingPlan->value ), true ) ) {
			return false;
		}

		$run = $this->store->getRun( $run_id );
		if ( null === $run || ! $run->status->isParked() ) {
			return false;
		}

		$park = $this->currentPark( $run );
		if ( null === $park || $run->notifiedParkStep === $park->id ) {
			return false;
		}

		$kind = $this->kindOf( $run, $park );
		if ( null === $kind ) {
			return false; // A hold, or a Belayline-mode approval.
		}

		// Once per park, even when the mail is vetoed or cannot be sent: a
		// failing mailer must not be retried by every later tick.
		$this->store->updateRun( $run->id, array( 'notified_park_step' => $park->id ) );

		/**
		 * Whether to email the run's owner that a run is waiting on them.
		 *
		 * @param bool   $notify Default true.
		 * @param Run    $run    The parked run.
		 * @param string $kind   'question', 'plan' or 'approval'.
		 */
		if ( true !== apply_filters( 'specflux_site_agent_notify_park', true, $run, $kind ) ) {
			return false;
		}

		$user = get_userdata( $run->userId );
		$to   = is_object( $user ) && isset( $user->user_email ) ? (string) $user->user_email : '';
		if ( '' === $to ) {
			return false;
		}

		$email = $this->compose( $run, $kind, $to );

		/**
		 * The park email before it is sent. Return an array with the same keys
		 * (an unusable return keeps the default). Never put tool arguments or
		 * model output in it.
		 *
		 * @param array{to:string,subject:string,body:string,headers:list<string>} $email Default email.
		 * @param Run                                                               $run   The parked run.
		 * @param string                                                            $kind  'question', 'plan' or 'approval'.
		 */
		$filtered = apply_filters( 'specflux_site_agent_park_email', $email, $run, $kind );
		if ( is_array( $filtered ) ) {
			$email = array(
				'to'      => is_string( $filtered['to'] ?? null ) ? $filtered['to'] : $email['to'],
				'subject' => is_string( $filtered['subject'] ?? null ) ? $filtered['subject'] : $email['subject'],
				'body'    => is_string( $filtered['body'] ?? null ) ? $filtered['body'] : $email['body'],
				'headers' => is_array( $filtered['headers'] ?? null ) ? array_values( array_filter( $filtered['headers'], 'is_string' ) ) : $email['headers'],
			);
		}
		if ( '' === $email['to'] ) {
			return false;
		}

		return (bool) wp_mail( $email['to'], $email['subject'], $email['body'], $email['headers'] );
	}

	/** The newest parked step of a human-park kind. */
	private function currentPark( Run $run ): ?Step {
		foreach ( array_reverse( $this->store->getSteps( $run->id ) ) as $step ) {
			if ( 'parked' === $step->status && in_array( $step->kind, array( StepKind::Approval, StepKind::Question, StepKind::Plan ), true ) ) {
				return $step;
			}
		}

		return null;
	}

	/** 'question' | 'plan' | 'approval', or null when no email is due for this park. */
	private function kindOf( Run $run, Step $park ): ?string {
		if ( StepKind::Question === $park->kind ) {
			return 'question';
		}
		if ( StepKind::Plan === $park->kind ) {
			return 'plan';
		}

		// Approvals: a hold waits on the clock; a Belayline-mode approval is
		// already emailed by Belayline. Only the built-in gate is ours to mail.
		$hold = is_array( $park->messageArray ) ? ( $park->messageArray['hold'] ?? null ) : null;
		if ( ( is_string( $hold ) && '' !== $hold ) || GateMode::BuiltIn !== $run->gateMode ) {
			return null;
		}

		return 'approval';
	}

	/**
	 * @return array{to:string,subject:string,body:string,headers:list<string>}
	 */
	private function compose( Run $run, string $kind, string $to ): array {
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$goal = trim( (string) preg_replace( '/\s+/u', ' ', $run->goal ) );
		if ( mb_strlen( $goal ) > self::GOAL_CHARS ) {
			$goal = rtrim( mb_substr( $goal, 0, self::GOAL_CHARS - 1 ) ) . "\u{2026}";
		}

		$what = match ( $kind ) {
			'question' => __( 'has a question for you', 'specflux-site-agent' ),
			'plan'     => __( 'needs you to approve its plan', 'specflux-site-agent' ),
			default    => __( 'needs your approval', 'specflux-site-agent' ),
		};
		$link = RunsScreen::url( array( 'run_id' => $run->id ) );

		return array(
			'to'      => $to,
			// Translators: 1: site name, 2: what the run needs, e.g. "needs your approval".
			'subject' => sprintf( __( '[%1$s] A Site Agent run %2$s', 'specflux-site-agent' ), $site, $what ),
			'body'    => sprintf(
				// Translators: 1: site name, 2: what the run needs, 3: the run goal, 4: link to the run.
				__( "A Site Agent run on %1\$s %2\$s.\n\nGoal: %3\$s\n\nOpen the run to answer it:\n%4\$s\n", 'specflux-site-agent' ),
				$site,
				$what,
				$goal,
				$link
			),
			'headers' => array(),
		);
	}
}
