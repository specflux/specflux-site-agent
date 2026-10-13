<?php
/**
 * S22 gate robustness suite: an unmapped ability (absent from
 * `specflux_site_agent_verb_map`) must fail CLOSED to a Tier-2 park, never a free
 * pass — the built-in gate's own guarantee, exercised end to end here.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use SpecfluxSiteAgent_Test_Fake_Ability;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Approval\GrantBridge;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\PlanTools;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tests\Run\FakeGateway;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class UnmappedAbilityTest extends TestCase {

	private wpdb $db;

	private WpdbRunStore $store;

	private FakeGateway $gateway;

	private Runner $runner;

	protected function setUp(): void {
		$this->db              = new wpdb();
		$this->db->queryReturn = 1;
		$this->store           = new WpdbRunStore( $this->db );
		$this->gateway         = new FakeGateway();

		$this->runner = new Runner(
			$this->store,
			new ToolExecutor(),
			$this->gateway,
			new ApprovalBridge(),
			null,
			null,
			null,
			null,
			null,
			new GrantBridge(),
			null,
			static fn (): GateMode => GateMode::BuiltIn
		);

		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_agent_safety']    = null;
		// Deliberately no verb-map filter: 'adversarial/unmapped' names an
		// ability that exists (a model could still call it) but is absent
		// from every pack's verb map.
		$GLOBALS['specflux_site_agent_test_abilities'] = array(
			'adversarial/unmapped' => new SpecfluxSiteAgent_Test_Fake_Ability( 'adversarial/unmapped', execute_result: array( 'ok' => true ) ),
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_verb_map' );
	}

	private function createRun(): int {
		return $this->store->createRun(
			1,
			'test-consumer',
			'Do the unmapped thing',
			array( 'adversarial/*' ),
			Budget::defaults(),
			null,
			null,
			null,
			GateMode::BuiltIn
		);
	}

	private static function turn( MessagePart ...$parts ): ModelTurn {
		return new ModelTurn( new ModelMessage( $parts ), 10, 5 );
	}

	private static function callTurn( string $call_id, string $tool, array $args = array() ): ModelTurn {
		return self::turn( new MessagePart( new FunctionCall( $call_id, $tool, $args ) ) );
	}

	private static function planTurn( string $call_id, array $verbs ): ModelTurn {
		return self::turn(
			new MessagePart(
				new FunctionCall(
					$call_id,
					PlanTools::FUNCTION_NAME,
					array(
						'goal'        => 'Do the unmapped thing',
						'steps'       => array(
							array(
								'text'  => 'Call the unmapped ability',
								'verbs' => $verbs,
							),
						),
						'assumptions' => array(),
					)
				)
			)
		);
	}

	/**
	 * An ability absent from every verb map fails closed to Tier 2 (never
	 * executes without a park) — the concrete terminal outcome is
	 * `awaiting_approval`, exactly like an ordinary Tier-2 call, not
	 * `completed`.
	 */
	public function test_an_unmapped_ability_parks_for_approval_instead_of_executing(): void {
		$run_id = $this->createRun();

		$this->gateway->script[] = self::planTurn( 'call_p', array( 'adversarial/unmapped' ) );
		$parked                  = $this->runner->tick( $run_id, 0, null );
		$this->assertIsArray( $parked );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$run                     = $this->store->getRun( $run_id );
		$this->gateway->script[] = self::callTurn( 'call_u', 'adversarial/unmapped' );
		$result                  = $this->runner->tick( $run_id, (int) $run->stepCount, array( 'plan' => array( 'action' => 'accept' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			'awaiting_approval',
			$result['run']['status'],
			'an unmapped ability must fail closed to a park, never execute unseen'
		);
	}
}
