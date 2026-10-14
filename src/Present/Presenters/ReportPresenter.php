<?php
/**
 * Store report card — a table of the figures.
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
			'title'   => trim( sprintf( /* translators: 1: start date, 2: end date. */ __( 'Store report %1$s to %2$s', 'specflux-site-agent' ), Fmt::text( $response['from'] ?? '' ), Fmt::text( $response['to'] ?? '' ) ) ),
			'columns' => array( __( 'Measure', 'specflux-site-agent' ), __( 'Value', 'specflux-site-agent' ) ),
			'rows'    => array(
				array( __( 'Orders', 'specflux-site-agent' ), Fmt::text( $response['order_count'] ) ),
				array( __( 'Gross sales', 'specflux-site-agent' ), $money( 'gross_sales' ) ),
				array( __( 'Net sales', 'specflux-site-agent' ), $money( 'net_sales' ) ),
				array( __( 'Refunds', 'specflux-site-agent' ), $money( 'refunds_total' ) ),
				array( __( 'Low-stock products', 'specflux-site-agent' ), (string) $low ),
			),
		);
	}
}
