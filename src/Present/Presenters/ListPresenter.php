<?php
/**
 * List-read card — a count plus the first five items.
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

final class ListPresenter implements Presenter {

	private const SHOWN = 5;

	/** Keys that hold the rows of the bundled list reads, in preference order. */
	private const ROW_KEYS = array( 'posts', 'products', 'results', 'attachments', 'terms', 'items', 'orders', 'coupons' );

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return null !== $this->rowsKey( $response );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$key   = (string) $this->rowsKey( $response );
		$rows  = (array) $response[ $key ];
		$total = is_int( $response['total'] ?? null ) && $response['total'] >= count( $rows ) ? $response['total'] : count( $rows );
		$items = array();
		foreach ( array_slice( $rows, 0, self::SHOWN ) as $row ) {
			$item  = array(
				'label'  => Fmt::first( $row, array( 'title_raw', 'title_rendered', 'name', 'title', 'label', 'code', 'filename', 'slug', 'alt' ) ),
				'detail' => Fmt::first( $row, array( 'status', 'regular_price', 'price', 'count', 'alt' ) ),
			);
			$thumb = Fmt::url( $row['url'] ?? null );
			if ( '' === $item['label'] ) {
				$item['label'] = is_int( $row['id'] ?? null ) ? '#' . $row['id'] : __( 'Item', 'senroflux' );
			}
			if ( '' !== $thumb && preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', $thumb ) ) {
				$item['thumbnail_url'] = $thumb;
			}
			$items[] = $item;
		}

		return array(
			'type'  => 'list',
			'title' => ucfirst( str_replace( '_', ' ', $key ) ),
			'count' => $total,
			'items' => $items,
			'more'  => max( 0, $total - count( $items ) ),
		);
	}

	/**
	 * The key whose value is the response's rows, or null when there is no
	 * single clear one.
	 *
	 * @param array<string,mixed> $response Response.
	 */
	private function rowsKey( array $response ): ?string {
		foreach ( self::ROW_KEYS as $key ) {
			$value = $response[ $key ] ?? null;
			if ( Fmt::isRows( $value ) || array() === $value ) {
				return $key;
			}
		}

		$found = array_keys( array_filter( $response, array( Fmt::class, 'isRows' ) ) );

		// An unknown shape is a list read only when the rows are its only
		// structure; any other nesting goes to the fallback table.
		$others = array_filter( $response, static fn ( $v, $k ): bool => ! in_array( $k, $found, true ) && ! is_scalar( $v ) && null !== $v, ARRAY_FILTER_USE_BOTH );

		return 1 === count( $found ) && array() === $others ? (string) $found[0] : null;
	}
}
