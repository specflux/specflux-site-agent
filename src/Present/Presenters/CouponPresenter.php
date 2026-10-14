<?php
/**
 * Coupon card — code, amount, enabled.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Present\Presenters;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SiteAgent\Present\Fmt;
use Specflux\SiteAgent\Present\Links;
use Specflux\SiteAgent\Present\Presenter;

/**
 * coupon-create and coupon-enable answer `{coupon_id}` only; the ability
 * name says which state that leaves the coupon in (create writes a draft,
 * enable publishes it), so no lookup is needed.
 */
final class CouponPresenter implements Presenter {

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		if ( is_int( $response['coupon_id'] ?? null ) ) {
			return in_array( $ability, array( 'specflux/coupon-create', 'specflux/coupon-enable' ), true );
		}

		return is_string( $response['code'] ?? null ) && array_key_exists( 'amount', $response );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$id     = (int) ( $response['coupon_id'] ?? $response['id'] ?? 0 );
		$fields = array();
		$code   = Fmt::text( $response['code'] ?? '' );
		if ( '' === $code && $id > 0 ) {
			$code = Fmt::text( $links->couponCode( $id ) );
		}
		if ( array_key_exists( 'amount', $response ) ) {
			$fields[] = array(
				'label' => __( 'Amount', 'specflux-site-agent' ),
				'value' => Fmt::text( $response['amount'] ),
			);
		}

		if ( array_key_exists( 'enabled', $response ) ) {
			$enabled = (bool) $response['enabled'];
		} elseif ( is_string( $response['status'] ?? null ) ) {
			$enabled = 'publish' === $response['status'];
		} else {
			$enabled = 'specflux/coupon-enable' === $ability;
		}
		$fields[] = array(
			'label' => __( 'Enabled', 'specflux-site-agent' ),
			'value' => Fmt::text( $enabled ),
		);

		return array(
			'type'   => 'coupon',
			'title'  => '' !== $code ? $code : sprintf( /* translators: %d: coupon id. */ __( 'Coupon #%d', 'specflux-site-agent' ), $id ),
			'fields' => $fields,
			'links'  => $id > 0 ? Fmt::links( array( Fmt::link( __( 'Edit', 'specflux-site-agent' ), $links->edit( $id ), 'edit' ) ) ) : array(),
		);
	}
}
