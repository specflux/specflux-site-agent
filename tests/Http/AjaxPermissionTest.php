<?php
/**
 * The admin-ajax run handlers use the Runs-screen gate (a held pack run
 * capability), not the coarse `read` capability every subscriber has — the
 * same gate {@see RestPermissionTest} pins for the REST twins.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Http\Ajax;
use Specflux\SiteAgent\Plugin;
use SpecfluxSiteAgentJsonResponse;
use wpdb;

final class AjaxPermissionTest extends TestCase {

	/** Handler method => the admin-ajax action it serves. */
	private const HANDLERS = array(
		'handleStart'  => 'specflux_site_agent_start',
		'handleTick'   => 'specflux_site_agent_tick',
		'handleCancel' => 'specflux_site_agent_cancel',
		'handleGet'    => 'specflux_site_agent_get',
	);

	protected function setUp(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 7;
		$GLOBALS['specflux_site_agent_test_user_caps']       = array();
		$GLOBALS['specflux_site_agent_test_filters']         = array();
		$GLOBALS['wpdb']                                     = new wpdb();
		unset( $_POST );
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'edit_pages' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		unset( $_POST );
	}

	/** Call one handler and return the JSON it sent (the shims throw it in place of die()). */
	private function call( string $method ): SpecfluxSiteAgentJsonResponse {
		$_POST = array(
			'nonce'  => 'n',
			'run_id' => '999999',
		);

		try {
			( new Ajax() )->$method();
		} catch ( SpecfluxSiteAgentJsonResponse $json ) {
			return $json;
		}

		$this->fail( $method . ' sent no JSON response.' );
	}

	public function test_a_user_holding_only_read_gets_403_from_every_handler(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'read' => true );

		foreach ( self::HANDLERS as $method => $action ) {
			$json = $this->call( $method );

			$this->assertFalse( $json->success, $action );
			$this->assertSame( 403, $json->status, $action );
			$this->assertSame( 'Insufficient permissions.', $json->data['message'] ?? null, $action );
		}
	}

	public function test_a_holder_of_the_screen_capability_gets_past_the_permission_check(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array(
			'read'       => true,
			'edit_pages' => true,
		);

		foreach ( self::HANDLERS as $method => $action ) {
			$json = $this->call( $method );

			$this->assertNotSame( 'Insufficient permissions.', is_array( $json->data ) ? ( $json->data['message'] ?? null ) : null, $action );
		}
	}
}
