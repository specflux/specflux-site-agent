<?php
/**
 * 0.4 S1: GET run detail carries a computed `display` on tool_result steps
 * only, and leaves every existing step field alone.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\StepKind;
use wpdb;

final class PluginStepDisplayTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['senroflux_test_current_user_id'] = 1;
		$GLOBALS['senroflux_test_user_caps']       = array( 'edit_posts' => true );
		$GLOBALS['wpdb']                           = new wpdb();
		Plugin::set_dependency_probe( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['senroflux_test_user_caps'] );
		Plugin::reset();
	}

	public function test_tool_result_steps_carry_display_and_other_kinds_null(): void {
		$method = new \ReflectionMethod( Plugin::class, 'runner' );
		$method->setAccessible( true );
		$store  = $method->invoke( Plugin::instance() )->store();
		$run_id = $store->createRun( 1, 'test-consumer', 'Goal', array( 'senroflux/*' ), array(), null );

		$store->appendStep(
			$run_id,
			StepKind::User,
			array(
				'role'  => 'user',
				'parts' => array( array( 'text' => 'Goal' ) ),
			)
		);
		$store->appendStep(
			$run_id,
			StepKind::ToolResult,
			array(
				'role'  => 'user',
				'parts' => array(
					array(
						'functionResponse' => array(
							'name'     => 'wpab__senroflux__create-post',
							'response' => array(
								'id'     => 42,
								'status' => 'draft',
							),
						),
					),
				),
				'tier'  => 1,
			),
			'wpab__senroflux__create-post',
			null,
			'ok'
		);
		$store->appendStep(
			$run_id,
			StepKind::ToolResult,
			array(
				'role'  => 'user',
				'parts' => array(
					array(
						'functionResponse' => array(
							'name'     => 'wpab__senroflux__update-post',
							'response' => array( 'error' => 'Post not found.' ),
						),
					),
				),
			),
			'wpab__senroflux__update-post',
			null,
			'error'
		);

		$detail = Plugin::instance()->get( $run_id );

		$this->assertIsArray( $detail );
		$steps = $detail['steps'];
		$this->assertNull( $steps[0]['display'] );
		$this->assertSame( 'post', $steps[1]['display']['type'] );
		$this->assertSame( 'Post #42', $steps[1]['display']['title'] );
		$this->assertSame( 1, $steps[1]['tier'] );
		$this->assertSame( 'error', $steps[2]['display']['type'] );
		$this->assertSame( 'Post not found.', $steps[2]['display']['message'] );
		$this->assertSame( array( 'role', 'parts', 'tier' ), array_keys( $steps[1]['message'] ), 'stored message untouched' );
	}
}
