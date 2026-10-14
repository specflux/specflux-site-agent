<?php
/**
 * ObjectReader backed by WordPress and WooCommerce read APIs.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Approval\Diff;

use Specflux\SiteAgent\Packs\Site\Navigation;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

final class WpObjectReader implements ObjectReader {

	/** {@inheritDoc} */
	public function post( int $id ): ?array {
		if ( ! $this->mayRead( $id ) || ! function_exists( 'get_post' ) ) {
			return null;
		}
		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return null;
		}

		return array(
			'title'   => (string) $post->post_title,
			'status'  => (string) $post->post_status,
			'slug'    => (string) $post->post_name,
			'excerpt' => (string) $post->post_excerpt,
			'content' => (string) $post->post_content,
		);
	}

	/** {@inheritDoc} */
	public function product( int $id ): ?array {
		$post = $this->post( $id );
		if ( null === $post || ! function_exists( 'wc_get_product' ) ) {
			return $post;
		}
		$product = wc_get_product( $id );
		if ( ! is_object( $product ) ) {
			return $post;
		}

		return $post + array(
			'regular_price' => $this->call( $product, 'get_regular_price' ),
			'sale_price'    => $this->call( $product, 'get_sale_price' ),
		);
	}

	/** {@inheritDoc} */
	public function coupon( int $id ): ?array {
		if ( ! $this->mayRead( $id ) || ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}
		$coupon = new \WC_Coupon( $id );

		return array( 'amount' => $this->call( $coupon, 'get_amount' ) );
	}

	/** {@inheritDoc} */
	public function menuLabels(): array {
		$current = Navigation::currentItemsForSummary();
		$labels  = array();
		foreach ( $current['items'] as $item ) {
			if ( is_array( $item ) && is_string( $item['label'] ?? null ) ) {
				$labels[] = $item['label'];
			}
		}

		return $labels;
	}

	/**
	 * Only show current values to someone who could edit the object anyway.
	 *
	 * @param int $id Post id.
	 */
	private function mayRead( int $id ): bool {
		return $id > 0 && function_exists( 'current_user_can' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * A guarded getter call on a duck-typed WooCommerce object.
	 *
	 * @param object $target Object.
	 * @param string $method Getter name.
	 */
	private function call( object $target, string $method ): string {
		if ( ! method_exists( $target, $method ) ) {
			return '';
		}
		$value = $target->{$method}();

		return is_scalar( $value ) ? (string) $value : '';
	}
}
