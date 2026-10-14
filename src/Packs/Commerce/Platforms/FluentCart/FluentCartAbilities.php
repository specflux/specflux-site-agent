<?php
/**
 * The `specflux/fluentcart-*` polyfill abilities (C4-F).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Commerce\Platforms\FluentCart;

use Specflux\SiteAgent\Packs\Commerce\Abilities;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * One ability per FluentCart commerce role, so the pack works whether or not
 * FluentCart's MCP module (off by default) registered its own `fluent-cart/`
 * abilities.
 *
 * Writes go through FluentCart's own REST routes in-process
 * ({@see FluentCartRest}), so FluentCart's policies, validation and hooks run:
 *  - `POST /fluent-cart/products`, `POST /products/{id}/pricing` (partial post
 *    update when no `detail`/`variants` are sent: `ProductController::update`
 *    -> `ProductResource::partialUpdate`), `POST /products/variants/{id}`
 *    (price), `GET /products/{id}/pricing`: `app/Http/Routes/api.php:118,163,
 *    139,65` and the variants group below it; permissions `products/*`.
 *  - `POST /orders/{order_id}/refund`, `GET /orders/{order_id}`: `api.php:585,
 *    569`; permissions `orders/can_refund`, `orders/view`.
 *  - `POST|PUT /shipping/zones`, `POST /shipping/methods`:
 *    `app/Modules/Shipping/Http/shipping-api.php`; permission `store/sensitive`.
 *  - `POST|PUT /tax/country/rate`: `api.php:~907`; permission `store/sensitive`.
 *
 * Two exceptions, each for a stated reason:
 *  - Coupons call FluentCart's own `CouponTools::manageCoupon()` (the code its
 *    MCP `manage-coupon` runs, which works without the module being on). The
 *    REST `PUT /coupons/{id}` needs a full payload and its amount formatter
 *    corrupts a partial one (see the comment in `CouponTools::manageCoupon`).
 *  - The store report reads `fct_orders` directly: there is no REST route that
 *    returns the aggregate the pack's report needs.
 *
 * @internal
 */
final class FluentCartAbilities {

	/**
	 * The polyfill templates this class registers.
	 *
	 * @return list<string>
	 */
	public static function templates(): array {
		return array(
			'fluentcart-product-read',
			'fluentcart-order-read',
			'fluentcart-product-create',
			'fluentcart-product-update',
			'fluentcart-set-product-image',
			'fluentcart-coupon-create',
			'fluentcart-coupon-enable',
			'fluentcart-orders-refund',
			'fluentcart-shipping-zone-save',
			'fluentcart-tax-rate-save',
			'fluentcart-store-report',
		);
	}

	/**
	 * Register one polyfill by template (an unknown template registers nothing).
	 *
	 * @param string $template Polyfill template.
	 */
	public static function register( string $template ): void {
		match ( $template ) {
			'fluentcart-product-read' => self::registerProductRead(),
			'fluentcart-order-read' => self::registerOrderRead(),
			'fluentcart-product-create' => self::registerProductCreate(),
			'fluentcart-product-update' => self::registerProductUpdate(),
			'fluentcart-set-product-image' => self::registerSetProductImage(),
			'fluentcart-coupon-create' => self::registerCouponCreate(),
			'fluentcart-coupon-enable' => self::registerCouponEnable(),
			'fluentcart-orders-refund' => self::registerOrdersRefund(),
			'fluentcart-shipping-zone-save' => self::registerShippingZoneSave(),
			'fluentcart-tax-rate-save' => self::registerTaxRateSave(),
			'fluentcart-store-report' => self::registerStoreReport(),
			default => null,
		};
	}

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	private static function registerProductRead(): void {
		self::define(
			'fluentcart-product-read',
			__( 'Read a product', 'specflux-site-agent' ),
			__( 'Read one FluentCart product: status, description and its variations with price (store currency) and SKU.', 'specflux-site-agent' ),
			array( 'id' => array( 'type' => 'integer' ) ),
			array( 'id' ),
			'products/view',
			array( self::class, 'executeProductRead' ),
			true
		);
	}

	private static function registerOrderRead(): void {
		self::define(
			'fluentcart-order-read',
			__( 'Read an order', 'specflux-site-agent' ),
			__( 'Read one FluentCart order: status, totals (store currency), mode and its payment transactions. No customer details.', 'specflux-site-agent' ),
			array( 'id' => array( 'type' => 'integer' ) ),
			array( 'id' ),
			'orders/view',
			array( self::class, 'executeOrderRead' ),
			true
		);
	}

	private static function registerProductCreate(): void {
		self::define(
			'fluentcart-product-create',
			__( 'Create a product', 'specflux-site-agent' ),
			__( 'Create a simple FluentCart product (one variation) as a draft unless a publish status is given. Prices are in the store currency.', 'specflux-site-agent' ),
			array(
				'name'              => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ),
				'status'            => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'publish' ),
				),
				'regular_price'     => array( 'type' => 'string' ),
				'sale_price'        => array( 'type' => 'string' ),
			),
			array( 'name' ),
			'products/create',
			array( self::class, 'executeProductCreate' )
		);
	}

	private static function registerProductUpdate(): void {
		self::define(
			'fluentcart-product-update',
			__( 'Update a product', 'specflux-site-agent' ),
			__( 'Update a FluentCart product\'s name, description, status or price. A price change needs a product with exactly one variation. Prices are in the store currency.', 'specflux-site-agent' ),
			array(
				'id'                => array( 'type' => 'integer' ),
				'name'              => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'short_description' => array( 'type' => 'string' ),
				'status'            => array(
					'type' => 'string',
					'enum' => array( 'draft', 'pending', 'publish' ),
				),
				'regular_price'     => array( 'type' => array( 'string', 'null' ) ),
				'sale_price'        => array( 'type' => array( 'string', 'null' ) ),
			),
			array( 'id' ),
			'products/edit',
			array( self::class, 'executeProductUpdate' )
		);
	}

	private static function registerSetProductImage(): void {
		self::define(
			'fluentcart-set-product-image',
			__( 'Set product image', 'specflux-site-agent' ),
			__( 'Set (or add to the gallery of) a FluentCart product\'s image from an existing media library attachment; never fetches a URL.', 'specflux-site-agent' ),
			array(
				'product_id'    => array( 'type' => 'integer' ),
				'attachment_id' => array( 'type' => 'integer' ),
				'gallery'       => array( 'type' => 'boolean' ),
			),
			array( 'product_id', 'attachment_id' ),
			'products/edit',
			array( self::class, 'executeSetProductImage' ),
			false,
			array( self::class, 'maySetProductImage' )
		);
	}

	private static function registerCouponCreate(): void {
		self::define(
			'fluentcart-coupon-create',
			__( 'Create coupon (draft)', 'specflux-site-agent' ),
			__( 'Create an INACTIVE FluentCart coupon. Refuses a code that already exists. `amount` is a percent for a percentage coupon, a store-currency value for a fixed one.', 'specflux-site-agent' ),
			array(
				'code'          => array( 'type' => 'string' ),
				'type'          => array(
					'type' => 'string',
					'enum' => array( 'fixed', 'percentage' ),
				),
				'discount_type' => array( 'type' => 'string' ),
				'amount'        => array( 'type' => 'number' ),
				'title'         => array( 'type' => 'string' ),
				'start_date'    => array( 'type' => 'string' ),
				'end_date'      => array( 'type' => 'string' ),
			),
			array( 'code', 'amount' ),
			'coupons/manage',
			array( self::class, 'executeCouponCreate' )
		);
	}

	private static function registerCouponEnable(): void {
		self::define(
			'fluentcart-coupon-enable',
			__( 'Enable coupon', 'specflux-site-agent' ),
			__( 'Activate an existing FluentCart coupon. Only the status changes.', 'specflux-site-agent' ),
			array( 'coupon_id' => array( 'type' => 'integer' ) ),
			array( 'coupon_id' ),
			'coupons/manage',
			array( self::class, 'executeCouponEnable' )
		);
	}

	private static function registerOrdersRefund(): void {
		self::define(
			'fluentcart-orders-refund',
			__( 'Refund order', 'specflux-site-agent' ),
			__( 'Refund part or all of a FluentCart order against its latest successful charge, through the payment gateway. Refuses more than remains refundable, and a second refund once this run\'s refund budget is spent. `amount` is in the store currency.', 'specflux-site-agent' ),
			array(
				'order_id' => array( 'type' => 'integer' ),
				'amount'   => array( 'type' => 'number' ),
				'reason'   => array( 'type' => 'string' ),
			),
			array( 'order_id', 'amount', 'reason' ),
			'orders/can_refund',
			array( self::class, 'executeOrdersRefund' )
		);
	}

	private static function registerShippingZoneSave(): void {
		self::define(
			'fluentcart-shipping-zone-save',
			__( 'Save shipping zone', 'specflux-site-agent' ),
			__( 'Create or update a FluentCart shipping zone; never deletes one. `countries` are two-letter codes (empty = whole world). Methods given are ADDED to the zone, none are edited or removed.', 'specflux-site-agent' ),
			array(
				'zone_id'        => array( 'type' => 'integer' ),
				'name'           => array( 'type' => 'string' ),
				'countries'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'selection_type' => array(
					'type' => 'string',
					'enum' => array( 'included', 'excluded' ),
				),
				'methods'        => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'required'             => array( 'title', 'type' ),
						'additionalProperties' => false,
						'properties'           => array(
							'title'  => array( 'type' => 'string' ),
							'type'   => array( 'type' => 'string' ),
							'amount' => array( 'type' => 'number' ),
						),
					),
				),
			),
			array( 'name' ),
			'store/sensitive',
			array( self::class, 'executeShippingZoneSave' )
		);
	}

	private static function registerTaxRateSave(): void {
		self::define(
			'fluentcart-tax-rate-save',
			__( 'Save tax rate', 'specflux-site-agent' ),
			__( 'Create or update a FluentCart tax rate for a country (and optionally state, postcode, city); never deletes one. Takes effect at checkout immediately.', 'specflux-site-agent' ),
			array(
				'tax_rate_id' => array( 'type' => 'integer' ),
				'country'     => array( 'type' => 'string' ),
				'state'       => array( 'type' => 'string' ),
				'postcode'    => array( 'type' => 'string' ),
				'city'        => array( 'type' => 'string' ),
				'rate'        => array( 'type' => 'number' ),
				'name'        => array( 'type' => 'string' ),
				'priority'    => array( 'type' => 'integer' ),
				'compound'    => array( 'type' => 'boolean' ),
				'shipping'    => array( 'type' => 'boolean' ),
				'class_id'    => array( 'type' => 'integer' ),
			),
			array( 'country', 'rate', 'name' ),
			'store/sensitive',
			array( self::class, 'executeTaxRateSave' )
		);
	}

	private static function registerStoreReport(): void {
		self::define(
			'fluentcart-store-report',
			__( 'Store health report', 'specflux-site-agent' ),
			__( 'A read-only sales/refunds report over a date window of at most 92 days, with an out-of-stock list.', 'specflux-site-agent' ),
			array(
				'from' => array( 'type' => 'string' ),
				'to'   => array( 'type' => 'string' ),
			),
			array( 'from', 'to' ),
			'reports/view',
			array( self::class, 'executeStoreReport' ),
			true
		);
	}

	/**
	 * @param string                           $template   Polyfill template.
	 * @param string                           $label      Label.
	 * @param string                           $description Description.
	 * @param array<string,array<string,mixed>> $properties Input properties.
	 * @param list<string>                     $required   Required input keys.
	 * @param string                           $permission FluentCart permission string.
	 * @param callable                         $execute    `fn( array $input ): array|WP_Error`.
	 * @param bool                             $read_only   Read-only ability.
	 * @param (callable(array<string,mixed>):bool)|null $object_gate Extra per-object check, run after the FluentCart permission.
	 */
	private static function define( string $template, string $label, string $description, array $properties, array $required, string $permission, callable $execute, bool $read_only = false, ?callable $object_gate = null ): void {
		wp_register_ability(
			strtolower( 'specflux/' . $template ),
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => $required,
					'additionalProperties' => false,
					'properties'           => $properties,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => static function ( $input = array() ) use ( $execute ) {
					return $execute( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) use ( $permission, $object_gate ) {
					if ( ! self::can( $permission ) ) {
						return false;
					}

					return null === $object_gate || true === $object_gate( is_array( $input ) ? $input : array() );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => $read_only,
						'destructive' => false,
						'idempotent'  => $read_only,
					),
					'specflux'    => array( 'hidden' => false ),
				),
			)
		);
	}

	/**
	 * FluentCart's own permission check. Its `PermissionManager::hasPermission()`
	 * grants every permission to `manage_options` users, so that is the
	 * fallback when the class is not loadable (fail closed otherwise).
	 *
	 * @param string $permission e.g. `coupons/manage`.
	 */
	public static function can( string $permission ): bool {
		$manager = '\FluentCart\App\Services\Permission\PermissionManager';
		if ( class_exists( $manager ) && is_callable( array( $manager, 'hasPermission' ) ) ) {
			return true === call_user_func( array( $manager, 'hasPermission' ), $permission );
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	// ------------------------------------------------------------------
	// Execution
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeProductRead( array $input ): array|WP_Error {
		$id      = (int) ( $input['id'] ?? 0 );
		$product = self::loadProduct( $id );
		if ( $product instanceof WP_Error ) {
			return $product;
		}

		$variants = array();
		foreach ( self::variantsOf( $product ) as $variant ) {
			$item    = (int) ( $variant['item_price'] ?? 0 );
			$compare = (int) ( $variant['compare_price'] ?? 0 );
			$on_sale = $compare > $item && $compare > 0;

			$variants[] = array(
				'id'            => (int) ( $variant['id'] ?? 0 ),
				'title'         => is_string( $variant['variation_title'] ?? null ) ? $variant['variation_title'] : '',
				'sku'           => is_string( $variant['sku'] ?? null ) ? $variant['sku'] : '',
				'regular_price' => FluentCartLookup::money( $on_sale ? $compare : $item ),
				'sale_price'    => $on_sale ? FluentCartLookup::money( $item ) : '',
			);
		}

		return array(
			'id'                => $id,
			'name'              => is_string( $product['post_title'] ?? null ) ? $product['post_title'] : '',
			'status'            => is_string( $product['post_status'] ?? null ) ? $product['post_status'] : '',
			'description'       => is_string( $product['post_content'] ?? null ) ? $product['post_content'] : '',
			'short_description' => is_string( $product['post_excerpt'] ?? null ) ? $product['post_excerpt'] : '',
			'variants'          => $variants,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeOrderRead( array $input ): array|WP_Error {
		$order = self::loadOrder( (int) ( $input['id'] ?? 0 ) );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		$currency     = is_string( $order['currency'] ?? null ) ? $order['currency'] : '';
		$transactions = array();
		foreach ( self::transactionsOf( $order ) as $transaction ) {
			$transactions[] = array(
				'id'           => (int) ( $transaction['id'] ?? 0 ),
				'type'         => is_string( $transaction['transaction_type'] ?? null ) ? $transaction['transaction_type'] : '',
				'status'       => is_string( $transaction['status'] ?? null ) ? $transaction['status'] : '',
				'total'        => FluentCartLookup::money( $transaction['total'] ?? null ),
				'payment_mode' => is_string( $transaction['payment_mode'] ?? null ) ? $transaction['payment_mode'] : '',
			);
		}

		return array(
			'id'              => (int) ( $order['id'] ?? 0 ),
			'status'          => is_string( $order['status'] ?? null ) ? $order['status'] : '',
			'payment_status'  => is_string( $order['payment_status'] ?? null ) ? $order['payment_status'] : '',
			'shipping_status' => is_string( $order['shipping_status'] ?? null ) ? $order['shipping_status'] : '',
			'mode'            => is_string( $order['mode'] ?? null ) ? $order['mode'] : '',
			'currency'        => $currency,
			'total'           => FluentCartLookup::money( $order['total_amount'] ?? null ),
			'total_paid'      => FluentCartLookup::money( $order['total_paid'] ?? null ),
			'total_refund'    => FluentCartLookup::money( $order['total_refund'] ?? null ),
			'transactions'    => $transactions,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeProductCreate( array $input ): array|WP_Error {
		$name = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
		if ( '' === $name ) {
			return self::invalid( __( 'A product name is required.', 'specflux-site-agent' ) );
		}
		$status = self::status( $input['status'] ?? 'draft' );
		if ( null === $status ) {
			return self::invalid( __( 'The status must be draft, pending or publish.', 'specflux-site-agent' ) );
		}
		$prices = self::pricesFrom( $input );
		if ( $prices instanceof WP_Error ) {
			return $prices;
		}

		$created = FluentCartRest::call(
			'POST',
			'/products',
			array(
				'post_title'  => $name,
				'post_status' => $status,
				'detail'      => array(
					'fulfillment_type' => 'physical',
					'variation_type'   => 'simple',
				),
			)
		);
		if ( $created instanceof WP_Error ) {
			return $created;
		}

		$data = is_array( $created['data'] ?? null ) ? $created['data'] : $created;
		$id   = (int) ( $data['ID'] ?? $data['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new WP_Error( 'fluentcart_unexpected_response', __( 'FluentCart did not say which product it created.', 'specflux-site-agent' ), array( 'status' => 502 ) );
		}

		$followed = self::applyProductChanges( $id, $input, array(), $prices );

		return $followed instanceof WP_Error ? self::withProduct( $followed, $id ) : array( 'product_id' => $id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeProductUpdate( array $input ): array|WP_Error {
		$id = (int) ( $input['id'] ?? 0 );

		$status = null;
		if ( array_key_exists( 'status', $input ) ) {
			$status = self::status( $input['status'] );
			if ( null === $status ) {
				return self::invalid( __( 'The status must be draft, pending or publish.', 'specflux-site-agent' ) );
			}
		}
		$prices = self::pricesFrom( $input );
		if ( $prices instanceof WP_Error ) {
			return $prices;
		}

		$product = self::loadProduct( $id );
		if ( $product instanceof WP_Error ) {
			return $product;
		}

		$followed = self::applyProductChanges( $id, $input, $product, $prices );
		if ( $followed instanceof WP_Error ) {
			return $followed;
		}

		return array( 'product_id' => $id );
	}

	/**
	 * The text/status write (one partial post update) then the price write.
	 *
	 * @param int                                              $id      Product id.
	 * @param array<string,mixed>                              $input   Call input.
	 * @param array<string,mixed>                              $product The loaded product (empty for a fresh create).
	 * @param array{regular:?int,sale:?int,sale_given:bool}|null $prices Parsed prices, or null when none given.
	 */
	private static function applyProductChanges( int $id, array $input, array $product, ?array $prices ): true|WP_Error {
		$post = array();
		foreach ( array(
			'name'              => 'post_title',
			'description'       => 'post_content',
			'short_description' => 'post_excerpt',
		) as $key => $field ) {
			if ( is_string( $input[ $key ] ?? null ) && ( 'name' !== $key || '' !== trim( $input[ $key ] ) ) ) {
				$post[ $field ] = $input[ $key ];
			}
		}
		// A create already carried its status; an update carries it only when asked.
		if ( array() !== $product && is_string( $input['status'] ?? null ) ) {
			$post['post_status'] = strtolower( trim( $input['status'] ) );
		}

		if ( array() !== $post ) {
			$updated = FluentCartRest::call( 'POST', '/products/' . $id . '/pricing', $post );
			if ( $updated instanceof WP_Error ) {
				return $updated;
			}
		}

		if ( null === $prices ) {
			return true;
		}

		if ( array() === $product ) {
			$product = self::loadProduct( $id );
			if ( $product instanceof WP_Error ) {
				return $product;
			}
		}

		return self::writePrice( $id, $product, $prices );
	}

	/**
	 * @param int                                       $id      Product id.
	 * @param array<string,mixed>                       $product Loaded product.
	 * @param array{regular:?int,sale:?int,sale_given:bool} $prices  Parsed prices (cents).
	 */
	private static function writePrice( int $id, array $product, array $prices ): true|WP_Error {
		$variants = self::variantsOf( $product );
		if ( 1 !== count( $variants ) ) {
			return new WP_Error(
				'unsupported',
				__( 'A price change needs a product with exactly one variation; edit the others in FluentCart.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		$variant    = $variants[0];
		$variant_id = (int) ( $variant['id'] ?? 0 );
		if ( $variant_id <= 0 ) {
			return new WP_Error( 'fluentcart_unexpected_response', __( 'FluentCart did not return the variation.', 'specflux-site-agent' ), array( 'status' => 502 ) );
		}

		$item    = (int) ( $variant['item_price'] ?? 0 );
		$compare = (int) ( $variant['compare_price'] ?? 0 );
		$on_sale = $compare > $item && $compare > 0;

		$regular = $prices['regular'] ?? ( $on_sale ? $compare : $item );
		$sale    = $prices['sale_given'] ? $prices['sale'] : ( $on_sale ? $item : null );

		if ( null !== $sale && $sale >= $regular ) {
			return self::invalid( __( 'The sale price must be below the regular price.', 'specflux-site-agent' ) );
		}

		$variant['post_id']       = $id;
		$variant['item_price']    = null !== $sale ? $sale : $regular;
		$variant['compare_price'] = null !== $sale ? $regular : 0;

		$result = FluentCartRest::call( 'POST', '/products/variants/' . $variant_id, array( 'variants' => $variant ) );

		return $result instanceof WP_Error ? $result : true;
	}

	/**
	 * Per-object gate for set-product-image, which writes post meta directly
	 * (not through FluentCart's REST routes): edit on this product and read on
	 * this attachment.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	public static function maySetProductImage( array $input ): bool {
		$product_id    = (int) ( $input['product_id'] ?? 0 );
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );

		return $product_id > 0
			&& $attachment_id > 0
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'edit_post', $product_id )
			&& current_user_can( 'read_post', $attachment_id );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeSetProductImage( array $input ): array|WP_Error {
		$product_id    = (int) ( $input['product_id'] ?? 0 );
		$attachment_id = (int) ( $input['attachment_id'] ?? 0 );
		$gallery       = true === ( $input['gallery'] ?? false );

		if ( 'fluent-products' !== self::postType( $product_id ) ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		if ( 'attachment' !== self::postType( $attachment_id ) || ! self::isImage( $attachment_id ) ) {
			return new WP_Error( 'not_an_image', __( 'That attachment is not an image.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		if ( '' === self::altText( $attachment_id ) ) {
			return new WP_Error( 'missing_alt', __( 'That image has no alt text.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $product_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit that product.', 'specflux-site-agent' ), array( 'status' => 403 ) );
		}

		if ( $gallery ) {
			self::appendToGallery( $product_id, $attachment_id );
		} elseif ( function_exists( 'set_post_thumbnail' ) ) {
			// FluentCart mirrors a thumbnail change into its gallery itself
			// (`FluentProducts::handleThumbChange`, on `_thumbnail_id`).
			set_post_thumbnail( $product_id, $attachment_id );
		}

		return array(
			'product_id'    => $product_id,
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeCouponCreate( array $input ): array|WP_Error {
		$shaped = FluentCartPlatform::shapeCoupon(
			array_intersect_key(
				$input,
				array_flip( array( 'code', 'type', 'discount_type', 'amount', 'title', 'start_date', 'end_date', 'expiry' ) )
			)
		);
		if ( ! isset( $shaped['code'], $shaped['type'], $shaped['amount'] ) ) {
			return self::invalid( __( 'A code, a discount type (fixed or percentage) and an amount are required.', 'specflux-site-agent' ) );
		}

		return self::manageCoupon( $shaped );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeCouponEnable( array $input ): array|WP_Error {
		$coupon_id = (int) ( $input['coupon_id'] ?? 0 );
		if ( $coupon_id <= 0 ) {
			return self::invalid( __( 'A coupon id is required.', 'specflux-site-agent' ) );
		}

		return self::manageCoupon( FluentCartPlatform::shapeCoupon( array( 'coupon_id' => $coupon_id ) ) );
	}

	/**
	 * @param array<string,mixed> $shaped The native `manage-coupon` input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function manageCoupon( array $shaped ): array|WP_Error {
		$tools = '\FluentCart\App\Modules\MCP\Tools\CouponTools';
		if ( ! class_exists( $tools ) || ! is_callable( array( $tools, 'manageCoupon' ) ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'FluentCart coupons are not available.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		if ( ! self::can( 'coupons/manage' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot manage coupons.', 'specflux-site-agent' ), array( 'status' => 403 ) );
		}

		$result = call_user_func( array( $tools, 'manageCoupon' ), $shaped );
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$data = is_array( $result ) && is_array( $result['data'] ?? null ) ? $result['data'] : ( is_array( $result ) ? $result : array() );
		$id   = (int) ( $data['coupon_id'] ?? 0 );
		if ( $id <= 0 ) {
			return new WP_Error( 'fluentcart_unexpected_response', __( 'FluentCart did not say which coupon it saved.', 'specflux-site-agent' ), array( 'status' => 502 ) );
		}

		return array(
			'coupon_id' => $id,
			'code'      => is_string( $data['code'] ?? null ) ? $data['code'] : '',
			'status'    => is_string( $data['status'] ?? null ) ? $data['status'] : '',
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeOrdersRefund( array $input ): array|WP_Error {
		$order_id = (int) ( $input['order_id'] ?? 0 );
		$amount   = is_numeric( $input['amount'] ?? null ) ? (float) $input['amount'] : 0.0;
		$reason   = is_string( $input['reason'] ?? null ) ? $input['reason'] : '';
		$cents    = (int) round( $amount * 100 );

		if ( $order_id <= 0 || $cents <= 0 ) {
			return self::invalid( __( 'An order and a refund amount greater than zero are required.', 'specflux-site-agent' ) );
		}

		$order = self::loadOrder( $order_id );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		$remaining = (int) ( $order['total_paid'] ?? 0 ) - (int) ( $order['total_refund'] ?? 0 );
		if ( $cents > $remaining ) {
			return new WP_Error( 'refund_too_large', __( 'That refund is more than remains refundable on this order.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		if ( Abilities::refundBudgetSpent() ) {
			return new WP_Error( 'budget_exhausted', __( 'This run has used up its refund budget.', 'specflux-site-agent' ), array( 'status' => 409 ) );
		}

		$charge = null;
		foreach ( self::transactionsOf( $order ) as $transaction ) {
			if ( 'charge' === ( $transaction['transaction_type'] ?? null ) && 'succeeded' === ( $transaction['status'] ?? null ) && (int) ( $transaction['id'] ?? 0 ) > (int) ( $charge['id'] ?? 0 ) ) {
				$charge = $transaction;
			}
		}
		if ( null === $charge ) {
			return new WP_Error( 'transaction_not_found', __( 'No refundable charge was found on this order.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		$result = FluentCartRest::call(
			'POST',
			'/orders/' . $order_id . '/refund',
			array(
				'refund_info' => array(
					'transaction_id' => (int) $charge['id'],
					'amount'         => $cents,
					'reason'         => $reason,
				),
			)
		);
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return array(
			'order_id'             => $order_id,
			'amount'               => $amount,
			'payment_mode'         => is_string( $charge['payment_mode'] ?? null ) ? $charge['payment_mode'] : '',
			'remaining_refundable' => round( max( 0, $remaining - $cents ) / 100, 2 ),
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeShippingZoneSave( array $input ): array|WP_Error {
		$zone_id = (int) ( $input['zone_id'] ?? 0 );
		$name    = is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '';
		if ( '' === $name ) {
			return self::invalid( __( 'A zone name is required.', 'specflux-site-agent' ) );
		}

		$countries = array();
		foreach ( is_array( $input['countries'] ?? null ) ? $input['countries'] : array() as $code ) {
			if ( ! is_string( $code ) || 1 !== preg_match( '/^[A-Za-z]{2}$/', trim( $code ) ) ) {
				return self::invalid( __( 'Countries must be two-letter codes.', 'specflux-site-agent' ) );
			}
			$countries[] = strtoupper( trim( $code ) );
		}
		$countries = array_values( array_unique( $countries ) );

		$methods = array();
		foreach ( is_array( $input['methods'] ?? null ) ? $input['methods'] : array() as $method ) {
			if ( ! is_array( $method ) || ! is_string( $method['title'] ?? null ) || '' === trim( $method['title'] ) || ! is_string( $method['type'] ?? null ) || '' === trim( $method['type'] ) ) {
				return self::invalid( __( 'Each shipping method needs a title and a type.', 'specflux-site-agent' ) );
			}
			$methods[] = array(
				'title'      => trim( $method['title'] ),
				'type'       => trim( $method['type'] ),
				'amount'     => is_numeric( $method['amount'] ?? null ) ? (float) $method['amount'] : 0,
				'is_enabled' => 1,
			);
		}

		$zone = array(
			'name'   => $name,
			'region' => array() === $countries ? 'all' : 'selection',
			'meta'   => array(
				'countries'      => $countries,
				'selection_type' => 'excluded' === ( $input['selection_type'] ?? null ) ? 'excluded' : 'included',
			),
		);

		$saved = $zone_id > 0
			? FluentCartRest::call( 'PUT', '/shipping/zones/' . $zone_id, $zone )
			: FluentCartRest::call( 'POST', '/shipping/zones', $zone );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$row = is_array( $saved['shipping_zone'] ?? null ) ? $saved['shipping_zone'] : array();
		$id  = $zone_id > 0 ? $zone_id : (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new WP_Error( 'fluentcart_unexpected_response', __( 'FluentCart did not say which zone it saved.', 'specflux-site-agent' ), array( 'status' => 502 ) );
		}

		foreach ( $methods as $method ) {
			$added = FluentCartRest::call( 'POST', '/shipping/methods', array( 'zone_id' => $id ) + $method );
			if ( $added instanceof WP_Error ) {
				return self::withData( $added, array( 'zone_id' => $id ) );
			}
		}

		return array(
			'zone_id'       => $id,
			'methods_added' => count( $methods ),
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeTaxRateSave( array $input ): array|WP_Error {
		$tax_rate_id = (int) ( $input['tax_rate_id'] ?? 0 );
		$country     = is_string( $input['country'] ?? null ) ? strtoupper( trim( $input['country'] ) ) : '';
		if ( 1 !== preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return self::invalid( __( 'A two-letter country code is required.', 'specflux-site-agent' ) );
		}
		if ( ! is_numeric( $input['rate'] ?? null ) || (float) $input['rate'] < 0 || (float) $input['rate'] > 100 ) {
			return self::invalid( __( 'The tax rate must be a percentage between 0 and 100.', 'specflux-site-agent' ) );
		}

		$rate = array(
			'country'      => $country,
			'state'        => is_string( $input['state'] ?? null ) ? strtoupper( trim( $input['state'] ) ) : '',
			'postcode'     => is_string( $input['postcode'] ?? null ) ? trim( $input['postcode'] ) : '',
			'city'         => is_string( $input['city'] ?? null ) ? trim( $input['city'] ) : '',
			'rate'         => (float) $input['rate'],
			'name'         => is_string( $input['name'] ?? null ) ? trim( $input['name'] ) : '',
			'priority'     => max( 1, (int) ( $input['priority'] ?? 1 ) ),
			'is_compound'  => true === ( $input['compound'] ?? false ) ? 1 : 0,
			'for_shipping' => true === ( $input['shipping'] ?? false ) ? 1 : 0,
		);
		if ( isset( $input['class_id'] ) && is_numeric( $input['class_id'] ) && (int) $input['class_id'] > 0 ) {
			$rate['class_id'] = (int) $input['class_id'];
		}

		$saved = $tax_rate_id > 0
			? FluentCartRest::call( 'PUT', '/tax/country/rate/' . $tax_rate_id, $rate )
			: FluentCartRest::call( 'POST', '/tax/country/rate', $rate );
		if ( $saved instanceof WP_Error ) {
			return $saved;
		}

		$row = is_array( $saved['tax_rate'] ?? null ) ? $saved['tax_rate'] : array();
		$id  = $tax_rate_id > 0 ? $tax_rate_id : (int) ( $row['id'] ?? 0 );
		if ( $id <= 0 ) {
			return new WP_Error( 'fluentcart_unexpected_response', __( 'FluentCart did not say which tax rate it saved.', 'specflux-site-agent' ), array( 'status' => 502 ) );
		}

		return array( 'tax_rate_id' => $id );
	}

	/**
	 * Sales over a window from `fct_orders` (money is integer cents; a charged
	 * order has `total_paid` > 0, a refund adds to `total_refund`): the only
	 * read here that does not go through a route, because no route returns
	 * this aggregate. Out-of-stock comes from `fct_product_variations`.
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function executeStoreReport( array $input ): array|WP_Error {
		$from = is_string( $input['from'] ?? null ) ? $input['from'] : '';
		$to   = is_string( $input['to'] ?? null ) ? $input['to'] : '';

		$from_ts = strtotime( $from . ' 00:00:00 UTC' );
		$to_ts   = strtotime( $to . ' 23:59:59 UTC' );
		if ( false === $from_ts || false === $to_ts || $to_ts < $from_ts ) {
			return self::invalid( __( 'A valid from/to date range is required.', 'specflux-site-agent' ) );
		}
		if ( ( $to_ts - $from_ts ) / DAY_IN_SECONDS > 92 ) {
			return new WP_Error( 'window_too_long', __( 'A store report window may not exceed 92 days.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return new WP_Error( 'gateway_unavailable', __( 'Orders are not available.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		$orders   = $wpdb->prefix . 'fct_orders';
		$variants = $wpdb->prefix . 'fct_product_variations';
		$from_sql = gmdate( 'Y-m-d H:i:s', $from_ts );
		$to_sql   = gmdate( 'Y-m-d H:i:s', $to_ts );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names are $wpdb->prefix plus a constant, bound as %i identifiers; a read-only aggregate nothing else exposes.
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, COUNT(*) AS order_count, COALESCE(SUM(total_paid), 0) AS gross, COALESCE(SUM(total_refund), 0) AS refunds FROM %i WHERE total_paid > 0 AND created_at >= %s AND created_at <= %s GROUP BY currency',
				$orders,
				$from_sql,
				$to_sql
			),
			ARRAY_A
		);
		$out_of_stock = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT post_id FROM %i WHERE stock_status = 'out-of-stock' ORDER BY post_id ASC LIMIT 50", $variants ), ARRAY_A );
		// phpcs:enable

		$count    = 0;
		$gross    = 0;
		$refunds  = 0;
		$currency = '';
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$count   += (int) ( $row['order_count'] ?? 0 );
			$gross   += (int) ( $row['gross'] ?? 0 );
			$refunds += (int) ( $row['refunds'] ?? 0 );
			if ( '' === $currency && is_string( $row['currency'] ?? null ) ) {
				$currency = $row['currency'];
			}
		}

		$low = array();
		foreach ( is_array( $out_of_stock ) ? $out_of_stock : array() as $row ) {
			if ( is_array( $row ) && (int) ( $row['post_id'] ?? 0 ) > 0 ) {
				$low[] = (int) $row['post_id'];
			}
		}

		return array(
			'from'               => $from,
			'to'                 => $to,
			'order_count'        => $count,
			'gross_sales'        => round( $gross / 100, 2 ),
			'net_sales'          => round( ( $gross - $refunds ) / 100, 2 ),
			'refunds_total'      => round( $refunds / 100, 2 ),
			'currency'           => $currency,
			'low_stock_products' => $low,
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * @return array<string,mixed>|WP_Error The product (post fields + `variants`).
	 */
	private static function loadProduct( int $id ): array|WP_Error {
		if ( $id <= 0 ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		$response = FluentCartRest::call( 'GET', '/products/' . $id . '/pricing' );
		if ( $response instanceof WP_Error ) {
			return $response;
		}
		$product = $response['product'] ?? null;
		if ( ! is_array( $product ) || array() === $product ) {
			return new WP_Error( 'not_found', __( 'Product not found.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		return $product;
	}

	/**
	 * @return array<string,mixed>|WP_Error The order row (+ its `transactions`).
	 */
	private static function loadOrder( int $id ): array|WP_Error {
		if ( $id <= 0 ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		$response = FluentCartRest::call( 'GET', '/orders/' . $id );
		if ( $response instanceof WP_Error ) {
			return $response;
		}
		$order = $response['order'] ?? null;
		if ( ! is_array( $order ) || array() === $order ) {
			return new WP_Error( 'not_found', __( 'Order not found.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		return $order;
	}

	/**
	 * @param array<string,mixed> $product Product.
	 * @return list<array<string,mixed>>
	 */
	private static function variantsOf( array $product ): array {
		return array_values( array_filter( is_array( $product['variants'] ?? null ) ? $product['variants'] : array(), 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $order Order.
	 * @return list<array<string,mixed>>
	 */
	private static function transactionsOf( array $order ): array {
		return array_values( array_filter( is_array( $order['transactions'] ?? null ) ? $order['transactions'] : array(), 'is_array' ) );
	}

	/**
	 * Regular/sale in cents from the pack's store-currency inputs. Null when
	 * neither key was sent. A null/'' sale price means "clear the sale".
	 *
	 * @param array<string,mixed> $input Call input.
	 * @return array{regular:?int,sale:?int,sale_given:bool}|WP_Error|null
	 */
	private static function pricesFrom( array $input ): array|WP_Error|null {
		$regular_given = array_key_exists( 'regular_price', $input ) && null !== $input['regular_price'] && '' !== $input['regular_price'];
		$sale_given    = array_key_exists( 'sale_price', $input );
		if ( ! $regular_given && ! $sale_given ) {
			return null;
		}

		$regular = null;
		if ( $regular_given ) {
			$regular = self::cents( $input['regular_price'] );
			if ( null === $regular || $regular <= 0 ) {
				return self::invalid( __( 'The regular price must be a number greater than zero.', 'specflux-site-agent' ) );
			}
		}

		$sale = null;
		if ( $sale_given && null !== $input['sale_price'] && '' !== $input['sale_price'] ) {
			$sale = self::cents( $input['sale_price'] );
			if ( null === $sale || $sale <= 0 ) {
				return self::invalid( __( 'The sale price must be a number greater than zero.', 'specflux-site-agent' ) );
			}
		}

		return array(
			'regular'    => $regular,
			'sale'       => $sale,
			'sale_given' => $sale_given,
		);
	}

	private static function cents( mixed $value ): ?int {
		return ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) && is_numeric( $value ) ? (int) round( (float) $value * 100 ) : null;
	}

	private static function status( mixed $value ): ?string {
		$status = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ? $status : null;
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'invalid_input', $message, array( 'status' => 400 ) );
	}

	/**
	 * Keep a failure's message but say which object already exists, so the run
	 * report and the model do not lose track of a half-finished write.
	 *
	 * @param WP_Error $error   The failure.
	 * @param int      $product The product that was already created.
	 */
	private static function withProduct( WP_Error $error, int $product ): WP_Error {
		return self::withData( $error, array( 'product_id' => $product ) );
	}

	/**
	 * @param WP_Error            $error The failure.
	 * @param array<string,mixed> $extra Extra error data.
	 */
	private static function withData( WP_Error $error, array $extra ): WP_Error {
		$data = $error->get_error_data();

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), ( is_array( $data ) ? $data : array() ) + $extra );
	}

	private static function postType( int $id ): string {
		if ( $id <= 0 || ! function_exists( 'get_post' ) ) {
			return '';
		}
		$post = get_post( $id );

		return is_object( $post ) ? (string) $post->post_type : '';
	}

	private static function isImage( int $attachment_id ): bool {
		if ( ! function_exists( 'get_post_mime_type' ) ) {
			return false;
		}
		$mime = get_post_mime_type( $attachment_id );

		return is_string( $mime ) && str_starts_with( $mime, 'image/' );
	}

	private static function altText( int $attachment_id ): string {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return '';
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return is_string( $alt ) ? trim( $alt ) : '';
	}

	/**
	 * FluentCart keeps a product's gallery in post meta
	 * `fluent-products-gallery-image` as a list of `{id,url,name}`
	 * (`FluentProducts::handleThumbChange`).
	 */
	private static function appendToGallery( int $product_id, int $attachment_id ): void {
		if ( ! function_exists( 'get_post_meta' ) || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$gallery = get_post_meta( $product_id, 'fluent-products-gallery-image', true );
		$gallery = is_array( $gallery ) ? $gallery : array();
		foreach ( $gallery as $image ) {
			if ( is_array( $image ) && (int) ( $image['id'] ?? 0 ) === $attachment_id ) {
				return;
			}
		}

		$gallery[] = array(
			'id'   => $attachment_id,
			'url'  => function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $attachment_id ) : '',
			'name' => function_exists( 'get_the_title' ) ? (string) get_the_title( $attachment_id ) : '',
		);
		update_post_meta( $product_id, 'fluent-products-gallery-image', $gallery );
	}
}
