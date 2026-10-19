<?php
/**
 * Seed WordPress content at volume: posts and WooCommerce orders.
 *
 *   wp eval-file seed-content.php posts  <target-total>   # tops posts tagged _scale_seed up to <target-total>
 *   wp eval-file seed-content.php orders <target-total> [<from-index> <to-index>]
 *
 * Idempotent: it counts what is already seeded and only adds the difference, so it can be re-run
 * (or resumed after an interruption). Posts go through wp_insert_post(); orders through
 * wc_create_order() + a real line item + calculate_totals() + save(), i.e. WooCommerce's own CRUD
 * (HPOS tables when HPOS is on). Cache invalidation and term counting are deferred for speed only.
 */

$kind   = $args[0] ?? '';
$target = (int) ( $args[1] ?? 0 );
if ( ! in_array( $kind, array( 'posts', 'orders' ), true ) || $target < 1 ) {
	WP_CLI::error( 'usage: seed-content.php posts|orders <target-total>' );
}

global $wpdb;
wp_suspend_cache_invalidation( true );
wp_defer_term_counting( true );
wp_defer_comment_counting( true );
wp_set_current_user( 1 );
// Seeding must not try to send mail (WooCommerce emails on every status change; no MTA in wp-env).
add_filter( 'pre_wp_mail', '__return_true', 1 );
$start = microtime( true );
$words = explode( ' ', 'the quick brown fox jumps over a lazy dog while store owners review orders products shipping stock pages posts and launch plans for next season' );

if ( 'posts' === $kind ) {
	$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_scale_seed'" );
	for ( $i = $have; $i < $target; $i++ ) {
		shuffle( $words );
		wp_insert_post(
			array(
				'post_title'   => 'Scale post ' . ( $i + 1 ) . ' ' . implode( ' ', array_slice( $words, 0, 4 ) ),
				'post_content' => '<!-- wp:paragraph --><p>' . str_repeat( implode( ' ', $words ) . '. ', 12 ) . '</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => 1,
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() - ( $i * 600 ) ),
				'meta_input'   => array( '_scale_seed' => '1' ),
			)
		);
		if ( 0 === ( $i + 1 ) % 1000 ) {
			WP_CLI::log( sprintf( 'posts %d/%d (%.0fs)', $i + 1, $target, microtime( true ) - $start ) );
		}
	}
	WP_CLI::success( sprintf( 'posts seeded: %d total tagged, %.0fs', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_scale_seed'" ), microtime( true ) - $start ) );
	return;
}

// Orders: make a small catalogue once, then orders against it.
$product_ids = get_option( 'specflux_scale_product_ids', array() );
if ( empty( $product_ids ) ) {
	$product_ids = array();
	for ( $p = 1; $p <= 25; $p++ ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Scale product ' . $p );
		$product->set_regular_price( (string) ( 5 + $p * 3 ) );
		$product->set_status( 'publish' );
		$product_ids[] = $product->save();
	}
	update_option( 'specflux_scale_product_ids', $product_ids, false );
}
$statuses = array( 'completed', 'completed', 'completed', 'processing', 'on-hold', 'cancelled', 'refunded' );
// Optional explicit index range (`orders <target> <from> <to>`) so several processes can seed disjoint
// slices in parallel; without it, resume from however many orders exist.
$from = isset( $args[2] ) ? (int) $args[2] : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" );
$to   = isset( $args[3] ) ? (int) $args[3] : $target;
for ( $i = $from; $i < $to; $i++ ) {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	$order->add_product( wc_get_product( $product_ids[ $i % count( $product_ids ) ] ), 1 + ( $i % 3 ) );
	$order->set_billing_first_name( 'Buyer' . $i );
	$order->set_billing_last_name( 'Scale' );
	$order->set_billing_email( 'buyer' . $i . '@example.test' );
	$order->set_billing_country( 'US' );
	$order->set_date_created( time() - ( $i * 300 ) );
	$order->calculate_totals( false );
	$order->set_status( $statuses[ $i % count( $statuses ) ] );
	$order->save();
	if ( 0 === ( $i + 1 ) % 500 ) {
		WP_CLI::log( sprintf( 'orders %d/%d (%.0fs)', $i + 1, $target, microtime( true ) - $start ) );
	}
}
WP_CLI::success( sprintf( 'orders seeded: %d shop_order rows, %.0fs', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order'" ), microtime( true ) - $start ) );
