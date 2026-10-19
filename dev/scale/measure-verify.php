<?php
/**
 * Time Belayline's own full-chain verification (AuditReader::verifyChain(), the call behind the audit
 * screen's "chain verified" status) at the current audit row count. Repeats and reports peak memory.
 *
 *   wp eval-file measure-verify.php [<repeats>]
 */

use Belayline\Plugin\Audit\AuditReader;

global $wpdb;
$n      = max( 1, (int) ( $args[0] ?? 3 ) );
$reader = new AuditReader( $wpdb );
$rows   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reader->table()}" );
$ms     = array();
$ok     = true;
for ( $i = 0; $i < $n; $i++ ) {
	$t    = microtime( true );
	$ok   = $reader->verifyChain() && $ok;
	$ms[] = round( ( microtime( true ) - $t ) * 1000 );
}
sort( $ms );
WP_CLI::log( wp_json_encode( array(
	'audit_rows'    => $rows,
	'chain_valid'   => $ok,
	'verify_ms'     => $ms,
	'verify_ms_med' => $ms[ (int) floor( ( count( $ms ) - 1 ) / 2 ) ],
	'mem_peak_mb'   => round( memory_get_peak_usage( true ) / 1048576, 1 ),
), JSON_PRETTY_PRINT ) );
