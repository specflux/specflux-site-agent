<?php
/**
 * Dynamic Client Registration.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector\OAuth;

use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\OAuth\Clients;
use Specflux\SenroFlux\Connector\OAuth\RedirectUri;
use Specflux\SenroFlux\Connector\OAuth\Registration;
use Specflux\SenroFlux\Connector\OAuth\Store;

require_once __DIR__ . '/OAuthTestCase.php';

final class RegistrationTest extends OAuthTestCase {

	public function test_registers_a_public_client(): void {
		$r = Registration::handle(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( self::CALLBACK ),
			),
			'203.0.113.1'
		);

		$this->assertSame( 201, $r['status'] );
		$this->assertMatchesRegularExpression( '/^snf_[a-f0-9]{32}$/', $r['body']['client_id'] );
		$this->assertSame( 'none', $r['body']['token_endpoint_auth_method'] );
		$this->assertArrayNotHasKey( 'client_secret', $r['body'] );
		$this->assertSame( array( self::CALLBACK ), Store::client( $r['body']['client_id'] )['redirect_uris'] );
	}

	/** @return array<string,array{0:mixed}> */
	public static function badRedirects(): array {
		return array(
			'http non-loopback'   => array( 'http://evil.example/cb' ),
			'http lookalike host' => array( 'http://localhost.evil.example/cb' ),
			'fragment'            => array( 'https://app.example/cb#frag' ),
			'javascript'          => array( 'javascript:alert(1)' ),
			'custom scheme'       => array( 'myapp://cb' ),
			'data'                => array( 'data:text/html,hi' ),
			'userinfo'            => array( 'https://user:pw@app.example/cb' ),
			'backslash'           => array( 'https://app.example\\@evil.example/cb' ),
			'whitespace'          => array( "https://app.example/cb\n.evil" ),
			'relative'            => array( '/cb' ),
			'empty'               => array( '' ),
			'not a string'        => array( 42 ),
			'too long'            => array( 'https://app.example/' . str_repeat( 'a', 600 ) ),
		);
	}

	/** @dataProvider badRedirects */
	public function test_rejects_bad_redirect_uris( mixed $uri ): void {
		$r = Registration::handle( array( 'redirect_uris' => array( self::CALLBACK, $uri ) ), '203.0.113.2' );

		$this->assertSame( 400, $r['status'] );
		$this->assertSame( 'invalid_redirect_uri', $r['body']['error'] );
		$this->assertSame( array(), Store::clientIndex(), 'Nothing is stored for a rejected registration.' );
	}

	public function test_loopback_http_is_allowed_on_any_port(): void {
		foreach ( array( 'http://localhost:8123/cb', 'http://127.0.0.1/cb', 'http://[::1]:9/cb' ) as $uri ) {
			$this->assertTrue( RedirectUri::valid( $uri ), $uri );
		}
		$this->assertSame( 201, Registration::handle( array( 'redirect_uris' => array( 'http://127.0.0.1:5555/cb' ) ), '203.0.113.3' )['status'] );
	}

	public function test_redirect_uris_must_be_a_non_empty_list(): void {
		foreach ( array( array(), 'https://app.example/cb', array( 'a' => self::CALLBACK ), null ) as $uris ) {
			$this->assertSame( 400, Registration::handle( array( 'redirect_uris' => $uris ), '203.0.113.4' )['status'] );
		}
	}

	public function test_confidential_client_methods_are_refused(): void {
		$r = Registration::handle(
			array(
				'redirect_uris'              => array( self::CALLBACK ),
				'token_endpoint_auth_method' => 'client_secret_post',
			),
			'203.0.113.5'
		);
		$this->assertSame( 'invalid_client_metadata', $r['body']['error'] );

		$r = Registration::handle(
			array(
				'redirect_uris' => array( self::CALLBACK ),
				'grant_types'   => array( 'client_credentials' ),
			),
			'203.0.113.5'
		);
		$this->assertSame( 'invalid_client_metadata', $r['body']['error'] );
	}

	public function test_rate_limit_is_per_ip(): void {
		for ( $i = 0; $i < Registration::LIMIT_PER_HOUR; $i++ ) {
			$this->assertSame( 201, Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '198.51.100.7' )['status'] );
		}
		$blocked = Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '198.51.100.7' );
		$this->assertSame( 429, $blocked['status'] );
		$this->assertSame( 201, Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '198.51.100.8' )['status'], 'Another address is unaffected.' );

		\Specflux\SenroFlux\Run\Clock::useFixed( self::T0 + 3601 );
		$this->assertSame( 201, Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '198.51.100.7' )['status'], 'The window ends.' );
	}

	public function test_invalid_attempts_count_toward_the_limit(): void {
		for ( $i = 0; $i < Registration::LIMIT_PER_HOUR; $i++ ) {
			Registration::handle( array( 'redirect_uris' => array( 'http://evil.example' ) ), '198.51.100.9' );
		}
		$this->assertSame( 429, Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '198.51.100.9' )['status'] );
	}

	public function test_disabled_connector_has_no_registration(): void {
		update_option( Connector::OPTION, '0' );

		$this->assertSame( 404, Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '203.0.113.6' )['status'] );
	}

	public function test_name_is_sanitised_and_capped(): void {
		$r = Registration::handle(
			array(
				'client_name'   => '<script>alert(1)</script>' . str_repeat( 'n', 300 ),
				'redirect_uris' => array( self::CALLBACK ),
			),
			'203.0.113.7'
		);

		$this->assertStringNotContainsString( '<', $r['body']['client_name'] );
		$this->assertLessThanOrEqual( Clients::MAX_NAME_LENGTH, strlen( $r['body']['client_name'] ) );
		$this->assertSame( 'Unnamed app', Clients::cleanName( array( 'x' ) ) );
	}

	public function test_registry_is_capped_and_prunes_the_oldest_unused_client(): void {
		for ( $i = 0; $i < Clients::MAX_CLIENTS; $i++ ) {
			Store::saveClient(
				array(
					'id'            => sprintf( 'seed%04d_x', $i ),
					'name'          => 'n',
					'redirect_uris' => array( self::CALLBACK ),
					'created'       => self::T0 + $i,
				)
			);
		}
		$new = Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '203.0.113.10' );

		$this->assertSame( 201, $new['status'] );
		$this->assertCount( Clients::MAX_CLIENTS, Store::clientIndex() );
		$this->assertNull( Store::client( 'seed0000_x' ), 'The oldest unused client was pruned.' );
		$this->assertNotNull( Store::client( 'seed0001_x' ) );
	}

	public function test_a_full_registry_of_clients_in_use_refuses_instead_of_evicting_them(): void {
		for ( $i = 0; $i < Clients::MAX_CLIENTS; $i++ ) {
			$id = sprintf( 'seed%04d_x', $i );
			Store::saveClient(
				array(
					'id'            => $id,
					'name'          => 'n',
					'redirect_uris' => array( self::CALLBACK ),
					'created'       => self::T0 + $i,
				)
			);
			Store::createGrant( 1, $id, 'senroflux:read', 'r', 'h' );
		}
		$this->assertSame( 503, Registration::handle( array( 'redirect_uris' => array( self::CALLBACK ) ), '203.0.113.11' )['status'] );
		$this->assertNotNull( Store::client( 'seed0000_x' ) );
	}
}
