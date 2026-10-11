<?php
/**
 * `Ajax::handleStart()` follow-up path (0.3 stage 22b, S20): the Runs screen
 * starts a follow-up over admin-ajax, so `follow_up_of` must reach `start()`
 * there too — with the same fail-closed rules as REST and admin-post: the
 * source run's pack wins, and a viewer who cannot see the source run is
 * refused.
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
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\ToolRegistry;
use SpecfluxSiteAgentJsonResponse;
use WP_Error;
use wpdb;

final class AjaxFollowUpTest extends TestCase {

	private WpdbRunStore $store;

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['specflux_site_agent_test_user_caps']       = array(
			'read'           => true,
			'edit_posts'     => true,
			'manage_options' => true,
		);
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		unset( $_POST );
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
		add_filter( ConsumerPolicy::FILTER, array( new RunsScreen(), 'registerAdminConsumer' ) );

		$this->seedRunnerGraph();
		$this->registerFakePack( 'pages' );
		$this->registerFakePack( 'site' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( ConsumerPolicy::FILTER );
		unset( $_POST );
	}

	public function test_follow_up_of_is_forwarded_and_the_source_runs_pack_wins(): void {
		$source = $this->finishedRun( 1, 'pages' );

		$_POST = array(
			'consumer'     => RunsScreen::CONSUMER,
			'goal'         => 'Tidy it up',
			'pack'         => 'site',
			'follow_up_of' => (string) $source,
			'nonce'        => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertTrue( $json->success );
		$this->assertSame( $source, $json->data['run']['follow_up_of'] );
		$this->assertSame( 'pages', $json->data['run']['pack'], 'the source run\'s pack wins over the posted one' );
	}

	public function test_a_follow_up_needs_no_pack_field_at_all(): void {
		$source = $this->finishedRun( 1, 'site' );

		$_POST = array(
			'consumer'     => RunsScreen::CONSUMER,
			'goal'         => 'Tidy it up',
			'follow_up_of' => (string) $source,
			'nonce'        => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertTrue( $json->success );
		$this->assertSame( 'site', $json->data['run']['pack'] );
	}

	public function test_a_running_source_is_refused(): void {
		$source = $this->finishedRun( 1, 'pages', 'running' );

		$_POST = array(
			'consumer'     => RunsScreen::CONSUMER,
			'goal'         => 'Tidy it up',
			'pack'         => 'pages',
			'follow_up_of' => (string) $source,
			'nonce'        => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertFalse( $json->success );
		$this->assertSame( 'follow_up_not_finished', $json->code() );
	}

	public function test_a_viewer_who_cannot_see_the_source_run_is_refused(): void {
		// Run 2 belongs to another user; the viewer holds the pack's run
		// capability but not `manage_options`, so maySee() is false.
		$source                                        = $this->finishedRun( 2, 'pages' );
		$GLOBALS['specflux_site_agent_test_user_caps'] = array(
			'read'       => true,
			'edit_posts' => true,
		);
		remove_all_filters( 'specflux_site_agent_runs_capability' );

		$_POST = array(
			'consumer'     => RunsScreen::CONSUMER,
			'goal'         => 'Tidy it up',
			'pack'         => 'pages',
			'follow_up_of' => (string) $source,
			'nonce'        => 'test-nonce',
		);

		$json = $this->captureAjaxStart();

		$this->assertFalse( $json->success );
		$this->assertSame( 403, $json->status );
		$this->assertSame( 'follow_up_forbidden', $json->code() );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function finishedRun( int $owner, string $pack, string $status = 'completed' ): int {
		$run_id = $this->store->createRun( $owner, RunsScreen::CONSUMER, 'Original goal', array( 'specflux/read-content' ), array(), $pack );
		$this->store->updateRun(
			$run_id,
			array(
				'status'      => $status,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'result_json' => array(
					'summary' => '',
					'changes' => array(),
				),
			)
		);

		return $run_id;
	}

	private function captureAjaxStart(): SpecfluxSiteAgentJsonResponse {
		try {
			( new Ajax() )->handleStart();
		} catch ( SpecfluxSiteAgentJsonResponse $json ) {
			return $json;
		}

		$this->fail( 'handleStart() returned without sending a JSON response.' );
	}

	private function registerFakePack( string $name ): void {
		$pack = new class( $name ) extends Pack {
			public function __construct( private readonly string $packName ) {
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

			public function runCapability(): string {
				return 'edit_posts';
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
	}

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;
		$this->store     = new WpdbRunStore( $db );

		$runner = new Runner(
			$this->store,
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
