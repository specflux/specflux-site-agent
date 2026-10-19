<?php
/**
 * Top the Belayline audit log up to <target-total> rows, using Belayline's OWN append path
 * (Belayline\Plugin\Audit\WpdbAuditSink::append(): advisory lock, read last entry_hash, insert
 * with the next hash). Nothing is inserted by hand, so the result is a genuine hash chain.
 * Rows alternate decision / execution records over a few abilities and packs, like real traffic.
 *
 *   wp eval-file seed-audit.php <target-total>
 */

use Belayline\Audit\AuditDecision;
use Belayline\Audit\AuditRecord;
use Belayline\Plugin\Audit\WpdbAuditSink;

$target = (int) ( $args[0] ?? 0 );
if ( $target < 1 ) {
	WP_CLI::error( 'usage: seed-audit.php <target-total>' );
}

global $wpdb;
$sink  = new WpdbAuditSink( $wpdb );
$table = $sink->table();
$have  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$abil  = array( 'specflux-e2e/read-thing', 'specflux-e2e/list-things', 'specflux-e2e/search-things', 'woocommerce/orders-list', 'core/get-site-info' );
$start = microtime( true );
$actor = array( 'token_id' => 'role:administrator', 'wp_user' => 1 );

for ( $i = $have; $i < $target; $i++ ) {
	$ability = $abil[ $i % count( $abil ) ];
	$corr    = 'specflux:run:' . ( 100000 + intdiv( $i, 10 ) );
	$ts      = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( ( $target - $i ) * 2 ) );
	$id      = 'evt_' . wp_generate_uuid4();
	$record  = 0 === $i % 2
		? AuditRecord::decision( $id, $ts, $corr, 'specflux-e2e-pack', $actor, $ability, 0, array( 'query' => 'item ' . $i ), AuditDecision::Allowed, null, array(), '127.0.0.1' )
		: AuditRecord::execution( $id, $ts, $corr, 'specflux-e2e-pack', $actor, $ability, 0, array( 'query' => 'item ' . $i ), 'success', array(), '127.0.0.1' );
	$sink->append( $record );
	if ( 0 === ( $i + 1 ) % 5000 ) {
		WP_CLI::log( sprintf( 'audit %d/%d (%.0fs, %.0f rows/s)', $i + 1, $target, microtime( true ) - $start, ( $i + 1 - $have ) / max( 0.001, microtime( true ) - $start ) ) );
	}
}
WP_CLI::success( sprintf( 'audit rows: %d, %.0fs', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), microtime( true ) - $start ) );
