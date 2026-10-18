<?php
/**
 * Dev-only: renders the Site Agent admin screens with realistic fixture data into
 * build/ui-snapshots/*.html, wrapped in wp-admin-like chrome. Not part of the suite
 * and never shipped (bin/ is excluded from the zip).
 *
 *   vendor/bin/phpunit bin/snapshots/UiSnapshotsTest.php
 *
 * Then `node bin/ui-snapshots.mjs` screenshots every page and runs axe.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Snapshots;

use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Admin\SettingsScreen;
use Specflux\SiteAgent\Connector\ActivityLog;
use Specflux\SiteAgent\Connector\AppsScreen;
use Specflux\SiteAgent\Connector\Approvals;
use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\ConnectScreen;
use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Connector\OAuth\Authorize;
use Specflux\SiteAgent\Connector\OAuth\Client;
use Specflux\SiteAgent\Connector\OAuth\Store;
use Specflux\SiteAgent\Passthrough\Passthrough;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\SiteBrief;
use Specflux\SiteAgent\Tests\Connector\OAuth\OAuthTestCase;

require_once dirname( __DIR__, 2 ) . '/tests/Connector/OAuth/OAuthTestCase.php';

final class UiSnapshotsTest extends OAuthTestCase {

	private string $out;

	protected function setUp(): void {
		parent::setUp();
		$this->out = dirname( __DIR__, 2 ) . '/build/ui-snapshots/';
		if ( ! is_dir( $this->out ) ) {
			mkdir( $this->out, 0775, true );
		}
		Clock::useFixed( 1_800_000_000 );
		$GLOBALS['pagenow'] = 'admin.php';
		add_filter( 'specflux_site_agent_runs_capability', static fn (): string => 'manage_options' );
		$GLOBALS['specflux_site_agent_test_users'] = array( self::USER => (object) array( 'user_login' => 'stephen' ) );
	}

	/** Run a screen and return its output. */
	private function capture( callable $render ): string {
		ob_start();
		try {
			$render();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}

		return (string) ob_get_clean();
	}

	private function chrome( string $title, string $current, string $inner, string $scripts = '' ): string {
		$items = array( 'Dashboard', 'Posts', 'Media', 'Pages', 'Comments', 'WooCommerce', 'Products', 'Appearance', 'Plugins', 'Users', 'Tools', 'Settings' );
		array_splice( $items, 8, 0, 'Site Agent' );
		$menu = '';
		foreach ( $items as $item ) {
			$menu .= '<li' . ( 'Site Agent' === $item ? ' class="cur"' : '' ) . '><span class="ico"></span>' . $item . '</li>';
		}
		$assets = '../../assets/';

		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . htmlspecialchars( $title ) . '</title>'
			. '<link rel="stylesheet" href="' . $assets . 'vendor/admin-ui-kit/tokens.css"><link rel="stylesheet" href="' . $assets . 'vendor/admin-ui-kit/components.css"><link rel="stylesheet" href="' . $assets . 'admin/site-agent-ui.css">'
			. ( str_contains( $scripts, 'runs-bundle' ) ? '<link rel="stylesheet" href="runs-bundle.css">' : '' )
			. '<style>.screen-reader-text{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}html{background:#f0f0f1}body{margin:0;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}'
			. '.wp{display:grid;grid-template-columns:160px minmax(0,1fr);grid-template-rows:32px 1fr;width:100%;max-width:1440px;min-height:900px;background:#f0f0f1}'
			. '.wp-adminbar{grid-column:1/3;display:flex;align-items:center;gap:20px;padding:0 12px;background:#1d2327;color:#f0f0f1;font-size:13px}.wp-adminbar .logo{width:16px;height:16px;border-radius:50%;background:#a7aaad}.wp-adminbar .r{margin-left:auto}'
			. '.wp-menu{margin:0;padding:8px 0 0;list-style:none;background:#1d2327}.wp-menu li{display:flex;align-items:center;gap:8px;height:34px;padding:0 12px;color:#f0f0f1;font-size:14px}.wp-menu li.cur{background:#2271b1;color:#fff;font-weight:600}'
			. '.wp-menu .ico{width:16px;height:16px;border-radius:4px;background:#8c8f94}.wp-menu li.cur .ico{background:#fff}'
			. '.wp-content{display:flex;flex-direction:column;min-width:0;padding-left:20px}.wp-content>.aui{flex:1 1 auto}</style></head><body>'
			. '<div class="wp"><div class="wp-adminbar" aria-hidden="true"><span class="logo"></span><span>Greenleaf Goods</span><span>0 updates</span><span>+ New</span><span class="r">Howdy, stephen</span></div>'
			. '<ul class="wp-menu" aria-hidden="true">' . $menu . '</ul><div class="wp-content" data-current="' . htmlspecialchars( $current ) . '">' . $inner . '</div></div>'
			. '<script>window.ajaxurl="/wp-admin/admin-ajax.php"</script>' . $scripts . '</body></html>';
	}

	private function kitScripts(): string {
		return '<script src="../../assets/vendor/admin-ui-kit/aui.js"></script><script src="../../assets/admin/site-agent-ui.js"></script>';
	}

	private function write( string $name, string $html ): void {
		file_put_contents( $this->out . $name . '.html', $html );
		$this->assertGreaterThan( 2000, strlen( $html ), $name );
	}

	private function fakeAbility( string $name, string $label, string $description, bool $readonly ): object {
		return new class( $name, $label, $description, $readonly ) extends \SpecfluxSiteAgent_Test_Fake_Ability {
			public function __construct( string $name, private string $label, string $description, bool $readonly ) {
				parent::__construct( $name, true, array(), $description, array( 'type' => 'object' ), array( 'annotations' => array( 'readonly' => $readonly ) ) );
			}

			public function get_label(): string {
				return $this->label;
			}
		};
	}

	public function test_render_every_screen(): void {
		update_option( 'permalink_structure', '/%postname%/' );
		Connector::set_adapter_version_probe( '0.7.0' );

		// ---- AI apps data
		$cursor = ConnectScreen::create( self::USER, 'cursor', Connection::SCOPE_READ_WRITE );
		ConnectScreen::create( self::USER, 'claude-code', Connection::SCOPE_READ );
		$this->assertIsArray( $cursor );
		foreach ( $GLOBALS['specflux_site_agent_test_app_passwords'][ self::USER ] as $i => $item ) {
			$GLOBALS['specflux_site_agent_test_app_passwords'][ self::USER ][ $i ]['last_used'] = 1_800_000_000 - 3600 * ( $i + 1 );
		}
		Store::saveClient(
			array(
				'id'            => 'claudeclient01',
				'name'          => 'Claude',
				'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ),
				'created'       => 1_799_000_000,
			)
		);
		Store::saveClient(
			array(
				'id'            => 'gptclient0001',
				'name'          => 'ChatGPT',
				'redirect_uris' => array( 'https://chatgpt.com/connector_platform_oauth_redirect' ),
				'created'       => 1_799_100_000,
			)
		);
		Store::createGrant( self::USER, 'claudeclient01', 'specflux:read specflux:write', 'http://example.test/wp-json/specflux-site-agent/mcp', 'h1' );
		$connection = new Connection( self::USER, (string) $cursor['uuid'], Connection::SCOPE_READ_WRITE );
		ActivityLog::record( $connection, 'content/update-page', ActivityLog::RAN );
		ActivityLog::record( $connection, 'content/list-posts', ActivityLog::RAN );
		ActivityLog::record( $connection, 'commerce/order-refund', ActivityLog::REFUSED );
		ActivityLog::record( $connection, 'content/publish-page', ActivityLog::WAITING );
		Approvals::park( self::USER, (string) $cursor['uuid'], 'content/publish-page', array( 'id' => 42, 'status' => 'publish' ) );

		$this->write( 'ai-apps', $this->chrome( 'AI apps', 'apps', $this->capture( array( AppsScreen::class, 'render' ) ), $this->kitScripts() ) );

		// A just-created connection (the one-time config view).
		$GLOBALS['specflux_site_agent_test_app_passwords'][ self::USER ] = array();
		$_POST = array(
			'specflux_site_agent_connect_action' => 'create',
			'client'                             => 'cursor',
			'scope'                              => 'read',
		);
		$this->write( 'ai-apps-created', $this->chrome( 'AI apps (just created)', 'apps', $this->capture( array( AppsScreen::class, 'render' ) ), $this->kitScripts() ) );
		$_POST = array();

		// ---- AI apps with the connector off
		update_option( Connector::OPTION, '0' );
		$this->write( 'ai-apps-off', $this->chrome( 'AI apps (off)', 'apps', $this->capture( array( AppsScreen::class, 'render' ) ), $this->kitScripts() ) );
		update_option( Connector::OPTION, '1' );

		// ---- Settings
		SiteBrief::set( 'Greenleaf Goods sells small-batch tea and handmade ceramics. Keep the tone friendly and plain. Prices are in USD. Never mention competitors by name.' );
		$names = array(
			array( 'acf/groups-list', 'List field groups', 'Returns every field group.', true ),
			array( 'acf/values-get', 'Read field values', 'Reads the saved values of one post.', true ),
			array( 'acf/values-set', 'Update field values', 'Changes saved field values on a post.', false ),
			array( 'acf/group-create', 'Create a field group', 'Adds a new field group.', false ),
			array( 'smtp/log', 'Show the email log', 'Lists recent outgoing emails.', true ),
			array( 'smtp/resend', 'Resend a failed email', 'Sends a failed message again.', false ),
			array( 'smtp/test-send', 'Send a test email', 'Sends a test message to you.', false ),
			array( 'smtp/settings-get', 'Read mail settings', 'Reads the mail settings.', true ),
			array( 'seo/audit', 'Audit a page', 'Checks one page for SEO problems.', true ),
		);
		foreach ( $names as $n ) {
			$GLOBALS['specflux_site_agent_test_abilities'][ $n[0] ] = $this->fakeAbility( $n[0], $n[1], $n[2], $n[3] );
		}
		Passthrough::save( array( 'acf/groups-list', 'acf/values-get', 'smtp/log', 'smtp/settings-get' ), array( 'acf/groups-list' => 'read', 'acf/values-get' => 'read', 'smtp/log' => 'read', 'smtp/settings-get' => 'read' ) );
		$this->write( 'settings', $this->chrome( 'Settings', 'settings', $this->capture( array( new SettingsScreen(), 'render' ) ), $this->kitScripts() ) );

		$_GET = array( 'specflux_site_agent_pt_error' => 'too_many' );
		$this->write( 'settings-error', $this->chrome( 'Settings (error)', 'settings', $this->capture( array( new SettingsScreen(), 'render' ) ), $this->kitScripts() ) );
		$_GET = array();

		// ---- Runs (PHP shell; the React bundle mounts into it)
		foreach ( array( 'parked', 'finished' ) as $scene ) {
			$html = $this->capture( array( new RunsScreen(), 'render' ) );
			$this->write( 'runs-' . $scene, $this->chrome( 'Runs (' . $scene . ')', 'runs', $html, '<script>window.__SCENE__="' . $scene . '"</script><script src="runs-bundle.js"></script>' . $this->kitScripts() ) );
		}

		// ---- OAuth consent page (a complete standalone document)
		$method = new \ReflectionMethod( Authorize::class, 'consent' );
		$method->setAccessible( true );
		$page = $method->invoke(
			null,
			new Client( 'claudeclient01', 'Claude', array( 'https://claude.ai/api/mcp/auth_callback' ) ),
			'https://claude.ai/api/mcp/auth_callback',
			array( 'client_id' => 'claudeclient01' ),
			'specflux_site_agent_oauth_consent'
		);
		$this->write( 'consent', $page->body );
	}
}
