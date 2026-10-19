<?php
/**
 * OAuth on multisite (0.4 M1): off on subsites of a subdirectory network,
 * unchanged on the main site and on subdomain subsites.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector\OAuth;

use Specflux\SiteAgent\Connector\ConnectScreen;
use Specflux\SiteAgent\Connector\OAuth\Authorize;
use Specflux\SiteAgent\Connector\OAuth\Bearer;
use Specflux\SiteAgent\Connector\OAuth\Discovery;
use Specflux\SiteAgent\Connector\OAuth\OAuth;
use Specflux\SiteAgent\Connector\OAuth\Registration;
use Specflux\SiteAgent\Connector\OAuth\Token;

require_once __DIR__ . '/OAuthTestCase.php';

final class MultisiteOAuthTest extends OAuthTestCase {

	protected function setUp(): void {
		parent::setUp();
		specflux_site_agent_test_network( array( 1, 2 ) );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( \Specflux\SiteAgent\Connector\Connector::OPTION, '1' );
	}

	protected function tearDown(): void {
		while ( ( $GLOBALS['specflux_site_agent_test_network']['stack'] ?? array() ) !== array() ) {
			restore_current_blog();
		}
		specflux_site_agent_test_network_reset();
		parent::tearDown();
	}

	private function onSubsite(): void {
		switch_to_blog( 2 );
		update_option( \Specflux\SiteAgent\Connector\Connector::OPTION, '1' );
		update_option( 'permalink_structure', '/%postname%/' );
	}

	private function screen(): string {
		ob_start();
		ConnectScreen::render();

		return (string) ob_get_clean();
	}

	public function test_a_subdirectory_subsite_sends_no_bearer_challenge_for_a_document_that_404s(): void {
		$GLOBALS['specflux_site_agent_test_subdomain_install'] = false;
		$this->onSubsite();
		$request = new \SpecfluxSiteAgent_Test_Route_Request( '/specflux-site-agent/mcp' );

		$response = new \SpecfluxSiteAgent_Test_Header_Response( 401 );
		Bearer::challenge( $response, null, $request );
		$this->assertArrayNotHasKey( 'WWW-Authenticate', $response->headers );
	}

	public function test_a_subdirectory_subsite_has_no_oauth_on_any_endpoint(): void {
		$GLOBALS['specflux_site_agent_test_subdomain_install'] = false;
		$this->onSubsite();

		$this->assertFalse( OAuth::availableOnThisSite() );
		$this->assertFalse( OAuth::serving() );

		$dcr = Registration::handle(
			array(
				'client_name'   => 'Claude',
				'redirect_uris' => array( self::CALLBACK ),
			),
			'203.0.113.9'
		);
		$this->assertSame( 404, $dcr['status'] );
		$this->assertSame( 'not_found', $dcr['body']['error'] );

		$token = Token::handle(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'x',
			)
		);
		$this->assertSame( 404, $token['status'] );

		$page = Authorize::run( 'GET', array( 'client_id' => 'x' ) );
		$this->assertSame( 404, $page->status );

		$this->assertInstanceOf( \WP_Error::class, Discovery::restResource() );
		$this->assertInstanceOf( \WP_Error::class, Discovery::restServer() );

		$_SERVER['REQUEST_URI'] = '/sub/.well-known/oauth-protected-resource';
		Discovery::maybeServe(); // Returns (does not exit) because it refuses to serve.
		$this->assertTrue( true );
	}

	public function test_a_subdirectory_subsite_shows_the_reason_on_the_connect_cards_and_keeps_app_passwords(): void {
		$GLOBALS['specflux_site_agent_test_subdomain_install'] = false;
		$this->onSubsite();

		$this->assertStringContainsString( 'subdirectory multisite network', (string) ConnectScreen::oauthBlocker() );
		$html = $this->screen();

		$this->assertSame( 2, substr_count( $html, 'subdirectory multisite network' ) );
		$this->assertStringNotContainsString( 'Add custom connector', $html );
		$this->assertStringContainsString( 'Claude Code', $html, 'application-password clients stay' );
	}

	public function test_the_main_site_of_a_subdirectory_network_keeps_oauth(): void {
		$GLOBALS['specflux_site_agent_test_subdomain_install'] = false;

		$this->assertTrue( OAuth::availableOnThisSite() );
		$this->assertTrue( OAuth::serving() );
		$this->assertSame(
			201,
			Registration::handle(
				array(
					'client_name'   => 'Claude',
					'redirect_uris' => array( self::CALLBACK ),
				),
				'203.0.113.9'
			)['status']
		);
		$this->assertNull( ConnectScreen::oauthBlocker() );
	}

	public function test_a_subdomain_subsite_keeps_oauth(): void {
		$this->onSubsite();

		$this->assertTrue( OAuth::availableOnThisSite() );
		$this->assertSame(
			201,
			Registration::handle(
				array(
					'client_name'   => 'Claude',
					'redirect_uris' => array( self::CALLBACK ),
				),
				'203.0.113.8'
			)['status']
		);
		$this->assertNull( ConnectScreen::oauthBlocker() );
	}
}
