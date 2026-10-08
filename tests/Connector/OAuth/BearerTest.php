<?php
/**
 * Bearer tokens authenticate the MCP route and nothing else.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector\OAuth;

use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Connections;
use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\OAuth\Bearer;
use Specflux\SenroFlux\Connector\OAuth\Store;
use SenroFlux_Test_Route_Request;

require_once __DIR__ . '/OAuthTestCase.php';

final class BearerTest extends OAuthTestCase {

	private function register(): void {
		Bearer::register();
	}

	public function test_valid_token_authenticates_on_the_mcp_route(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );

		$this->assertSame( self::USER, Bearer::authenticate( 0 ) );
	}

	public function test_bearer_token_is_ignored_on_any_other_route(): void {
		$t = $this->tokens();

		foreach ( array( '/wp-json/wp/v2/users', '/wp-json/senroflux/v1/runs', '/wp-json/senroflux/mcp/extra', '/wp-json/senroflux/mcpx', '/wp-admin/admin-ajax.php', '/', '/xmlrpc.php', '/wp-json/senroflux/oauth/token' ) as $path ) {
			$this->asMcpRequest( $t['body']['access_token'], $path );

			$this->assertSame( 0, Bearer::authenticate( 0 ), $path );
			$this->assertNull( $this->connectionFor( self::USER ), $path );
		}
	}

	public function test_rest_route_parameter_cannot_smuggle_the_token_onto_another_route(): void {
		$t = $this->tokens();
		// WordPress dispatches ?rest_route= over the URL path: this request is /wp/v2/users.
		foreach ( array( '/wp/v2/users', '/wp/v2/users/me', '/senroflux/mcp/../v1/runs', '/senroflux/mcpx' ) as $route ) {
			$this->asMcpRequest( $t['body']['access_token'] );
			$_GET['rest_route'] = $route;

			$this->assertSame( 0, Bearer::authenticate( 0 ), $route );
		}
		$this->asMcpRequest( $t['body']['access_token'] );
		$_GET['rest_route'] = array( '/senroflux/mcp' );
		$this->assertSame( 0, Bearer::authenticate( 0 ), 'Array-valued rest_route is refused.' );

		$this->asMcpRequest( $t['body']['access_token'], '/' );
		$_GET['rest_route'] = '/senroflux/mcp';
		$this->assertSame( self::USER, Bearer::authenticate( 0 ), 'The plain-permalink form of the MCP route works.' );
	}

	public function test_dispatch_guard_validates_every_dispatch_while_a_token_is_active(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );

		$this->assertNull( Bearer::guardDispatch( null, null, new SenroFlux_Test_Route_Request( '/senroflux/mcp' ) ) );
		$blocked = Bearer::guardDispatch( null, null, new SenroFlux_Test_Route_Request( '/wp/v2/users' ) );
		$this->assertInstanceOf( \WP_Error::class, $blocked, 'A later sub-dispatch is refused.' );
		$this->assertSame( 403, $blocked->get_error_data()['status'] ?? 0 );
		$this->assertInstanceOf( \WP_Error::class, Bearer::guardDispatch( null, null, new SenroFlux_Test_Route_Request( '/batch/v1' ) ) );
		$this->assertInstanceOf( \WP_Error::class, Bearer::guardDispatch( null, null, new SenroFlux_Test_Route_Request( '/wc/v3/products' ) ) );
		$this->assertNull( Bearer::guardDispatch( null, null, new SenroFlux_Test_Route_Request( '/senroflux/mcp' ) ), 'Refusing a sub-dispatch does not break the in-flight MCP request.' );
		$this->assertNotNull( $this->connectionFor( self::USER ), 'The token still yields its connection.' );
	}

	public function test_dispatch_guard_does_nothing_for_other_authentication(): void {
		$this->assertNull( Bearer::guardDispatch( null, null, new SenroFlux_Test_Route_Request( '/wp/v2/users' ) ) );
	}

	/** @return array<string,array{0:string,1:array<string,mixed>}> */
	public static function non_front_controller_entries(): array {
		return array(
			'admin-ajax (is_admin true)' => array(
				ABSPATH . 'wp-admin/admin-ajax.php',
				array(
					'senroflux_test_is_admin'   => true,
					'senroflux_test_doing_ajax' => true,
				),
			),
			'is_admin only'              => array( ABSPATH . 'index.php', array( 'senroflux_test_is_admin' => true ) ),
			'doing ajax only'            => array( ABSPATH . 'index.php', array( 'senroflux_test_doing_ajax' => true ) ),
			'doing cron'                 => array( ABSPATH . 'index.php', array( 'senroflux_test_doing_cron' => true ) ),
			'wp-cron.php'                => array( ABSPATH . 'wp-cron.php', array() ),
			'wp-login.php'               => array( ABSPATH . 'wp-login.php', array() ),
			'admin-post.php'             => array( ABSPATH . 'wp-admin/admin-post.php', array() ),
			'xmlrpc.php'                 => array( ABSPATH . 'xmlrpc.php', array() ),
			'wp-comments-post.php'       => array( ABSPATH . 'wp-comments-post.php', array() ),
			'index.php outside ABSPATH'  => array( '/var/www/other/index.php', array() ),
			'index.php in a subdir'      => array( ABSPATH . 'wp-admin/index.php', array() ),
			'pagenow is wp-login'        => array( ABSPATH . 'index.php', array( 'pagenow' => 'wp-login.php' ) ),
			'no script filename'         => array( '', array() ),
		);
	}

	/**
	 * @dataProvider non_front_controller_entries
	 * @param array<string,mixed> $globals
	 */
	public function test_token_is_refused_outside_the_front_controller( string $script, array $globals ): void {
		foreach ( array( '/wp-json/senroflux/mcp', '/' ) as $uri ) {
			$t = $this->tokens();
			$this->asMcpRequest( $t['body']['access_token'], $uri );
			if ( '/' === $uri ) {
				$_GET['rest_route'] = '/senroflux/mcp';
			}
			$_SERVER['SCRIPT_FILENAME'] = $script;
			foreach ( $globals as $key => $value ) {
				$GLOBALS[ $key ] = $value; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- test knobs.
			}

			$this->assertSame( 0, Bearer::authenticate( 0 ), $uri );
			$this->assertNull( $this->connectionFor( self::USER ) );
		}
	}

	public function test_token_is_accepted_on_the_front_controller_in_both_url_forms(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );
		$this->assertSame( self::USER, Bearer::authenticate( 0 ), 'Pretty permalinks.' );

		$this->asMcpRequest( $t['body']['access_token'], '/' );
		$_GET['rest_route'] = '/senroflux/mcp';
		$this->assertSame( self::USER, Bearer::authenticate( 0 ), '?rest_route= form.' );

		$this->asMcpRequest( $t['body']['access_token'], '/' );
		unset( $_GET['rest_route'] );
		$_POST['rest_route'] = '/senroflux/mcp';
		$user                = Bearer::authenticate( 0 );
		unset( $_POST['rest_route'] );
		$this->assertSame( self::USER, $user, 'POSTed rest_route on the front controller.' );
	}

	public function test_garbage_and_wrong_shapes_authenticate_nobody(): void {
		$t = $this->tokens();
		foreach ( array(
			'Bearer ' . str_repeat( 'a', 64 ),
			'Bearer ' . strtoupper( $t['body']['access_token'] ),
			'Bearer ' . $t['body']['access_token'] . 'x',
			'Basic ' . base64_encode( 'u:' . $t['body']['access_token'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'Bearer',
			'',
		) as $header ) {
			Bearer::reset();
			$_SERVER['REQUEST_URI']        = '/wp-json/senroflux/mcp';
			$_SERVER['HTTP_AUTHORIZATION'] = $header;

			$this->assertSame( 0, Bearer::authenticate( 0 ), $header );
		}
	}

	public function test_a_refresh_token_is_not_an_access_token(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['refresh_token'] );

		$this->assertSame( 0, Bearer::authenticate( 0 ) );
	}

	public function test_an_existing_user_is_never_overridden(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );

		$this->assertSame( 99, Bearer::authenticate( 99 ) );
	}

	public function test_token_for_another_audience_is_rejected(): void {
		$t               = $this->tokens();
		$grant           = array_key_first( Store::grantIndex() );
		$rec             = Store::grant( (string) $grant );
		$rec['resource'] = 'https://other.example/mcp';
		Store::saveGrant( (string) $grant, $rec );
		$this->asMcpRequest( $t['body']['access_token'] );

		$this->assertSame( 0, Bearer::authenticate( 0 ) );
	}

	public function test_revoked_grant_stops_the_token_at_once(): void {
		$t = $this->tokens();
		Store::revokeGrant( (string) array_key_first( Store::grantIndex() ) );
		$this->asMcpRequest( $t['body']['access_token'] );

		$this->assertSame( 0, Bearer::authenticate( 0 ) );
	}

	public function test_deleting_the_user_revokes_their_tokens(): void {
		$t = $this->tokens();
		Store::revokeForUser( self::USER );
		$this->asMcpRequest( $t['body']['access_token'] );

		$this->assertSame( 0, Bearer::authenticate( 0 ) );
		$this->assertSame( array(), Store::grantIndex() );
	}

	public function test_disabled_connector_authenticates_nobody(): void {
		$t = $this->tokens();
		update_option( Connector::OPTION, '0' );
		$this->asMcpRequest( $t['body']['access_token'] );

		$this->assertSame( 0, Bearer::authenticate( 0 ) );
	}

	/** Resolve the request's connection the way Connections::current() does. */
	private function connectionFor( int $user ): ?Connection {
		$GLOBALS['senroflux_test_current_user_id'] = $user;
		$this->register();

		return Connections::current();
	}

	public function test_read_scope_token_yields_a_read_only_connection(): void {
		$t = $this->tokens( 'read' );
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );

		$connection = $this->connectionFor( self::USER );

		$this->assertNotNull( $connection );
		$this->assertSame( Connection::SCOPE_READ, $connection->scope );
		$this->assertFalse( $connection->mayWrite() );
		$this->assertStringStartsWith( 'oauth:', $connection->uuid );
		$this->assertSame( self::USER, $connection->userId );
	}

	public function test_write_scope_token_yields_a_read_write_connection(): void {
		$t = $this->tokens( 'write' );
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );

		$this->assertTrue( $this->connectionFor( self::USER )->mayWrite() );
	}

	public function test_a_narrowed_access_token_is_read_only_even_on_a_write_grant(): void {
		$t      = $this->tokens( 'write' );
		$narrow = $this->refreshWith( $t['body']['refresh_token'], $t['client'], array( 'scope' => 'senroflux:read' ) );
		$this->asMcpRequest( $narrow['body']['access_token'] );
		Bearer::authenticate( 0 );

		$this->assertFalse( $this->connectionFor( self::USER )->mayWrite() );
	}

	public function test_connector_authorise_accepts_the_token_and_rechecks_the_capability(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );
		$this->register();
		$GLOBALS['senroflux_test_current_user_id'] = self::USER;

		$this->assertNotNull( Connector::authorise() );

		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => false );
		$this->assertNull( Connector::authorise(), 'Losing the capability makes the token useless.' );
	}

	public function test_connection_for_a_different_current_user_is_refused(): void {
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );

		$this->assertNull( $this->connectionFor( 8 ) );
	}

	public function test_application_password_connections_are_unchanged(): void {
		$this->register();
		Connections::add( self::USER, self::UUID, Connection::SCOPE_READ_WRITE );
		$GLOBALS['senroflux_test_app_password'] = self::UUID;

		$this->assertSame( self::UUID, Connections::current()->uuid );
	}
}
