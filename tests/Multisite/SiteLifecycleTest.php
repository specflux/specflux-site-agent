<?php
/**
 * Multisite lifecycle (0.4 M1): activation, new and deleted sites, network
 * deactivation, uninstall and the background owner's membership.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Multisite;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\SiteAgent\Background\BackgroundDriver;
use Specflux\SiteAgent\Connector\ActivityLog;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\RunLifecycle;
use Specflux\SiteAgent\Schema;
use Specflux\SiteAgent\SiteLifecycle;
use wpdb;

final class SiteLifecycleTest extends TestCase {

	private const CRONS = array(
		ActivityLog::CRON_HOOK,
		RunLifecycle::EXPIRE_HOOK,
		RunLifecycle::PRUNE_HOOK,
	);

	private wpdb $db;

	public static function setUpBeforeClass(): void {
		// Uninstall-only WordPress functions, absent from the shared shims.
		foreach ( array( 'delete_metadata', 'delete_post_meta_by_key', 'delete_transient' ) as $name ) {
			if ( ! function_exists( $name ) ) {
				eval( "function {$name}( ...\$args ) { return true; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only no-op shims.
			}
		}
		require_once dirname( __DIR__ ) . '/stubs/oauth.php';
		require_once dirname( __DIR__, 2 ) . '/specflux-site-agent.php';
	}

	protected function setUp(): void {
		$this->db                                       = new wpdb();
		$GLOBALS['wpdb']                                = $this->db;
		$GLOBALS['specflux_site_agent_test_filters']    = array();
		$GLOBALS['specflux_site_agent_test_as']         = array();
		$GLOBALS['specflux_site_agent_test_as_version'] = '4.2.0';
		$GLOBALS['specflux_site_agent_test_cron']       = array();
		$GLOBALS['specflux_site_agent_test_dbdelta_queries'] = array();
		unset( $GLOBALS['specflux_site_agent_test_is_admin'], $GLOBALS['specflux_site_agent_test_doing_cron'] );
		Clock::useFixed( 1_800_000_000 );
		if ( ! function_exists( 'dbDelta' ) ) {
			// Stand-in so Schema::install() runs without wp-admin.
			eval( 'function dbDelta( $queries = "", bool $execute = true ): array { foreach ( (array) $queries as $q ) { $GLOBALS["specflux_site_agent_test_dbdelta_queries"][] = (string) $q; } return array(); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stand-in for wp-admin/includes/upgrade.php.
		}
	}

	protected function tearDown(): void {
		Clock::reset();
		$GLOBALS['specflux_site_agent_test_deactivated_plugins'] = array();
		specflux_site_agent_test_network_reset();
		$GLOBALS['specflux_site_agent_test_filters'] = array();
		$GLOBALS['specflux_site_agent_test_options'] = array();
		$GLOBALS['specflux_site_agent_test_cron']    = array();
		$GLOBALS['specflux_site_agent_test_as']      = array();
		Plugin::reset();
	}

	private function activate( bool $network_wide ): void {
		\Specflux\SiteAgent\specflux_site_agent_activate( $network_wide );
	}

	private function markNetworkActive(): void {
		$GLOBALS['specflux_site_agent_test_site_options']['active_sitewide_plugins'] = array( SiteLifecycle::basename() => 1_800_000_000 );
	}

	private function installed( int $site ): bool {
		return Schema::DB_VERSION === (int) ( specflux_site_agent_test_site_state( $site )['options']['specflux_site_agent_db_version'] ?? 0 );
	}

	/** @return list<string> */
	private function cronsOn( int $site ): array {
		return array_keys( specflux_site_agent_test_site_state( $site )['cron'] );
	}

	// -- the refusal filter -------------------------------------------------

	public function test_the_filter_false_restores_the_old_refusal(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		add_filter( 'specflux_site_agent_allow_multisite', static fn (): bool => false );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'does not support WordPress multisite' );
		try {
			$this->activate( false );
		} finally {
			$this->assertFalse( $this->installed( 1 ) );
		}
	}

	public function test_the_filter_false_also_refuses_network_activation_and_the_runtime(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		add_filter( 'specflux_site_agent_allow_multisite', static fn (): bool => false );

		$this->assertTrue( \Specflux\SiteAgent\MultisiteGuard::refused() );
		$this->expectException( RuntimeException::class );
		$this->activate( true );
	}

	public function test_by_default_multisite_activates(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );

		$this->assertFalse( \Specflux\SiteAgent\MultisiteGuard::refused() );
		$this->activate( false );

		$this->assertTrue( $this->installed( 1 ) );
		$this->assertEqualsCanonicalizing( self::CRONS, array_intersect( $this->cronsOn( 1 ), self::CRONS ) );
	}

	// -- network activation + lazy install ------------------------------------

	public function test_network_activation_installs_the_current_site_only_and_another_site_installs_lazily(): void {
		specflux_site_agent_test_network( array( 1, 2, 3 ) );
		$this->markNetworkActive();

		$this->activate( true );

		$this->assertTrue( $this->installed( 1 ) );
		$this->assertFalse( $this->installed( 2 ), 'no synchronous loop over the network' );
		$this->assertSame( array(), specflux_site_agent_test_site_state( 2 )['cron'] );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_network']['log'], 'activation never switched blog' );

		// Site 2's first request: boot() upgrades the schema, then an admin init schedules the crons.
		switch_to_blog( 2 );
		$this->db->tables = array();
		Plugin::reset();
		Plugin::set_dependency_probe( false );
		$GLOBALS['specflux_site_agent_test_actions'] = array();
		Plugin::instance()->boot();
		$this->assertTrue( $this->installed( 2 ) );
		$this->assertStringContainsString( 'wp_2_specflux_site_agent_runs', implode( "\n", $GLOBALS['specflux_site_agent_test_dbdelta_queries'] ) );

		$GLOBALS['specflux_site_agent_test_is_admin'] = true;
		$this->fireInit();
		restore_current_blog();

		$this->assertEqualsCanonicalizing( self::CRONS, array_values( array_intersect( $this->cronsOn( 2 ), self::CRONS ) ) );
		$this->assertContains( BackgroundDriver::SWEEP_HOOK, array_column( specflux_site_agent_test_site_state( 2 )['as'], 'hook' ), 'the stall sweep is queued on that site' );
		$this->assertFalse( $this->installed( 3 ), 'an untouched site stays untouched' );
		$this->assertSame( array(), specflux_site_agent_test_site_state( 3 )['cron'] );
	}

	/** Run the callbacks Plugin::boot() put on `init` (priority 20 is the cron self-heal). */
	private function fireInit(): void {
		foreach ( $GLOBALS['specflux_site_agent_test_action_priorities']['init'] ?? array() as list( $callback, $priority ) ) {
			if ( 20 === $priority && $callback instanceof \Closure ) {
				$callback();
			}
		}
	}

	// -- new and deleted sites ---------------------------------------------

	public function test_a_new_site_installs_when_the_plugin_is_network_active(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		$this->markNetworkActive();

		SiteLifecycle::onInitializeSite( (object) array( 'blog_id' => 2 ) );

		$this->assertTrue( $this->installed( 2 ) );
		$this->assertEqualsCanonicalizing( self::CRONS, array_values( array_intersect( $this->cronsOn( 2 ), self::CRONS ) ) );
		$this->assertSame( 1, get_current_blog_id(), 'the current site is restored' );
		$this->assertFalse( $this->installed( 1 ) );
	}

	public function test_a_new_site_is_not_installed_when_the_plugin_is_only_site_active_elsewhere(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		update_option( 'active_plugins', array( SiteLifecycle::basename() ) ); // Site 1 only.

		SiteLifecycle::onInitializeSite( (object) array( 'blog_id' => 2 ) );

		$this->assertFalse( $this->installed( 2 ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_network']['log'] );
	}

	public function test_a_deleted_site_loses_its_tables_and_options_and_no_other_site_does(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		$this->markNetworkActive();
		SiteLifecycle::onInitializeSite( (object) array( 'blog_id' => 2 ) );
		$this->activate( true );
		switch_to_blog( 2 );
		update_option( 'specflux_site_agent_site_brief', 'x' );
		restore_current_blog();
		$this->db->queries = array();

		SiteLifecycle::onUninitializeSite( (object) array( 'blog_id' => 2 ) );

		$state = specflux_site_agent_test_site_state( 2 );
		$this->assertArrayNotHasKey( 'specflux_site_agent_db_version', $state['options'] );
		$this->assertArrayNotHasKey( 'specflux_site_agent_site_brief', $state['options'] );
		$this->assertSame( array(), array_intersect( array_keys( $state['cron'] ), self::CRONS ) );
		$dropped = implode( "\n", $this->db->queries );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_2_specflux_site_agent_runs', $dropped );
		$this->assertStringNotContainsString( 'DROP TABLE IF EXISTS wp_specflux', $dropped );
		$this->assertTrue( $this->installed( 1 ), 'site 1 is untouched' );
		$this->assertNotEmpty( array_intersect( $this->cronsOn( 1 ), self::CRONS ) );
	}

	public function test_a_deleted_site_where_the_plugin_was_not_active_is_left_alone(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		$this->db->queries = array();

		SiteLifecycle::onUninitializeSite( (object) array( 'blog_id' => 2 ) );

		$this->assertSame( array(), $this->db->queries );
	}

	// -- network deactivation -------------------------------------------------

	public function test_network_deactivation_unschedules_every_site_in_batches_of_100(): void {
		specflux_site_agent_test_network( range( 1, 250 ) );
		foreach ( array( 1, 120, 250 ) as $id ) {
			switch_to_blog( $id );
			SiteLifecycle::installCurrentSite();
			as_schedule_single_action( 1, BackgroundDriver::HOOK, array( 'run_id' => $id ), BackgroundDriver::GROUP );
			restore_current_blog();
		}

		\Specflux\SiteAgent\specflux_site_agent_deactivate( true );

		foreach ( array( 1, 120, 250 ) as $id ) {
			$state = specflux_site_agent_test_site_state( $id );
			$this->assertSame( array(), array_intersect( array_keys( $state['cron'] ), self::CRONS ), "site $id crons" );
			$this->assertSame( array(), $state['as'], "site $id queue" );
		}
		$calls = $GLOBALS['specflux_site_agent_test_get_sites_calls'];
		$this->assertSame( array( 0, 100, 200 ), array_column( $calls, 'offset' ) );
		$this->assertSame( array( 100, 100, 100 ), array_column( $calls, 'number' ) );
		$this->assertSame( 'ids', $calls[0]['fields'] );
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_a_site_level_deactivation_touches_only_the_current_site(): void {
		specflux_site_agent_test_network( array( 1, 2 ) );
		SiteLifecycle::installCurrentSite();
		switch_to_blog( 2 );
		SiteLifecycle::installCurrentSite();
		restore_current_blog();

		\Specflux\SiteAgent\specflux_site_agent_deactivate( false );

		$this->assertSame( array(), array_intersect( $this->cronsOn( 1 ), self::CRONS ) );
		$this->assertNotEmpty( array_intersect( $this->cronsOn( 2 ), self::CRONS ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_get_sites_calls'] );
	}

	// -- uninstall ------------------------------------------------------------

	public function test_uninstall_walks_every_site_in_batches_and_honours_each_sites_opt_in(): void {
		specflux_site_agent_test_network( range( 1, 150 ) );
		foreach ( array( 1, 2, 150 ) as $id ) {
			switch_to_blog( $id );
			SiteLifecycle::installCurrentSite();
			update_option( 'specflux_site_agent_site_brief', 'keep?' );
			restore_current_blog();
		}
		foreach ( array( 2, 150 ) as $id ) {
			switch_to_blog( $id );
			update_option( 'specflux_site_agent_uninstall_delete_data', '1' );
			restore_current_blog();
		}
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'specflux-site-agent/specflux-site-agent.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WordPress's own uninstall constant.
		}
		$this->db->queries = array();

		$this->runUninstall();

		$one = specflux_site_agent_test_site_state( 1 );
		$this->assertSame( 'keep?', $one['options']['specflux_site_agent_site_brief'], 'no opt-in: data kept' );
		$this->assertSame( array(), array_intersect( array_keys( $one['cron'] ), array( RunLifecycle::EXPIRE_HOOK, RunLifecycle::PRUNE_HOOK ) ), 'run crons cleared even without opt-in' );
		foreach ( array( 2, 150 ) as $id ) {
			$state = specflux_site_agent_test_site_state( $id );
			$this->assertArrayNotHasKey( 'specflux_site_agent_site_brief', $state['options'], "site $id opted in" );
		}
		$dropped = implode( "\n", $this->db->queries );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_150_specflux_site_agent_runs', $dropped );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_2_specflux_site_agent_runs', $dropped );
		$this->assertStringNotContainsString( 'DROP TABLE IF EXISTS wp_specflux_site_agent_runs', $dropped );
		$this->assertSame( array( 0, 100 ), array_column( $GLOBALS['specflux_site_agent_test_get_sites_calls'], 'offset' ) );
		$this->assertSame( 1, get_current_blog_id() );
	}

	private function runUninstall(): void {
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}
}
