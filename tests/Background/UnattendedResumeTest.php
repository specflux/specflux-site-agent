<?php
/**
 * Off-screen resume, the approval TTL filter, approval expiry and the park
 * email (0.4 U3).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Background;

use Belayline\Plugin\Support\RequestContext;
use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Background\ApprovalEvents;
use Specflux\SiteAgent\Background\BackgroundDriver;
use Specflux\SiteAgent\Background\ParkNotifier;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tests\Run\FakeGateway;
use Specflux\SiteAgent\Tests\Run\RecordingBridge;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WP_Error;
use wpdb;

final class UnattendedResumeTest extends TestCase {

	private const T0 = 1_800_000_000;

	/** A string that exists only in the parked call's arguments. */
	private const SECRET_ARG = 'SECRET-ARG-9f3a';

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	private BackgroundDriver $driver;

	private ApprovalEvents $events;

	private ParkNotifier $notifier;

	/** @var list<array{int,int,string,?array<string,string>}> */
	private array $ticks = array();

	/** @var callable|null Custom tick; null = record and report a running run. */
	private $tickResult = null;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$GLOBALS['wpdb']       = $this->db;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 0;
		$GLOBALS['specflux_site_agent_test_as']              = array();
		$GLOBALS['specflux_site_agent_test_as_version']      = '4.2.0';
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_cron']            = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		$GLOBALS['specflux_site_agent_test_mail']            = array();
		$GLOBALS['specflux_site_agent_test_blogname']        = 'Acme Shop';
		$GLOBALS['specflux_site_agent_test_users']           = array(
			1 => (object) array(
				'ID'         => 1,
				'user_email' => 'owner@example.test',
			),
		);
		$GLOBALS['specflux_site_agent_test_user_caps']       = array( 'manage_options' => true );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
		Clock::useFixed( self::T0 );
		RequestContext::reset();

		$this->ticks      = array();
		$this->tickResult = null;
		$this->driver     = new BackgroundDriver(
			$this->store,
			$this->runner,
			function ( int $run_id, int $step_count, string $driver, ?array $resume = null ): array|WP_Error {
				$this->ticks[] = array( $run_id, $step_count, $driver, $resume );
				if ( null !== $this->tickResult ) {
					return ( $this->tickResult )( $run_id, $step_count, $driver, $resume );
				}

				return array(
					'run'         => array( 'status' => 'running' ),
					'continue'    => false,
					'retry_after' => 0,
				);
			}
		);
		$this->events     = new ApprovalEvents( $this->store, $this->runner, $this->driver );
		$this->notifier   = new ParkNotifier( $this->store );
	}

	protected function tearDown(): void {
		Clock::reset();
		remove_all_filters();
		RequestContext::reset();
		specflux_site_agent_test_no_agent_safety();
		$GLOBALS['specflux_site_agent_test_as']              = array();
		$GLOBALS['specflux_site_agent_test_mail']            = array();
		$GLOBALS['specflux_site_agent_test_user_caps']       = array();
		$GLOBALS['specflux_site_agent_test_users']           = array();
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 0;
		unset( $GLOBALS['wpdb'] );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function newRun( string $goal = 'Tidy the shop pages', GateMode $mode = GateMode::AgentSafety ): int {
		return $this->store->createRun( 1, 'test-consumer', $goal, array( 'agsafe-smoke/*' ), Budget::defaults(), null, null, null, $mode );
	}

	/** Park a run on an approval (or a hold when `$hold` is set). */
	private function parkOnApproval( int $run_id, string $approval_id = 'ap-1', ?string $hold = null ): int {
		$seq = $this->store->appendStep(
			$run_id,
			StepKind::Approval,
			array(
				'parked'           => true,
				'approval_id'      => $approval_id,
				'verb'             => 'agsafe-smoke/spend',
				'tool_name'        => 'agsafe-smoke/spend',
				'function_call_id' => 'c1',
				'args'             => array( 'note' => self::SECRET_ARG ),
				'remaining'        => array(),
			) + ( null !== $hold ? array( 'hold' => $hold ) : array() ),
			'agsafe-smoke/spend',
			$approval_id,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'status' => RunStatus::AwaitingApproval->value ) );

		return $seq;
	}

	private function parkOnQuestion( int $run_id ): void {
		$this->store->appendStep(
			$run_id,
			StepKind::Question,
			array(
				'parked'           => true,
				'function_call_id' => 'q1',
				'question'         => self::SECRET_ARG,
			),
			null,
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'status' => RunStatus::AwaitingUser->value ) );
	}

	/** @return list<array<string,mixed>> */
	private function queued(): array {
		return array_values( $GLOBALS['specflux_site_agent_test_as'] );
	}

	/** @return array<string,mixed> */
	private function parkedResult( string $status ): array {
		return array(
			'run'         => array( 'status' => $status ),
			'continue'    => false,
			'retry_after' => 0,
		);
	}

	// ------------------------------------------------------------------
	// Resume on resolution
	// ------------------------------------------------------------------

	public function test_an_approved_resolution_queues_an_immediate_background_tick_with_the_approve_resume(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );

		$this->events->onResolved( 'ap-1', 'approved' );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( BackgroundDriver::HOOK, $queued[0]['hook'] );
		$this->assertSame( self::T0, $queued[0]['when'] );
		$this->assertSame(
			array(
				'run_id'      => $run_id,
				'resume'      => array( 'action' => 'approve' ),
				'approval_id' => 'ap-1',
			),
			$queued[0]['args']
		);
	}

	public function test_a_rejected_resolution_carries_the_reject_resume(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );

		$this->events->onResolved( 'ap-1', 'rejected' );

		$this->assertSame( array( 'action' => 'reject' ), $this->queued()[0]['args']['resume'] );
	}

	public function test_nothing_is_queued_when_no_run_is_parked_on_that_approval(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );

		$this->events->onResolved( 'someone-elses', 'approved' );
		$this->events->onResolved( 'ap-1', 'weird' );
		$this->events->onResolved( '', 'approved' );

		$this->assertSame( array(), $this->queued() );
	}

	public function test_nothing_is_queued_when_the_run_has_moved_on_to_a_later_park(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );
		$this->parkOnApproval( $run_id, 'ap-2' );

		$this->events->onResolved( 'ap-1', 'approved' );

		$this->assertSame( array(), $this->queued() );
	}

	public function test_nothing_is_queued_for_a_hold_park_or_a_run_not_awaiting_approval(): void {
		$hold = $this->newRun();
		$this->parkOnApproval( $hold, 'ap-hold', 'rate_limited_minute' );
		$done = $this->newRun();
		$this->parkOnApproval( $done, 'ap-done' );
		$this->store->updateRun( $done, array( 'status' => RunStatus::Completed->value ) );

		$this->events->onResolved( 'ap-hold', 'approved' );
		$this->events->onResolved( 'ap-done', 'approved' );

		$this->assertSame( array(), $this->queued() );
	}

	public function test_nothing_is_queued_when_the_background_queue_is_unavailable(): void {
		$GLOBALS['specflux_site_agent_test_as_version'] = '3.0.0';
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );

		$this->events->onResolved( 'ap-1', 'approved' );

		$this->assertSame( array(), $this->queued() );
	}

	public function test_the_queued_resume_ticks_once_as_background_with_the_resume_and_the_owner(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );
		$seen_user        = null;
		$this->tickResult = function ( int $id ) use ( &$seen_user ): array {
			$seen_user = get_current_user_id();
			$this->store->updateRun( $id, array( 'status' => RunStatus::Running->value ) );

			return $this->parkedResult( 'running' );
		};

		$this->events->onResolved( 'ap-1', 'approved' );
		$args = $this->queued()[0]['args'];
		$this->driver->handle( ...$args );

		$this->assertSame( array( array( $run_id, 1, 'background', array( 'action' => 'approve' ) ) ), $this->ticks );
		$this->assertSame( 1, $seen_user, 'the tick ran as the run owner' );
		$this->assertSame( 0, get_current_user_id(), 'and the user is reset afterwards' );
	}

	public function test_a_duplicate_event_or_a_screen_resume_makes_the_second_tick_skip(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );
		$this->tickResult = function ( int $id ): array {
			$this->store->updateRun( $id, array( 'status' => RunStatus::Running->value ) );

			return $this->parkedResult( 'running' );
		};

		$this->events->onResolved( 'ap-1', 'approved' );
		$this->events->onResolved( 'ap-1', 'approved' );
		$this->assertCount( 1, $this->queued(), 'a pending identical resume is queued once' );

		$args = $this->queued()[0]['args'];
		$this->driver->handle( ...$args );
		$this->driver->handle( ...$args ); // The duplicate action firing again.

		$this->assertCount( 1, $this->ticks, 'the run is no longer parked on ap-1, so the second tick skips' );
	}

	public function test_a_resume_is_skipped_when_a_person_resumed_on_screen_first(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );
		$this->events->onResolved( 'ap-1', 'approved' );
		$args = $this->queued()[0]['args'];

		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Running->value ) );
		$this->driver->handle( ...$args );

		$this->assertSame( array(), $this->ticks );
	}

	public function test_a_malformed_resume_in_the_queue_is_ignored(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );

		$this->driver->handle( run_id: $run_id, resume: array( 'action' => 'delete-everything' ), approval_id: 'ap-1' );
		$this->driver->handle( run_id: $run_id, resume: 'approve', approval_id: 'ap-1' );
		$this->driver->handle( run_id: $run_id, resume: array( 'action' => 'approve' ), approval_id: 'ap-OTHER' );
		$this->driver->handle( run_id: $run_id, resume: array( 'action' => 'approve' ) );

		$this->assertSame( array(), $this->ticks );
	}

	public function test_a_resume_that_hits_a_409_is_requeued_with_the_resume_intact(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );
		$this->tickResult = static fn (): WP_Error => new WP_Error( 'specflux_site_agent_conflict', 'busy', array( 'status' => 409 ) );

		$this->driver->handle( run_id: $run_id, resume: array( 'action' => 'approve' ), approval_id: 'ap-1' );

		$queued = $this->queued();
		$this->assertCount( 1, $queued );
		$this->assertSame( array( 'action' => 'approve' ), $queued[0]['args']['resume'] );
		$this->assertSame( 'ap-1', $queued[0]['args']['approval_id'] );
	}

	public function test_a_real_runner_tick_resumes_a_rejected_park_and_finishes_the_run(): void {
		specflux_site_agent_test_grants( true );
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Understood, stopping.' ) ) ), 10, 5 );
		$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'Understood, stopping.' ) ) ), 10, 5 );
		$driver                  = new BackgroundDriver(
			$this->store,
			$this->runner,
			fn ( int $id, int $count, string $who, ?array $resume = null ): array|WP_Error => $this->runner->tick( $id, $count, $resume, $who )
		);
		$events                  = new ApprovalEvents( $this->store, $this->runner, $driver );

		$events->onResolved( 'ap-1', 'rejected' );
		$driver->handle( ...$this->queued()[0]['args'] );

		$this->assertSame( RunStatus::Completed, $this->store->getRun( $run_id )->status );
		$this->assertSame( 'background', $this->store->getRun( $run_id )->lastDriver );
	}

	// ------------------------------------------------------------------
	// Pending TTL
	// ------------------------------------------------------------------

	public function test_a_site_agent_correlation_gets_seven_days_by_default(): void {
		$this->assertSame( 7 * 86400, ApprovalEvents::pendingTtl( 3600, 'agsafe-smoke/spend', 'specflux:run:42' ) );
	}

	public function test_the_wait_days_filter_is_honoured_and_clamped_to_1_30(): void {
		add_filter( 'specflux_site_agent_approval_wait_days', static fn (): int => 3 );
		$this->assertSame( 3 * 86400, ApprovalEvents::pendingTtl( 3600, 'v', 'specflux:run:1' ) );

		remove_all_filters( 'specflux_site_agent_approval_wait_days' );
		add_filter( 'specflux_site_agent_approval_wait_days', static fn (): int => 0 );
		$this->assertSame( 86400, ApprovalEvents::pendingTtl( 3600, 'v', 'specflux:run:1' ) );

		remove_all_filters( 'specflux_site_agent_approval_wait_days' );
		add_filter( 'specflux_site_agent_approval_wait_days', static fn (): int => 400 );
		$this->assertSame( 30 * 86400, ApprovalEvents::pendingTtl( 3600, 'v', 'specflux:run:1' ) );

		remove_all_filters( 'specflux_site_agent_approval_wait_days' );
		add_filter( 'specflux_site_agent_approval_wait_days', static fn (): string => 'soon' );
		$this->assertSame( 7 * 86400, ApprovalEvents::pendingTtl( 3600, 'v', 'specflux:run:1' ) );
	}

	public function test_other_correlations_keep_belaylines_ttl(): void {
		$this->assertSame( 3600, ApprovalEvents::pendingTtl( 3600, 'v', 'wp-cli:abc' ) );
		$this->assertSame( 1234, ApprovalEvents::pendingTtl( 1234, 'v', '' ) );
		$this->assertSame( 3600, ApprovalEvents::pendingTtl( 3600, 'v', 'x-specflux:run:1' ) );
	}

	// ------------------------------------------------------------------
	// Expiry
	// ------------------------------------------------------------------

	public function test_an_expired_approval_fails_the_parked_run_with_a_readable_line(): void {
		$run_id = $this->newRun();
		$this->parkOnApproval( $run_id, 'ap-1' );

		$this->events->onExpired( 'ap-1', 'agsafe-smoke/spend' );

		$run = $this->store->getRun( $run_id );
		$this->assertSame( RunStatus::Failed, $run->status );
		$this->assertSame( 'approval_expired', $run->error['code'] ?? '' );
		$this->assertSame( 'The approval for agsafe-smoke/spend expired before anyone approved it.', $run->error['message'] ?? '' );
		$this->assertSame( 'The approval for agsafe-smoke/spend expired before anyone approved it.', $run->result['unverified'] ?? '' );
		$this->assertNotNull( $run->finishedAtUtc );
	}

	public function test_an_unrelated_or_stale_expiry_is_a_no_op(): void {
		$parked = $this->newRun();
		$this->parkOnApproval( $parked, 'ap-1' );
		$moved = $this->newRun();
		$this->parkOnApproval( $moved, 'ap-old' );
		$this->parkOnApproval( $moved, 'ap-new' );

		$this->events->onExpired( 'nobody', 'v' );
		$this->events->onExpired( 'ap-old', 'v' );
		$this->events->onExpired( '', 'v' );

		$this->assertSame( RunStatus::AwaitingApproval, $this->store->getRun( $parked )->status );
		$this->assertSame( RunStatus::AwaitingApproval, $this->store->getRun( $moved )->status );
	}

	// ------------------------------------------------------------------
	// Park email
	// ------------------------------------------------------------------

	public function test_a_background_tick_that_parks_on_a_question_emails_the_owner_once(): void {
		$run_id = $this->newRun( "Rewrite\nthe   about page" );
		$this->parkOnQuestion( $run_id );

		$this->assertTrue( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' ) );
		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' ), 'same park, no second mail' );

		$mail = $GLOBALS['specflux_site_agent_test_mail'];
		$this->assertCount( 1, $mail );
		$this->assertSame( 'owner@example.test', $mail[0]['to'] );
		$this->assertStringContainsString( 'Acme Shop', $mail[0]['subject'] );
		$this->assertStringContainsString( 'has a question for you', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Goal: Rewrite the about page', $mail[0]['body'] );
		$this->assertStringContainsString( 'admin.php?page=' . \Specflux\SiteAgent\Admin\RunsScreen::SLUG . '&run_id=' . $run_id, $mail[0]['body'] );
		$this->assertNotNull( $this->store->getRun( $run_id )->notifiedParkStep );
	}

	public function test_a_later_park_mails_again(): void {
		$run_id = $this->newRun();
		$this->parkOnQuestion( $run_id );
		$this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' );
		$this->parkOnQuestion( $run_id );

		$this->assertTrue( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' ) );
		$this->assertCount( 2, $GLOBALS['specflux_site_agent_test_mail'] );
	}

	public function test_plan_and_built_in_approval_parks_mail_with_their_own_wording(): void {
		$plan = $this->newRun();
		$this->store->appendStep( $plan, StepKind::Plan, array( 'parked' => true ), null, null, 'parked' );
		$this->store->updateRun( $plan, array( 'status' => RunStatus::AwaitingPlan->value ) );
		$approval = $this->newRun( 'Approve me', GateMode::BuiltIn );
		$this->parkOnApproval( $approval, 'builtin:abc' );

		$this->notifier->afterTick( $plan, $this->parkedResult( 'awaiting_plan' ), 'background' );
		$this->notifier->afterTick( $approval, $this->parkedResult( 'awaiting_approval' ), 'background' );

		$mail = $GLOBALS['specflux_site_agent_test_mail'];
		$this->assertCount( 2, $mail );
		$this->assertStringContainsString( 'needs you to approve its plan', $mail[0]['subject'] );
		$this->assertStringContainsString( 'needs your approval', $mail[1]['subject'] );
	}

	public function test_the_goal_is_trimmed_to_200_characters(): void {
		$run_id = $this->newRun( str_repeat( 'a', 500 ) );
		$this->parkOnQuestion( $run_id );

		$this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' );

		$this->assertStringContainsString( 'Goal: ' . str_repeat( 'a', 199 ) . "\u{2026}", $GLOBALS['specflux_site_agent_test_mail'][0]['body'] );
		$this->assertStringNotContainsString( str_repeat( 'a', 201 ), $GLOBALS['specflux_site_agent_test_mail'][0]['body'] );
	}

	public function test_a_screen_driven_tick_sends_nothing(): void {
		$run_id = $this->newRun();
		$this->parkOnQuestion( $run_id );

		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'screen' ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_mail'] );
		$this->assertNull( $this->store->getRun( $run_id )->notifiedParkStep );
	}

	public function test_a_belayline_mode_approval_park_sends_nothing(): void {
		$run_id = $this->newRun( 'x', GateMode::AgentSafety );
		$this->parkOnApproval( $run_id );

		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_approval' ), 'background' ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_mail'] );
	}

	public function test_a_hold_park_sends_nothing_even_in_built_in_mode(): void {
		$run_id = $this->newRun( 'x', GateMode::BuiltIn );
		$this->parkOnApproval( $run_id, 'ap-1', 'rate_limited_minute' );

		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_approval' ), 'background' ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_mail'] );
	}

	public function test_non_parked_results_and_errors_send_nothing(): void {
		$run_id = $this->newRun();
		$this->parkOnQuestion( $run_id );

		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'running' ), 'background' ) );
		$this->assertFalse( $this->notifier->afterTick( $run_id, new WP_Error( 'x', 'y' ), 'background' ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_mail'] );
	}

	public function test_the_notify_filter_can_veto_and_the_park_is_still_marked_notified(): void {
		$run_id = $this->newRun();
		$this->parkOnQuestion( $run_id );
		$kinds = array();
		add_filter(
			'specflux_site_agent_notify_park',
			static function ( bool $notify, $run, string $kind ) use ( &$kinds ): bool {
				$kinds[] = $kind;

				return false;
			},
			10,
			3
		);

		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' ) );

		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_mail'] );
		$this->assertSame( array( 'question' ), $kinds );
		$this->assertNotNull( $this->store->getRun( $run_id )->notifiedParkStep );
	}

	public function test_the_email_filter_can_rewrite_every_part(): void {
		$run_id = $this->newRun();
		$this->parkOnQuestion( $run_id );
		add_filter(
			'specflux_site_agent_park_email',
			static fn ( array $email ): array => array(
				'to'      => 'ops@example.test',
				'subject' => 'Custom subject',
				'body'    => 'Custom body',
				'headers' => array( 'X-Test: 1' ),
			) + $email,
			10,
			3
		);

		$this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' );

		$mail = $GLOBALS['specflux_site_agent_test_mail'][0];
		$this->assertSame( 'ops@example.test', $mail['to'] );
		$this->assertSame( 'Custom subject', $mail['subject'] );
		$this->assertSame( 'Custom body', $mail['body'] );
		$this->assertSame( array( 'X-Test: 1' ), $mail['headers'] );
	}

	public function test_the_email_never_carries_tool_arguments_or_the_question(): void {
		$question = $this->newRun();
		$this->parkOnQuestion( $question );
		$approval = $this->newRun( 'Approve', GateMode::BuiltIn );
		$this->parkOnApproval( $approval, 'builtin:abc' );

		$this->notifier->afterTick( $question, $this->parkedResult( 'awaiting_user' ), 'background' );
		$this->notifier->afterTick( $approval, $this->parkedResult( 'awaiting_approval' ), 'background' );

		$mail = $GLOBALS['specflux_site_agent_test_mail'];
		$this->assertCount( 2, $mail );
		foreach ( $mail as $message ) {
			$this->assertStringNotContainsString( self::SECRET_ARG, $message['subject'] . $message['body'] );
		}
	}

	public function test_an_owner_without_an_email_address_gets_nothing(): void {
		$GLOBALS['specflux_site_agent_test_users'] = array( 1 => (object) array( 'ID' => 1 ) );
		$run_id                                    = $this->newRun();
		$this->parkOnQuestion( $run_id );

		$this->assertFalse( $this->notifier->afterTick( $run_id, $this->parkedResult( 'awaiting_user' ), 'background' ) );
	}
}
