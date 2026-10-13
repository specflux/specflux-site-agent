<?php
/**
 * Child-process probe for ActivationTest: boots the test shims WITHOUT dbDelta()
 * (as during real WordPress activation), runs the activation callback, and
 * prints what happened as JSON. dbDelta only appears if Schema::install()
 * requires the stand-in upgrade.php itself.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

$GLOBALS['specflux_site_agent_test_lazy_dbdelta'] = true;
require dirname( __DIR__ ) . '/bootstrap.php';

$specflux_site_agent_dbdelta_before = function_exists( 'dbDelta' );

$GLOBALS['specflux_site_agent_test_options']   = array();
$GLOBALS['specflux_site_agent_test_multisite'] = false;
$GLOBALS['wpdb']                               = new wpdb();

require dirname( __DIR__, 2 ) . '/specflux-site-agent.php';
\Specflux\SiteAgent\specflux_site_agent_activate();

echo json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- bare PHP probe, no WordPress loaded.
	array(
		'dbdelta_defined_at_start' => $specflux_site_agent_dbdelta_before,
		'queries'                  => $GLOBALS['specflux_site_agent_test_dbdelta_queries'] ?? array(),
		'db_version'               => get_option( 'specflux_site_agent_db_version' ),
	)
);
