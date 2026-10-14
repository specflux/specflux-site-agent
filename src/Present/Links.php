<?php
/**
 * Link builder for result cards — local reads by id through core helpers.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A presenter reads only LOCAL objects by id, through core helpers (0.4
 * S3.1): never abilities or remote services. This is the only seam that
 * touches WordPress, so tests inject a fake. A null link means "omit it".
 */
interface Links {

	/**
	 * The wp-admin edit URL for a post-like object (post, page, product,
	 * coupon, attachment).
	 *
	 * @param int $id Object id.
	 */
	public function edit( int $id ): ?string;

	/**
	 * The front-end URL for an object: its permalink-redirecting `?p=` form
	 * once published, the draft-preview form otherwise.
	 *
	 * @param int  $id        Object id.
	 * @param bool $published Whether the response says it is published.
	 */
	public function view( int $id, bool $published ): ?string;

	/**
	 * The stored title of a local post-like object; '' when unknown.
	 *
	 * @param int $id Object id.
	 */
	public function title( int $id ): string;

	/**
	 * The code of a local coupon; '' when unknown or WooCommerce is absent.
	 *
	 * @param int $id Coupon id.
	 */
	public function couponCode( int $id ): string;
}
