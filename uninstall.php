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

require_once __DIR__ . '/src/Uninstaller.php';

// Multisite: every site is its own install (tables, options, crons, queue), so
// walk them all in batches of 100 and honour each site's own opt-in. The walk is
// capped so a runaway network cannot hold uninstall forever; WordPress deletes
// the plugin files regardless, and a site beyond the cap keeps inert leftovers.
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
	$specflux_site_agent_offset = 0;
	$specflux_site_agent_count  = 0;
	do {
		$specflux_site_agent_ids = get_sites(
			array(
				'number' => 100,
				'offset' => $specflux_site_agent_offset,
				'fields' => 'ids',
			)
		);
		foreach ( $specflux_site_agent_ids as $specflux_site_agent_blog_id ) {
			switch_to_blog( (int) $specflux_site_agent_blog_id );
			\Specflux\SiteAgent\Uninstaller::uninstallSite( $wpdb );
			restore_current_blog();
		}
		$specflux_site_agent_count   = count( $specflux_site_agent_ids );
		$specflux_site_agent_offset += 100;
	} while ( 100 === $specflux_site_agent_count && $specflux_site_agent_offset < 10000 );

	// Network (site) options: this plugin stores none.
	return;
}

\Specflux\SiteAgent\Uninstaller::uninstallSite( $wpdb );
