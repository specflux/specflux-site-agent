<?php
/**
 * Read-only access to the current state of the objects an approval touches.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Approval\Diff;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The only seam the approval diff uses to touch WordPress, so tests inject a
 * fake. Every method reads and returns; none writes. A null result means
 * "unknown or not readable by this user": the diff then omits that part.
 */
interface ObjectReader {

	/**
	 * A post or page: title, status, slug, excerpt, content (raw).
	 *
	 * @param int $id Post id.
	 * @return array<string,string>|null
	 */
	public function post( int $id ): ?array;

	/**
	 * A WooCommerce product: title, status, slug, excerpt, content,
	 * regular_price, sale_price.
	 *
	 * @param int $id Product id.
	 * @return array<string,string>|null
	 */
	public function product( int $id ): ?array;

	/**
	 * A WooCommerce coupon: amount.
	 *
	 * @param int $id Coupon id.
	 * @return array<string,string>|null
	 */
	public function coupon( int $id ): ?array;

	/**
	 * The labels of the site's current navigation, in order.
	 *
	 * @return list<string>
	 */
	public function menuLabels(): array;
}
