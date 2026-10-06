<?php
/**
 * 0.4 S5: SenroGate's pause and rate-limit denials park the run; they never
 * fail it, and the held call is retried (and counted) exactly once.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\StepKind;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolOutcome;
use SenroFlux_Test_Fake_Ability;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class GateHoldTest extends TestCase {

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	/** @var string|null The SenroGate reason the fake ability denies with; null = allow. */
	private ?string $denyReason = null;

	private int $executions = 0;

	protected function setUp(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$this->store     = new WpdbRunStore( $db );
		$this->gateway   = new FakeGateway();
		$this->runner    = new Runner( $this->store, new ToolExecutor(), $this->gateway, new RecordingBridge() );

		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_transients']      = array();
		$GLOBALS['senroflux_test_agent_safety']    = null;

		$this->denyReason = null;
		$this->executions = 0;

		$ability             = new SenroFlux_Test_Fake_Ability(
			'agsafe-smoke/blocked',
			permission_result: fn () => null === $this->denyReason
				? true
				: new WP_Error(
					'agent_safety_denied',
					'Blocked by SenroGate (Smoke): ' . $this->denyReason,
					array(
						'status' => 403,
						'verb'   => 'agsafe-smoke/blocked',
						'tier'   => 1,
					)
				)
		);
		$ability->on_execute = function (): void {
			++$this->executions;
		};

		$GLOBALS['senroflux_test_abilities'] = array( 'agsafe-smoke/blocked' => $ability );

		add_filter( 'senroflux_verb_map', static fn (): array => array( 'agsafe-smoke/blocked' => 0 ), 10, 0 );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_verb_map' );
		Clock::reset();
		$GLOBALS['senroflux_test_agent_safety'] = null;
	}

	private function createRun( GateMode $mode = GateMode::AgentSafety ): int {
		return $this->store->createRun( 1, 'test-consumer', 'Do the thing', array( 'agsafe-smoke/*' ), Budget::defaults(), null, null, null, $mode );
	}

	private function scriptCall(): void {
		$this->gateway->script[] = new ModelTurn(
			new ModelMessage( array( new MessagePart( new FunctionCall( 'call_1', 'wpab__agsafe-smoke__blocked', array() ) ) ) ),
			10,
			5
		);
	}

	private function scriptDone(): void {
		// A text-only turn is nudged once to verify, so it takes two to finish.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->gateway->script[] = new ModelTurn( new ModelMessage( array( new MessagePart( 'All done.' ) ) ), 10, 5 );
		}
	}

	private function tick( int $run_id ): array {
		$result = $this->runner->tick( $run_id, $this->store->getRun( $run_id )->stepCount, null );
		$this->assertIsArray( $result );

		return $result;
	}

	private function toolResults( int $run_id ): int {
		return count( array_filter( $this->store->getSteps( $run_id ), static fn ( $s ): bool => StepKind::ToolResult === $s->kind ) );
	}

	private function pauseApi( int $since, string $reason ): void {
		$GLOBALS['senroflux_test_agent_safety'] = new class( $since, $reason ) {
			public function __construct( private int $since, private string $reason ) {
			}

			public function pause(): object {
				return new class( $this->since, $this->reason ) {
					public function __construct( private int $since, private string $reason ) {
					}

					public function state(): array {
						return array(
							'since'  => $this->since,
							'by'     => 1,
							'reason' => $this->reason,
						);
					}
				};
			}

			public function approvals(): ?object {
				return null;
			}

			public function grants(): ?object {
				return null;
			}
		};
	}

	public function test_a_pause_mid_run_parks_then_unpause_resumes_and_completes(): void {
		$this->pauseApi( 1700000000, 'Investigating a bad import' );
		$run_id           = $this->createRun();
		$this->denyReason = 'site_paused';
		$this->scriptCall();

		$parked = $this->tick( $run_id );

		$this->assertSame( RunStatus::AwaitingApproval->value, $parked['run']['status'] );
		$this->assertSame( 'paused', $parked['ui']['hold']['kind'] );
		$this->assertSame( 1700000000, $parked['ui']['hold']['paused_at'] );
		$this->assertSame( 'Investigating a bad import', $parked['ui']['hold']['paused_reason'] );
		$this->assertArrayNotHasKey( 'approval', $parked['ui'], 'a hold is not an approval card' );
		$this->assertSame( 0, $this->executions );
		$this->assertSame( 0, $this->toolResults( $run_id ), 'the denied call leaves no tool_result' );

		// Still paused: a tick re-surfaces the same card, adds no step, calls no model.
		$before = $this->store->getRun( $run_id );
		$again  = $this->tick( $run_id );
		$this->assertSame( RunStatus::AwaitingApproval->value, $again['run']['status'] );
		$this->assertSame( 'paused', $again['ui']['hold']['kind'] );
		$this->assertSame( array(), $again['new_steps'] );
		$this->assertSame( $before->stepCount, $this->store->getRun( $run_id )->stepCount );
		$this->assertCount( 1, $this->gateway->calls );

		// Unpaused: the SAME call is retried and the run completes.
		$this->denyReason = null;
		$this->scriptDone();
		$done = $this->tick( $run_id );

		$this->assertSame( RunStatus::Completed->value, $done['run']['status'] );
		$this->assertSame( 1, $this->executions, 'the held call ran exactly once, on the retry' );
		$this->assertSame( 1, $this->toolResults( $run_id ) );
	}

	public function test_a_rate_limit_parks_with_the_time_left_in_the_window_then_retries_later(): void {
		$run_id           = $this->createRun();
		$this->denyReason = 'rate_limited_calls_per_hour';
		Clock::useFixed( 7200 + 600 ); // 10 minutes into the hour window.
		$this->scriptCall();

		$parked = $this->tick( $run_id );

		$this->assertSame( RunStatus::AwaitingApproval->value, $parked['run']['status'] );
		$this->assertSame( 'rate_limited', $parked['ui']['hold']['kind'] );
		$this->assertSame( 3000, $parked['ui']['hold']['retry_after'], '50 minutes left in the hour window' );

		// Per-minute rule, 15 seconds before the window turns over.
		Clock::useFixed( 7200 + 45 );
		$this->denyReason = 'rate_limited_calls_per_minute';
		$minute           = $this->tick( $run_id );
		$this->assertSame( 15, $minute['ui']['hold']['retry_after'] );
		$this->assertSame( 0, $this->executions );

		// A later tick, window passed: retried and completed.
		Clock::useFixed( 7260 );
		$this->denyReason = null;
		$this->scriptDone();
		$done = $this->tick( $run_id );

		$this->assertSame( RunStatus::Completed->value, $done['run']['status'] );
		$this->assertSame( 1, $this->executions );
	}

	public function test_holds_and_their_retries_do_not_charge_budgets_twice(): void {
		$run_id           = $this->store->createRun(
			1,
			'test-consumer',
			'Do the thing',
			array( 'agsafe-smoke/*' ),
			array_merge( Budget::defaults(), array( 'max_tool_calls' => 1 ) )
		);
		$this->denyReason = 'rate_limited_calls_per_minute';
		$this->scriptCall();
		$this->tick( $run_id );
		$tokens = $this->store->getRun( $run_id );

		// Three more denied retries must not add steps, tokens or tool results.
		$this->tick( $run_id );
		$this->tick( $run_id );
		$this->tick( $run_id );
		$after = $this->store->getRun( $run_id );
		$this->assertSame( $tokens->stepCount, $after->stepCount );
		$this->assertSame( $tokens->tokensIn, $after->tokensIn );
		$this->assertSame( $tokens->tokensOut, $after->tokensOut );
		$this->assertCount( 1, $this->gateway->calls, 'a held tick makes no model call' );
		$this->assertSame( 0, $this->toolResults( $run_id ) );

		// With max_tool_calls = 1 the single real execution still fits: had
		// the denied attempts counted, the retry would trip the ceiling.
		$this->denyReason = null;
		$this->scriptDone();
		$done = $this->tick( $run_id );
		$this->assertSame( RunStatus::Completed->value, $done['run']['status'] );
		$this->assertSame( 1, $this->toolResults( $run_id ) );
	}

	public function test_a_reload_gets_the_hold_card_and_no_approval_card(): void {
		$this->pauseApi( 1700000000, '' );
		$run_id           = $this->createRun();
		$this->denyReason = 'site_paused';
		$this->scriptCall();
		$this->tick( $run_id );

		$run = $this->store->getRun( $run_id );
		$this->assertNull( $this->runner->parkedApprovalUi( $run ) );
		$hold = $this->runner->parkedHoldUi( $run );
		$this->assertSame( 'paused', $hold['kind'] ?? null );
	}

	public function test_a_pause_without_the_pause_api_still_parks_with_a_generic_card(): void {
		$run_id           = $this->createRun();
		$this->denyReason = 'site_paused';
		$this->scriptCall();

		$parked = $this->tick( $run_id );

		$this->assertSame( RunStatus::AwaitingApproval->value, $parked['run']['status'] );
		$this->assertNull( $parked['ui']['hold']['paused_at'] );
		$this->assertSame( '', $parked['ui']['hold']['paused_reason'] );
	}

	public function test_a_hold_that_lifts_into_an_approval_requirement_parks_for_approval(): void {
		$run_id           = $this->createRun();
		$this->denyReason = 'site_paused';
		$this->scriptCall();
		$this->tick( $run_id );

		$GLOBALS['senroflux_test_abilities']['agsafe-smoke/blocked'] = new SenroFlux_Test_Fake_Ability(
			'agsafe-smoke/blocked',
			permission_result: new WP_Error(
				'approval_required',
				'needs a human',
				array(
					'approval_id' => 'apr_9',
					'verb'        => 'agsafe-smoke/blocked',
					'tier'        => 2,
				)
			)
		);
		$next = $this->tick( $run_id );

		$this->assertSame( RunStatus::AwaitingApproval->value, $next['run']['status'] );
		$this->assertSame( 'apr_9', $next['ui']['approval']['approval_id'] );
		$this->assertNull( $this->runner->parkedHoldUi( $this->store->getRun( $run_id ) ) );
	}

	public function test_built_in_mode_never_turns_a_denial_into_a_hold(): void {
		$this->runner     = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new RecordingBridge(),
			null,
			null,
			null,
			null,
			null,
			new \Specflux\SenroFlux\Approval\GrantBridge(),
			null,
			static fn (): GateMode => GateMode::BuiltIn
		);
		$run_id           = $this->createRun( GateMode::BuiltIn );
		$this->denyReason = 'site_paused';
		$this->scriptCall();
		$this->scriptDone();

		$result = $this->tick( $run_id );

		$this->assertSame( RunStatus::Completed->value, $result['run']['status'] );
		$this->assertArrayNotHasKey( 'hold', $result['ui'] );
		$this->assertSame( 1, $this->toolResults( $run_id ), 'the denial flowed back to the model as a tool error' );
	}

	public function test_other_senrogate_denials_still_flow_back_to_the_model_as_errors(): void {
		foreach ( array( 'denied_by_class', 'denial_lockout', 'repeat_call', 'argument_cap_max_per_call' ) as $reason ) {
			$this->setUp();
			$run_id           = $this->createRun();
			$this->denyReason = $reason;
			$this->scriptCall();
			$this->scriptDone();

			$result = $this->tick( $run_id );

			$this->assertSame( RunStatus::Completed->value, $result['run']['status'], $reason );
			$this->assertArrayNotHasKey( 'hold', $result['ui'], $reason );
			$this->assertSame( 1, $this->toolResults( $run_id ), $reason );
			$this->assertSame( 0, $this->executions, $reason );
		}
	}

	public function test_transient_reason_parses_only_senrogate_pause_and_rate_limit_denials(): void {
		$this->assertSame( 'site_paused', ToolOutcome::transientReason( 'agent_safety_denied', 'Blocked by SenroGate (Site (core)): site_paused' ) );
		$this->assertSame( 'rate_limited_calls_per_minute', ToolOutcome::transientReason( 'agent_safety_denied', 'Blocked by SenroGate (Posts): rate_limited_calls_per_minute' ) );
		$this->assertNull( ToolOutcome::transientReason( 'agent_safety_denied', 'Blocked by SenroGate (Posts): denied_by_class' ) );
		$this->assertNull( ToolOutcome::transientReason( 'agent_safety_denied', 'site_paused is mentioned in passing: denied_by_class' ) );
		$this->assertNull( ToolOutcome::transientReason( 'other_code', 'Blocked by SenroGate (Posts): site_paused' ) );
	}

	public function test_transient_reason_prefers_the_error_data_reason_over_the_message(): void {
		$data = array(
			'status' => 403,
			'verb'   => 'x/y',
			'tier'   => 1,
			'reason' => 'site_paused',
		);
		$this->assertSame( 'site_paused', ToolOutcome::transientReason( 'agent_safety_denied', 'Blocked by SenroGate (Posts): denied_by_class', $data ) );
		$this->assertSame( 'site_paused', ToolOutcome::transientReason( 'agent_safety_denied', 'a reworded message', $data ) );
		$this->assertNull( ToolOutcome::transientReason( 'agent_safety_denied', 'Blocked by SenroGate (Posts): site_paused', array( 'reason' => 'denied_by_class' ) ), 'a non-transient data reason wins over the message' );
		$this->assertSame( 'rate_limited_calls_per_hour', ToolOutcome::transientReason( 'agent_safety_denied', 'Blocked by SenroGate (Posts): rate_limited_calls_per_hour', array( 'status' => 403 ) ), 'no data reason falls back to the message' );
		$this->assertNull( ToolOutcome::transientReason( 'other_code', 'm', $data ) );
	}
}
