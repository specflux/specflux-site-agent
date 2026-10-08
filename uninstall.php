<?php
/**
 * SenroFlux uninstall: drop runs/steps ONLY when the site opted in.
 *
 * Runs are conversational history someone may want to keep; the Agent Safety
 * audit chain remains the authoritative record of what executed regardless.
 * Deleting data on uninstall therefore requires the explicit
 * senroflux_uninstall_delete_data option.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

// Abort if WordPress didn't call us (direct access or wrong context).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || empty( $GLOBALS['wpdb'] ) ) {
	exit;
}

global $wpdb;

$senroflux_delete_data = get_option( 'senroflux_uninstall_delete_data' );

if ( true !== $senroflux_delete_data && '1' !== $senroflux_delete_data ) {
	// Keep tables + options; the site did not opt into deletion.
	return;
}

foreach ( array( 'senroflux_steps', 'senroflux_runs', 'senroflux_connector_activity' ) as $senroflux_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- opt-in uninstall dropping a table this plugin owns; nothing to cache.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $senroflux_table ) );
}

foreach ( array(
	'senroflux_db_version',
	'senroflux_legacy_run_watermark',
	'senroflux_site_brief',
	'senroflux_setup_brief_skipped',
	'senroflux_use_theme_patterns',
	'senroflux_uninstall_delete_data',
	'senroflux_connector_enabled',
	'senroflux_connector_approvals',
	'senroflux_oauth_clients',
	'senroflux_oauth_grants',
) as $senroflux_option ) {
	delete_option( $senroflux_option );
}

foreach ( array( 'senroflux_activation_notice', 'senroflux_image_generation_unavailable' ) as $senroflux_transient ) {
	delete_transient( $senroflux_transient );
}

// Per-user and per-run keys: one pending-brief transient per user id, one tick
// lock per run id. Transients live in the options table unless an external
// object cache holds them (those expire on their own).
foreach ( array( 'senroflux_brief_pending_', 'senroflux_lock_', 'senroflux_rl_', 'senroflux_oauth_rl_', 'senroflux_oauth_cimd_', 'senroflux_oac_', 'senroflux_oau_', 'senroflux_oat_' ) as $senroflux_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_' ) as $senroflux_kind ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall removing this plugin's own prefixed transients; nothing to cache.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $senroflux_kind . $senroflux_prefix ) . '%' ) );
	}
}

// Connector approval grants and OAuth clients, grants, refresh tokens and
// rotated-token tombstones: one option per hash or id.
foreach ( array( 'senroflux_cxa_', 'senroflux_oc_', 'senroflux_og_', 'senroflux_or_', 'senroflux_ox_' ) as $senroflux_prefix ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall removing this plugin's own prefixed options; nothing to cache.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $senroflux_prefix ) . '%' ) );
}

delete_metadata( 'user', 0, 'senroflux_agent_safety_check_dismissed', '', true );
delete_metadata( 'user', 0, 'senroflux_connections', '', true );
delete_post_meta_by_key( '_senroflux_stock_source_id' );
delete_post_meta_by_key( '_senroflux_style_variation' );
