<?php
/**
 * Input rules for the WooCommerce write roles C4-W adds (order status,
 * product trash, stock, variation price, sale schedule). One place, used twice:
 * by {@see WooPlatform::validateCall()} BEFORE a call reaches its ability, and
 * by the `senroflux/` polyfills themselves, so a call that skips the pack gate
 * is refused the same way.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms\Woo;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * @internal
 */
final class WriteInput {

	/** Stock operations `stock-adjust` accepts. */
	public const STOCK_MODES = array( 'set', 'increase', 'decrease' );

	/** Stock statuses `stock-adjust` can set on a product that does not manage a quantity. */
	public const STOCK_STATUSES = array( 'instock', 'outofstock', 'onbackorder' );

	/**
	 * Order statuses whose transition has customer-facing side effects
	 * (emails, stock moves, money marked returned): irreversible, Tier 2.
	 * Mirrors SenroGate's OrderFulfillmentElevationRule::IRREVERSIBLE_STATUSES.
	 */
	public const NOTIFYING_ORDER_STATUSES = array( 'processing', 'completed', 'shipped', 'cancelled', 'refunded', 'on-hold', 'pending' );

	/**
	 * An order status slug as Woo reads it: lower-cased, trimmed, `wc-` prefix
	 * dropped (Woo's own ability strips the prefix, so `wc-completed` IS
	 * `completed` and must be classified as such).
	 *
	 * @param mixed $status The call's `status`.
	 */
	public static function normalizeOrderStatus( mixed $status ): ?string {
		if ( ! is_string( $status ) ) {
			return null;
		}
		$slug = strtolower( trim( $status ) );
		if ( 0 === strpos( $slug, 'wc-' ) ) {
			$slug = substr( $slug, 3 );
		}

		return '' === $slug ? null : $slug;
	}

	/**
	 * A `force` flag counts as OFF only when clearly false (absent, false, 0,
	 * '', '0', 'false'); any other shape is treated as a force delete —
	 * SenroGate's ForceDeleteElevationRule reads it the same way.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function isForceDelete( array $input ): bool {
		if ( ! array_key_exists( 'force', $input ) ) {
			return false;
		}
		$force = $input['force'];
		if ( false === $force || 0 === $force ) {
			return false;
		}

		return ! ( is_string( $force ) && in_array( strtolower( $force ), array( '', '0', 'false' ), true ) );
	}

	/**
	 * `woocommerce/order-update-status`: a status is required; the pack never
	 * rewrites it, Woo's own enum check names the unknown ones.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function orderStatus( array $input ): ?WP_Error {
		if ( null === self::normalizeOrderStatus( $input['status'] ?? null ) ) {
			return self::invalid( 'invalid_input', __( 'An order status is required.', 'senroflux' ) );
		}

		return null;
	}

	/**
	 * `woocommerce/product-delete`: trash only. A force delete is permanent
	 * and no restore brings it back, so it is refused whole, never downgraded.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function productTrash( array $input ): ?WP_Error {
		if ( self::isForceDelete( $input ) ) {
			return self::invalid( 'force_delete_refused', __( 'Permanent deletion is not allowed here. Trash the product instead (omit force) — a trashed product can be restored.', 'senroflux' ) );
		}

		return null;
	}

	/**
	 * `senroflux/stock-adjust`: either a quantity change (`mode` + `quantity`)
	 * or a `stock_status` for a product that does not manage a quantity, never both.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function stock( array $input ): ?WP_Error {
		if ( ! isset( $input['id'] ) || ! is_int( $input['id'] ) || $input['id'] <= 0 ) {
			return self::invalid( 'invalid_input', __( 'A product or variation id is required.', 'senroflux' ) );
		}

		$has_qty    = array_key_exists( 'quantity', $input ) || array_key_exists( 'mode', $input );
		$has_status = array_key_exists( 'stock_status', $input );

		if ( $has_qty === $has_status ) {
			return self::invalid( 'invalid_input', __( 'Give either a mode and quantity, or a stock_status — not both, and not neither.', 'senroflux' ) );
		}

		if ( $has_status ) {
			if ( ! is_string( $input['stock_status'] ) || ! in_array( $input['stock_status'], self::STOCK_STATUSES, true ) ) {
				return self::invalid( 'invalid_input', __( 'stock_status must be instock, outofstock or onbackorder.', 'senroflux' ) );
			}

			return null;
		}

		if ( ! is_string( $input['mode'] ?? null ) || ! in_array( $input['mode'], self::STOCK_MODES, true ) ) {
			return self::invalid( 'invalid_input', __( 'mode must be set, increase or decrease.', 'senroflux' ) );
		}
		if ( ! isset( $input['quantity'] ) || ! is_int( $input['quantity'] ) || $input['quantity'] < 0 ) {
			return self::invalid( 'invalid_input', __( 'quantity must be a whole number, zero or more.', 'senroflux' ) );
		}

		return null;
	}

	/**
	 * `senroflux/variation-price-update`: the parent and the variation, and at
	 * least one price.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function variationPrice( array $input ): ?WP_Error {
		foreach ( array( 'product_id', 'variation_id' ) as $key ) {
			if ( ! isset( $input[ $key ] ) || ! is_int( $input[ $key ] ) || $input[ $key ] <= 0 ) {
				return self::invalid( 'invalid_input', __( 'Both product_id (the parent) and variation_id are required.', 'senroflux' ) );
			}
		}
		if ( ! array_key_exists( 'regular_price', $input ) && ! array_key_exists( 'sale_price', $input ) ) {
			return self::invalid( 'invalid_input', __( 'Give a regular_price, a sale_price, or both.', 'senroflux' ) );
		}

		return self::prices( $input, true );
	}

	/**
	 * `senroflux/sale-schedule`: a sale price with an optional window, or an
	 * empty `sale_price` to clear the sale and its dates.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function saleSchedule( array $input ): ?WP_Error {
		if ( ! isset( $input['id'] ) || ! is_int( $input['id'] ) || $input['id'] <= 0 ) {
			return self::invalid( 'invalid_input', __( 'A product or variation id is required.', 'senroflux' ) );
		}
		if ( ! array_key_exists( 'sale_price', $input ) ) {
			return self::invalid( 'invalid_input', __( 'sale_price is required (an empty string clears the sale).', 'senroflux' ) );
		}

		$error = self::prices( $input, false );
		if ( null !== $error ) {
			return $error;
		}

		$from  = self::dateOf( $input, 'date_on_sale_from' );
		$to    = self::dateOf( $input, 'date_on_sale_to' );
		$clear = '' === self::priceString( $input['sale_price'] );

		if ( false === $from || false === $to ) {
			return self::invalid( 'invalid_date', __( 'Sale dates must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, in the store\'s timezone.', 'senroflux' ) );
		}
		if ( $clear && ( null !== $from || null !== $to ) ) {
			return self::invalid( 'invalid_input', __( 'Clearing a sale takes no dates.', 'senroflux' ) );
		}
		if ( null !== $from && null !== $to && $to < $from ) {
			return self::invalid( 'invalid_date', __( 'The sale cannot end before it starts.', 'senroflux' ) );
		}

		return null;
	}

	/**
	 * A date-only end is the end of that day, the way Woo's own editor stores
	 * it; every other value is passed through unchanged.
	 *
	 * @param string $value A validated date string.
	 * @param bool   $end   Whether it is the sale's end.
	 */
	public static function expandDate( string $value, bool $end ): string {
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value . ( $end ? ' 23:59:59' : ' 00:00:00' );
		}

		return $value;
	}

	/**
	 * The price rule shared with products: a plain non-negative decimal, and
	 * (when both are in the call) a sale strictly below the regular price —
	 * Woo silently blanks a sale at or above it, which would look like success.
	 *
	 * @param array<string,mixed> $input        Call input.
	 * @param bool                $refuse_blank_regular Whether an empty regular price is refused.
	 */
	private static function prices( array $input, bool $refuse_blank_regular ): ?WP_Error {
		$regular = null;
		$sale    = null;

		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = self::priceString( $input[ $key ] );
			if ( null === $value || ( '' !== $value && 1 !== preg_match( '/^\d+(\.\d+)?$/', $value ) ) ) {
				return self::invalid( 'invalid_price', __( 'A price must be a plain non-negative number such as 19.99.', 'senroflux' ) );
			}
			if ( 'regular_price' === $key ) {
				$regular = $value;
			} else {
				$sale = $value;
			}
		}

		if ( $refuse_blank_regular && '' === $regular ) {
			return self::invalid( 'invalid_price', __( 'regular_price cannot be blank.', 'senroflux' ) );
		}
		if ( null !== $regular && null !== $sale && '' !== $sale && (float) $sale >= (float) $regular ) {
			return self::invalid( 'sale_not_below_regular', __( 'The sale price must be below the regular price.', 'senroflux' ) );
		}

		return null;
	}

	/**
	 * @param mixed $value A price as sent: string or number.
	 */
	private static function priceString( mixed $value ): ?string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value < 0 ? null : (string) $value;
		}

		return is_string( $value ) ? trim( $value ) : null;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @param string              $key   Date key.
	 * @return string|false|null Sortable normalised date, null when absent, false when malformed.
	 */
	private static function dateOf( array $input, string $key ): string|false|null {
		if ( ! array_key_exists( $key, $input ) || null === $input[ $key ] ) {
			return null;
		}
		$value = $input[ $key ];
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2}:\d{2})?$/', trim( $value ), $m ) ) {
			return false;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return false;
		}

		return self::expandDate( trim( $value ), 'date_on_sale_to' === $key );
	}

	private static function invalid( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
