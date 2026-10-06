<?php
/**
 * 0.4 S3.2: the report's Changes section and undo, built from a fake
 * SenroGate 0.5 `changes()` API; and the fallback without it.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Approval;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Changes\UndoItem;
use Specflux\AgentSafety\Changes\UndoResult;
use Specflux\SenroFlux\Approval\ChangesBridge;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Run;
use Specflux\SenroFlux\Run\RunStatus;
use SenroFlux_Test_Change;
use SenroFlux_Test_Changes;
use SenroFlux_Test_Container;

final class ChangesBridgeTest extends TestCase {

	private SenroFlux_Test_Changes $api;

	protected function setUp(): void {
		$this->api                                    = new SenroFlux_Test_Changes();
		$GLOBALS['senroflux_test_agent_safety']       = new SenroFlux_Test_Container( $this->api );
		$GLOBALS['senroflux_test_current_user_id']    = 7;
		$GLOBALS['senroflux_test_user_caps']          = array(
			'read'           => true,
			'manage_options' => true,
		);
		$this->api->by_correlation['senroflux:run:5'] = array(
			new SenroFlux_Test_Change(
				11,
				'post',
				'12',
				array(
					array(
						'field'  => 'post_title',
						'before' => 'Old',
						'after'  => 'New',
					),
					array(
						'field'  => 'meta',
						'before' => null,
						'after'  => array( 'a' => 1 ),
					),
				)
			),
			new SenroFlux_Test_Change( 12, 'option', 'blogname', array(), 'irreversible' ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['senroflux_test_agent_safety'] = null;
	}

	private function makeRun( GateMode $mode = GateMode::AgentSafety, RunStatus $status = RunStatus::Completed, ?string $pack = null, ?array $result = null ): Run {
		return new Run( 5, 7, 'c', 'g', $status, array( '*' ), array(), pack: $pack, result: $result, gateMode: $mode );
	}

	public function test_section_lists_each_object_with_diff_and_reason_label(): void {
		$section = ( new ChangesBridge() )->section( $this->makeRun() );

		$this->assertSame( 'undo', $section['mode'] );
		$this->assertCount( 2, $section['changes'] );
		$first = $section['changes'][0];
		$this->assertSame( 11, $first['id'] );
		$this->assertTrue( $first['restorable'] );
		$this->assertSame( 'New', $first['diff'][0]['after'] );
		$this->assertSame( '', $first['diff'][1]['before'] );
		$this->assertSame( '{"a":1}', $first['diff'][1]['after'] );
		$second = $section['changes'][1];
		$this->assertFalse( $second['restorable'] );
		$this->assertSame( "This kind of change can't be undone.", $second['reason_label'] );
		$this->assertSame( wp_create_nonce( 'senroflux_undo_5' ), $section['nonce'] );
	}

	public function test_no_section_for_a_live_run_or_a_viewer_without_the_capability(): void {
		$this->assertNull( ( new ChangesBridge() )->section( $this->makeRun( status: RunStatus::Running ) ) );

		$GLOBALS['senroflux_test_user_caps']['manage_options'] = false;
		$this->assertNull( ( new ChangesBridge() )->section( $this->makeRun() ) );
	}

	public function test_commerce_runs_need_manage_woocommerce(): void {
		$bridge = new ChangesBridge();
		$this->assertNull( $bridge->section( $this->makeRun( pack: 'commerce' ) ), 'manage_options alone is not enough for a commerce run' );

		$GLOBALS['senroflux_test_user_caps']['manage_woocommerce'] = true;
		$GLOBALS['senroflux_test_user_caps']['manage_options']     = false;
		$this->assertSame( 'undo', $bridge->section( $this->makeRun( pack: 'commerce' ) )['mode'] );
	}

	public function test_fallback_in_built_in_mode_has_revisions_and_the_note(): void {
		$GLOBALS['senroflux_test_user_caps']['edit_post:12'] = true;
		$GLOBALS['senroflux_test_revisions']                 = array( 12 => array( (object) array( 'ID' => 99 ) ) );
		$result = array(
			'changes' => array(
				array(
					'object_type' => 'post',
					'object_id'   => '12',
				),
				array(
					'object_type' => 'page',
					'object_id'   => '13',
				),
				array(
					'object_type' => 'product',
					'object_id'   => '14',
				),
			),
		);

		$section = ( new ChangesBridge() )->section( $this->makeRun( GateMode::BuiltIn, result: $result ) );

		$this->assertSame( 'revisions', $section['mode'] );
		$this->assertSame( 'Undo needs SenroGate', $section['note'] );
		$this->assertSame(
			array( '12' => 'https://example.test/wp-admin/revision.php?revision=99' ),
			(array) $section['revisions'],
			'only changed posts and pages that have a revision and are editable by the viewer'
		);
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_fallback_when_senrogate_is_older_than_0_5(): void {
		$GLOBALS['senroflux_test_agent_safety'] = new \stdClass();

		$section = ( new ChangesBridge() )->section( $this->makeRun() );

		$this->assertSame( 'revisions', $section['mode'] );
		$this->assertSame( 'Undo needs SenroGate', $section['note'] );
		$this->assertFalse( ( new ChangesBridge() )->isAvailable() );
	}

	public function test_the_undo_note_is_not_in_the_undo_section(): void {
		$section = ( new ChangesBridge() )->section( $this->makeRun() );

		$this->assertArrayNotHasKey( 'note', $section );
	}

	public function test_undo_whole_run_calls_the_api_as_the_current_user_and_returns_items(): void {
		$this->api->undo_result = new UndoResult(
			'partial',
			array(
				new UndoItem( 12, 'option', 'blogname', 'restored' ),
				new UndoItem(
					11,
					'post',
					'12',
					'failed',
					'drift',
					array(
						array(
							'field'   => 'post_title',
							'before'  => 'Old',
							'after'   => 'New',
							'current' => 'Edited since',
						),
					)
				),
			)
		);

		$out = ( new ChangesBridge() )->undo( $this->makeRun(), null, 7 );

		$this->assertSame( 'partial', $out['status'] );
		$this->assertSame( 7, $this->api->undo_calls[0][1] );
		$this->assertSame( 'correlation', $this->api->undo_calls[0][0]->kind );
		$this->assertSame( 'senroflux:run:5', $this->api->undo_calls[0][0]->id );
		$this->assertSame( 'drift', $out['items'][1]['reason'] );
		$this->assertSame( 'Edited since', $out['items'][1]['drift'][0]['current'] );
		$this->assertSame( 'undo', $out['section']['mode'] );
	}

	public function test_per_object_undo_uses_a_change_scope_and_only_for_this_runs_changes(): void {
		$bridge = new ChangesBridge();

		$bridge->undo( $this->makeRun(), 11, 7 );
		$this->assertSame( 'change', $this->api->undo_calls[0][0]->kind );
		$this->assertSame( '11', $this->api->undo_calls[0][0]->id );

		$foreign = $bridge->undo( $this->makeRun(), 999, 7 );
		$this->assertInstanceOf( \WP_Error::class, $foreign );
		$this->assertSame( 'senroflux_change_not_found', $foreign->get_error_code() );
		$this->assertCount( 1, $this->api->undo_calls, 'a change from another run never reaches undo()' );
	}

	public function test_undo_refused_without_the_capability_or_on_a_live_run_or_without_the_api(): void {
		$bridge = new ChangesBridge();

		$GLOBALS['senroflux_test_user_caps']['manage_options'] = false;
		$this->assertSame( 'senroflux_forbidden', $bridge->undo( $this->makeRun(), null, 7 )->get_error_code() );
		$GLOBALS['senroflux_test_user_caps']['manage_options'] = true;

		$this->assertSame( 'senroflux_undo_run_active', $bridge->undo( $this->makeRun( status: RunStatus::Running ), null, 7 )->get_error_code() );
		$this->assertSame( 'senroflux_undo_unavailable', $bridge->undo( $this->makeRun( GateMode::BuiltIn ), null, 7 )->get_error_code() );
		$this->assertSame( array(), $this->api->undo_calls );
	}

	public function test_an_agent_context_refusal_from_senrogate_is_reported_not_swallowed(): void {
		$this->api->undo_result = new UndoResult( 'forbidden', array(), null, 'agent_context' );

		$out = ( new ChangesBridge() )->undo( $this->makeRun(), null, 7 );

		$this->assertSame( 'forbidden', $out['status'] );
		$this->assertSame( 'agent_context', $out['reason'] );
		$this->assertSame( 'An undo must come from a person, not an agent.', $out['message'] );
	}

	public function test_preview_writes_nothing(): void {
		$this->api->preview_items = array( new UndoItem( 11, 'post', '12', 'restorable' ) );

		$out = ( new ChangesBridge() )->preview( $this->makeRun(), null );

		$this->assertSame( 'restorable', $out['items'][0]['status'] );
		$this->assertSame( array(), $this->api->undo_calls );
		$this->assertCount( 1, $this->api->preview_calls );
	}
}
