<?php
/**
 * `senroflux/surecart-product-status`: publish or unpublish a SureCart product.
 *
 * SureCart's own `update-product` ability changes only name, description and
 * metadata, so a product's draft/published state has no native ability. The
 * `status` attribute (`published` / `draft`) is real on the product API:
 * SureCart's own sync reads it (`Sync/PostSyncService::getPostStatusFromModel`)
 * and writes it when importing from WooCommerce
 * (`Sync/WooCommerce/WooCommerceProductMapper::mapStatus`). This polyfill
 * PATCHes it through `Product::update()`, the same call the native
 * `update-product` ability makes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms\SureCart;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * @internal
 */
final class SureCartProductStatus {

	public const TEMPLATE = 'surecart-product-status';

	/** SureCart's own capability for editing products (`UpdateProduct::check_permission()`). */
	public const CAPABILITY = 'edit_sc_products';

	public const PUBLISHED = 'published';

	public const DRAFT = 'draft';

	/**
	 * Register the ability.
	 *
	 * @param string              $category    Ability category.
	 * @param array<string,mixed> $annotations Ability annotations.
	 */
	public static function register( string $category, array $annotations ): void {
		wp_register_ability(
			'senroflux/' . self::TEMPLATE,
			array(
				'label'               => __( 'Set SureCart product status', 'senroflux' ),
				'description'         => __( 'Publish a SureCart product or put it back to draft. SureCart\'s own update ability cannot change status.', 'senroflux' ),
				'category'            => $category,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array( 'type' => 'string' ),
						'status' => array(
							'type' => 'string',
							'enum' => array( self::PUBLISHED, self::DRAFT ),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'success', 'product' ),
					'additionalProperties' => false,
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'product' => array(
							'type'                 => 'object',
							'required'             => array( 'id', 'status' ),
							'additionalProperties' => false,
							'properties'           => array(
								'id'     => array( 'type' => 'string' ),
								'status' => array( 'type' => 'string' ),
							),
						),
					),
				),
				'execute_callback'    => static fn ( $input = array() ) => self::execute( is_array( $input ) ? $input : array() ),
				'permission_callback' => static function ( $input = array() ) {
					unset( $input );

					// phpcs:ignore WordPress.WP.Capabilities.Unknown -- a real SureCart capability; unknown only to phpcs's core capability list.
					return function_exists( 'current_user_can' ) && current_user_can( self::CAPABILITY );
				},
				'meta'                => array(
					'annotations' => $annotations,
					'senroflux'   => array( 'hidden' => false ),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $input Ability input.
	 * @return array{success:bool,product:array{id:string,status:string}}|WP_Error
	 */
	public static function execute( array $input ): array|WP_Error {
		$id     = $input['id'] ?? null;
		$status = $input['status'] ?? null;

		if ( ! is_string( $id ) || ! SureCartRemote::validId( $id ) ) {
			return new WP_Error( 'invalid_id', __( 'A SureCart product id is required.', 'senroflux' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $status, array( self::PUBLISHED, self::DRAFT ), true ) ) {
			return new WP_Error( 'invalid_status', __( 'Status must be "published" or "draft".', 'senroflux' ), array( 'status' => 400 ) );
		}

		$product = SureCartRemote::update(
			'Product',
			array(
				'id'     => $id,
				'status' => $status,
			)
		);
		if ( $product instanceof WP_Error ) {
			return $product;
		}

		return array(
			'success' => true,
			'product' => array(
				'id'     => is_string( $product['id'] ?? null ) ? $product['id'] : $id,
				'status' => is_string( $product['status'] ?? null ) ? $product['status'] : $status,
			),
		);
	}
}
