<?php
/**
 * Client ID Metadata Documents and the SSRF guard.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector\OAuth;

use Specflux\SenroFlux\Connector\OAuth\Clients;
use Specflux\SenroFlux\Connector\OAuth\Ssrf;

require_once __DIR__ . '/OAuthTestCase.php';

final class ClientMetadataTest extends OAuthTestCase {

	private const URL = 'https://app.example/oauth/client.json';

	private function queue( array $doc, int $code = 200 ): void {
		$GLOBALS['senroflux_test_remote_get_responses'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $doc ),
		);
	}

	private function doc( array $override = array() ): array {
		return array_merge(
			array(
				'client_id'     => self::URL,
				'client_name'   => 'Example App',
				'redirect_uris' => array( 'https://app.example/cb' ),
			),
			$override
		);
	}

	public function test_resolves_a_valid_document_and_requests_it_safely(): void {
		$this->queue( $this->doc() );

		$client = Clients::resolve( self::URL );

		$this->assertNotNull( $client );
		$this->assertSame( 'Example App', $client->name );
		$this->assertTrue( $client->allowsRedirect( 'https://app.example/cb' ) );
		$args = $GLOBALS['senroflux_test_remote_get_calls'][0]['args'];
		$this->assertSame( 0, $args['redirection'], 'Redirects are not followed.' );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertLessThanOrEqual( 5, $args['timeout'] );
		$this->assertGreaterThan( 0, $args['limit_response_size'] );
	}

	public function test_document_is_cached_and_failures_are_cached_briefly(): void {
		$this->queue( $this->doc() );
		Clients::resolve( self::URL );
		Clients::resolve( self::URL );
		$this->assertCount( 1, $GLOBALS['senroflux_test_remote_get_calls'] );

		$other = 'https://other.example/c.json';
		$this->assertNull( Clients::resolve( $other ) ); // Nothing queued: the fetch fails.
		$this->assertNull( Clients::resolve( $other ) );
		$this->assertCount( 2, $GLOBALS['senroflux_test_remote_get_calls'], 'A failed fetch is not retried on every request.' );
	}

	public function test_document_client_id_must_equal_the_url(): void {
		$this->queue( $this->doc( array( 'client_id' => 'https://app.example/oauth/other.json' ) ) );

		$this->assertNull( Clients::resolve( self::URL ) );
	}

	public function test_document_redirect_uris_follow_the_same_rules(): void {
		$this->queue( $this->doc( array( 'redirect_uris' => array( 'https://app.example/cb', 'http://evil.example/cb' ) ) ) );

		$this->assertNull( Clients::resolve( self::URL ) );
	}

	public function test_document_asking_for_client_authentication_is_refused(): void {
		$this->queue( $this->doc( array( 'token_endpoint_auth_method' => 'private_key_jwt' ) ) );

		$this->assertNull( Clients::resolve( self::URL ) );
	}

	public function test_document_preferring_client_authentication_but_supporting_none_is_accepted(): void {
		// ChatGPT's document: private_key_jwt preferred, none supported. The token endpoint advertises none only.
		$this->queue(
			$this->doc(
				array(
					'token_endpoint_auth_method' => 'private_key_jwt',
					'token_endpoint_auth_methods_supported' => array( 'none', 'private_key_jwt' ),
				)
			)
		);

		$this->assertNotNull( Clients::resolve( self::URL ) );
	}

	public function test_document_supporting_only_client_authentication_is_refused(): void {
		$this->queue(
			$this->doc(
				array(
					'token_endpoint_auth_method' => 'private_key_jwt',
					'token_endpoint_auth_methods_supported' => array( 'private_key_jwt' ),
				)
			)
		);

		$this->assertNull( Clients::resolve( self::URL ) );
	}

	public function test_non_200_and_non_json_and_oversized_are_refused(): void {
		$this->queue( $this->doc(), 404 );
		$this->assertNull( Clients::resolve( 'https://a.example/x.json' ) );

		$GLOBALS['senroflux_test_remote_get_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not json',
		);
		$this->assertNull( Clients::resolve( 'https://b.example/x.json' ) );

		$GLOBALS['senroflux_test_remote_get_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => str_repeat( ' ', Clients::CIMD_MAX_BYTES ) . wp_json_encode( $this->doc( array( 'client_id' => 'https://c.example/x.json' ) ) ),
		);
		$this->assertNull( Clients::resolve( 'https://c.example/x.json' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function unsafeTargets(): array {
		return array(
			'loopback v4'         => array( 'https://127.0.0.1/c.json' ),
			'private 10/8'        => array( 'https://10.0.0.5/c.json' ),
			'private 192.168'     => array( 'https://192.168.1.10/c.json' ),
			'private 172.16'      => array( 'https://172.16.0.1/c.json' ),
			'link-local metadata' => array( 'https://169.254.169.254/latest/meta-data/x' ),
			'cgnat'               => array( 'https://100.64.0.1/c.json' ),
			'ipv6 loopback'       => array( 'https://[::1]/c.json' ),
			'ipv4-mapped'         => array( 'https://[::ffff:10.0.0.1]/c.json' ),
			'ipv6 ula'            => array( 'https://[fd00::1]/c.json' ),
			'localhost'           => array( 'https://localhost/c.json' ),
			'.internal'           => array( 'https://db.internal/c.json' ),
			'odd port'            => array( 'https://app.example:8443/c.json' ),
			'userinfo'            => array( 'https://user@app.example/c.json' ),
			'plain http'          => array( 'http://app.example/c.json' ),
		);
	}

	/** @dataProvider unsafeTargets */
	public function test_ssrf_guard_refuses_unsafe_targets_without_fetching( string $url ): void {
		$this->queue( $this->doc( array( 'client_id' => $url ) ) );

		$this->assertFalse( Ssrf::allowed( $url ) );
		$this->assertNull( Clients::resolve( $url ) );
		$this->assertSame( array(), $GLOBALS['senroflux_test_remote_get_calls'], 'No request was made.' );
	}

	public function test_hostname_resolving_to_a_private_address_is_refused(): void {
		Ssrf::setResolver( static fn (): array => array( '93.184.216.34', '192.168.0.9' ) );
		$this->assertFalse( Ssrf::allowed( 'https://rebind.example/c.json' ) );

		Ssrf::setResolver( static fn (): array => array() );
		$this->assertFalse( Ssrf::allowed( 'https://nxdomain.example/c.json' ) );

		Ssrf::setResolver( static fn (): array => array( '93.184.216.34' ) );
		$this->assertTrue( Ssrf::allowed( 'https://app.example/c.json' ) );
	}

	public function test_client_id_needs_a_path_and_no_dot_segments(): void {
		foreach ( array( 'https://app.example', 'https://app.example/', 'https://app.example/a/../b.json', 'https://app.example/a/./b.json' ) as $url ) {
			$this->assertNull( Clients::resolve( $url ), $url );
		}
		$this->assertSame( array(), $GLOBALS['senroflux_test_remote_get_calls'] );
	}

	public function test_fetches_have_a_global_hourly_budget(): void {
		for ( $i = 0; $i < Clients::CIMD_FETCHES_HOUR; $i++ ) {
			Clients::resolve( "https://h{$i}.example/c.json" );
		}
		$before = count( $GLOBALS['senroflux_test_remote_get_calls'] );
		Clients::resolve( 'https://one-more.example/c.json' );

		$this->assertSame( $before, count( $GLOBALS['senroflux_test_remote_get_calls'] ) );
	}
}
