<?php
/**
 * Links built from WordPress URL helpers (no database reads).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class WpLinks implements Links {

	/** {@inheritDoc} */
	public function edit( int $id ): ?string {
		if ( ! function_exists( 'get_edit_post_link' ) ) {
			return null;
		}
		$url = get_edit_post_link( $id, 'raw' );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/** {@inheritDoc} */
	public function view( int $id, bool $published ): ?string {
		$helper = $published ? 'get_permalink' : 'get_preview_post_link';
		if ( ! function_exists( $helper ) ) {
			return null;
		}
		$url = $published ? get_permalink( $id ) : get_preview_post_link( $id );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/** {@inheritDoc} */
	public function title( int $id ): string {
		return function_exists( 'get_the_title' ) ? (string) get_the_title( $id ) : '';
	}

	/** {@inheritDoc} */
	public function couponCode( int $id ): string {
		if ( ! function_exists( 'wc_get_coupon_code_by_id' ) ) {
			return '';
		}

		return (string) wc_get_coupon_code_by_id( $id );
	}
}
