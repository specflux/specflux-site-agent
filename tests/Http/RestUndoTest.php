<?php
/**
 * 0.4 S3.2: the undo REST route's gate. Nonce, capability, human-only
 * (no application-password request, no cross-run change) and SenroGate's own
 * agent-context refusal.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\SenroFlux\Approval\ApprovalBridge;
use Specflux\SenroFlux\Http\Rest;
use Specflux\SenroFlux\Model\ModelGatewayInterface;
use Specflux\SenroFlux\Model\ModelTurn;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\Runner;
use Specflux\SenroFlux\Run\WpdbRunStore;
use Specflux\SenroFlux\Tools\ToolExecutor;
use Specflux\SenroFlux\Tools\ToolRegistry;
use SenroFlux_Test_Change;
use SenroFlux_Test_Changes;
use SenroFlux_Test_Container;
use WP_Error;
use WP_REST_Request;
use wpdb;

final class RestUndoTest extends TestCase {

	private SenroFlux_Test_Changes $api;
	private int $run_id;

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_logged_in']       = true;
		$GLOBALS['senroflux_test_user_caps']       = array(
			'read'           => true,
			'manage_options' => true,
		);
		$GLOBALS['senroflux_test_filters']         = array();
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );

		$db              = new wpdb();
		$db->queryReturn = 1;
		$db->emulateRows = true;
		$GLOBALS['wpdb'] = $db;
		$store           = new WpdbRunStore( $db );
		$runner          = new Runner(
			$store,
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls' );
				}
			},
			new ApprovalBridge()
		);
		$prop            = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );
		$this->run_id = $store->createRun( 1, 'c', 'g', array( '*' ), Budget::defaults() );
		$store->updateRun( $this->run_id, array( 'status' => 'completed' ) );

		$this->api = new SenroFlux_Test_Changes();
		$this->api->by_correlation[ 'senroflux:run:' . $this->run_id ] = array( new SenroFlux_Test_Change( 11 ) );
		$GLOBALS['senroflux_test_agent_safety']                        = new SenroFlux_Test_Container( $this->api );
	}

	protected function tearDown(): void {
		Plugin::reset();
		remove_all_filters( 'senroflux_runs_capability' );
		unset( $GLOBALS['senroflux_test_did_action'], $GLOBALS['wpdb'], $GLOBALS['senroflux_test_logged_in'] );
		$GLOBALS['senroflux_test_agent_safety'] = null;
	}

	private function request( array $params = array() ): WP_REST_Request {
		return new WP_REST_Request(
			array_merge(
				array(
					'run_id' => $this->run_id,
					'nonce'  => wp_create_nonce( 'senroflux_undo_' . $this->run_id ),
				),
				$params
			)
		);
	}

	public function test_a_human_with_the_nonce_and_capability_undoes_the_run_as_themselves(): void {
		$response = ( new Rest() )->routeUndo( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'complete', $response->get_data()['status'] );
		$this->assertSame( 1, $this->api->undo_calls[0][1], 'the acting user is the current user' );
	}

	public function test_a_missing_or_wrong_nonce_is_refused_and_nothing_is_undone(): void {
		foreach ( array( 'wrong', null ) as $nonce ) {
			$response = ( new Rest() )->routeUndo( $this->request( array( 'nonce' => $nonce ) ) );

			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( 'senroflux_bad_nonce', $response->get_data()['code'] );
		}
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_a_nonce_for_another_run_is_refused(): void {
		$response = ( new Rest() )->routeUndo( $this->request( array( 'nonce' => wp_create_nonce( 'senroflux_undo_999' ) ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_a_logged_out_requester_is_refused(): void {
		$GLOBALS['senroflux_test_logged_in'] = false;

		$response = ( new Rest() )->routeUndo( $this->request() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_a_user_without_manage_options_is_refused(): void {
		// Their own run (so ownership passes), but no capability.
		$GLOBALS['senroflux_test_user_caps']['manage_options'] = false;

		$response = ( new Rest() )->routeUndo( $this->request() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'senroflux_forbidden', $response->get_data()['code'] );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_another_users_run_is_refused_without_the_runs_capability(): void {
		$GLOBALS['senroflux_test_current_user_id']             = 2;
		$GLOBALS['senroflux_test_user_caps']['manage_options'] = false;

		$response = ( new Rest() )->routeUndo( $this->request() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_an_application_password_request_is_refused_as_not_a_person(): void {
		$GLOBALS['senroflux_test_did_action']['application_password_did_authenticate'] = 1;

		$response = ( new Rest() )->routeUndo( $this->request() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'senroflux_undo_human_only', $response->get_data()['code'] );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_senrogate_refusing_an_agent_context_is_returned_to_the_screen(): void {
		$this->api->undo_result = new UndoResult( 'forbidden', array(), null, 'agent_context' );

		$data = ( new Rest() )->routeUndo( $this->request() )->get_data();

		$this->assertSame( 'forbidden', $data['status'] );
		$this->assertSame( 'agent_context', $data['reason'] );
	}

	public function test_a_change_that_is_not_in_this_run_is_refused(): void {
		$response = ( new Rest() )->routeUndo( $this->request( array( 'change_id' => 999 ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_preview_goes_through_the_same_gate_and_writes_nothing(): void {
		$bad = ( new Rest() )->routeUndoPreview( $this->request( array( 'nonce' => 'wrong' ) ) );
		$this->assertSame( 403, $bad->get_status() );

		$ok = ( new Rest() )->routeUndoPreview( $this->request() );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array(), $this->api->undo_calls );
		$this->assertCount( 1, $this->api->preview_calls );
	}

	public function test_the_run_state_carries_the_changes_section_and_no_tool_exposes_undo(): void {
		$state = senroflux()->get( $this->run_id );

		$this->assertSame( 'undo', $state['run']['changes_section']['mode'] );
		// The model's surfaces (tools, packs, prompts) never touch the undo bridge.
		$files = array_merge(
			(array) glob( dirname( __DIR__, 2 ) . '/src/Tools/*.php' ),
			(array) glob( dirname( __DIR__, 2 ) . '/src/Packs/*/*.php' ),
			(array) glob( dirname( __DIR__, 2 ) . '/src/Skills/*.php' )
		);
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file.
			$this->assertStringNotContainsString( 'ChangesBridge', (string) file_get_contents( $file ), $file );
		}
	}
}
