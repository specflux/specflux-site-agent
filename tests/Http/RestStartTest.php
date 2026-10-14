<?php
/**
 * `Rest::routeStart()` (REST-pack fix): `POST /runs` never read a `pack`
 * parameter, so a REST consumer had no way to bind a run to a capability
 * pack — only the admin-ajax path (`Ajax::handleStart()`) accepted one. This
 * mirrors {@see AjaxStartTest} exactly, through the REST route instead, to
 * prove the same pack validation/binding now applies there too.
 *
 * TARGET REPO PATH: tests/Http/RestStartTest.php
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Http\ConsumerPolicy;
use Specflux\SiteAgent\Http\Rest;
use Specflux\SiteAgent\Model\ModelGatewayInterface;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Schema;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\ToolRegistry;
use WP_Error;
use WP_REST_Request;
use wpdb;

final class RestStartTest extends TestCase {

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
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
	}

	public function test_runs_consumer_with_a_valid_pack_starts_a_run_carrying_that_pack(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => RunsScreen::CONSUMER,
					'goal'     => 'Publish the spring page',
					'pack'     => 'pages',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pages', $data['run']['pack'] ?? null );
	}

	public function test_runs_consumer_without_a_pack_is_refused_bad_request_and_creates_no_run(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => RunsScreen::CONSUMER,
					'goal'     => 'Publish the spring page',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'specflux_site_agent_bad_request', $data['code'] ?? null );

		$table = Schema::runsTable( $GLOBALS['wpdb'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->tables[ $table ] ?? array(), 'no run row may exist after the refusal' );
	}

	public function test_an_unknown_pack_is_refused_the_same_way_as_start(): void {
		$this->seedRunnerGraph();
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'specflux/read-content' ) ),
			)
		);

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => 'mac',
					'goal'     => 'Do something',
					'pack'     => 'no-such-pack',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'pack_unknown', $data['code'] ?? null );
	}

	public function test_a_non_runs_consumer_may_still_start_pack_less(): void {
		$this->seedRunnerGraph();
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'specflux/read-content' ) ),
			)
		);

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => 'mac',
					'goal'     => 'Summarize traffic',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'pack', $data['run'] ?? array() );
		$this->assertNull( $data['run']['pack'] );
	}

	/**
	 * S7: a pack's own (flat-and-high) default budget becomes the ceiling
	 * ConsumerPolicy clamps against when the registered consumer sets NO
	 * budget of its own — mirrors `AjaxStartTest`'s equivalent, through REST.
	 */
	public function test_pack_budget_ceiling_is_not_clamped_to_the_generic_default(): void {
		$this->seedRunnerGraph();
		$this->registerFakePack( 'site', array( 'max_tool_calls' => 120 ) );
		add_filter(
			ConsumerPolicy::FILTER,
			static fn (): array => array(
				'mac' => array( 'allow' => array( 'specflux/read-content' ) ),
			)
		);

		$response = ( new Rest() )->routeStart(
			new WP_REST_Request(
				array(
					'consumer' => 'mac',
					'goal'     => 'Do something site-wide',
					'pack'     => 'site',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 120, $data['run']['budget']['max_tool_calls'] ?? null );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/** @param array<string,int> $budget_overrides Pack's own default-budget overrides (S7). */
	private function registerFakePack( string $name, array $budget_overrides = array() ): Pack {
		$pack = new class( $name, $budget_overrides ) extends Pack {
			public function __construct( private readonly string $packName, private readonly array $budgetOverrides ) {
				parent::__construct();
			}

			public function name(): string {
				return $this->packName;
			}

			/** @return list<string> */
			public function allowList(): array {
				return array( 'specflux/read-content' );
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array( 'read' => 0 );
			}

			/** @return array<string,int> */
			public function defaultBudget(): array {
				return $this->budgetOverrides;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}

			public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): bool|WP_Error {
				unset( $user_id, $consumer, $goal, $skills_disable );

				return true;
			}
		};

		add_filter(
			'specflux_site_agent_packs',
			static fn ( array $packs ): array => $packs + array( $name => $pack ),
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
