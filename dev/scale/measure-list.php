<?php
/**
 * Runs screen list timing at the current run count, three ways, each repeated:
 *   store    - WpdbRunStore::listRecent(50)         (the SQL the screen's list uses)
 *   plugin   - specflux_site_agent()->listRecent(50) (store + row mapping + the per-row filter)
 *   rest     - GET /specflux-site-agent/v1/runs?limit=50 through rest_do_request()
 * plus EXPLAIN of the list query. Prints one JSON object.
 *
 *   wp eval-file measure-list.php [<repeats>]
 */

global $wpdb;
wp_set_current_user( 1 );
$n       = max( 3, (int) ( $args[0] ?? 15 ) );
$plugin  = specflux_site_agent();
$table   = $wpdb->prefix . 'specflux_site_agent_runs';
$rm      = new ReflectionMethod( $plugin, 'runner' );
$rm->setAccessible( true );
$store   = $rm->invoke( $plugin )->store();

$stats = static function ( array $ms ): array {
	sort( $ms );
	$k = static fn ( float $q ): float => round( $ms[ (int) floor( $q * ( count( $ms ) - 1 ) ) ], 1 );
	return array( 'p50_ms' => $k( 0.5 ), 'p95_ms' => $k( 0.95 ), 'max_ms' => round( end( $ms ), 1 ), 'n' => count( $ms ) );
};
$time = static function ( callable $fn ) use ( $n ): array {
	$fn(); // warm: first call primes query/object caches and autoloaders; it is not counted.
	$ms = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$t = microtime( true );
		$fn();
		$ms[] = ( microtime( true ) - $t ) * 1000;
	}
	return $ms;
};

$out                  = array();
$out['runs']          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$out['store']         = $stats( $time( static fn () => $store->listRecent( 50 ) ) );
$out['plugin']        = $stats( $time( static fn () => $plugin->listRecent( 50 ) ) );
$out['rest']          = $stats( $time( static function () {
	$req = new WP_REST_Request( 'GET', '/specflux-site-agent/v1/runs' );
	$req->set_param( 'limit', 50 );
	return rest_do_request( $req );
} ) );
$out['rest_status']   = rest_do_request( new WP_REST_Request( 'GET', '/specflux-site-agent/v1/runs' ) )->get_status();
$out['explain']       = $wpdb->get_results( "EXPLAIN SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT 50", ARRAY_A );
$out['data_mb']       = round( (float) $wpdb->get_var( $wpdb->prepare( 'SELECT data_length/1048576 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table ) ), 2 );
WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT ) );
