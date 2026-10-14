<?php
/**
 * The token endpoint: code exchange, PKCE, rotation and reuse detection.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector\OAuth;

use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Connector\OAuth\Bearer;
use Specflux\SiteAgent\Connector\OAuth\OAuth;
use Specflux\SiteAgent\Connector\OAuth\Store;
use Specflux\SiteAgent\Connector\OAuth\Token;
use Specflux\SiteAgent\Run\Clock;

require_once __DIR__ . '/OAuthTestCase.php';

final class TokenTest extends OAuthTestCase {

	private function assertError( string $error, array $result ): void {
		$this->assertSame( $error, $result['body']['error'] ?? null );
		$this->assertGreaterThanOrEqual( 400, $result['status'] );
		$this->assertArrayNotHasKey( 'access_token', $result['body'] );
	}

	private function accessWorks( string $token ): bool {
		$this->asMcpRequest( $token );

		return 0 !== Bearer::authenticate( 0 );
	}

	public function test_code_exchange_issues_tokens(): void {
		$r = $this->exchange( $this->obtainCode() );

		$this->assertSame( 200, $r['status'] );
		$this->assertSame( 'Bearer', $r['body']['token_type'] );
		$this->assertSame( 3600, $r['body']['expires_in'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $r['body']['access_token'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $r['body']['refresh_token'] );
		$this->assertNotSame( $r['body']['access_token'], $r['body']['refresh_token'] );
		$this->assertSame( 'specflux:read', $r['body']['scope'] );
	}

	public function test_only_hashes_are_stored(): void {
		$r    = $this->tokens();
		$dump = wp_json_encode( array( $GLOBALS['specflux_site_agent_test_options'], $GLOBALS['specflux_site_agent_test_transients'] ) );

		$this->assertStringNotContainsString( $r['body']['access_token'], (string) $dump );
		$this->assertStringNotContainsString( $r['body']['refresh_token'], (string) $dump );
		$this->assertStringContainsString( OAuth::hash( $r['body']['refresh_token'] ), (string) $dump );
	}

	public function test_code_is_single_use(): void {
		$flow = $this->obtainCode();

		$this->assertSame( 200, $this->exchange( $flow )['status'] );
		$this->assertError( 'invalid_grant', $this->exchange( $flow ) );
	}

	public function test_code_expires_after_sixty_seconds(): void {
		$flow = $this->obtainCode();
		Clock::useFixed( self::T0 + 61 );

		$this->assertError( 'invalid_grant', $this->exchange( $flow ) );
	}

	public function test_code_is_still_valid_at_sixty_seconds(): void {
		$flow = $this->obtainCode();
		Clock::useFixed( self::T0 + 60 );

		$this->assertSame( 200, $this->exchange( $flow )['status'] );
	}

	public function test_reusing_a_code_revokes_the_tokens_it_issued(): void {
		$flow  = $this->obtainCode();
		$first = $this->exchange( $flow );
		$this->assertTrue( $this->accessWorks( $first['body']['access_token'] ) );

		$this->assertError( 'invalid_grant', $this->exchange( $flow ) );

		$this->assertFalse( $this->accessWorks( $first['body']['access_token'] ), 'Access token revoked.' );
		$this->assertError( 'invalid_grant', $this->refreshWith( $first['body']['refresh_token'], $flow['client'] ) );
	}

	public function test_wrong_pkce_verifier_is_rejected_and_burns_the_code(): void {
		$flow          = $this->obtainCode();
		list( $other ) = $this->pkce();

		$this->assertError( 'invalid_grant', $this->exchange( $flow, array( 'code_verifier' => $other ) ) );
		$this->assertError( 'invalid_grant', $this->exchange( $flow ), );
	}

	public function test_missing_or_malformed_verifier_is_rejected(): void {
		foreach ( array( '', 'short', str_repeat( 'a', 129 ), str_repeat( '!', 50 ) ) as $verifier ) {
			$flow = $this->obtainCode();
			$this->assertSame( 400, $this->exchange( $flow, array( 'code_verifier' => $verifier ) )['status'] );
		}
	}

	public function test_redirect_uri_must_match_the_authorization_request(): void {
		$flow = $this->obtainCode();

		$this->assertError( 'invalid_grant', $this->exchange( $flow, array( 'redirect_uri' => 'https://evil.example/cb' ) ) );
	}

	public function test_client_id_must_match(): void {
		$flow  = $this->obtainCode();
		$other = $this->dcr();

		$this->assertError( 'invalid_grant', $this->exchange( $flow, array( 'client_id' => $other ) ) );
	}

	public function test_resource_must_match(): void {
		$flow = $this->obtainCode();

		$this->assertError( 'invalid_target', $this->exchange( $flow, array( 'resource' => 'https://other.example/mcp' ) ) );
	}

	public function test_user_who_lost_the_capability_gets_nothing(): void {
		$flow = $this->obtainCode();
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => false );

		$this->assertError( 'invalid_grant', $this->exchange( $flow ) );
	}

	public function test_grant_types_other_than_the_two_are_refused(): void {
		$this->assertError(
			'unsupported_grant_type',
			Token::handle(
				array(
					'grant_type' => 'client_credentials',
					'client_id'  => 'x',
				)
			)
		);
		$this->assertError(
			'unsupported_grant_type',
			Token::handle(
				array(
					'grant_type' => 'password',
					'username'   => 'a',
					'password'   => 'b',
				)
			)
		);
		$this->assertError( 'invalid_request', Token::handle( array() ) );
		$this->assertError( 'invalid_request', Token::handle( array( 'grant_type' => array( 'authorization_code' ) ) ) );
	}

	public function test_errors_are_generic_json_without_echoing_input(): void {
		$r = Token::handle(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => 'SECRETCODE',
				'code_verifier' => str_repeat( 'v', 50 ),
				'client_id'     => 'CLIENTX',
				'redirect_uri'  => 'https://r.example',
			)
		);

		$this->assertStringNotContainsString( 'SECRETCODE', (string) wp_json_encode( $r ) );
		$this->assertStringNotContainsString( 'CLIENTX', (string) wp_json_encode( $r ) );
		$this->assertSame( array( 'error', 'error_description' ), array_keys( $r['body'] ) );
	}

	public function test_disabled_connector_issues_nothing(): void {
		$flow = $this->obtainCode();
		update_option( Connector::OPTION, '0' );

		$this->assertError( 'not_found', $this->exchange( $flow ) );
	}

	// ---- Refresh --------------------------------------------------------

	public function test_refresh_rotates_and_the_old_token_dies(): void {
		$first  = $this->tokens();
		$second = $this->refreshWith( $first['body']['refresh_token'], $first['client'] );

		$this->assertSame( 200, $second['status'] );
		$this->assertNotSame( $first['body']['refresh_token'], $second['body']['refresh_token'] );
		$this->assertNotSame( $first['body']['access_token'], $second['body']['access_token'] );
		$this->assertTrue( $this->accessWorks( $second['body']['access_token'] ) );
		$this->assertSame( 200, $this->refreshWith( $second['body']['refresh_token'], $first['client'] )['status'], 'The new token rotates again.' );
	}

	public function test_refresh_reuse_revokes_the_whole_family(): void {
		$first  = $this->tokens();
		$second = $this->refreshWith( $first['body']['refresh_token'], $first['client'] );
		$third  = $this->refreshWith( $second['body']['refresh_token'], $first['client'] );
		$this->assertSame( 200, $third['status'] );

		// An attacker (or a confused client) replays the first, already-rotated token.
		$this->assertError( 'invalid_grant', $this->refreshWith( $first['body']['refresh_token'], $first['client'] ) );

		$this->assertError( 'invalid_grant', $this->refreshWith( $third['body']['refresh_token'], $first['client'] ), 'The newest refresh token died.' );
		$this->assertFalse( $this->accessWorks( $third['body']['access_token'] ), 'The newest access token died.' );
		$this->assertFalse( $this->accessWorks( $second['body']['access_token'] ) );
	}

	public function test_a_foreign_client_cannot_use_or_burn_a_refresh_token(): void {
		$first = $this->tokens();
		$other = $this->dcr();

		$this->assertError( 'invalid_grant', $this->refreshWith( $first['body']['refresh_token'], $other ) );
		$this->assertSame( 200, $this->refreshWith( $first['body']['refresh_token'], $first['client'] )['status'], 'The real client is unaffected.' );
	}

	public function test_refresh_token_expires_after_thirty_days(): void {
		$first = $this->tokens();
		Clock::useFixed( self::T0 + 30 * 86400 + 1 );

		$this->assertError( 'invalid_grant', $this->refreshWith( $first['body']['refresh_token'], $first['client'] ) );
	}

	public function test_refresh_may_narrow_scope_but_never_widen_it(): void {
		$first = $this->tokens( 'write' );
		$this->assertSame( 'specflux:read specflux:write', $first['body']['scope'] );

		$narrow = $this->refreshWith( $first['body']['refresh_token'], $first['client'], array( 'scope' => 'specflux:read' ) );
		$this->assertSame( 'specflux:read', $narrow['body']['scope'] );

		$read = $this->tokens( 'read' );
		$wide = $this->refreshWith( $read['body']['refresh_token'], $read['client'], array( 'scope' => 'specflux:read specflux:write' ) );
		$this->assertError( 'invalid_scope', $wide );
		$this->assertSame( 200, $this->refreshWith( $read['body']['refresh_token'], $read['client'] )['status'], 'A bad scope request does not burn the token.' );
	}

	public function test_unknown_refresh_token_is_refused(): void {
		$this->assertError( 'invalid_grant', $this->refreshWith( OAuth::secret(), $this->dcr() ) );
	}

	public function test_refresh_revalidates_the_user(): void {
		$first = $this->tokens();
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => false );

		$this->assertError( 'invalid_grant', $this->refreshWith( $first['body']['refresh_token'], $first['client'] ) );
	}

	public function test_expired_access_token_is_rejected(): void {
		$first = $this->tokens();
		$this->assertTrue( $this->accessWorks( $first['body']['access_token'] ) );

		Clock::useFixed( self::T0 + 3599 );
		$this->assertTrue( $this->accessWorks( $first['body']['access_token'] ) );

		Clock::useFixed( self::T0 + 3601 );
		$this->assertFalse( $this->accessWorks( $first['body']['access_token'] ) );
	}

	public function test_gc_drops_grants_whose_refresh_token_expired(): void {
		$first = $this->tokens();
		Store::gc();
		$this->assertNotSame( array(), Store::grantIndex() );

		Clock::useFixed( self::T0 + 31 * 86400 );
		Store::gc();
		$this->assertSame( array(), Store::grantIndex() );
		$this->assertNull( Store::peekRefresh( $first['body']['refresh_token'] ) );
	}
}
