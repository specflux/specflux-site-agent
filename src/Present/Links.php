<?php
/**
 * Link builder for result cards — URLs built from ids, never fetched.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * A presenter never fetches (0.4 S3.1): it formats the response plus links
 * built from ids. This is the only seam that knows how a link is built, so
 * tests inject a fake and the WordPress one is pure string construction.
 */
interface Links {

	/**
	 * The wp-admin edit URL for a post-like object (post, page, product,
	 * coupon, attachment).
	 *
	 * @param int $id Object id.
	 */
	public function edit( int $id ): string;

	/**
	 * The front-end URL for an object: its permalink-redirecting `?p=` form
	 * once published, the draft-preview form otherwise.
	 *
	 * @param int  $id        Object id.
	 * @param bool $published Whether the response says it is published.
	 */
	public function view( int $id, bool $published ): string;
}
