<?php
/**
 * Navigation card — the item list.
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

final class NavigationPresenter implements Presenter {

	private const MAX_ITEMS = 25;

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return is_string( $response['kind'] ?? null ) && Fmt::isRows( $response['items'] ?? null );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$rows  = (array) $response['items'];
		$items = array();
		foreach ( array_slice( $rows, 0, self::MAX_ITEMS ) as $row ) {
			$items[] = array(
				'label'  => Fmt::first( $row, array( 'label', 'title', 'name' ) ),
				'detail' => Fmt::text( $row['url'] ?? '' ),
			);
		}

		return array(
			'type'  => 'list',
			'title' => __( 'Navigation', 'senroflux' ),
			'count' => count( $rows ),
			'items' => $items,
			'more'  => max( 0, count( $rows ) - self::MAX_ITEMS ),
		);
	}
}
