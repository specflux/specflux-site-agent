<?php
/**
 * S22 gate robustness suite: a REST call and an admin-post call, each
 * without the run capability, must both be refused — never silently
 * carried out.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Adversarial;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Http\Rest;
use Specflux\SiteAgent\Model\ModelGatewayInterface;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WP_Error;
use WP_REST_Request;
use wpdb;

final class CapabilityBypassTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/support.php';

		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		unset( $_POST );
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_can_tick' );
		unset( $_POST, $GLOBALS['wpdb'] );
		Plugin::reset();
	}

	/**
	 * REST: `Rest::routeSuggestionDecision()` (0.3 S20) re-checks
	 * `manage_options` INSIDE the callback body (fail closed, B0 rule 2) — a
	 * request that somehow slipped past `permission_callback` is still
	 * refused here, with the concrete outcome a caller can act on.
	 */
	public function test_rest_suggestion_decision_without_the_run_capability_is_refused_403(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'read' => true ); // No manage_options.

		$response = ( new Rest() )->routeSuggestionDecision(
			new WP_REST_Request(
				array(
					'run_id' => 1,
					'seq'    => 1,
					'action' => 'save',
					'text'   => 'Always mention free shipping.',
				)
			)
		);

		$data = $response->get_data();
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'specflux_site_agent_forbidden', $data['code'] ?? null );
	}

	/**
	 * admin-post: `RunsScreen::handleApprovalDecision()` without the
	 * delegation capability (`specflux_site_agent_runs_capability`) redirects with
	 * `specflux_site_agent_forbidden` rather than acting on the park — the ownership
	 * gate refuses even a syntactically valid decision.
	 */
	public function test_admin_post_approval_decision_without_the_run_capability_redirects_forbidden(): void {
		$this->seedRunnerGraph();
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'read' => true ); // No manage_options: cannot act for another owner.

		$screen = new class() extends RunsScreen {
			public ?string $redirectedError = null;

			protected function redirectBack( int $run_id, ?string $error_code = null ): void {
				unset( $run_id );
				$this->redirectedError = $error_code;
			}
		};

		$store  = new WpdbRunStore( $GLOBALS['wpdb'] );
		$run_id = $store->createRun(
			42, // A DIFFERENT owner than the current user (1).
			'test-consumer',
			'A goal',
			array( 'specflux/read-content' ),
			Budget::defaults()
		);
		$store->appendStep( $run_id, StepKind::Plan, array( 'text' => 'A plan.' ), null, 'plan_approval_123', 'ok' );

		$_POST = array(
			'run_id'                              => (string) $run_id,
			'step_count'                          => '0',
			'specflux_site_agent_approval_action' => 'approve',
		);

		$screen->handleApprovalDecision();

		$this->assertSame( 'specflux_site_agent_forbidden', $screen->redirectedError );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private function seedRunnerGraph(): void {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, \Specflux\SiteAgent\Tools\ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
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
