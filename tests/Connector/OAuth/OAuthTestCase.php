<?php
/**
 * Shared fixtures for the OAuth suites.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector\OAuth;

use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\OAuth\Authorize;
use Specflux\SenroFlux\Connector\OAuth\Bearer;
use Specflux\SenroFlux\Connector\OAuth\OAuth;
use Specflux\SenroFlux\Connector\OAuth\Page;
use Specflux\SenroFlux\Connector\OAuth\Registration;
use Specflux\SenroFlux\Connector\OAuth\Ssrf;
use Specflux\SenroFlux\Connector\OAuth\Token;
use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Tests\Connector\ConnectorTestCase;

require_once dirname( __DIR__, 2 ) . '/stubs/oauth.php';

abstract class OAuthTestCase extends ConnectorTestCase {

	protected const T0       = 1800000000;
	protected const CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['senroflux_test_transients']           = array();
		$GLOBALS['senroflux_test_logged_in']            = true;
		$GLOBALS['senroflux_test_remote_get_calls']     = array();
		$GLOBALS['senroflux_test_remote_get_responses'] = array();
		$_GET = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] );
		$this->enableConnector();
		Clock::useFixed( self::T0 );
		Bearer::reset();
		Ssrf::setResolver( static fn (): array => array( '93.184.216.34' ) );
	}

	protected function tearDown(): void {
		Ssrf::setResolver( null );
		Bearer::reset();
		$_GET = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REQUEST_URI'] );
		$GLOBALS['senroflux_test_logged_in'] = true;
		parent::tearDown();
	}

	/** Register a DCR client and return its id. */
	protected function dcr( array $uris = array( self::CALLBACK ), string $ip = '203.0.113.9' ): string {
		$result = Registration::handle(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => $uris,
			),
			$ip
		);
		$this->assertSame( 201, $result['status'] );

		return $result['body']['client_id'];
	}

	/** @return array{0:string,1:string} verifier and S256 challenge */
	protected function pkce(): array {
		$verifier = OAuth::base64url( random_bytes( 32 ) );

		return array( $verifier, OAuth::base64url( hash( 'sha256', $verifier, true ) ) );
	}

	/** Query parameters of a valid authorization request. */
	protected function authParams( string $client_id, string $challenge, array $override = array() ): array {
		return array_merge(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => self::CALLBACK,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'state'                 => 'xyz',
				'resource'              => OAuth::serverUrl(),
				'scope'                 => 'senroflux:read senroflux:write',
			),
			$override
		);
	}

	/** POST the consent form for these params. */
	protected function decide( array $params, string $decision = 'approve', string $grant = 'read', ?string $nonce = null ): Page {
		$action = 'senroflux_oauth_consent_' . substr( OAuth::hash( $params['client_id'] . '|' . $params['code_challenge'] ), 0, 24 );

		return Authorize::run(
			'POST',
			$params + array(
				'decision' => $decision,
				'grant'    => $grant,
				'_wpnonce' => $nonce ?? wp_create_nonce( $action ),
			)
		);
	}

	/** Query values of a redirect Location. */
	protected function query( ?string $location ): array {
		$this->assertNotNull( $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $out );

		return $out;
	}

	/** Run the whole flow to an authorization code. @return array{code:string,verifier:string,client:string} */
	protected function obtainCode( string $grant = 'read', ?string $client = null ): array {
		$client                     ??= $this->dcr();
		list( $verifier, $challenge ) = $this->pkce();
		$page                         = $this->decide( $this->authParams( $client, $challenge ), 'approve', $grant );
		$this->assertSame( 302, $page->status );

		return array(
			'code'     => $this->query( $page->location )['code'],
			'verifier' => $verifier,
			'client'   => $client,
		);
	}

	/** Exchange a code. @return array{status:int,body:array<string,mixed>} */
	protected function exchange( array $flow, array $override = array() ): array {
		return Token::handle(
			array_merge(
				array(
					'grant_type'    => 'authorization_code',
					'code'          => $flow['code'],
					'code_verifier' => $flow['verifier'],
					'client_id'     => $flow['client'],
					'redirect_uri'  => self::CALLBACK,
					'resource'      => OAuth::serverUrl(),
				),
				$override
			)
		);
	}

	/** Full flow to a token response. @return array{status:int,body:array<string,mixed>} */
	protected function tokens( string $grant = 'read' ): array {
		$flow     = $this->obtainCode( $grant );
		$response = $this->exchange( $flow );
		$this->assertSame( 200, $response['status'] );
		$response['client'] = $flow['client'];

		return $response;
	}

	protected function refreshWith( string $token, string $client, array $extra = array() ): array {
		return Token::handle(
			array_merge(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $token,
					'client_id'     => $client,
				),
				$extra
			)
		);
	}

	/** Pretend the request is the MCP route with this bearer token. */
	protected function asMcpRequest( ?string $token, string $path = '/wp-json/senroflux/mcp' ): void {
		Bearer::reset();
		$_SERVER['REQUEST_URI'] = $path;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		if ( null !== $token ) {
			$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		}
	}
}
