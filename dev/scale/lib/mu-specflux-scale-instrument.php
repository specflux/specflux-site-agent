<?php
/**
 * Plugin Name: Specflux Site Agent Scale Harness Instrument
 * Description: SCALE-HARNESS-ONLY (0.4 S1). Copied into wp-content/mu-plugins by dev/scale/scale.sh and
 *              inert unless the option `specflux_scale_instrument` is '1'. It (1) answers the e2e fake
 *              provider's script option with the ONE step the current run needs next (so interleaved
 *              runs in a shared Action Scheduler batch each get their own scenario), (2) times how
 *              long the fake model spends inside generateTextResult() so that time can be subtracted,
 *              and (3) writes one JSON line per Action Scheduler tick to
 *              wp-content/uploads/specflux-scale/metrics.jsonl. It changes no product code.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( '1' !== get_option( 'specflux_scale_instrument', '0' ) ) {
	return;
}

/** The scenario's step list; the fake model gets exactly the step for the run's current model turn. */
function specflux_scale_steps( string $scenario ): array {
	// 'delayed': every model turn takes 10 s (the shortest tick slice the plugin allows), so each tick covers exactly one turn and a run needs several ticks.
	$delay = 'delayed' === $scenario ? array( 'delay' => 10 ) : array();
	return array(
		array_merge( array( 'type' => 'calls', 'calls' => array( array( 'name' => 'wpab__specflux-e2e__list-things', 'args' => new stdClass() ) ) ), $delay ),
		array_merge(
			array(
				'type'  => 'calls',
				'calls' => array(
					array( 'name' => 'wpab__specflux-e2e__read-thing', 'args' => array( 'id' => 'thing-1' ) ),
					array( 'name' => 'wpab__specflux-e2e__search-things', 'args' => array( 'query' => 'launch' ) ),
				),
			),
			$delay
		),
		array_merge( array( 'type' => 'text', 'text' => 'Done.' ), $delay ),
	);
}

$GLOBALS['specflux_scale'] = array(
	'run_id'     => 0,
	'sched_ts'   => null,
	't0'         => 0.0,
	'q0'         => 0,
	'model_t0'   => 0.0,
	'model_ms'   => 0.0,
	'model_turns' => 0,
);

/** Mark the start of one measured tick for `$run_id` (`$sched_ts` = when the queue said it was due). */
function specflux_scale_begin( int $run_id, ?float $sched_ts = null ): void {
	global $wpdb;
	$s              = &$GLOBALS['specflux_scale'];
	$s['run_id']    = $run_id;
	$s['sched_ts']  = $sched_ts;
	$s['model_ms']  = 0.0;
	$s['model_turns'] = 0;
	$s['q0']        = (int) $wpdb->num_queries;
	$s['t0']        = microtime( true );
}

/** Mark the end and append one JSON line. */
function specflux_scale_end( string $source, array $extra = array() ): void {
	global $wpdb;
	$s     = &$GLOBALS['specflux_scale'];
	$now   = microtime( true );
	$total = ( $now - $s['t0'] ) * 1000;
	$dir   = wp_upload_dir()['basedir'] . '/specflux-scale';
	wp_mkdir_p( $dir );
	$line = array_merge(
		array(
			'source'       => $source,
			'run_id'       => $s['run_id'],
			'total_ms'     => round( $total, 2 ),
			'model_ms'     => round( $s['model_ms'], 2 ),
			'overhead_ms'  => round( $total - $s['model_ms'], 2 ),
			'model_turns'  => $s['model_turns'],
			'queries'      => (int) $wpdb->num_queries - $s['q0'],
			'lag_s'        => null === $s['sched_ts'] ? null : round( $s['t0'] - $s['sched_ts'], 3 ),
			'mem_peak_mb'  => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'mem_now_mb'   => round( memory_get_usage( true ) / 1048576, 1 ),
			'at'           => round( $now, 3 ),
		),
		$extra
	);
	file_put_contents( $dir . '/metrics.jsonl', wp_json_encode( $line ) . "\n", FILE_APPEND | LOCK_EX );
}

// The fake model asks for its script: hand it the one step this run needs next, and start its stopwatch.
add_filter(
	'pre_option_specflux_site_agent_e2e_script',
	static function ( $pre ) {
		global $wpdb;
		$s              = &$GLOBALS['specflux_scale'];
		$s['model_t0']  = microtime( true );
		$run_id         = (int) $s['run_id'];
		$turn           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}specflux_site_agent_steps WHERE run_id = %d AND kind = 'model'", $run_id ) );
		$steps          = specflux_scale_steps( (string) get_option( 'specflux_scale_scenario', 'single' ) );
		return (string) wp_json_encode( array( $steps[ min( $turn, count( $steps ) - 1 ) ] ) );
	}
);

// Never persist the drained script (it is rebuilt per turn above).
add_filter(
	'pre_update_option_specflux_site_agent_e2e_script',
	static fn ( $value, $old_value ) => $old_value,
	10,
	2
);

// The fake model's LAST write in generateTextResult(): stop its stopwatch.
add_filter(
	'pre_update_option_specflux_site_agent_e2e_prompts',
	static function ( $value ) {
		$s                 = &$GLOBALS['specflux_scale'];
		$s['model_ms']    += ( microtime( true ) - $s['model_t0'] ) * 1000;
		$s['model_turns'] += 1;
		return $value;
	}
);

// Action Scheduler path: one line per background tick.
add_action(
	'action_scheduler_before_execute',
	static function ( $action_id ): void {
		$action = ActionScheduler::store()->fetch_action( (string) $action_id );
		if ( 'specflux_site_agent_background_tick' !== $action->get_hook() ) {
			$GLOBALS['specflux_scale']['run_id'] = 0;
			return;
		}
		$args  = $action->get_args();
		$sched = $action->get_schedule()->get_date();
		specflux_scale_begin( (int) ( $args['run_id'] ?? 0 ), $sched ? (float) $sched->getTimestamp() : null );
	}
);
add_action(
	'action_scheduler_after_execute',
	static function (): void {
		if ( 0 !== (int) $GLOBALS['specflux_scale']['run_id'] ) {
			specflux_scale_end( 'as' );
			$GLOBALS['specflux_scale']['run_id'] = 0;
		}
	}
);

// Each Action Scheduler batch starts with the fixture's own call/prompt logs empty: they only ever grow
// (one entry per model turn) and would otherwise make the fake model slower the more runs came before it.
add_action(
	'action_scheduler_before_process_queue',
	static function (): void {
		delete_option( 'specflux_site_agent_e2e_calls' );
		delete_option( 'specflux_site_agent_e2e_prompts' );
	}
);
