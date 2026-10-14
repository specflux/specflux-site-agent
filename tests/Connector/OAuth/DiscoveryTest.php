<?php
/**
 * Discovery documents and the WWW-Authenticate challenge.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector\OAuth;

use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\OAuth\Bearer;
use Specflux\SiteAgent\Connector\OAuth\Discovery;
use Specflux\SiteAgent\Connector\OAuth\OAuth;
use SpecfluxSiteAgent_Test_Header_Response;
use SpecfluxSiteAgent_Test_Route_Request;

require_once __DIR__ . '/OAuthTestCase.php';

final class DiscoveryTest extends OAuthTestCase {

	private const MCP = 'http://example.test/wp-json/specflux-site-agent/mcp';

	protected function setUp(): void {
		parent::setUp();
		update_option( 'permalink_structure', '/%postname%/' );
	}

	public function test_protected_resource_names_the_exact_server_url(): void {
		$doc = Discovery::documentFor( '/.well-known/oauth-protected-resource' );

		$this->assertSame( self::MCP, $doc['resource'] );
		$this->assertSame( OAuth::serverUrl(), $doc['resource'] );
		$this->assertSame( array( 'http://example.test' ), $doc['authorization_servers'] );
		$this->assertSame( array( 'specflux:read', 'specflux:write' ), $doc['scopes_supported'] );
		$this->assertSame( array( 'header' ), $doc['bearer_methods_supported'] );
	}

	public function test_path_suffixed_form_is_served_and_unknown_paths_are_not(): void {
		$this->assertSame( self::MCP, Discovery::documentFor( '/.well-known/oauth-protected-resource/wp-json/specflux-site-agent/mcp' )['resource'] );
		$this->assertNull( Discovery::documentFor( '/.well-known/oauth-protected-resource/wp-json/other/route' ) );
		$this->assertNull( Discovery::documentFor( '/.well-known/something-else' ) );
		$this->assertNull( Discovery::documentFor( '/wp-json/specflux-site-agent/mcp' ) );
	}

	public function test_authorization_server_metadata_advertises_only_what_is_implemented(): void {
		$doc = Discovery::documentFor( '/.well-known/oauth-authorization-server' );

		$this->assertSame( 'http://example.test', $doc['issuer'] );
		$this->assertSame( array( 'S256' ), $doc['code_challenge_methods_supported'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $doc['grant_types_supported'] );
		$this->assertSame( array( 'code' ), $doc['response_types_supported'] );
		$this->assertSame( array( 'none' ), $doc['token_endpoint_auth_methods_supported'] );
		$this->assertTrue( $doc['client_id_metadata_document_supported'] );
		$this->assertSame( 'http://example.test/wp-json/specflux-site-agent/oauth/token', $doc['token_endpoint'] );
		$this->assertSame( 'http://example.test/wp-json/specflux-site-agent/oauth/register', $doc['registration_endpoint'] );
		$this->assertStringContainsString( 'admin-post.php?action=specflux_site_agent_oauth_authorize', $doc['authorization_endpoint'] );
		$this->assertSame( OAuth::SCOPE_READ, $doc['scopes_supported'][0] );
		$this->assertArrayNotHasKey( 'revocation_endpoint', $doc );
	}

	public function test_challenge_without_credential_points_at_the_resource_metadata(): void {
		$response = new SpecfluxSiteAgent_Test_Header_Response( 401 );
		Bearer::challenge( $response, null, new SpecfluxSiteAgent_Test_Route_Request( '/specflux-site-agent/mcp' ) );

		$header = $response->headers['WWW-Authenticate'];
		$this->assertStringStartsWith( 'Bearer ', $header );
		$this->assertStringContainsString( 'resource_metadata="http://example.test/.well-known/oauth-protected-resource/wp-json/specflux-site-agent/mcp"', $header );
		$this->assertStringNotContainsString( 'error=', $header );
	}

	public function test_challenge_offers_both_scopes_so_the_consent_screen_can_offer_write(): void {
		// Clients request the challenge's scope verbatim; read-only here would
		// leave the consent screen with no "Read and write" choice.
		$response = new SpecfluxSiteAgent_Test_Header_Response( 401 );
		Bearer::challenge( $response, null, new SpecfluxSiteAgent_Test_Route_Request( '/specflux-site-agent/mcp' ) );

		$this->assertStringContainsString( 'scope="specflux:read specflux:write"', $response->headers['WWW-Authenticate'] );
	}

	public function test_challenge_for_a_bad_token_says_invalid_token(): void {
		$this->asMcpRequest( str_repeat( 'a', 64 ) );
		$this->assertSame( 0, Bearer::authenticate( 0 ) );

		$response = new SpecfluxSiteAgent_Test_Header_Response( 401 );
		Bearer::challenge( $response, null, new SpecfluxSiteAgent_Test_Route_Request( '/specflux-site-agent/mcp' ) );

		$this->assertStringContainsString( 'error="invalid_token"', $response->headers['WWW-Authenticate'] );
		$this->assertStringContainsString( 'resource_metadata=', $response->headers['WWW-Authenticate'] );
	}

	public function test_challenge_is_scoped_to_the_mcp_route_only(): void {
		$response = new SpecfluxSiteAgent_Test_Header_Response( 401 );
		Bearer::challenge( $response, null, new SpecfluxSiteAgent_Test_Route_Request( '/wp/v2/users' ) );

		$this->assertSame( array(), $response->headers );
	}

	public function test_403_for_an_authenticated_token_is_insufficient_scope(): void {
		$tokens = $this->tokens();
		$this->asMcpRequest( $tokens['body']['access_token'] );
		Bearer::authenticate( 0 );

		$response = new SpecfluxSiteAgent_Test_Header_Response( 403 );
		Bearer::challenge( $response, null, new SpecfluxSiteAgent_Test_Route_Request( '/specflux-site-agent/mcp' ) );

		$this->assertStringContainsString( 'error="insufficient_scope"', $response->headers['WWW-Authenticate'] );

		$other = new SpecfluxSiteAgent_Test_Header_Response( 403 );
		Bearer::reset();
		Bearer::challenge( $other, null, new SpecfluxSiteAgent_Test_Route_Request( '/specflux-site-agent/mcp' ) );
		$this->assertSame( array(), $other->headers, 'A 403 with no OAuth token is not a scope problem.' );
	}

	public function test_subdirectory_or_plain_permalinks_fall_back_to_rest_discovery(): void {
		update_option( 'permalink_structure', '' );

		$this->assertFalse( Discovery::rootServed() );
		$this->assertSame( 'http://example.test/wp-json/specflux-site-agent/oauth/protected-resource', Discovery::metadataUrl() );
		$this->assertSame( 'http://example.test/wp-json/specflux-site-agent/oauth', Discovery::authorizationServer()['issuer'] );
		$this->assertSame( 'http://example.test/wp-json/specflux-site-agent/mcp', Discovery::protectedResource()['resource'] );
	}
}
