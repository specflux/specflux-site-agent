<?php
/**
 * Store report card — a table of the figures.
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

final class ReportPresenter implements Presenter {

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return array_key_exists( 'order_count', $response ) && array_key_exists( 'gross_sales', $response );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$currency = Fmt::text( $response['currency'] ?? '' );
		$money    = static fn ( string $key ): string => trim( Fmt::text( $response[ $key ] ?? '' ) . ' ' . $currency );
		$low      = is_array( $response['low_stock_products'] ?? null ) ? count( $response['low_stock_products'] ) : 0;

		return array(
			'type'    => 'table',
			'title'   => trim( sprintf( /* translators: 1: start date, 2: end date. */ __( 'Store report %1$s to %2$s', 'senroflux' ), Fmt::text( $response['from'] ?? '' ), Fmt::text( $response['to'] ?? '' ) ) ),
			'columns' => array( __( 'Measure', 'senroflux' ), __( 'Value', 'senroflux' ) ),
			'rows'    => array(
				array( __( 'Orders', 'senroflux' ), Fmt::text( $response['order_count'] ) ),
				array( __( 'Gross sales', 'senroflux' ), $money( 'gross_sales' ) ),
				array( __( 'Net sales', 'senroflux' ), $money( 'net_sales' ) ),
				array( __( 'Refunds', 'senroflux' ), $money( 'refunds_total' ) ),
				array( __( 'Low-stock products', 'senroflux' ), (string) $low ),
			),
		);
	}
}
