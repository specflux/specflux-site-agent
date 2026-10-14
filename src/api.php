<?php
/**
 * Global service locator for Specflux Site Agent consumers.
 *
 * Defined in the global namespace so any consumer (first one:
 * marketing-analytics-chat) can feature-detect the plugin without knowing
 * its namespace:
 *
 *   if ( function_exists( 'specflux_site_agent' )
 *       && null !== specflux_site_agent()
 *       && specflux_site_agent()->available() ) {
 *       $run = specflux_site_agent()->start( ... );
 *   }
 *
 * Absent entirely before this file loads; null after load but before
 * plugins_loaded, or when the plugin bailed. Null-safe by contract.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SiteAgent\Plugin;

if ( ! function_exists( 'specflux_site_agent' ) ) {
	/**
	 * The Specflux Site Agent plugin instance. Check available() before starting runs;
	 * feature-detect THIS FUNCTION with function_exists first — it does not
	 * exist while Specflux Site Agent is inactive.
	 *
	 * @return Plugin
	 */
	function specflux_site_agent(): Plugin {
		return Plugin::instance();
	}
}
