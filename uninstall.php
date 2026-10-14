<?php
/**
 * Specflux Site Agent uninstall: drop runs/steps ONLY when the site opted in.
 *
 * Runs are conversational history someone may want to keep; the Agent Safety
 * audit chain remains the authoritative record of what executed regardless.
 * Deleting data on uninstall therefore requires the explicit
 * specflux_site_agent_uninstall_delete_data option.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

// Abort if WordPress didn't call us (direct access or wrong context).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || empty( $GLOBALS['wpdb'] ) ) {
	exit;
}

global $wpdb;

$specflux_site_agent_delete_data = get_option( 'specflux_site_agent_uninstall_delete_data' );

if ( true !== $specflux_site_agent_delete_data && '1' !== $specflux_site_agent_delete_data ) {
	// Keep tables + options; the site did not opt into deletion.
	return;
}

foreach ( array( 'specflux_site_agent_steps', 'specflux_site_agent_runs', 'specflux_site_agent_connector_activity' ) as $specflux_site_agent_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- opt-in uninstall dropping a table this plugin owns; nothing to cache.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $specflux_site_agent_table ) );
}

foreach ( array(
	'specflux_site_agent_db_version',
	'specflux_site_agent_legacy_run_watermark',
	'specflux_site_agent_site_brief',
	'specflux_site_agent_setup_brief_skipped',
	'specflux_site_agent_use_theme_patterns',
	'specflux_site_agent_uninstall_delete_data',
	'specflux_site_agent_connector_enabled',
	'specflux_site_agent_connector_approvals',
	'specflux_site_agent_oauth_clients',
	'specflux_site_agent_oauth_grants',
) as $specflux_site_agent_option ) {
	delete_option( $specflux_site_agent_option );
}

foreach ( array( 'specflux_site_agent_activation_notice', 'specflux_site_agent_image_generation_unavailable' ) as $specflux_site_agent_transient ) {
	delete_transient( $specflux_site_agent_transient );
}

// Per-user and per-run keys: one pending-brief transient per user id, one tick
// lock per run id. Transients live in the options table unless an external
// object cache holds them (those expire on their own).
foreach ( array( 'specflux_site_agent_brief_pending_', 'specflux_site_agent_lock_', 'specflux_site_agent_rl_', 'specflux_site_agent_oauth_rl_', 'specflux_site_agent_oauth_cimd_', 'specflux_site_agent_oac_', 'specflux_site_agent_oau_', 'specflux_site_agent_oat_' ) as $specflux_site_agent_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_' ) as $specflux_site_agent_kind ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall removing this plugin's own prefixed transients; nothing to cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $specflux_site_agent_kind . $specflux_site_agent_prefix ) . '%' ) );
	}
}

// Connector approval grants and OAuth clients, grants, refresh tokens and
// rotated-token tombstones: one option per hash or id.
foreach ( array( 'specflux_site_agent_cxa_', 'specflux_site_agent_oc_', 'specflux_site_agent_og_', 'specflux_site_agent_or_', 'specflux_site_agent_ox_' ) as $specflux_site_agent_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall removing this plugin's own prefixed options; nothing to cache.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $specflux_site_agent_prefix ) . '%' ) );
}

delete_metadata( 'user', 0, 'specflux_site_agent_agent_safety_check_dismissed', '', true );
delete_metadata( 'user', 0, 'specflux_site_agent_connections', '', true );
delete_post_meta_by_key( '_specflux_site_agent_stock_source_id' );
delete_post_meta_by_key( '_specflux_site_agent_style_variation' );
