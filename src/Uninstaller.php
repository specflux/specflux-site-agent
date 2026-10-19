<?php
/**
 * Per-site teardown: the one manifest of what this plugin leaves on a site,
 * shared by uninstall.php and the multisite site-deleted hook.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent;

use wpdb;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Operates on the CURRENT site (callers switch_to_blog() first on multisite).
 * Deliberately free of autoloader dependencies: uninstall.php requires this
 * file directly because the plugin is not loaded when it runs.
 */
final class Uninstaller {

	/** Option holding the site's opt-in to delete data on uninstall. */
	public const DELETE_DATA_OPTION = 'specflux_site_agent_uninstall_delete_data';

	/**
	 * Queued background ticks and cron events die with the plugin whether or
	 * not the site opts into deleting data: their callbacks are gone. (The
	 * queue's own functions exist only if another plugin has loaded it.)
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'specflux-site-agent' );
		}
		foreach ( array( 'specflux_site_agent_background_sweep', 'specflux_site_agent_expire_runs', 'specflux_site_agent_prune_runs', 'specflux_site_agent_connector_prune_activity', 'specflux_site_agent_oauth_gc' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/** Has this site opted into deleting its data on uninstall? */
	public static function optedIn(): bool {
		$value = get_option( self::DELETE_DATA_OPTION );

		return true === $value || '1' === $value;
	}

	/**
	 * Uninstall one site: always unschedule; purge data only when it opted in
	 * (or when the site itself is being deleted, where {@see self::purge()} is
	 * called directly).
	 *
	 * @param wpdb $db Database.
	 * @return void
	 */
	public static function uninstallSite( wpdb $db ): void {
		self::unschedule();
		if ( self::optedIn() ) {
			self::purge( $db );
		}
	}

	/**
	 * Drop this site's tables, options, transients and meta.
	 *
	 * @param wpdb $db Database.
	 * @return void
	 */
	public static function purge( wpdb $db ): void {
		foreach ( array( 'specflux_site_agent_steps', 'specflux_site_agent_runs', 'specflux_site_agent_connector_activity' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- opt-in uninstall dropping a table this plugin owns; nothing to cache.
			$db->query( (string) $db->prepare( 'DROP TABLE IF EXISTS %i', $db->prefix . $table ) );
		}

		foreach ( array(
			'specflux_site_agent_db_version',
			'specflux_site_agent_wallclock_since',
			'specflux_site_agent_legacy_run_watermark',
			'specflux_site_agent_site_brief',
			'specflux_site_agent_setup_brief_skipped',
			'specflux_site_agent_use_theme_patterns',
			'specflux_site_agent_uninstall_delete_data',
			'specflux_site_agent_connector_enabled',
			'specflux_site_agent_connector_approvals',
			'specflux_site_agent_oauth_clients',
			'specflux_site_agent_oauth_grants',
		) as $option ) {
			delete_option( $option );
		}

		foreach ( array( 'specflux_site_agent_activation_notice', 'specflux_site_agent_image_generation_unavailable' ) as $transient ) {
			delete_transient( $transient );
		}

		// Per-user and per-run keys: one pending-brief transient per user id, one legacy
		// tick lock per run id (pre-schema-11 installs; the lock is a run-row claim now). Transients live in the options table unless an external
		// object cache holds them (those expire on their own).
		foreach ( array( 'specflux_site_agent_brief_pending_', 'specflux_site_agent_lock_', 'specflux_site_agent_bgconf_', 'specflux_site_agent_rl_', 'specflux_site_agent_oauth_rl_', 'specflux_site_agent_oauth_cimd_', 'specflux_site_agent_oac_', 'specflux_site_agent_oau_', 'specflux_site_agent_oat_' ) as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall removing this plugin's own prefixed transients; nothing to cache.
				$db->query( (string) $db->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $db->options, $db->esc_like( $kind . $prefix ) . '%' ) );
			}
		}

		// Connector approval grants and OAuth clients, grants, refresh tokens and
		// rotated-token tombstones: one option per hash or id.
		foreach ( array( 'specflux_site_agent_cxa_', 'specflux_site_agent_oc_', 'specflux_site_agent_og_', 'specflux_site_agent_or_', 'specflux_site_agent_ox_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall removing this plugin's own prefixed options; nothing to cache.
			$db->query( (string) $db->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $db->options, $db->esc_like( $prefix ) . '%' ) );
		}

		delete_metadata( 'user', 0, 'specflux_site_agent_agent_safety_check_dismissed', '', true );
		// Connections: the per-site user option, plus the bare pre-0.4 network-wide key (inert on multisite).
		delete_metadata( 'user', 0, \Specflux\SiteAgent\Connector\Connections::siteKey(), '', true );
		delete_metadata( 'user', 0, 'specflux_site_agent_connections', '', true );
		delete_post_meta_by_key( '_specflux_site_agent_stock_source_id' );
		delete_post_meta_by_key( '_specflux_site_agent_style_variation' );
	}
}
