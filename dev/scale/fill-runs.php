<?php
/**
 * Bring the runs table up to <target> rows WITHOUT driving each one: clone already-finished, really-driven
 * runs (and their steps) with new ids. The copies are byte-identical in shape to what the plugin wrote
 * (same columns, same JSON blobs), only id / created_at / updated_at / finished_at are re-stamped so the
 * list ordering is realistic. Used only to reach the "Runs screen at 10,000 runs" size cheaply; every
 * tick-cost figure comes from really-driven runs. Clones are tagged by goal prefix "[clone] ".
 *
 *   wp eval-file fill-runs.php <target-total-runs>
 */

global $wpdb;
$target = (int) ( $args[0] ?? 0 );
$runs   = $wpdb->prefix . 'specflux_site_agent_runs';
$steps  = $wpdb->prefix . 'specflux_site_agent_steps';
$have   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" );
if ( $target <= $have ) {
	WP_CLI::success( "already {$have} runs" );
	return;
}

$sources = $wpdb->get_col( "SELECT id FROM {$runs} WHERE status = 'completed' AND goal NOT LIKE '[clone]%' ORDER BY id DESC LIMIT 200" );
if ( array() === $sources ) {
	WP_CLI::error( 'no completed runs to clone; drive a campaign first' );
}

$run_cols  = array_diff( $wpdb->get_col( "SHOW COLUMNS FROM {$runs}" ), array( 'id' ) );
$step_cols = array_diff( $wpdb->get_col( "SHOW COLUMNS FROM {$steps}" ), array( 'id', 'run_id' ) );
$sel_run   = implode( ',', array_map( static function ( $c ) {
	return match ( $c ) {
		'goal'        => "CONCAT('[clone] ', goal)",
		'created_at'  => 'TIMESTAMPADD(SECOND, -@off, UTC_TIMESTAMP())',
		'updated_at'  => 'TIMESTAMPADD(SECOND, -@off, UTC_TIMESTAMP())',
		'finished_at' => 'TIMESTAMPADD(SECOND, -@off, UTC_TIMESTAMP())',
		default       => "`$c`",
	};
}, $run_cols ) );
$ins_run   = implode( ',', array_map( static fn ( $c ) => "`$c`", $run_cols ) );
$ins_step  = implode( ',', array_map( static fn ( $c ) => "`$c`", $step_cols ) );
$sel_step  = $ins_step;

$t = microtime( true );
$i = 0;
while ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" ) < $target ) {
	$src = (int) $sources[ $i++ % count( $sources ) ];
	$wpdb->query( 'SET @off = ' . ( 60 * ( $target - $have - ( $i % ( $target - $have ) ) ) ) );
	$wpdb->query( "INSERT INTO {$runs} ({$ins_run}) SELECT {$sel_run} FROM {$runs} WHERE id = {$src}" );
	$new = (int) $wpdb->insert_id;
	$wpdb->query( "INSERT INTO {$steps} (run_id,{$ins_step}) SELECT {$new},{$sel_step} FROM {$steps} WHERE run_id = {$src}" );
}
WP_CLI::success( sprintf( 'runs now %d (cloned %d from %d sources, %.0fs)', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$runs}" ), $i, count( $sources ), microtime( true ) - $t ) );
