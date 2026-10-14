<?php
/**
 * The run routes (including the 0.4 undo/preview routes) use the Runs-screen gate (a held pack run capability), not
 * the coarse `read` capability every subscriber has.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Http;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Http\Rest;
use Specflux\SiteAgent\Packs\Pack;
use WP_Error;

final class RestPermissionTest extends TestCase {

	/** Route => HTTP method of every run route that must use the screen gate. */
	private const RUN_ROUTES = array(
		'POST /runs',
		'POST /runs/(?P<run_id>\d+)/tick',
		'POST /runs/(?P<run_id>\d+)/cancel',
		'GET /runs',
		'GET /runs/(?P<run_id>\d+)',
		'POST /runs/(?P<run_id>\d+)/changes/preview',
		'POST /runs/(?P<run_id>\d+)/changes/undo',
	);

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/Adversarial/support.php';

		$GLOBALS['specflux_site_agent_test_filters']         = array();
		$GLOBALS['specflux_site_agent_test_rest_routes']     = array();
		$GLOBALS['specflux_site_agent_test_logged_in']       = true;
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 7;
		$GLOBALS['specflux_site_agent_test_user_caps']       = array();
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		$this->registerPostsPack();
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_runs_capability' );
		remove_all_filters( 'specflux_site_agent_packs' );
		$GLOBALS['specflux_site_agent_test_logged_in'] = true;
	}

	private function registerPostsPack(): void {
		$pack = new class() extends Pack {
			public function __construct() {
				parent::__construct( array() );
			}

			public function name(): string {
				return 'posts';
			}

			public function verbMap(): array {
				return array();
			}

			public function runCapability(): string {
				return 'edit_posts';
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};

		add_filter(
			'specflux_site_agent_packs',
			static fn ( array $packs ): array => $packs + array( 'posts' => $pack ),
			10,
			1
		);
	}

	/** @return array<string,callable> "METHOD route" => permission_callback */
	private function runRouteCallbacks(): array {
		( new Rest() )->register();

		$callbacks = array();
		foreach ( $GLOBALS['specflux_site_agent_test_rest_routes'] as list( , $route, $args ) ) {
			$key = $args['methods'] . ' ' . $route;
			if ( in_array( $key, self::RUN_ROUTES, true ) ) {
				$callbacks[ $key ] = $args['permission_callback'];
			}
		}

		return $callbacks;
	}

	public function test_all_seven_run_routes_are_covered(): void {
		$this->assertEqualsCanonicalizing( self::RUN_ROUTES, array_keys( $this->runRouteCallbacks() ) );
	}

	public function test_a_subscriber_holding_only_read_is_refused_on_every_run_route(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'read' => true );

		foreach ( $this->runRouteCallbacks() as $key => $callback ) {
			$this->assertFalse( $callback(), $key );
		}
	}

	public function test_a_user_holding_a_pack_run_capability_is_allowed_on_every_run_route(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array(
			'read'       => true,
			'edit_posts' => true,
		);

		foreach ( $this->runRouteCallbacks() as $key => $callback ) {
			$this->assertTrue( $callback(), $key );
		}
	}

	public function test_a_logged_out_visitor_is_refused_even_with_the_capability(): void {
		$GLOBALS['specflux_site_agent_test_logged_in'] = false;
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'edit_posts' => true );

		foreach ( $this->runRouteCallbacks() as $key => $callback ) {
			$this->assertFalse( $callback(), $key );
		}
	}
}
