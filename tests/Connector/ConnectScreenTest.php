<?php
/**
 * The AI apps tab's connections: creating, listing, re-scoping and revoking connections.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\ClientConfig;
use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\ConnectScreen;
use Specflux\SiteAgent\Connector\Connections;
use Specflux\SiteAgent\Connector\Connector;
use WP_Application_Passwords;

final class ConnectScreenTest extends ConnectorTestCase {

	private const PLAIN = 'abcdefghijklmnopqrstuvwx';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// home_url() and friends, shared with the OAuth tests.
		require_once dirname( __DIR__ ) . '/stubs/oauth.php';
	}

	private function html(): string {
		ob_start();
		try {
			ConnectScreen::render();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}

		return (string) ob_get_clean();
	}

	/** Submit the create form and return the page. */
	private function create( string $client, string $scope = '' ): string {
		$_POST = array(
			'specflux_site_agent_connect_action' => 'create',
			'client'                             => $client,
			'scope'                              => $scope,
		);

		return $this->html();
	}

	/** @return list<array<string,mixed>> */
	private function passwords(): array {
		return array_values( WP_Application_Passwords::get_user_application_passwords( self::USER ) );
	}

	public function test_the_screen_explains_each_missing_requirement(): void {
		$this->assertStringContainsString( 'switched off', $this->html() );

		update_option( Connector::OPTION, '1' );
		$this->assertStringContainsString( 'not installed', $this->html() );

		Connector::set_adapter_version_probe( '0.6.0' );
		$this->assertStringContainsString( 'version 0.6.0', $this->html() );

		$this->enableConnector();
		$GLOBALS['specflux_site_agent_test_multisite'] = true;
		add_filter( 'specflux_site_agent_allow_multisite', static fn (): bool => false );
		$this->assertStringContainsString( 'multisite', $this->html() );
	}

	public function test_an_unavailable_screen_creates_nothing(): void {
		$this->create( ClientConfig::CURSOR );

		$this->assertSame( array(), $this->passwords() );
		$this->assertSame( array(), Connections::forUser( self::USER ) );
	}

	public function test_creating_a_connection_registers_it_read_only_by_default_and_shows_the_config(): void {
		$this->enableConnector();

		$html = $this->create( ClientConfig::CURSOR );

		$items = $this->passwords();
		$this->assertCount( 1, $items );
		$this->assertSame( 'Specflux Site Agent – Cursor', $items[0]['name'] );
		$this->assertSame( array( $items[0]['uuid'] => array( 'scope' => Connection::SCOPE_READ ) ), Connections::forUser( self::USER ) );
		$this->assertStringContainsString( '&quot;mcpServers&quot;', $html );
		$this->assertStringContainsString( ClientConfig::authorization( 'editor', self::PLAIN ), $html );
		$this->assertStringContainsString( 'Copy', $html );
		$this->assertStringContainsString( 'Test connection', $html );
	}

	public function test_read_and_write_is_stored_when_chosen(): void {
		$this->enableConnector();

		$this->create( ClientConfig::VS_CODE, Connection::SCOPE_READ_WRITE );

		$this->assertSame( Connection::SCOPE_READ_WRITE, array_values( Connections::forUser( self::USER ) )[0]['scope'] );
	}

	public function test_the_plain_password_is_not_stored_anywhere_we_write(): void {
		$this->enableConnector();
		$this->create( ClientConfig::CURSOR );

		$stored = (string) wp_json_encode( array( $GLOBALS['specflux_site_agent_test_options'], $GLOBALS['specflux_site_agent_test_user_meta'] ) );
		$this->assertStringNotContainsString( self::PLAIN, $stored );
		$this->assertStringNotContainsString( 'abcd efgh', $stored );
	}

	public function test_an_unknown_client_creates_nothing(): void {
		$this->enableConnector();

		$html = $this->create( 'evil' );

		$this->assertSame( array(), $this->passwords() );
		$this->assertStringContainsString( 'Pick an app', $html );
	}

	public function test_a_bad_nonce_creates_nothing(): void {
		$this->enableConnector();
		$GLOBALS['specflux_site_agent_test_bad_nonce'] = true;

		try {
			$this->create( ClientConfig::CURSOR );
			$this->fail( 'Expected the nonce check to die.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$this->assertSame( array(), $this->passwords() );
	}

	public function test_a_user_without_the_capability_sees_nothing(): void {
		$this->enableConnector();
		$GLOBALS['specflux_site_agent_test_user_caps'] = array();

		$this->assertSame( '', $this->html() );
	}

	public function test_the_oauth_cards_show_the_url_and_the_instructions(): void {
		$this->enableConnector();
		update_option( 'permalink_structure', '/%postname%/' );

		$html = $this->html();

		$this->assertStringContainsString( 'Claude (web, desktop and mobile)', $html );
		$this->assertStringContainsString( 'ChatGPT', $html );
		$this->assertSame( 3, substr_count( $html, 'value="' . esc_attr( ConnectScreen::serverUrl() ) . '"' ), 'one copy field per sign-in card, one in the right rail' );
		$this->assertSame( 2, substr_count( $html, 'Copy URL' ) );
		$this->assertStringContainsString( 'Customize &gt; Connectors and click Add custom connector', $html );
		$this->assertStringContainsString( 'Plugins, open the Add menu, then Add custom MCP server', $html );
		$this->assertStringContainsString( 'id="specflux-site-agent-signed-in"', $html );
		$this->assertStringNotContainsString( 'Coming soon', $html );
	}

	public function test_plain_permalinks_show_the_reason_instead_of_the_instructions(): void {
		$this->enableConnector();
		update_option( 'permalink_structure', '' );

		$html = $this->html();

		$this->assertSame( 2, substr_count( $html, 'it uses plain permalinks' ) );
		$this->assertStringNotContainsString( 'Add custom connector', $html );
		$this->assertStringNotContainsString( 'Coming soon', $html );
	}

	public function test_wordpress_in_its_own_directory_shows_the_reason_instead_of_the_instructions(): void {
		$this->enableConnector();
		update_option( 'permalink_structure', '/%postname%/' );
		$GLOBALS['specflux_site_agent_test_site_url'] = 'http://example.test/wp';

		try {
			$html = $this->html();
		} finally {
			unset( $GLOBALS['specflux_site_agent_test_site_url'] );
		}

		$this->assertSame( 2, substr_count( $html, 'installed in its own directory' ) );
		$this->assertStringNotContainsString( 'Add custom connector', $html );
	}

	public function test_the_list_shows_label_scope_and_last_used(): void {
		$this->enableConnector();
		$this->create( ClientConfig::CURSOR, Connection::SCOPE_READ_WRITE );
		$_POST = array();
		$GLOBALS['specflux_site_agent_test_app_passwords'][ self::USER ][0]['last_used'] = 1_800_003_600;

		$html = $this->html();

		$this->assertStringContainsString( 'Specflux Site Agent – Cursor', $html );
		$this->assertStringContainsString( 'Read and write', $html );
		$this->assertStringNotContainsString( 'Never', $html );
		$this->assertStringContainsString( 'Revoke', $html );
	}

	public function test_scope_change_updates_the_registry(): void {
		$this->enableConnector();
		$this->create( ClientConfig::CURSOR );
		$uuid  = (string) array_key_first( Connections::forUser( self::USER ) );
		$_POST = array(
			'uuid'  => $uuid,
			'scope' => Connection::SCOPE_READ_WRITE,
		);

		ConnectScreen::handleScope();

		$this->assertSame( Connection::SCOPE_READ_WRITE, Connections::forUser( self::USER )[ $uuid ]['scope'] );
	}

	public function test_revoke_deletes_the_application_password_and_the_registry_entry(): void {
		$this->enableConnector();
		$this->create( ClientConfig::CURSOR );
		$uuid  = (string) array_key_first( Connections::forUser( self::USER ) );
		$_POST = array( 'uuid' => $uuid );

		ConnectScreen::handleRevoke();

		$this->assertSame( array(), $this->passwords() );
		$this->assertSame( array(), Connections::forUser( self::USER ) );
	}

	public function test_revoke_ignores_a_connection_that_is_not_yours(): void {
		$this->enableConnector();
		Connections::add( 99, 'someone-elses', Connection::SCOPE_READ );
		$_POST = array( 'uuid' => 'someone-elses' );

		ConnectScreen::handleRevoke();

		$this->assertArrayHasKey( 'someone-elses', Connections::forUser( 99 ) );
	}

	public function test_a_revoke_without_the_capability_dies_and_changes_nothing(): void {
		$this->enableConnector();
		$this->create( ClientConfig::CURSOR );
		$uuid  = (string) array_key_first( Connections::forUser( self::USER ) );
		$_POST = array( 'uuid' => $uuid );
		$GLOBALS['specflux_site_agent_test_user_caps'] = array();

		$this->expectException( \RuntimeException::class );
		try {
			ConnectScreen::handleRevoke();
		} finally {
			$this->assertArrayHasKey( $uuid, Connections::forUser( self::USER ) );
		}
	}

	public function test_test_connection_sends_the_credential_and_reports_the_failure_category(): void {
		$this->enableConnector();
		$this->create( ClientConfig::CURSOR );
		$uuid = (string) array_key_first( Connections::forUser( self::USER ) );
		$GLOBALS['specflux_site_agent_test_remote_post_responses'] = array(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '',
			),
		);
		$_POST = array(
			'specflux_site_agent_connect_action' => 'test',
			'client'                             => ClientConfig::CURSOR,
			'uuid'                               => $uuid,
			'password'                           => self::PLAIN,
		);

		$html = $this->html();

		$this->assertStringContainsString( 'refused the credential', $html );
		$this->assertSame( ClientConfig::authorization( 'editor', self::PLAIN ), $GLOBALS['specflux_site_agent_test_remote_post_calls'][0]['args']['headers']['Authorization'] );
	}

	public function test_test_connection_for_an_unregistered_uuid_makes_no_request(): void {
		$this->enableConnector();
		$_POST = array(
			'specflux_site_agent_connect_action' => 'test',
			'client'                             => ClientConfig::CURSOR,
			'uuid'                               => 'nope',
			'password'                           => self::PLAIN,
		);

		$html = $this->html();

		$this->assertStringContainsString( 'no longer exists', $html );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_remote_post_calls'] );
	}

	public function test_the_apps_are_one_grid_of_six_cards_with_a_dialog_for_the_password_apps(): void {
		$this->enableConnector();
		update_option( 'permalink_structure', '/%postname%/' );

		$html = $this->html();

		$this->assertSame( 6, substr_count( $html, 'specflux-site-agent-app-card"' ) );
		$this->assertStringNotContainsString( '<select class="aui-select" name="scope"', $html, 'access is chosen in a dialog, not a dropdown per card' );
		foreach ( array( 'claude-code', 'cursor', 'vscode', 'other', 'claude-desktop' ) as $client ) {
			$this->assertStringContainsString( 'id="specflux-site-agent-dlg-' . $client . '"', $html );
			$this->assertStringContainsString( 'data-aui-open="specflux-site-agent-dlg-' . $client . '"', $html );
		}
		$this->assertSame( 5, substr_count( $html, 'name="specflux_site_agent_connect_action" value="create"' ) );
		$this->assertSame( 5, substr_count( $html, 'name="scope" value="read" checked' ) );
		$this->assertSame( 5, substr_count( $html, 'name="scope" value="read_write"' ) );
		$this->assertStringContainsString( 'Claude Desktop (local proxy)', $html, 'folded into the Other card' );
	}

	public function test_connections_and_sign_ins_share_one_connected_table_with_a_type_column(): void {
		$this->enableConnector();
		update_option( 'permalink_structure', '/%postname%/' );
		$made = ConnectScreen::create( self::USER, ClientConfig::CURSOR, Connection::SCOPE_READ_WRITE );
		$this->assertIsArray( $made );
		\Specflux\SiteAgent\Connector\OAuth\Store::saveClient(
			array(
				'id'            => 'claudeclient01',
				'name'          => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/cb' ),
				'created'       => 1,
			)
		);
		\Specflux\SiteAgent\Connector\OAuth\Store::createGrant( self::USER, 'claudeclient01', 'specflux:read', 'http://example.test/x', 'h' );

		$html = $this->html();

		$this->assertSame( 1, substr_count( $html, '>Connected</h2>' ) );
		$this->assertStringContainsString( '>Password</span>', $html );
		$this->assertStringContainsString( '>Sign-in</span>', $html );
		$this->assertStringContainsString( 'specflux_site_agent_connection_revoke', $html );
		$this->assertStringContainsString( 'specflux_site_agent_oauth_revoke', $html );
		$this->assertMatchesRegularExpression( '#<details class="specflux-site-agent-disclosure"><summary[^>]*><div><h2 class="aui-card-t">Advanced: registered apps#', $html, 'registered apps are collapsed' );
		$this->assertDoesNotMatchRegularExpression( '#<details class="specflux-site-agent-disclosure" open#', $html );
	}
}
