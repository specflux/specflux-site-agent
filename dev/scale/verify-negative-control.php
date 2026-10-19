<?php
/**
 * Negative control for the verify timing: prove the verifier can FAIL on this 100k-row chain. Flips one
 * byte of one row's record_json in the middle of the log, runs AuditReader::verifyChain(), then restores
 * the row and verifies again. Prints before / tampered / restored results.
 *
 *   wp eval-file verify-negative-control.php
 */

use Belayline\Plugin\Audit\AuditReader;

global $wpdb;
$reader = new AuditReader( $wpdb );
$table  = $reader->table();
$id     = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id LIMIT 1 OFFSET " . intdiv( $reader->total(), 2 ) );
$orig   = (string) $wpdb->get_var( "SELECT record_json FROM {$table} WHERE id = {$id}" );
$out    = array( 'row_id' => $id, 'before' => $reader->verifyChain() );
try {
	$wpdb->update( $table, array( 'record_json' => substr_replace( $orig, 'X', 5, 1 ) ), array( 'id' => $id ) );
	$t                 = microtime( true );
	$out['tampered']   = $reader->verifyChain();
	$out['tampered_ms'] = round( ( microtime( true ) - $t ) * 1000 );
} finally {
	$wpdb->update( $table, array( 'record_json' => $orig ), array( 'id' => $id ) );
}
$out['restored'] = $reader->verifyChain();
WP_CLI::log( wp_json_encode( $out ) );
