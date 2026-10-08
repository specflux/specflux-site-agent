<?php
/**
 * The authorization endpoint and consent screen.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector\OAuth;

use Specflux\SenroFlux\Connector\OAuth\Authorize;
use Specflux\SenroFlux\Connector\OAuth\OAuth;
use Specflux\SenroFlux\Connector\OAuth\Store;

require_once __DIR__ . '/OAuthTestCase.php';

final class AuthorizeTest extends OAuthTestCase {

	private string $client;
	private string $challenge;

	protected function setUp(): void {
		parent::setUp();
		$this->client              = $this->dcr();
		list( , $this->challenge ) = $this->pkce();
	}

	private function get( array $override = array() ): \Specflux\SenroFlux\Connector\OAuth\Page {
		return Authorize::run( 'GET', $this->authParams( $this->client, $this->challenge, $override ) );
	}

	private function assertRejectedWithoutRedirect( \Specflux\SenroFlux\Connector\OAuth\Page $page, int $status = 400 ): void {
		$this->assertNull( $page->location, 'Must not redirect.' );
		$this->assertSame( $status, $page->status );
		$this->assertStringNotContainsString( 'name="decision"', $page->body, 'No consent form.' );
	}

	public function test_valid_request_renders_the_consent_screen(): void {
		$page = $this->get();

		$this->assertSame( 200, $page->status );
		$this->assertNull( $page->location );
		$this->assertStringContainsString( 'Connect Claude?', $page->body );
		$this->assertStringContainsString( '<strong>claude.ai</strong>', $page->body, 'The redirect host is shown.' );
		$this->assertStringContainsString( 'value="read" checked', $page->body, 'Read only is the default.' );
		$this->assertStringContainsString( 'value="write"', $page->body );
		$this->assertStringContainsString( 'name="_wpnonce"', $page->body );
	}

	public function test_read_only_request_offers_no_write_choice(): void {
		$page = $this->get( array( 'scope' => 'senroflux:read' ) );

		$this->assertStringNotContainsString( 'value="write"', $page->body );
	}

	public function test_consent_page_forbids_framing(): void {
		$headers = $this->get()->headers;

		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertStringContainsString( "frame-ancestors 'none'", $headers['Content-Security-Policy'] );
		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'] );
	}

	public function test_client_name_is_escaped(): void {
		Store::saveClient(
			array(
				'id'            => 'snf_xss_client_1',
				'name'          => '<img src=x onerror=alert(1)>',
				'redirect_uris' => array( self::CALLBACK ),
				'created'       => self::T0,
			)
		);
		$page = Authorize::run( 'GET', $this->authParams( 'snf_xss_client_1', $this->challenge ) );

		$this->assertStringNotContainsString( '<img src=x', $page->body );
		$this->assertStringContainsString( '&lt;img', $page->body );
	}

	public function test_logged_out_visitor_goes_to_wp_login_and_back_never_to_the_client(): void {
		$GLOBALS['senroflux_test_logged_in'] = false;
		$page                                = $this->get();

		$this->assertSame( 302, $page->status );
		$this->assertStringStartsWith( 'http://example.test/wp-login.php?redirect_to=', (string) $page->location );
		$this->assertStringNotContainsString( 'claude.ai', explode( 'redirect_to=', (string) $page->location )[0] );
		$this->assertStringContainsString( rawurlencode( 'action=senroflux_oauth_authorize' ), (string) $page->location );
	}

	public function test_logged_out_post_is_refused(): void {
		$GLOBALS['senroflux_test_logged_in'] = false;

		$this->assertRejectedWithoutRedirect( $this->decide( $this->authParams( $this->client, $this->challenge ) ), 403 );
	}

	public function test_user_without_the_connector_capability_is_refused(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => false );

		$this->assertRejectedWithoutRedirect( $this->get(), 403 );
		$post = $this->decide( $this->authParams( $this->client, $this->challenge ) );
		$this->assertRejectedWithoutRedirect( $post, 403 );
		$this->assertNull( Store::grantOfUsedCode( 'x' ) );
	}

	public function test_unknown_client_is_shown_on_page(): void {
		$page = Authorize::run( 'GET', $this->authParams( 'snf_doesnotexist', $this->challenge ) );

		$this->assertRejectedWithoutRedirect( $page );
	}

	public function test_mismatched_redirect_uri_is_shown_on_page_and_never_redirected(): void {
		foreach ( array( 'https://evil.example/cb', self::CALLBACK . '/', self::CALLBACK . '?x=1', 'HTTPS://CLAUDE.AI/api/mcp/auth_callback', '' ) as $uri ) {
			$page = $this->get( array( 'redirect_uri' => $uri ) );

			$this->assertRejectedWithoutRedirect( $page );
		}
		$page = $this->get(
			array(
				'redirect_uri' => 'https://evil.example/cb',
				'state'        => 's',
			)
		);
		$this->assertStringNotContainsString( 'evil.example', $page->body );
	}

	public function test_missing_or_plain_pkce_is_rejected_by_error_redirect(): void {
		$cases = array(
			'plain method'   => array( 'code_challenge_method' => 'plain' ),
			'missing method' => array( 'code_challenge_method' => '' ),
			'missing chall.' => array( 'code_challenge' => '' ),
			'short chall.'   => array( 'code_challenge' => 'abc' ),
			'S256 lowercase' => array( 'code_challenge_method' => 's256' ),
		);
		foreach ( $cases as $label => $override ) {
			$page = $this->get( $override );

			$this->assertSame( 302, $page->status, $label );
			$q = $this->query( $page->location );
			$this->assertStringStartsWith( self::CALLBACK, (string) $page->location, $label );
			$this->assertSame( 'invalid_request', $q['error'], $label );
			$this->assertSame( 'xyz', $q['state'], $label );
			$this->assertArrayNotHasKey( 'code', $q, $label );
		}
	}

	public function test_mismatched_resource_is_rejected(): void {
		foreach ( array( 'http://example.test/wp-json/senroflux/mcp/', 'https://other.example/mcp', 'http://example.test/wp-json/wp/v2' ) as $resource ) {
			$q = $this->query( $this->get( array( 'resource' => $resource ) )->location );

			$this->assertSame( 'invalid_target', $q['error'], $resource );
		}
	}

	public function test_missing_resource_defaults_to_the_only_server(): void {
		$params = $this->authParams( $this->client, $this->challenge );
		unset( $params['resource'] );
		$flow = $this->decide( $params );

		$this->assertArrayHasKey( 'code', $this->query( $flow->location ) );
	}

	public function test_response_type_and_scope_are_checked(): void {
		$this->assertSame( 'unsupported_response_type', $this->query( $this->get( array( 'response_type' => 'token' ) )->location )['error'] );
		$this->assertSame( 'invalid_scope', $this->query( $this->get( array( 'scope' => 'admin' ) )->location )['error'] );
	}

	public function test_consent_post_without_a_valid_nonce_does_nothing(): void {
		$params = $this->authParams( $this->client, $this->challenge );
		foreach ( array( '', 'forged', null ) as $nonce ) {
			$page = null === $nonce
				? Authorize::run(
					'POST',
					$params + array(
						'decision' => 'approve',
						'grant'    => 'write',
					)
				)
				: $this->decide( $params, 'approve', 'write', $nonce );

			$this->assertRejectedWithoutRedirect( $page, 403 );
		}
		$bound = wp_create_nonce( 'senroflux_oauth_consent_' . substr( OAuth::hash( 'other|' . $this->challenge ), 0, 24 ) );
		$this->assertRejectedWithoutRedirect( $this->decide( $params, 'approve', 'read', $bound ), 403, 'A nonce for another request does not work.' );
	}

	public function test_approve_redirects_with_a_code_and_state(): void {
		$page = $this->decide( $this->authParams( $this->client, $this->challenge ), 'approve', 'read' );
		$q    = $this->query( $page->location );

		$this->assertStringStartsWith( self::CALLBACK . '?', (string) $page->location );
		$this->assertSame( 'xyz', $q['state'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $q['code'] );
		$this->assertSame( 'no-store', $page->headers['Cache-Control'] );
	}

	public function test_deny_redirects_with_access_denied_and_no_code(): void {
		$q = $this->query( $this->decide( $this->authParams( $this->client, $this->challenge ), 'deny' )->location );

		$this->assertSame( 'access_denied', $q['error'] );
		$this->assertArrayNotHasKey( 'code', $q );
	}

	public function test_write_is_granted_only_when_chosen_and_requested(): void {
		$read = $this->exchange( $this->obtainCode( 'read' ) );
		$this->assertSame( 'senroflux:read', $read['body']['scope'] );

		$write = $this->exchange( $this->obtainCode( 'write' ) );
		$this->assertSame( 'senroflux:read senroflux:write', $write['body']['scope'] );

		list( $verifier, $challenge ) = $this->pkce();
		$params                       = $this->authParams( $this->client, $challenge, array( 'scope' => 'senroflux:read' ) );
		$code                         = $this->query( $this->decide( $params, 'approve', 'write' )->location )['code'];
		$exchange                     = $this->exchange(
			array(
				'code'     => $code,
				'verifier' => $verifier,
				'client'   => $this->client,
			)
		);
		$this->assertSame( 'senroflux:read', $exchange['body']['scope'], 'A forged grant=write cannot exceed what the app asked for.' );
	}

	public function test_state_is_returned_url_encoded(): void {
		$q = $this->query( $this->decide( $this->authParams( $this->client, $this->challenge, array( 'state' => 'a b&c=d' ) ) )->location );

		$this->assertSame( 'a b&c=d', $q['state'] );
	}

	public function test_loopback_redirect_shows_a_warning(): void {
		$id   = $this->dcr( array( 'http://127.0.0.1:7777/cb' ) );
		$page = Authorize::run( 'GET', $this->authParams( $id, $this->challenge, array( 'redirect_uri' => 'http://127.0.0.1:7777/cb' ) ) );

		$this->assertStringContainsString( '127.0.0.1:7777', $page->body );
		$this->assertStringContainsString( 'runs on the computer you are using', $page->body );
	}
}
