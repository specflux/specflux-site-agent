<?php
/**
 * Product card — name, price, stock, status.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Present\Presenters;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SenroFlux\Present\Fmt;
use Specflux\SenroFlux\Present\Links;
use Specflux\SenroFlux\Present\Presenter;

final class ProductPresenter implements Presenter {

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return is_string( $response['name'] ?? null )
			&& ( array_key_exists( 'regular_price', $response ) || array_key_exists( 'price', $response ) );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$sale   = Fmt::text( $response['sale_price'] ?? '' );
		$price  = '' !== $sale ? $sale : Fmt::first( $response, array( 'price', 'regular_price' ) );
		$stock  = Fmt::text( $response['stock_status'] ?? '' );
		$qty    = $response['stock_quantity'] ?? null;
		$fields = array();
		if ( '' !== $price ) {
			$fields[] = array(
				'label' => __( 'Price', 'senroflux' ),
				'value' => $price,
			);
		}
		if ( '' !== $stock ) {
			$fields[] = array(
				'label' => __( 'Stock', 'senroflux' ),
				'value' => is_int( $qty ) ? sprintf( '%s (%d)', $stock, $qty ) : $stock,
			);
		}
		$id        = is_int( $response['id'] ?? null ) ? $response['id'] : 0;
		$published = 'publish' === Fmt::text( $response['status'] ?? '' );

		return array(
			'type'   => 'product',
			'title'  => Fmt::text( $response['name'] ),
			'status' => Fmt::text( $response['status'] ?? '' ),
			'fields' => $fields,
			// Edit plus a view (published) or preview (otherwise) link, so the
			// preview pane can show a product like a post or page.
			'links'  => $id > 0 ? Fmt::links(
				array(
					Fmt::link( __( 'Edit', 'senroflux' ), $links->edit( $id ), 'edit' ),
					$published
						? Fmt::link( __( 'View', 'senroflux' ), $links->view( $id, true ), 'view' )
						: Fmt::link( __( 'Preview', 'senroflux' ), $links->view( $id, false ), 'preview' ),
				)
			) : array(),
		);
	}
}
