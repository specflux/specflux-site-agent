<?php
/**
 * EXPLAIN the two SELECTs Belayline's audit Calls screen issues (captured live via $wpdb->queries,
 * so the SQL is exactly what the plugin sent), plus the index list of the audit table.
 *
 *   wp eval-file explain-audit.php
 */

use Belayline\Plugin\Audit\AuditFilter;
use Belayline\Plugin\Audit\AuditReader;

global $wpdb;
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}
$wpdb->queries = array();
$reader        = new AuditReader( $wpdb );
$filter        = AuditFilter::fromRequest( array() );
$reader->countFiltered( $filter, time() );
$reader->filtered( $filter, 50, 0, time() );
$out = array( 'indexes' => $wpdb->get_results( "SHOW INDEX FROM {$reader->table()}", ARRAY_A ) );
foreach ( $wpdb->queries as $q ) {
	if ( stripos( $q[0], 'belayline_audit_log' ) !== false && stripos( ltrim( $q[0] ), 'SELECT' ) === 0 && stripos( $q[0], 'LEFT JOIN' ) !== false ) {
		$out['sql'][]     = preg_replace( '/\s+/', ' ', $q[0] );
		$out['explain'][] = $wpdb->get_results( 'EXPLAIN ' . $q[0], ARRAY_A );
	}
}
$out['indexes'] = array_map( static fn ( $i ) => $i['Key_name'] . '(' . $i['Column_name'] . ')', $out['indexes'] );
WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT ) );
