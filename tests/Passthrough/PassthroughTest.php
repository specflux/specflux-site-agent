<?php
/**
 * 0.4 S6: abilities from other plugins (governed passthrough).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Passthrough;

use PHPUnit\Framework\TestCase;
use SpecfluxSiteAgent_Test_Fake_Ability;
use Specflux\SiteAgent\Admin\SettingsScreen;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Approval\GrantBridge;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Passthrough\AbilityReviewProbe;
use Specflux\SiteAgent\Passthrough\Passthrough;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\Run;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Setup\Checks;
use Specflux\SiteAgent\Run\InstructionRenderer;
use Specflux\SiteAgent\Tests\Api\Fixtures\FixturePack;
use Specflux\SiteAgent\Tests\Run\FakeGateway;
use Specflux\SiteAgent\Tools\PlanTools;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\ToolRegistry;
use Specflux\SiteAgent\Tools\VerbTier;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use wpdb;

final class PassthroughTest extends TestCase {

	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/Api/Fixtures/FixturePack.php';
	}

	protected function setUp(): void {
		$GLOBALS['specflux_site_agent_test_options']         = array();
		$GLOBALS['specflux_site_agent_test_user_caps']       = array( 'manage_options' => true );
		$GLOBALS['specflux_site_agent_test_agent_safety']    = null;
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$_POST = array();
		remove_all_filters( 'specflux_site_agent_verb_map' );
		remove_all_filters( 'specflux_site_agent_packs' );
		// A registered pack resolving `core/` (the default namespace list).
		add_filter( 'specflux_site_agent_packs', static fn ( array $packs ): array => $packs + array( 'fixture' => new FixturePack() ) );
		$GLOBALS['specflux_site_agent_test_abilities'] = array(
			'acme/list-things'   => $this->ability( 'acme/list-things' ),
			'acme/delete-things' => $this->ability( 'acme/delete-things' ),
			'core/x'             => $this->ability( 'core/x' ),
			'specflux/y'         => $this->ability( 'specflux/y' ),
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_verb_map' );
		remove_all_filters( 'specflux_site_agent_packs' );
		unset( $GLOBALS['specflux_site_agent_test_abilities'], $GLOBALS['specflux_site_agent_test_options'], $GLOBALS['specflux_site_agent_test_user_caps'] );
		$GLOBALS['specflux_site_agent_test_agent_safety'] = null;
		$_POST = array();
	}

	private function ability( string $name, array $meta = array(), ?array $schema = null ): SpecfluxSiteAgent_Test_Fake_Ability {
		return new SpecfluxSiteAgent_Test_Fake_Ability( $name, true, array( 'ok' => true ), 'desc of ' . $name, $schema, $meta );
	}

	private function runIn( GateMode $mode, array $allow = array() ): Run {
		return new Run(
			id: 7,
			userId: 1,
			consumer: 'test-consumer',
			goal: 'goal',
			status: RunStatus::Pending,
			allow: $allow,
			budget: Budget::defaults(),
			gateMode: $mode
		);
	}

	/** Enable names through the real save path. */
	private function enable( array $names, array $read = array() ): void {
		$this->assertTrue( Passthrough::save( $names, $read ) );
	}

	private function reviews( array $statuses ): void {
		$GLOBALS['specflux_site_agent_test_agent_safety'] = new class( $statuses ) {
			public function __construct( private array $statuses ) {
			}

			public function abilityReviews(): object {
				return new class( $this->statuses ) {
					public function __construct( private array $statuses ) {
					}

					public function status( string $name ): string {
						return $this->statuses[ $name ] ?? 'unreviewed';
					}
				};
			}
		};
	}

	public function test_nothing_is_enabled_by_default_and_reserved_namespaces_are_not_candidates(): void {
		$this->assertSame( array( 'acme/delete-things', 'acme/list-things' ), array_keys( Passthrough::candidates() ) );
		$this->assertSame( array(), Passthrough::config() );
		$this->assertSame( array(), ToolRegistry::forRun( $this->runIn( GateMode::BuiltIn ) )->names() );
	}

	public function test_the_41st_is_refused_and_nothing_is_saved(): void {
		$names = array();
		for ( $i = 1; $i <= 41; $i++ ) {
			$name    = 'bulk/a' . $i;
			$names[] = $name;
			$GLOBALS['specflux_site_agent_test_abilities'][ $name ] = $this->ability( $name );
		}

		$refused = Passthrough::save( $names, array() );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( Passthrough::ERROR_TOO_MANY, $refused->get_error_code() );
		$this->assertSame( array(), Passthrough::config() );

		$this->assertTrue( Passthrough::save( array_slice( $names, 0, 40 ), array() ) );
		$this->assertCount( 40, Passthrough::config() );
	}

	public function test_exposure_is_exact_names_only(): void {
		$this->enable( array( 'acme/list-things' ) );
		$registry = ToolRegistry::forRun( $this->runIn( GateMode::BuiltIn, array( 'unrelated/*' ) ) );

		$this->assertTrue( $registry->admits( 'acme/list-things' ) );
		$this->assertFalse( $registry->admits( 'acme/delete-things' ), 'a sibling in the same namespace is not exposed' );
	}

	public function test_a_reserved_namespace_name_in_the_option_is_never_exposed(): void {
		update_option(
			Passthrough::OPTION,
			array(
				'core/x' => array(
					'enabled' => true,
					'read'    => true,
					'hash'    => Passthrough::schemaHash( $GLOBALS['specflux_site_agent_test_abilities']['core/x'] ),
				),
			)
		);

		$this->assertFalse( ToolRegistry::forRun( $this->runIn( GateMode::BuiltIn ) )->admits( 'core/x' ) );
	}

	public function test_built_in_read_is_tier_0_and_unmarked_is_tier_2(): void {
		$this->enable( array( 'acme/list-things', 'acme/delete-things' ), array( 'acme/list-things' => 'read' ) );
		$map = Passthrough::tierMap( GateMode::BuiltIn );

		$this->assertSame( VerbTier::TIER_0, $map['acme/list-things'] );
		$this->assertSame( VerbTier::TIER_2, $map['acme/delete-things'] );
	}

	public function test_destructive_annotation_forces_tier_2_even_when_marked_read(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['acme/list-things'] = $this->ability(
			'acme/list-things',
			array(
				'annotations' => array(
					'readonly'    => true,
					'destructive' => true,
				),
			)
		);
		$this->enable( array( 'acme/list-things' ), array( 'acme/list-things' => 'read' ) );

		$this->assertSame( VerbTier::TIER_2, Passthrough::tierMap( GateMode::BuiltIn )['acme/list-things'] );
	}

	public function test_readonly_false_marked_read_is_still_the_admins_call(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['acme/list-things'] = $this->ability(
			'acme/list-things',
			array(
				'annotations' => array(
					'readonly'    => false,
					'destructive' => false,
				),
			)
		);
		$this->enable( array( 'acme/list-things' ), array( 'acme/list-things' => 'read' ) );

		$this->assertSame( VerbTier::TIER_0, Passthrough::tierMap( GateMode::BuiltIn )['acme/list-things'] );
	}

	public function test_agent_governance_mode_without_the_probe_exposes_nothing(): void {
		$this->enable( array( 'acme/list-things' ), array( 'acme/list-things' => 'read' ) );

		$this->assertFalse( AbilityReviewProbe::available() );
		$this->assertSame( array(), ToolRegistry::forRun( $this->runIn( GateMode::AgentSafety ) )->names() );
	}

	public function test_agent_governance_mode_exposes_only_reviewed_and_never_tiers_zero(): void {
		$this->enable( array( 'acme/list-things', 'acme/delete-things' ), array( 'acme/list-things' => 'read' ) );
		$this->reviews(
			array(
				'acme/list-things'   => 'reviewed',
				'acme/delete-things' => 'blocked',
			)
		);

		$registry = ToolRegistry::forRun( $this->runIn( GateMode::AgentSafety ) );
		$this->assertSame( array( 'acme/list-things' ), $registry->names() );
		$this->assertSame( VerbTier::TIER_2, Passthrough::tierMap( GateMode::AgentSafety )['acme/list-things'], 'Read is a built-in-mode mark only' );
	}

	public function test_a_probe_that_throws_or_answers_oddly_fails_closed(): void {
		$GLOBALS['specflux_site_agent_test_agent_safety'] = new class() {
			public function abilityReviews(): object {
				return new class() {
					public function status( string $name ): string {
						throw new \RuntimeException( 'review service down' );
					}
				};
			}
		};

		$this->assertSame( 'unreviewed', AbilityReviewProbe::status( 'acme/list-things' ) );

		$GLOBALS['specflux_site_agent_test_agent_safety'] = new class() {};
		$this->assertFalse( AbilityReviewProbe::available() );
	}

	public function test_a_schema_change_disables_until_re_ticked(): void {
		$this->enable( array( 'acme/list-things' ) );
		$this->assertSame( array( 'acme/list-things' ), Passthrough::exposedNames( GateMode::BuiltIn ) );

		$GLOBALS['specflux_site_agent_test_abilities']['acme/list-things'] = $this->ability(
			'acme/list-things',
			array(),
			array(
				'type'       => 'object',
				'properties' => array( 'q' => array( 'type' => 'string' ) ),
			)
		);
		$this->assertSame( array(), Passthrough::exposedNames( GateMode::BuiltIn ) );

		ob_start();
		( new SettingsScreen() )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'inputs changed', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="acme\/list-things"[^>]*checked/', $html );

		$this->enable( array( 'acme/list-things' ) );
		$this->assertSame( array( 'acme/list-things' ), Passthrough::exposedNames( GateMode::BuiltIn ) );
	}

	public function test_the_setup_check_counts_enabled_abilities_awaiting_review(): void {
		$this->enable( array( 'acme/list-things', 'acme/delete-things' ) );
		$this->reviews( array( 'acme/list-things' => 'reviewed' ) );

		$this->assertNull( Checks::passthroughAwaitingReview(), 'built-in mode: nothing is withheld for review' );

		Plugin::reset();
		Plugin::set_dependency_probe( true );
		try {
			$check = Checks::passthroughAwaitingReview();
			$this->assertNotNull( $check );
			$this->assertFalse( $check->passed() );
			$this->assertStringContainsString( '1 enabled ability', $check->fixMessage() );
			$this->assertStringContainsString( 'Belayline Agent Governance', $check->fixMessage() );
		} finally {
			Plugin::reset();
		}
		$this->assertSame( array( 'acme/delete-things' ), Passthrough::awaitingReview() );
	}

	public function test_the_setup_check_links_governances_registered_review_page(): void {
		$this->enable( array( 'acme/list-things' ) );
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		try {
			$this->assertSame( 'https://example.test/wp-admin/tools.php?page=agent-safety-abilities', Checks::passthroughAwaitingReview()?->fixUrl(), 'unregistered menu falls back to Tools' );

			$GLOBALS['specflux_site_agent_test_menu_urls'] = array( 'agent-safety-abilities' => 'https://example.test/wp-admin/tools.php?page=agent-safety-abilities&x=1' );
			$this->assertSame( 'https://example.test/wp-admin/tools.php?page=agent-safety-abilities&x=1', Checks::passthroughAwaitingReview()?->fixUrl(), 'the registered menu URL is preferred' );
		} finally {
			unset( $GLOBALS['specflux_site_agent_test_menu_urls'] );
			Plugin::reset();
		}
	}

	public function test_the_save_handler_needs_the_capability_and_sanitises(): void {
		$screen = new class() extends SettingsScreen {
			public ?string $to = 'none';

			protected function redirectPassthrough( ?string $error_code ): void {
				$this->to = $error_code;
			}
		};

		$_POST = array(
			'specflux_site_agent_pt_enabled' => array( 'acme/list-things', 'nope/not-registered', '../etc' ),
			'specflux_site_agent_pt_mode'    => array( 'acme/list-things' => 'read' ),
		);
		$screen->handleSavePassthrough();
		$this->assertNull( $screen->to );
		$this->assertSame( array( 'acme/list-things' ), array_keys( Passthrough::config() ) );
		$this->assertTrue( Passthrough::config()['acme/list-things']['read'] );
	}

	public function test_the_instruction_carries_the_passthrough_line_only_when_exposed(): void {
		$tail = new \Specflux\SiteAgent\Run\Tail( 1, 1, 1, 1, 1 );
		$with = InstructionRenderer::render( array(), $tail, GateMode::BuiltIn, null, null, true );
		$none = InstructionRenderer::render( array(), $tail, GateMode::BuiltIn );

		$this->assertStringContainsString( 'Abilities from other plugins', $with );
		$this->assertStringContainsString( 'untrusted text', $with );
		$this->assertStringNotContainsString( 'Abilities from other plugins', $none );
	}

	// --- The plan fence, unchanged, over a passthrough ability. ---

	private function fenceRunner( WpdbRunStore $store, FakeGateway $gateway ): Runner {
		return new Runner(
			$store,
			new ToolExecutor(),
			$gateway,
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
	}

	private static function callTurn( string $id, string $tool, array $args = array() ): ModelTurn {
		return new ModelTurn( new ModelMessage( array( new MessagePart( new FunctionCall( $id, $tool, $args ) ) ) ), 10, 5 );
	}

	private function startFence( array $read ): array {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$store           = new WpdbRunStore( $db );
		$gateway         = new FakeGateway();
		$this->enable( array( 'acme/list-things', 'acme/delete-things' ), $read );
		add_filter( VerbTier::VERB_MAP_FILTER, array( Passthrough::class, 'filterVerbMap' ), PHP_INT_MAX );
		$run_id = $store->createRun( 1, 'test-consumer', 'go', array( 'none/*' ), Budget::defaults(), null, null, null, GateMode::BuiltIn );

		return array( $this->fenceRunner( $store, $gateway ), $gateway, $run_id, $store );
	}

	public function test_a_tier_2_passthrough_call_without_a_plan_is_refused_by_the_fence(): void {
		[ $runner, $gateway, $run_id ] = $this->startFence( array() );
		$gateway->script[]             = self::callTurn( 'c1', 'wpab__acme__delete-things' );

		$result = $runner->tick( $run_id, 0, null );
		$this->assertIsArray( $result );
		$this->assertNotSame( 'awaiting_approval', $result['run']['status'], 'the fence refuses before the gate can park' );
		$this->assertStringContainsString( 'plan_required', (string) wp_json_encode( $result ) );
	}

	public function test_a_built_in_read_passthrough_call_runs_without_parking(): void {
		[ $runner, $gateway, $run_id ] = $this->startFence( array( 'acme/list-things' => 'read' ) );
		$gateway->script[]             = self::callTurn( 'c1', 'wpab__acme__list-things' );
		$gateway->script[]             = new ModelTurn( new ModelMessage( array( new MessagePart( 'done' ) ) ), 5, 5 );

		$result = $runner->tick( $run_id, 0, null );
		$this->assertIsArray( $result );
		$this->assertNotSame( 'awaiting_approval', $result['run']['status'] );
		$this->assertNotSame( 'awaiting_plan', $result['run']['status'] );
	}

	public function test_an_unmarked_passthrough_call_in_an_accepted_plan_parks_for_approval(): void {
		[ $runner, $gateway, $run_id, $store ] = $this->startFence( array() );
		$gateway->script[]                     = self::callTurn(
			'p1',
			PlanTools::FUNCTION_NAME,
			array(
				'goal'        => 'go',
				'steps'       => array(
					array(
						'text'  => 'delete',
						'verbs' => array( 'acme/delete-things' ),
					),
				),
				'assumptions' => array(),
			)
		);
		$parked                                = $runner->tick( $run_id, 0, null );
		$this->assertSame( 'awaiting_plan', $parked['run']['status'] );

		$run               = $store->getRun( $run_id );
		$gateway->script[] = self::callTurn( 'c1', 'wpab__acme__delete-things' );
		$result            = $runner->tick( $run_id, (int) $run->stepCount, array( 'plan' => array( 'action' => 'accept' ) ) );
		$this->assertSame( 'awaiting_approval', $result['run']['status'] );
	}

	// --- Measurement: 40 synthetic declarations (3 properties each). ---

	public function test_measure_declaration_overhead_for_40_abilities(): void {
		$names = array();
		for ( $i = 1; $i <= 40; $i++ ) {
			$name    = 'meas/ability-' . $i;
			$names[] = $name;
			$GLOBALS['specflux_site_agent_test_abilities'][ $name ] = new SpecfluxSiteAgent_Test_Fake_Ability(
				$name,
				true,
				array(),
				'Does thing number ' . $i . ' for the site: fetches or updates a record and returns a short structured result for the caller.',
				array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'The record id.',
						),
						'query' => array(
							'type'        => 'string',
							'description' => 'Free text filter.',
						),
						'limit' => array(
							'type'        => 'integer',
							'description' => 'Max results.',
						),
					),
					'required'   => array( 'id' ),
				)
			);
		}
		$this->enable( $names );
		$registry = ToolRegistry::forRun( $this->runIn( GateMode::BuiltIn ) );
		$this->assertCount( 40, $registry->names() );

		$bytes = 0;
		foreach ( $registry->declarations() as $declaration ) {
			$bytes += strlen( (string) wp_json_encode( $declaration instanceof \JsonSerializable ? $declaration->jsonSerialize() : $declaration ) );
		}
		// Recorded at stage 6: ~16,000 bytes (~4,000 tokens by bytes/4) per tick.
		$this->assertGreaterThan( 10000, $bytes );
		$this->assertLessThan( 25000, $bytes );
	}
}
