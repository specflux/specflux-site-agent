<?php
/**
 * The "From AI apps" tab and its approve / deny handlers.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\Approvals;
use Specflux\SenroFlux\Connector\ApprovalsScreen;
use Specflux\SenroFlux\Run\Clock;

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
		return Approvals::park( self::USER, self::UUID, 'senroflux/write-thing', $args );
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
		$this->assertStringContainsString( 'tab=ai-apps', $GLOBALS['senroflux_test_redirect'] );
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
		$GLOBALS['senroflux_test_bad_nonce'] = true;

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
		$GLOBALS['senroflux_test_user_caps'] = array();

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
		$hash                            = $this->park( array( 'title' => '<script>alert(1)</script>' ) );
		$GLOBALS['senroflux_test_users'] = array( self::USER => (object) array( 'user_login' => 'owner' ) );

		ob_start();
		ApprovalsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'senroflux/write-thing', $html );
		$this->assertStringContainsString( substr( self::UUID, 0, 8 ), $html );
		$this->assertStringContainsString( 'owner', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'value="senroflux_connector_approve"', $html );
		$this->assertStringContainsString( 'value="senroflux_connector_deny"', $html );
		$this->assertStringContainsString( 'value="' . $hash . '"', $html );
	}

	public function test_an_empty_tab_says_so(): void {
		ob_start();
		ApprovalsScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Nothing is waiting.', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_the_runs_screen_shows_the_tab_when_asked_and_not_otherwise(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );
		add_filter( 'senroflux_runs_capability', static fn (): string => 'manage_options' );
		$this->park();
		update_option( 'senroflux_connector_enabled', '1' );

		$_GET = array( 'tab' => 'ai-apps' );
		ob_start();
		( new \Specflux\SenroFlux\Admin\RunsScreen() )->render();
		$tab = (string) ob_get_clean();

		$_GET = array();
		ob_start();
		( new \Specflux\SenroFlux\Admin\RunsScreen() )->render();
		$runs = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'senroflux-runs-root', $tab );
		$this->assertStringContainsString( 'senroflux/write-thing', $tab );
		$this->assertStringNotContainsString( 'senroflux/write-thing', $runs );
		$this->assertStringContainsString( 'senroflux-runs-root', $runs );
		$this->assertStringContainsString( 'From AI apps (1)', $runs );
	}
}
