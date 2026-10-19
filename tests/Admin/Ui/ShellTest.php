<?php
/**
 * The app shell: menu, header, status pill and the old-URL redirects.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Admin\Ui;

use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Connector\Approvals;
use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Tests\Connector\ConnectorTestCase;

final class ShellTest extends ConnectorTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['specflux_site_agent_test_menus'] = array();
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['pagenow'] );
		parent::tearDown();
	}

	private function redirecting(): Shell {
		return new class() extends Shell {
			public ?string $to = null;

			protected function redirectAndExit( string $url ): void {
				$this->to = $url;
			}
		};
	}

	private function redirectFor( string $pagenow, array $get ): ?string {
		$GLOBALS['pagenow'] = $pagenow;
		$_GET               = $get;
		$shell              = $this->redirecting();
		$shell->redirectLegacy();

		return $shell->to;
	}

	public function test_urls_are_admin_php_pages_with_args_and_a_section(): void {
		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=specflux-site-agent-runs', Shell::url( 'runs' ) );
		$this->assertStringContainsString( 'page=specflux-site-agent-apps', Shell::url( 'apps' ) );
		$this->assertStringContainsString( 'page=specflux-site-agent-settings', Shell::url( 'settings' ) );
		$this->assertStringContainsString( 'run_id=7', Shell::url( 'runs', array( 'run_id' => 7 ) ) );
		$this->assertStringEndsWith( '#specflux-site-agent-waiting', Shell::url( 'apps', array(), Shell::SECTION_WAITING ) );
	}

	public function test_the_old_connect_url_lands_on_the_ai_apps_tab_with_every_argument(): void {
		$to = $this->redirectFor(
			'admin.php',
			array(
				'page'       => 'specflux-site-agent-connect',
				'connection' => 'abc',
				'x'          => 'y',
			)
		);

		$this->assertStringContainsString( 'page=specflux-site-agent-apps', (string) $to );
		$this->assertStringContainsString( 'connection=abc', (string) $to );
		$this->assertStringContainsString( 'x=y', (string) $to );
	}

	public function test_the_old_signed_in_apps_url_lands_on_its_section(): void {
		$to = $this->redirectFor( 'admin.php', array( 'page' => 'specflux-site-agent-signed-in-apps' ) );

		$this->assertStringContainsString( 'page=specflux-site-agent-apps', (string) $to );
		$this->assertStringEndsWith( '#specflux-site-agent-signed-in', (string) $to );
	}

	public function test_the_old_runs_approvals_tab_lands_on_the_waiting_section_and_drops_only_the_tab(): void {
		$to = $this->redirectFor(
			'admin.php',
			array(
				'page'  => 'specflux-site-agent-runs',
				'tab'   => 'ai-apps',
				'other' => '1',
			)
		);

		$this->assertStringContainsString( 'page=specflux-site-agent-apps', (string) $to );
		$this->assertStringContainsString( 'other=1', (string) $to );
		$this->assertStringNotContainsString( 'tab=', (string) $to );
		$this->assertStringEndsWith( '#specflux-site-agent-waiting', (string) $to );
	}

	public function test_runs_deep_links_are_not_redirected(): void {
		$this->assertNull(
			$this->redirectFor(
				'admin.php',
				array(
					'page'   => 'specflux-site-agent-runs',
					'run_id' => '9',
				)
			)
		);
		$this->assertNull( $this->redirectFor( 'admin.php', array( 'page' => 'specflux-site-agent-settings' ) ) );
		$this->assertNull( $this->redirectFor( 'admin.php', array( 'page' => 'something-else' ) ) );
	}

	public function test_the_pre_0_3_tools_url_redirects_to_runs_keeping_every_scalar_argument(): void {
		$to = $this->redirectFor(
			'tools.php',
			array(
				'page'    => 'specflux-site-agent-runs',
				'run_id'  => '7',
				'goal'    => 'Publish the spring page',
				'unknown' => 'x',
				'run'     => array( '1' ),
			)
		);

		$this->assertStringContainsString( 'admin.php', (string) $to );
		$this->assertStringContainsString( 'page=specflux-site-agent-runs', (string) $to );
		$this->assertStringContainsString( 'run_id=7', (string) $to );
		$this->assertStringContainsString( 'goal=', (string) $to );
		$this->assertStringContainsString( 'unknown=x', (string) $to );
		$this->assertStringNotContainsString( 'run%5B', (string) $to, 'a nested array is not carried' );
		$this->assertNull( $this->redirectFor( 'tools.php', array( 'page' => 'something-else' ) ) );
	}

	public function test_only_the_three_screens_get_the_kit(): void {
		$this->assertTrue( Shell::isOwnScreen( 'toplevel_page_specflux-site-agent-runs' ) );
		$this->assertTrue( Shell::isOwnScreen( 'site-agent_page_specflux-site-agent-apps' ) );
		$this->assertTrue( Shell::isOwnScreen( 'site-agent_page_specflux-site-agent-settings' ) );
		$this->assertFalse( Shell::isOwnScreen( 'index.php' ) );
		$this->assertFalse( Shell::isOwnScreen( 'toplevel_page_something-else' ) );
		$this->assertFalse( Shell::isOwnScreen( 'tools_page_belayline-packs' ) );
	}

	public function test_the_header_names_the_app_marks_the_tab_and_shows_status_and_version(): void {
		update_option( Connector::OPTION, '1' );
		Connector::set_adapter_version_probe( '0.7.0' );
		$GLOBALS['specflux_site_agent_test_app_passwords'][ self::USER ] = array();

		$html = Shell::header( 'settings' );

		$this->assertStringContainsString( '<h1>Site Agent</h1>', $html );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*page=specflux-site-agent-settings" aria-current="page">Settings/', $html );
		$this->assertDoesNotMatchRegularExpression( '/page=specflux-site-agent-runs" aria-current/', $html );
		$this->assertStringContainsString( 'AI apps', $html );
		$this->assertMatchesRegularExpression( '/<span class="aui-chip">v\d+\.\d+\.\d+/', $html );
		$this->assertStringNotContainsString( 'data-header-background', $html, 'the background-run pill is for Runs only' );
	}

	public function test_the_runs_header_adds_the_background_run_availability(): void {
		$html = Shell::header( 'runs' );

		$this->assertStringContainsString( 'data-header-background', $html );
	}

	public function test_the_ai_apps_tab_counts_calls_waiting_for_approval(): void {
		$this->assertStringNotContainsString( 'aui-count', Shell::header( 'runs' ) );

		Approvals::park( self::USER, self::UUID, 'specflux/write-thing', array( 'title' => 'Hi' ) );

		$this->assertMatchesRegularExpression( '/AI apps <span class="aui-count aui-count--warn">1<span class="aui-sr"> 1 call waiting for approval/', Shell::header( 'runs' ) );
	}

	public function test_the_status_pill_says_what_the_connector_is_doing(): void {
		$this->assertSame( 'AI apps off', Shell::status()['text'] );
		$this->assertSame( '', Shell::status()['tone'] );

		update_option( Connector::OPTION, '1' );
		$this->assertSame( 'warn', Shell::status()['tone'], 'on but the MCP Adapter is missing' );

		Connector::set_adapter_version_probe( '0.7.0' );
		$this->assertSame( 'No apps connected', Shell::status()['text'] );

		\Specflux\SiteAgent\Connector\Connections::add( self::USER, self::UUID );
		$this->assertSame( '1 app connected', Shell::status()['text'] );
		$this->assertSame( 'ok', Shell::status()['tone'] );
	}

	public function test_the_menu_is_one_top_level_entry_with_a_submenu_per_tab(): void {
		$calls = array();
		// The suite's add_menu_page / add_submenu_page shims record nothing, so exercise the pure parts.
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', Shell::menuIcon() );
		$svg = (string) base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the menu icon under test.
			substr( Shell::menuIcon(), strlen( 'data:image/svg+xml;base64,' ) ),
			true
		);
		$this->assertStringContainsString( '<svg', $svg );
		$this->assertStringNotContainsString( 'http://www.w3.org/1999', $svg );
		unset( $calls );

		$this->assertSame( array( 'runs', 'apps', 'settings' ), array_keys( Shell::tabs() ) );
	}

	/**
	 * The top-level page and the first submenu share a slug (so they share one WordPress
	 * hook). Two render callbacks on that hook print the screen twice, so only one may carry it.
	 */
	public function test_each_menu_slug_has_exactly_one_render_callback(): void {
		$GLOBALS['specflux_site_agent_test_menu'] = array();

		( new Shell() )->menu();

		$per_slug = array();
		foreach ( $GLOBALS['specflux_site_agent_test_menu'] as $entry ) {
			if ( ! empty( $entry['callback'] ) ) {
				$per_slug[ $entry['slug'] ] = ( $per_slug[ $entry['slug'] ] ?? 0 ) + 1;
			}
		}

		$this->assertSame(
			array(
				Shell::RUNS     => 1,
				Shell::APPS     => 1,
				Shell::SETTINGS => 1,
			),
			$per_slug
		);
	}
}
