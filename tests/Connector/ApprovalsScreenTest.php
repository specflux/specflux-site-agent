<?php
/**
 * The waiting-calls section of the AI apps tab and its approve / deny handlers.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\Approvals;
use Specflux\SiteAgent\Connector\ApprovalsScreen;
use Specflux\SiteAgent\Run\Clock;

final class ApprovalsScreenTest extends ConnectorTestCase {

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		Clock::useFixed( 1_800_000_000 );
	}

	private function park( array $args = array( 'title' => 'Hi' ) ): string {
		return Approvals::park( self::USER, self::UUID, 'specflux/write-thing', $args );
	}

	private function post( string $hash ): void {
		$_POST = array( 'hash' => $hash );
	}

	public function test_approve_marks_the_call_approved(): void {
		$hash = $this->park();
		$this->post( $hash );

		ApprovalsScreen::handleApprove();

		$this->assertSame( array(), Approvals::pending() );
		$this->assertTrue( Approvals::consume( $hash ) );
		$this->assertStringContainsString( 'page=specflux-site-agent-apps', $GLOBALS['specflux_site_agent_test_redirect'] );
		$this->assertStringContainsString( '#specflux-site-agent-waiting', $GLOBALS['specflux_site_agent_test_redirect'] );
	}

	public function test_deny_removes_the_call(): void {
		$hash = $this->park();
		$this->post( $hash );

		ApprovalsScreen::handleDeny();

		$this->assertSame( array(), Approvals::pending() );
		$this->assertFalse( Approvals::consume( $hash ) );
	}

	public function test_a_bad_nonce_changes_nothing(): void {
		$hash = $this->park();
		$this->post( $hash );
		$GLOBALS['specflux_site_agent_test_bad_nonce'] = true;

		foreach ( array( 'handleApprove', 'handleDeny' ) as $handler ) {
			try {
				ApprovalsScreen::$handler();
				$this->fail( $handler . ' accepted a bad nonce' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'expired', $e->getMessage() );
			}
		}

		$this->assertArrayHasKey( $hash, Approvals::pending() );
	}

	public function test_without_the_capability_neither_handler_acts(): void {
		$hash = $this->park();
		$this->post( $hash );
		$GLOBALS['specflux_site_agent_test_user_caps'] = array();

		foreach ( array( 'handleApprove', 'handleDeny' ) as $handler ) {
			try {
				ApprovalsScreen::$handler();
				$this->fail( $handler . ' acted without the capability' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
			}
		}

		$this->assertArrayHasKey( $hash, Approvals::pending() );
	}

	public function test_a_malformed_hash_is_ignored(): void {
		$hash = $this->park();
		$this->post( 'not-a-hash' );

		ApprovalsScreen::handleApprove();

		$this->assertArrayHasKey( $hash, Approvals::pending() );
	}

	public function test_the_tab_lists_pending_calls_escaped_with_both_buttons(): void {
		$hash                                      = $this->park( array( 'title' => '<script>alert(1)</script>' ) );
		$GLOBALS['specflux_site_agent_test_users'] = array( self::USER => (object) array( 'user_login' => 'owner' ) );

		ob_start();
		ApprovalsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'specflux/write-thing', $html );
		$this->assertStringContainsString( substr( self::UUID, 0, 8 ), $html );
		$this->assertStringContainsString( 'owner', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'value="specflux_site_agent_connector_approve"', $html );
		$this->assertStringContainsString( 'value="specflux_site_agent_connector_deny"', $html );
		$this->assertStringContainsString( 'value="' . $hash . '"', $html );
	}

	public function test_an_empty_tab_says_so(): void {
		ob_start();
		ApprovalsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Nothing is waiting.', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_the_ai_apps_tab_shows_the_waiting_calls_and_counts_them_in_its_tab(): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => true );
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
		$this->park();
		update_option( 'specflux_site_agent_connector_enabled', '1' );

		ob_start();
		\Specflux\SiteAgent\Connector\AppsScreen::render();
		$apps = (string) ob_get_clean();

		ob_start();
		( new \Specflux\SiteAgent\Admin\RunsScreen() )->render();
		$runs = (string) ob_get_clean();

		$this->assertStringContainsString( 'specflux/write-thing', $apps );
		$this->assertStringContainsString( 'id="specflux-site-agent-waiting"', $apps );
		$this->assertStringContainsString( '1 call waiting for approval', $apps, 'the AI apps tab carries the count' );
		$this->assertStringContainsString( '1 call waiting for approval', $runs, 'and so does the header on the Runs screen' );
		$this->assertStringNotContainsString( 'specflux/write-thing', $runs );
		$this->assertStringContainsString( 'specflux-site-agent-runs-root', $runs );
	}
}
