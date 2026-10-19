<?php
/**
 * The queries Belayline's audit "Calls" screen runs on every load, at the current row count:
 * countFiltered + filtered (the default filter, 50 per page, joined to approvals), the unfiltered
 * newest-first page, the plain COUNT(*), and the cached-chain-status miss (a full verify) vs hit.
 *
 *   wp eval-file measure-audit-screen.php [<repeats>]
 */

use Belayline\Plugin\Audit\AuditFilter;
use Belayline\Plugin\Audit\AuditReader;
use Belayline\Plugin\Audit\ChainStatus;

global $wpdb;
$n      = max( 3, (int) ( $args[0] ?? 10 ) );
$reader = new AuditReader( $wpdb );
$filter = AuditFilter::fromRequest( array() );
$now    = time();

$time = static function ( callable $fn ) use ( $n ): array {
	$fn();
	$ms = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$t    = microtime( true );
		$fn();
		$ms[] = ( microtime( true ) - $t ) * 1000;
	}
	sort( $ms );
	return array( 'p50_ms' => round( $ms[ (int) floor( 0.5 * ( $n - 1 ) ) ], 1 ), 'p95_ms' => round( $ms[ (int) floor( 0.95 * ( $n - 1 ) ) ], 1 ), 'max_ms' => round( end( $ms ), 1 ) );
};

$out                    = array( 'audit_rows' => $reader->total(), 'default_filter_days' => $filter->days );
$out['count_star']      = $time( static fn () => $reader->total() );
$out['latest_50']       = $time( static fn () => $reader->latest( 50 ) );
$out['countFiltered']   = $time( static fn () => $reader->countFiltered( $filter, $now ) );
$out['filtered_50']     = $time( static fn () => $reader->filtered( $filter, 50, 0, $now ) );
$status                 = new ChainStatus( $reader );
$t                      = microtime( true );
$status->get( $now, true );
$out['chain_status_miss_ms'] = round( ( microtime( true ) - $t ) * 1000 );
$t                      = microtime( true );
$status->get( $now );
$out['chain_status_hit_ms']  = round( ( microtime( true ) - $t ) * 1000, 2 );
WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT ) );
