<?php
/**
 * C4-0: a run pins its store platform at start; a resume keeps it, and a
 * platform that has vanished fails the run with a partial report (the same
 * shape as the gate-mode mismatch).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use wpdb;

final class StorePlatformPinTest extends TestCase {

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	/** @var array<string,bool> Which platform ids are usable right now. */
	private array $usable = array( 'fake-a' => true );

	private Runner $runner;

	protected function setUp(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$this->store     = new WpdbRunStore( $db );
		$this->gateway   = new FakeGateway();
		$this->usable    = array( 'fake-a' => true );

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new ApprovalBridge(),
			gate_mode_probe: static fn (): GateMode => GateMode::BuiltIn,
			store_platform_probe: fn ( string $id ): bool => $this->usable[ $id ] ?? false
		);

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
	}

	private function createRun( ?string $platform ): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Update the shop',
			array( 'agsafe-smoke/*' ),
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::BuiltIn,
			array(),
			null,
			null,
			null,
			$platform
		);
	}

	private static function textTurn( string $text ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( $text ) ) ), 10, 5 );
	}

	public function test_the_platform_is_pinned_on_the_row_and_round_trips(): void {
		$run_id = $this->createRun( 'fake-a' );

		$this->assertSame( 'fake-a', $this->store->getRun( $run_id )?->storePlatform );
		$this->assertNull( $this->store->getRun( $this->createRun( null ) )?->storePlatform );
	}

	public function test_a_resume_with_the_platform_still_usable_carries_on(): void {
		$run_id = $this->createRun( 'fake-a' );

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'completed', $result['run']['status'], (string) ( $this->store->getRun( $run_id )?->error['code'] ?? '' ) );
		$this->assertSame( 'fake-a', $this->store->getRun( $run_id )?->storePlatform, 'the pin never changes' );
	}

	public function test_a_vanished_platform_fails_the_run_with_a_partial_report(): void {
		$run_id = $this->createRun( 'fake-a' );
		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Running->value ) );
		$this->usable['fake-a'] = false;

		$this->gateway->script[] = self::textTurn( 'must not be asked' );
		$result                  = $this->runner->tick( $run_id, 0, null );

		$this->assertIsArray( $result );
		$this->assertSame( 'failed', $result['run']['status'] );
		$this->assertArrayHasKey( 'report', $result['ui'] ?? array() );
		$this->assertSame( 'store_platform_changed', $this->store->getRun( $run_id )?->error['code'] ?? null );
		$this->assertCount( 0, $this->gateway->calls, 'the model is never called under a platform that is gone' );
	}

	public function test_a_run_with_no_pinned_platform_is_never_checked(): void {
		$run_id       = $this->createRun( null );
		$this->usable = array();

		$this->gateway->script[] = self::textTurn( 'Done.' );
		$this->gateway->script[] = self::textTurn( 'Done.' );
		$result                  = $this->runner->tick( $run_id, 0, null );

		$this->assertSame( 'completed', $result['run']['status'], (string) ( $this->store->getRun( $run_id )?->error['code'] ?? '' ) );
	}

	public function test_a_terminal_run_is_not_failed_by_a_vanished_platform(): void {
		$run_id = $this->createRun( 'fake-a' );
		$this->store->updateRun( $run_id, array( 'status' => RunStatus::Completed->value ) );
		$this->usable['fake-a'] = false;

		$this->assertNull( $this->runner->storePlatformMismatch( $this->store->getRun( $run_id ) ) );
	}
}
