<?php
/**
 * Start N real runs through the plugin's own entry point and queue each one's first background tick
 * exactly as BackgroundDriver::schedule() does. Prints the first/last run id and how long start() took.
 *
 *   wp eval-file start-runs.php <count> [<label>] [keep]
 *
 * The fake model is scripted by the instrument mu-plugin (see lib/), so the goal text is just a label.
 */

use Specflux\SiteAgent\Http\ConsumerPolicy;

$count = (int) ( $args[0] ?? 0 );
$label = (string) ( $args[1] ?? 'scale' );
if ( $count < 1 ) {
	WP_CLI::error( 'usage: start-runs.php <count> [label]' );
}

// A clean slate for this campaign (unless the 3rd arg is "keep"): no leftover queued ticks, an empty metrics file.
if ( 'keep' !== ( $args[2] ?? '' ) ) {
	as_unschedule_all_actions( 'specflux_site_agent_background_tick' );
	$metrics = wp_upload_dir()['basedir'] . '/specflux-scale/metrics.jsonl';
	if ( file_exists( $metrics ) ) {
		unlink( $metrics );
	}
}

wp_set_current_user( 1 );
$policy = ConsumerPolicy::resolve( 'specflux-site-agent-admin', array() );
$plugin = specflux_site_agent();
$bg     = ( new ReflectionMethod( $plugin, 'background' ) );
$bg->setAccessible( true );
$driver = $bg->invoke( $plugin );

$first = 0;
$last  = 0;
$times = array();
for ( $i = 1; $i <= $count; $i++ ) {
	$t = microtime( true );
	$r = $plugin->start( 'specflux-site-agent-admin', sprintf( '%s run %d', $label, $i ), $policy['allow'], $policy['budget'], 'specflux-e2e' );
	if ( is_wp_error( $r ) ) {
		WP_CLI::error( 'start failed: ' . $r->get_error_code() . ' ' . $r->get_error_message() );
	}
	$id = (int) $r['run']['id'];
	if ( ! $driver->schedule( $id, 0 ) ) {
		WP_CLI::error( "run $id: nothing queued" );
	}
	$times[] = ( microtime( true ) - $t ) * 1000;
	$first   = $first ?: $id;
	$last    = $id;
}
sort( $times );
WP_CLI::log( wp_json_encode( array(
	'first_id'     => $first,
	'last_id'      => $last,
	'start_ms_p50' => round( $times[ (int) floor( 0.5 * ( count( $times ) - 1 ) ) ], 1 ),
	'start_ms_p95' => round( $times[ (int) floor( 0.95 * ( count( $times ) - 1 ) ) ], 1 ),
	'queued_at'    => microtime( true ),
) ) );
