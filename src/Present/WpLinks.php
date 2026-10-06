<?php
/**
 * Links built from WordPress URL helpers (no database reads).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class WpLinks implements Links {

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Object id.
	 */
	public function edit( int $id ): string {
		return function_exists( 'admin_url' ) ? admin_url( 'post.php?post=' . $id . '&action=edit' ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int  $id        Object id.
	 * @param bool $published Whether the response says it is published.
	 */
	public function view( int $id, bool $published ): string {
		if ( ! function_exists( 'home_url' ) ) {
			return '';
		}

		return home_url( '/?p=' . $id . ( $published ? '' : '&preview=true' ) );
	}
}
