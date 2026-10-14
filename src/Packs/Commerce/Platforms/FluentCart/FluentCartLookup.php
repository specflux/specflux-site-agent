<?php
/**
 * Read-only lookups into FluentCart's own tables (C4-F).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Commerce\Platforms\FluentCart;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * One row by id from a `fct_*` table, plus the store's money formatting
 * (FluentCart stores money as integer cents: `fct_orders.total_paid`,
 * `fct_product_variations.item_price`; a fixed coupon's `amount` too).
 *
 * Read-only and guarded: a missing table or row is `null`, never a fatal.
 *
 * @internal
 */
final class FluentCartLookup {

	/**
	 * @param string $table Table without prefix, e.g. `fct_orders`.
	 * @param int    $id    Row id.
	 * @return array<string,mixed>|null
	 */
	public static function row( string $table, int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 || ! $wpdb instanceof \wpdb ) {
			return null;
		}

		$name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $name is $wpdb->prefix plus a fixed FluentCart table literal from the callers (never user input), bound as %i; one row by id, read once per approval card or report row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $name, $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A coupon's current status (`active` / `inactive`), or null when unknown.
	 *
	 * @param int $coupon_id Coupon id.
	 */
	public static function couponStatus( int $coupon_id ): ?string {
		$row = self::row( 'fct_coupons', $coupon_id );

		return ( null !== $row && is_string( $row['status'] ?? null ) ) ? strtolower( $row['status'] ) : null;
	}

	/**
	 * Cents as a plain decimal string with the currency code, e.g. `12.50 USD`.
	 *
	 * @param mixed  $cents    Integer cents.
	 * @param string $currency ISO code, or '' for none.
	 */
	public static function money( mixed $cents, string $currency = '' ): string {
		if ( ! is_numeric( $cents ) ) {
			return '';
		}

		$text = number_format( ( (int) $cents ) / 100, 2, '.', '' );

		return '' !== $currency ? $text . ' ' . $currency : $text;
	}
}
