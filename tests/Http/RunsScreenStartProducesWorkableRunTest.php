<?php
/**
 * Contract test (runs-pack fix): a run started from the Runs screen's own
 * admin-ajax consumer must actually be WORKABLE — not merely created.
 *
 * TARGET REPO PATH: tests/Http/RunsScreenStartProducesWorkableRunTest.php
 *
 * The live defect this pins: `Ajax::handleStart()` never passed the chosen
 * pack through to `specflux_site_agent()->start()`. A pack-less run has an EMPTY verb
 * map, so `VerbTier::tierFor()` fails closed to tier 2 for every verb — a
 * Tier-0 read is refused `plan_required` before the model can do anything,
 * and `propose-plan` is refused `unknown_verb` right after, deadlocking the
 * run forever. The e2e fixture (`tests/e2e/fake-provider/fake-provider.php`)
 * registers its own site-wide `specflux_site_agent_verb_map` filter, which MASKS this
 * exact defect end to end — so this test deliberately clears that filter
 * (`remove_all_filters( 'specflux_site_agent_verb_map' )`) and resolves the tier
 * straight from the STARTED RUN'S OWN PACK, never the global filter, to prove
 * the fix does not depend on it.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Http\Ajax;
use Specflux\SiteAgent\Http\ConsumerPolicy;
use Specflux\SiteAgent\Model\ModelGatewayInterface;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Packs\PackRegistry;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\ToolRegistry;
use Specflux\SiteAgent\Tools\VerbTier;
use SpecfluxSiteAgentJsonResponse;
use WP_Error;
use wpdb;

final class RunsScreenStartProducesWorkableRunTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['specflux_site_agent_test_user_caps']       = array(
			'read'           => true,
			'manage_options' => true,
		);
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		unset( $_POST );
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		// The regression this test exists for: prove the fix works with NO
		// global verb map registered, unlike the e2e fixture's fixture pack.
		remove_all_filters( VerbTier::VERB_MAP_FILTER );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		remove_all_filters( VerbTier::VERB_MAP_FILTER );
		unset( $_POST );
	}

	public function test_a_pack_started_run_resolves_its_own_tier_0_read_verb_without_any_global_verb_map(): void {
		$this->seedRunnerGraph();
		$pack = $this->registerPagesStylePack();
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$_POST = array(
			'consumer' => RunsScreen::CONSUMER,
			'goal'     => 'Publish the spring page',
			'pack'     => 'pages',
			'nonce'    => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertTrue( $json->success, 'the pack-started run must actually start' );
		$this->assertSame( 'pages', $json->data['run']['pack'] );

		// The regression check: resolve the STARTED RUN'S OWN pack's verb map
		// (never the global filter, which is cleared in setUp()) and confirm a
		// Tier-0 read verb resolves to tier 0 — the exact call
		// (`pages/list-patterns`) that deadlocked as `plan_required` on a
		// pack-less run.
		$resolved = PackRegistry::fromFilters()->get( $json->data['run']['pack'] );
		$this->assertNotNull( $resolved );
		$this->assertSame(
			VerbTier::TIER_0,
			VerbTier::tierFor( 'pages/list-patterns', $resolved->verbMap() ),
			'a pack-started run must resolve its own Tier-0 read verb, with no global specflux_site_agent_verb_map filter in play'
		);
		unset( $pack );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** Run `Ajax::handleStart()` and capture the JSON it would have died with. */
	private function captureAjaxStart(): SpecfluxSiteAgentJsonResponse {
		try {
			( new Ajax() )->handleStart();
		} catch ( SpecfluxSiteAgentJsonResponse $json ) {
			return $json;
		}

		$this->fail( 'handleStart() returned without sending a JSON response.' );
	}

	/** A fake `pages`-shaped pack: real pack-verb tiers, scripted preflight. */
	private function registerPagesStylePack(): Pack {
		$pack = new class() extends Pack {
			public function name(): string {
				return 'pages';
			}

			/** @return list<string> */
			public function allowList(): array {
				return array( 'specflux/read-content', 'specflux/list-patterns', 'specflux/publish-page' );
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array(
					'pages/read'          => 0,
					'pages/list-patterns' => 0,
					'pages/publish'       => 2,
				);
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}

			/** @param list<string>|null $skills_disable Ignored by the double. */
			public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): bool|WP_Error {
				unset( $user_id, $consumer, $goal, $skills_disable );

				return true;
			}
		};

		add_filter(
			'specflux_site_agent_packs',
			static fn ( array $packs ): array => $packs + array( 'pages' => $pack ),
			10,
			1
		);

		return $pack;
	}

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );
	}
}
