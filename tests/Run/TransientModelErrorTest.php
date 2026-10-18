<?php
/**
 * Runner::tick() tests for transient model-error retry.
 *
 * Live run evidence: a single dropped connection to the model
 * ("cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes
 * received") used to fail the run outright via `failError( $run, 'model_error', ... )`
 * — even after the run had already published pages, set the front page, and
 * updated navigation. A transient network/timeout error (or a 429/5xx from
 * the AI Client's own HTTP exceptions, surfaced via
 * {@see \Specflux\SiteAgent\Model\AiClientGateway}'s WP_Error data) now keeps
 * the run `running` and retries on the caller's next tick, failing as
 * `model_error` only after 3 CONSECUTIVE transient failures with no
 * successful model turn in between. A non-transient error (e.g. an auth
 * failure) still fails immediately.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\PlanTools;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\VerbTier;
use WP_Error;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use wpdb;

final class TransientModelErrorTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private RecordingBridge $bridge;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();
		$this->bridge          = new RecordingBridge();
		$this->runner          = new Runner( $this->store, new ToolExecutor(), $this->gateway, $this->bridge );

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_abilities']       = array();
	}

	private function createRun(): int {
		return $this->store->createRun( 1, 'test-consumer', 'Publish three pages', array( 'agsafe-smoke/*' ), Budget::defaults() );
	}

	private function stepCount( int $run_id ): int {
		return (int) $this->store->getRun( $run_id )->stepCount;
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	/** The exact live-run evidence string, cURL error 28 via NetworkException (status data absent, code 0). */
	private static function timeoutError(): WP_Error {
		return new WP_Error(
			'gateway_failed',
			'Network error occurred while sending POST request to https://api.openai.com/v1/responses: cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received',
			array( 'status' => 0 )
		);
	}

	private static function rateLimitError(): WP_Error {
		return new WP_Error( 'gateway_failed', 'Too Many Requests (429)', array( 'status' => 429 ) );
	}

	private static function authError(): WP_Error {
		return new WP_Error( 'gateway_failed', 'Unauthorized (401) - invalid API key', array( 'status' => 401 ) );
	}

	public function test_run_stays_running_after_a_transient_error_and_completes_on_retry(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::timeoutError();
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$first = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $first );
		$this->assertSame( 'running', $first['run']['status'] );
		$this->assertSame( array(), $first['ui'], 'mirrors the nudge path: the client just retries on its next tick' );
		$this->assertSame( array( 'user', 'system' ), array_column( $first['new_steps'], 'kind' ) );

		$second = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( 'completed', $second['run']['status'] );
		$this->assertSame( array( 'model', 'system', 'user', 'model' ), array_column( $second['new_steps'], 'kind' ) );
		$this->assertCount( 3, $this->gateway->calls, 'the failed attempt, the retry, and the no-write nudge turn' );
	}

	public function test_retry_marker_is_never_sent_to_the_model_as_history(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::timeoutError();
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$this->runner->tick( $run_id, 0, null );
		$this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		// The failed attempt and the retry both carry only the seeded goal —
		// the system note the failure left behind never re-enters the prompt.
		// A third call is the no-write nudge turn.
		$this->assertCount( 3, $this->gateway->histories );
		foreach ( array_slice( $this->gateway->histories, 0, 2 ) as $history ) {
			$this->assertCount( 1, $history, 'history must contain only the seeded goal, never the transient-error note' );
		}

		$system_steps = array_filter(
			$this->store->getSteps( $run_id ),
			static fn ( $step ): bool => StepKind::System === $step->kind
				&& 'transient_model_error' === ( $step->messageArray['note'] ?? null )
		);
		$this->assertCount( 1, $system_steps, 'the transient-error note was recorded exactly once' );
		$note = reset( $system_steps );
		$this->assertSame( 'transient_model_error', $note->messageArray['note'] ?? null );
	}

	/**
	 * Live batch 2026-09-28-fix3: space-bunny's provider answered roughly one
	 * call in two with "Provider returned an empty response" for half an hour;
	 * three attempts ~10 s apart killed runs that the next call would have
	 * carried on.
	 */
	public function test_five_consecutive_transient_errors_still_retry_and_complete(): void {
		$run_id = $this->createRun();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->gateway->script[] = self::rateLimitError();
		}
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$result       = null;
		$retry_afters = array();
		$started      = hrtime( true );
		for ( $i = 0; $i < 6; $i++ ) {
			$result         = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
			$retry_afters[] = $result['retry_after'];
		}
		$elapsed_seconds = ( hrtime( true ) - $started ) / 1e9;

		$this->assertSame( 'completed', $result['run']['status'] );
		$this->assertSame(
			array( 2, 4, 8, 16, 30, 0 ),
			$retry_afters,
			'one retry_after per transient error, on the 2/4/8/16/30s schedule; the successful tick asks for none'
		);
		$this->assertLessThan( 2.0, $elapsed_seconds, 'the 60s of backoff is handed to the driver, never slept in-request' );
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-4-1: six "Missing the choices
	 * key" provider errors landed 2-3 s apart (all within 13 s) because
	 * nothing spaced the retries out. The spacing now rides the tick result as
	 * `retry_after` (the driver waits) instead of a blocking in-request sleep,
	 * and the pinned Clock never moves: no wall-clock wait happens in the tick.
	 */
	public function test_a_transient_error_returns_retry_after_and_never_sleeps_in_request(): void {
		\Specflux\SiteAgent\Run\Clock::useFixed( 1_800_000_000 );
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::rateLimitError();
		$this->gateway->script[] = self::rateLimitError();
		$this->gateway->script[] = self::textTurn( 'Published three pages.' );

		$first  = $this->runner->tick( $run_id, 0, null );
		$second = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		$third  = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );

		$this->assertSame( array( 2, 4, 0 ), array( $first['retry_after'], $second['retry_after'], $third['retry_after'] ) );
		$this->assertTrue( $first['continue'], 'a retry is still a run to tick again' );
		$this->assertSame( 'running', $first['run']['status'] );
		$this->assertSame( 1_800_000_000, \Specflux\SiteAgent\Run\Clock::now() );
		\Specflux\SiteAgent\Run\Clock::reset();
	}

	public function test_six_consecutive_transient_errors_fail_as_model_error(): void {
		$run_id = $this->createRun();
		for ( $i = 0; $i < 6; $i++ ) {
			$this->gateway->script[] = 0 === $i % 2 ? self::timeoutError() : self::rateLimitError();
		}

		$run_status   = 'pending';
		$retry_afters = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$result         = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
			$run_status     = $result['run']['status'];
			$retry_afters[] = $result['retry_after'];
		}

		$this->assertSame( array( 2, 4, 8, 16, 30, 0 ), $retry_afters, 'the 6th error fails the run: no further retry is requested' );
		$this->assertFalse( $result['continue'] );
		$this->assertSame( 'failed', $run_status );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 6, $this->gateway->calls, 'no seventh call: the run failed instead of retrying again' );

		$system_notes = array_values(
			array_filter(
				$this->store->getSteps( $run_id ),
				static fn ( $step ): bool => StepKind::System === $step->kind
					&& 'transient_model_error' === ( $step->messageArray['note'] ?? null )
			)
		);
		$this->assertCount( 5, $system_notes, 'the 6th (final) failure fails the run rather than adding a 6th note' );
	}

	/**
	 * Live evidence 2026-09-29-cards1/scenario-4-1: all of scenario 4's pages
	 * were already published when six consecutive "Missing the choices key"
	 * provider errors exhausted the retry budget and failed the run outright.
	 * Same treatment as {@see Runner::failBudget()}: writes finished, so this
	 * reads as "cut short during verification", not a failure.
	 */
	public function test_writes_finished_completes_unverified_when_transient_errors_exhaust_the_retry_budget(): void {
		add_filter(
			'specflux_site_agent_verb_map',
			static fn ( array $map ): array => $map + array( 'agsafe-smoke/write' => VerbTier::TIER_1 ),
			20,
			1
		);

		$run_id = $this->createRun();

		// An accepted plan naming exactly one write verb, already satisfied.
		$plan_seq = $this->store->appendStep(
			$run_id,
			StepKind::Plan,
			array(
				'goal'        => 'G',
				'steps'       => array(
					array(
						'text'  => 'Write it',
						'verbs' => array( 'agsafe-smoke/write' ),
						'tier'  => 1,
					),
				),
				'assumptions' => array(),
			),
			PlanTools::toolName(),
			null,
			'parked'
		);
		$this->store->updateRun( $run_id, array( 'accepted_plan_step_id' => $plan_seq ) );

		$write_message              = ( new UserMessage(
			array( new MessagePart( new FunctionResponse( 'call_w', 'belayline_smoke_write', array( 'ok' => true ) ) ) )
		) )->toArray();
		$write_message['plan_verb'] = 'agsafe-smoke/write';
		$this->store->appendStep( $run_id, StepKind::ToolResult, $write_message, 'wpab__agsafe-smoke__write', null, 'ok' );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->gateway->script[] = 0 === $i % 2 ? self::timeoutError() : self::rateLimitError();
		}

		$result = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$result = $this->runner->tick( $run_id, $this->stepCount( $run_id ), null );
		}

		$this->assertSame( 'completed', $result['run']['status'], 'writes were finished: an exhausted retry budget must not fail the run' );
		$this->assertNull( $result['run']['error'] ?? null );

		$note = $result['ui']['report']['unverified'] ?? '';
		$this->assertIsString( $note );
		$this->assertStringContainsString( 'model_error', $note );
	}

	public function test_a_non_transient_error_fails_immediately_without_retrying(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = self::authError();
		$this->gateway->script[] = self::textTurn( 'Should never be reached.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 1, $this->gateway->calls, 'a non-transient error never retries' );
	}

	public function test_an_exhausted_account_429_fails_immediately_without_retrying(): void {
		$run_id                  = $this->createRun();
		$this->gateway->script[] = new WP_Error(
			'gateway_failed',
			'Too Many Requests (429) - You have no credits remaining. Add credits to continue using the API at https://platform.openai.com/settings/organization/billing/.',
			array( 'status' => 429 )
		);
		$this->gateway->script[] = self::textTurn( 'Should never be reached.' );

		$result = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertSame( 'model_error', $result['run']['error']['code'] ?? '' );
		$this->assertCount( 1, $this->gateway->calls, 'an exhausted account never retries' );
	}
}
