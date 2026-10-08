<?php
/**
 * The FluentCart polyfill abilities (C4-F), run against a fake REST
 * dispatcher and fake FluentCart classes: no network, no real FluentCart.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce\Platforms;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\Abilities;
use Specflux\SenroFlux\Packs\Commerce\Platforms\FluentCart\FluentCartAbilities;
use Specflux\SenroFlux\Packs\Commerce\Platforms\FluentCart\FluentCartPlatform;
use Specflux\SenroFlux\Packs\Commerce\Platforms\FluentCart\FluentCartRest;
use Specflux\SenroFlux\Run\Budget;
use Specflux\SenroFlux\Run\WpdbRunStore;
use WP_Error;
use wpdb;

final class FluentCartAbilitiesTest extends TestCase {

	/** @var list<array{method:string,route:string,params:array<string,mixed>}> */
	private array $calls = array();

	/** @var array<string,mixed> route-key => response (array|WP_Error), keyed "METHOD /route". */
	private array $responses = array();

	protected function setUp(): void {
		require_once dirname( __DIR__, 3 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 3 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 3 ) . '/stubs/commerce.php';
		require_once __DIR__ . '/FakeFluentCartCoupons.php';
		require_once dirname( __DIR__, 3 ) . '/stubs/fluentcart-coupon-tools.php';

		remove_all_filters( 'senroflux_store_platforms' );
		$GLOBALS['senroflux_test_abilities'] = array();
		$GLOBALS['senroflux_test_posts']     = array();
		$GLOBALS['senroflux_test_postmeta']  = array();
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_options']   = array();
		$GLOBALS['wpdb']                     = new wpdb();
		FakeFluentCartCoupons::$calls        = array();
		FakeFluentCartCoupons::$result       = null;
		FluentCartPlatform::boot();

		$this->calls     = array();
		$this->responses = array();
		FluentCartRest::useDispatcher(
			function ( string $method, string $route, array $params ): mixed {
				$this->calls[] = array(
					'method' => $method,
					'route'  => $route,
					'params' => $params,
				);

				return $this->responses[ $method . ' ' . $route ] ?? new WP_Error( 'no_route', 'unexpected ' . $method . ' ' . $route, array( 'status' => 404 ) );
			}
		);

		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();
	}

	protected function tearDown(): void {
		FluentCartRest::useDispatcher( null );
		Abilities::forgetRunContext();
		remove_all_filters( 'senroflux_store_platforms' );
		$GLOBALS['senroflux_test_abilities'] = array();
		$GLOBALS['senroflux_test_user_caps'] = array();
		unset( $GLOBALS['wpdb'] );
	}

	/** @param array<string,mixed> $input Input. */
	private function call( string $name, array $input ): mixed {
		$ability = wp_get_ability( 'senroflux/' . $name );
		$this->assertNotNull( $ability, "senroflux/$name was not registered" );

		return $ability->execute( $input );
	}

	/** @param array<string,mixed> $response Response. */
	private function respond( string $key, array|WP_Error $response ): void {
		$this->responses[ $key ] = $response;
	}

	/** @return array<string,mixed> */
	private function variant( int $id, int $price, int $compare = 0 ): array {
		return array(
			'id'              => $id,
			'post_id'         => 50,
			'variation_title' => 'Mug',
			'sku'             => 'MUG-1',
			'item_price'      => $price,
			'compare_price'   => $compare,
			'total_stock'     => 7,
			'other_info'      => array( 'payment_type' => 'onetime' ),
		);
	}

	/** @param list<array<string,mixed>> $variants Variants. */
	private function product( array $variants, string $status = 'draft' ): void {
		$this->respond(
			'GET /products/50/pricing',
			array(
				'product' => array(
					'ID'           => 50,
					'post_title'   => 'Mug',
					'post_status'  => $status,
					'post_content' => '<p>Old</p>',
					'post_excerpt' => '',
					'variants'     => $variants,
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Registration
	// ------------------------------------------------------------------

	public function test_registration_adds_every_fluentcart_polyfill_without_touching_woos(): void {
		foreach ( FluentCartAbilities::templates() as $template ) {
			$this->assertNotNull( wp_get_ability( 'senroflux/' . $template ), $template );
		}
		$this->assertNotNull( wp_get_ability( 'senroflux/orders-refund' ), 'Woo\'s polyfills still register' );
		$this->assertSame( array(), array_intersect( FluentCartAbilities::templates(), array( 'orders-refund', 'coupon-create', 'coupon-enable', 'set-product-image', 'shipping-zone-save', 'tax-rate-save', 'store-report', 'save-store-report', 'products-catalogue' ) ), 'no template collides with a Woo one' );
	}

	public function test_every_platform_polyfill_has_a_registrar(): void {
		$own = array_diff( ( new FluentCartPlatform() )->polyfills(), array( 'save-store-report' ) );

		$this->assertEqualsCanonicalizing( FluentCartAbilities::templates(), array_values( $own ) );
		FluentCartAbilities::register( 'not-a-template' );
		$this->assertNull( wp_get_ability( 'senroflux/not-a-template' ) );
	}

	public function test_permissions_follow_fluentcarts_gate_with_manage_options_as_the_floor(): void {
		$ability = wp_get_ability( 'senroflux/fluentcart-orders-refund' );
		$this->assertTrue( $ability?->check_permissions( array() ) );

		$GLOBALS['senroflux_test_user_caps'] = array();
		$this->assertFalse( $ability?->check_permissions( array() ) );
		$this->assertFalse( FluentCartAbilities::can( 'coupons/manage' ) );
	}

	public function test_set_product_image_permission_is_checked_per_product_and_attachment(): void {
		$ability = wp_get_ability( 'senroflux/fluentcart-set-product-image' );
		$input   = array(
			'product_id'    => 50,
			'attachment_id' => 60,
		);

		// FluentCart's own gate alone is not enough.
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );
		$this->assertFalse( $ability?->check_permissions( $input ), 'no edit on the product' );

		$GLOBALS['senroflux_test_user_caps'] = array(
			'manage_options' => true,
			'edit_post'      => true,
			'read_post'      => true,
		);
		$this->assertTrue( $ability?->check_permissions( $input ) );

		$GLOBALS['senroflux_test_user_caps']['edit_post:50'] = false;
		$this->assertFalse( $ability?->check_permissions( $input ), 'no edit on THIS product' );

		$GLOBALS['senroflux_test_user_caps']['edit_post:50'] = true;
		$GLOBALS['senroflux_test_user_caps']['read_post:60'] = false;
		$this->assertFalse( $ability?->check_permissions( $input ), 'cannot read THIS attachment' );

		$GLOBALS['senroflux_test_user_caps']['read_post:60'] = true;
		$this->assertFalse( $ability?->check_permissions( array() ), 'naming no object' );
	}

	// ------------------------------------------------------------------
	// Reads
	// ------------------------------------------------------------------

	public function test_product_read_reports_prices_in_the_store_currency(): void {
		$this->product( array( $this->variant( 9, 1500, 2000 ) ) );

		$out = $this->call( 'fluentcart-product-read', array( 'id' => 50 ) );

		$this->assertSame( 'Mug', $out['name'] );
		$this->assertSame( '20.00', $out['variants'][0]['regular_price'] );
		$this->assertSame( '15.00', $out['variants'][0]['sale_price'] );
		$this->assertSame( 'GET', $this->calls[0]['method'] );
	}

	public function test_a_missing_product_or_order_is_a_clean_refusal(): void {
		$this->respond( 'GET /products/51/pricing', array( 'product' => array() ) );
		$this->assertSame( 'not_found', $this->call( 'fluentcart-product-read', array( 'id' => 51 ) )->get_error_code() );
		$this->respond( 'GET /orders/52', new WP_Error( 'x', 'Order not found', array( 'status' => 404 ) ) );
		$this->assertSame( 'x', $this->call( 'fluentcart-order-read', array( 'id' => 52 ) )->get_error_code() );
	}

	public function test_order_read_shows_money_and_transactions_but_no_customer(): void {
		$this->respond(
			'GET /orders/7',
			array(
				'order' => array(
					'id'           => 7,
					'status'       => 'completed',
					'currency'     => 'USD',
					'mode'         => 'test',
					'total_amount' => 5000,
					'total_paid'   => 5000,
					'total_refund' => 0,
					'customer'     => array( 'email' => 'secret@example.test' ),
					'transactions' => array(
						array(
							'id'               => 3,
							'transaction_type' => 'charge',
							'status'           => 'succeeded',
							'total'            => 5000,
							'payment_mode'     => 'test',
						),
					),
				),
			)
		);

		$out = $this->call( 'fluentcart-order-read', array( 'id' => 7 ) );

		$this->assertSame( '50.00', $out['total_paid'] );
		$this->assertSame( 3, $out['transactions'][0]['id'] );
		$this->assertStringNotContainsString( 'secret@example.test', (string) wp_json_encode( $out ) );
	}

	// ------------------------------------------------------------------
	// Products
	// ------------------------------------------------------------------

	public function test_create_makes_a_draft_then_text_then_price(): void {
		$this->respond(
			'POST /products',
			array(
				'data' => array(
					'ID'      => 50,
					'variant' => array( 'id' => 9 ),
				),
			)
		);
		$this->respond( 'POST /products/50/pricing', array( 'data' => array() ) );
		$this->product( array( $this->variant( 9, 0 ) ) );
		$this->respond( 'POST /products/variants/9', array( 'data' => array() ) );

		$out = $this->call(
			'fluentcart-product-create',
			array(
				'name'          => 'Mug',
				'description'   => '<p>Nice</p>',
				'regular_price' => '19.99',
			)
		);

		$this->assertSame( array( 'product_id' => 50 ), $out );
		$this->assertSame( 'draft', $this->calls[0]['params']['post_status'] );
		$this->assertSame( 'simple', $this->calls[0]['params']['detail']['variation_type'] );
		$this->assertSame(
			array(
				'post_title'   => 'Mug',
				'post_content' => '<p>Nice</p>',
			),
			$this->calls[1]['params']
		);
		$variant = $this->calls[3]['params']['variants'];
		$this->assertSame( 1999, $variant['item_price'], 'cents' );
		$this->assertSame( 0, $variant['compare_price'] );
		$this->assertSame( 7, $variant['total_stock'], 'the loaded row is written back, not rebuilt' );
	}

	public function test_create_refuses_a_bad_status_and_a_bad_price_before_writing(): void {
		$bad = $this->call(
			'fluentcart-product-create',
			array(
				'name'   => 'Mug',
				'status' => 'future',
			)
		);
		$this->assertSame( 'invalid_input', $bad->get_error_code() );
		$bad = $this->call(
			'fluentcart-product-create',
			array(
				'name'          => 'Mug',
				'regular_price' => 'free',
			)
		);
		$this->assertSame( 'invalid_input', $bad->get_error_code() );
		$this->assertSame( array(), $this->calls, 'nothing was sent to FluentCart' );
	}

	public function test_a_failed_follow_up_still_names_the_product_that_was_created(): void {
		$this->respond( 'POST /products', array( 'data' => array( 'ID' => 50 ) ) );
		$this->respond( 'POST /products/50/pricing', new WP_Error( 'rest_forbidden', 'nope', array( 'status' => 403 ) ) );

		$out = $this->call(
			'fluentcart-product-create',
			array(
				'name'        => 'Mug',
				'description' => 'x',
			)
		);

		$this->assertSame( 'rest_forbidden', $out->get_error_code() );
		$this->assertSame( 50, $out->get_error_data()['product_id'] );
	}

	public function test_update_sale_price_keeps_the_regular_and_writes_compare_price(): void {
		$this->product( array( $this->variant( 9, 2000 ) ) );
		$this->respond( 'POST /products/variants/9', array( 'data' => array() ) );

		$this->assertSame(
			array( 'product_id' => 50 ),
			$this->call(
				'fluentcart-product-update',
				array(
					'id'         => 50,
					'sale_price' => '15',
				)
			)
		);

		$variant = $this->calls[1]['params']['variants'];
		$this->assertSame( 1500, $variant['item_price'] );
		$this->assertSame( 2000, $variant['compare_price'] );
		$this->assertSame( 50, $variant['post_id'] );
	}

	public function test_update_can_clear_a_sale_and_refuses_a_sale_at_or_above_regular(): void {
		$this->product( array( $this->variant( 9, 1500, 2000 ) ) );
		$this->respond( 'POST /products/variants/9', array( 'data' => array() ) );

		$this->call(
			'fluentcart-product-update',
			array(
				'id'         => 50,
				'sale_price' => null,
			)
		);
		$this->assertSame( 2000, $this->calls[1]['params']['variants']['item_price'] );
		$this->assertSame( 0, $this->calls[1]['params']['variants']['compare_price'] );

		$this->calls = array();
		$bad         = $this->call(
			'fluentcart-product-update',
			array(
				'id'            => 50,
				'regular_price' => '10',
				'sale_price'    => '12',
			)
		);
		$this->assertSame( 'invalid_input', $bad->get_error_code() );
		$this->assertCount( 1, $this->calls, 'only the product read, no write' );
	}

	public function test_a_price_change_on_a_multi_variation_product_is_refused(): void {
		$this->product( array( $this->variant( 9, 1000 ), $this->variant( 10, 2000 ) ) );

		$out = $this->call(
			'fluentcart-product-update',
			array(
				'id'            => 50,
				'regular_price' => '5',
			)
		);

		$this->assertSame( 'unsupported', $out->get_error_code() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_update_status_and_text_use_the_partial_post_update_only(): void {
		$this->product( array( $this->variant( 9, 1000 ) ) );
		$this->respond( 'POST /products/50/pricing', array( 'data' => array() ) );

		$this->call(
			'fluentcart-product-update',
			array(
				'id'     => 50,
				'name'   => 'Cup',
				'status' => 'publish',
			)
		);

		$this->assertSame( 'POST', $this->calls[1]['method'] );
		$this->assertSame(
			array(
				'post_title'  => 'Cup',
				'post_status' => 'publish',
			),
			$this->calls[1]['params']
		);
		$this->assertCount( 2, $this->calls, 'no price write' );
	}

	public function test_set_product_image_checks_product_attachment_and_alt_text(): void {
		$GLOBALS['senroflux_test_posts'][50] = (object) array(
			'ID'         => 50,
			'post_type'  => 'fluent-products',
			'post_title' => 'Mug',
		);
		$GLOBALS['senroflux_test_posts'][60] = (object) array(
			'ID'             => 60,
			'post_type'      => 'attachment',
			'post_title'     => 'Photo',
			'post_mime_type' => 'image/jpeg',
		);
		$GLOBALS['senroflux_test_posts'][61] = (object) array(
			'ID'         => 61,
			'post_type'  => 'post',
			'post_title' => 'Not media',
		);

		$this->assertSame(
			'not_found',
			$this->call(
				'fluentcart-set-product-image',
				array(
					'product_id'    => 99,
					'attachment_id' => 60,
				)
			)->get_error_code()
		);
		$this->assertSame(
			'not_an_image',
			$this->call(
				'fluentcart-set-product-image',
				array(
					'product_id'    => 50,
					'attachment_id' => 61,
				)
			)->get_error_code()
		);
		$this->assertSame(
			'missing_alt',
			$this->call(
				'fluentcart-set-product-image',
				array(
					'product_id'    => 50,
					'attachment_id' => 60,
				)
			)->get_error_code()
		);

		$GLOBALS['senroflux_test_postmeta'][60]['_wp_attachment_image_alt'] = 'A mug';
		$this->assertSame(
			'forbidden',
			$this->call(
				'fluentcart-set-product-image',
				array(
					'product_id'    => 50,
					'attachment_id' => 60,
				)
			)->get_error_code(),
			'edit_post on THIS product is required'
		);

		$GLOBALS['senroflux_test_user_caps']['edit_post:50'] = true;
		$out = $this->call(
			'fluentcart-set-product-image',
			array(
				'product_id'    => 50,
				'attachment_id' => 60,
			)
		);
		$this->assertSame(
			array(
				'product_id'    => 50,
				'attachment_id' => 60,
			),
			$out
		);
		$this->assertSame( 60, $GLOBALS['senroflux_test_postmeta'][50]['_thumbnail_id'] );

		$this->call(
			'fluentcart-set-product-image',
			array(
				'product_id'    => 50,
				'attachment_id' => 60,
				'gallery'       => true,
			)
		);
		$this->call(
			'fluentcart-set-product-image',
			array(
				'product_id'    => 50,
				'attachment_id' => 60,
				'gallery'       => true,
			)
		);
		$gallery = $GLOBALS['senroflux_test_postmeta'][50]['fluent-products-gallery-image'];
		$this->assertCount( 1, $gallery, 'idempotent' );
		$this->assertSame( 60, $gallery[0]['id'] );
	}

	// ------------------------------------------------------------------
	// Coupons
	// ------------------------------------------------------------------

	public function test_coupon_create_calls_fluentcarts_own_code_as_an_inactive_draft(): void {
		FakeFluentCartCoupons::$result = array(
			'data' => array(
				'coupon_id' => 3,
				'code'      => 'SAVE10',
				'status'    => 'inactive',
			),
		);

		$out = $this->call(
			'fluentcart-coupon-create',
			array(
				'code'          => 'SAVE10',
				'discount_type' => 'percent',
				'amount'        => 10,
				'status'        => 'active',
			)
		);

		$this->assertSame(
			array(
				'coupon_id' => 3,
				'code'      => 'SAVE10',
				'status'    => 'inactive',
			),
			$out
		);
		$this->assertSame( 'create', FakeFluentCartCoupons::$calls[0]['action'] );
		$this->assertSame( 'inactive', FakeFluentCartCoupons::$calls[0]['status'] );
		$this->assertSame( 'percentage', FakeFluentCartCoupons::$calls[0]['type'] );
	}

	public function test_coupon_create_needs_code_type_and_amount(): void {
		$out = $this->call(
			'fluentcart-coupon-create',
			array(
				'code'          => 'X',
				'discount_type' => 'free_shipping',
				'amount'        => 1,
			)
		);

		$this->assertSame( 'invalid_input', $out->get_error_code() );
		$this->assertSame( array(), FakeFluentCartCoupons::$calls );
	}

	public function test_coupon_enable_changes_only_the_status_and_passes_fluentcarts_errors_through(): void {
		FakeFluentCartCoupons::$result = array(
			'data' => array(
				'coupon_id' => 3,
				'code'      => 'SAVE10',
				'status'    => 'active',
			),
		);
		$this->assertSame( 'active', $this->call( 'fluentcart-coupon-enable', array( 'coupon_id' => 3 ) )['status'] );
		$this->assertSame(
			array(
				'action'    => 'update',
				'coupon_id' => 3,
				'status'    => 'active',
			),
			FakeFluentCartCoupons::$calls[0]
		);

		FakeFluentCartCoupons::$result = new WP_Error( 'coupon_not_found', 'No coupon' );
		$this->assertSame( 'coupon_not_found', $this->call( 'fluentcart-coupon-enable', array( 'coupon_id' => 404 ) )->get_error_code() );
	}

	public function test_a_coupon_write_without_the_permission_never_reaches_fluentcart(): void {
		$GLOBALS['senroflux_test_user_caps'] = array();

		$out = FluentCartAbilities::executeCouponEnable( array( 'coupon_id' => 3 ) );

		$this->assertSame( 'forbidden', $out->get_error_code() );
		$this->assertSame( array(), FakeFluentCartCoupons::$calls );
	}

	// ------------------------------------------------------------------
	// Refund
	// ------------------------------------------------------------------

	/** @param list<array<string,mixed>> $transactions Transactions. */
	private function order( int $paid, int $refunded, array $transactions ): void {
		$this->respond(
			'GET /orders/7',
			array(
				'order' => array(
					'id'           => 7,
					'total_paid'   => $paid,
					'total_refund' => $refunded,
					'transactions' => $transactions,
				),
			)
		);
	}

	public function test_refund_posts_cents_against_the_latest_successful_charge(): void {
		$this->order(
			5000,
			1000,
			array(
				array(
					'id'               => 1,
					'transaction_type' => 'charge',
					'status'           => 'succeeded',
					'payment_mode'     => 'live',
				),
				array(
					'id'               => 4,
					'transaction_type' => 'refund',
					'status'           => 'succeeded',
				),
				array(
					'id'               => 3,
					'transaction_type' => 'charge',
					'status'           => 'succeeded',
					'payment_mode'     => 'test',
				),
				array(
					'id'               => 5,
					'transaction_type' => 'charge',
					'status'           => 'failed',
				),
			)
		);
		$this->respond( 'POST /orders/7/refund', array( 'fluent_cart_refund' => array( 'status' => 'success' ) ) );

		$out = $this->call(
			'fluentcart-orders-refund',
			array(
				'order_id' => 7,
				'amount'   => 12.5,
				'reason'   => 'damaged',
			)
		);

		$this->assertSame( 7, $out['order_id'] );
		$this->assertSame( 27.5, $out['remaining_refundable'] );
		$this->assertSame( 'test', $out['payment_mode'] );
		$this->assertSame(
			array(
				'refund_info' => array(
					'transaction_id' => 3,
					'amount'         => 1250,
					'reason'         => 'damaged',
				),
			),
			$this->calls[1]['params']
		);
	}

	public function test_refund_refuses_too_much_no_charge_and_a_non_positive_amount_without_posting(): void {
		$this->order(
			5000,
			4900,
			array(
				array(
					'id'               => 1,
					'transaction_type' => 'charge',
					'status'           => 'succeeded',
				),
			)
		);
		$this->assertSame(
			'refund_too_large',
			$this->call(
				'fluentcart-orders-refund',
				array(
					'order_id' => 7,
					'amount'   => 5,
					'reason'   => 'x',
				)
			)->get_error_code()
		);

		$this->order( 5000, 0, array() );
		$this->assertSame(
			'transaction_not_found',
			$this->call(
				'fluentcart-orders-refund',
				array(
					'order_id' => 7,
					'amount'   => 5,
					'reason'   => 'x',
				)
			)->get_error_code()
		);

		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-orders-refund',
				array(
					'order_id' => 7,
					'amount'   => 0,
					'reason'   => 'x',
				)
			)->get_error_code()
		);

		foreach ( $this->calls as $call ) {
			$this->assertSame( 'GET', $call['method'] );
		}
	}

	public function test_a_second_refund_in_one_run_hits_the_shared_refund_budget(): void {
		$store  = new WpdbRunStore( new wpdb() );
		$run_id = $store->createRun( 1, 'test', 'goal', array(), Budget::defaults() );
		Abilities::useRunContext( $run_id, $store );
		$this->order(
			5000,
			0,
			array(
				array(
					'id'               => 1,
					'transaction_type' => 'charge',
					'status'           => 'succeeded',
				),
			)
		);
		$this->respond( 'POST /orders/7/refund', array() );

		$this->assertIsArray(
			$this->call(
				'fluentcart-orders-refund',
				array(
					'order_id' => 7,
					'amount'   => 1,
					'reason'   => 'a',
				)
			)
		);
		$store->appendStep( $run_id, \Specflux\SenroFlux\Run\StepKind::ToolResult, array( 'ok' => true ), 'wpab__senroflux__fluentcart-orders-refund', null, 'ok' );

		$second = $this->call(
			'fluentcart-orders-refund',
			array(
				'order_id' => 7,
				'amount'   => 1,
				'reason'   => 'b',
			)
		);

		$this->assertSame( 'budget_exhausted', $second->get_error_code() );
	}

	public function test_a_gateway_refusal_surfaces_as_the_error(): void {
		$this->order(
			5000,
			0,
			array(
				array(
					'id'               => 1,
					'transaction_type' => 'charge',
					'status'           => 'succeeded',
				),
			)
		);
		$this->respond( 'POST /orders/7/refund', new WP_Error( 'fluentcart_rest_error', 'Gateway said no', array( 'status' => 400 ) ) );

		$out = $this->call(
			'fluentcart-orders-refund',
			array(
				'order_id' => 7,
				'amount'   => 1,
				'reason'   => 'a',
			)
		);

		$this->assertSame( 'Gateway said no', $out->get_error_message() );
	}

	// ------------------------------------------------------------------
	// Shipping and tax
	// ------------------------------------------------------------------

	public function test_a_new_zone_is_created_then_its_methods_added(): void {
		$this->respond( 'POST /shipping/zones', array( 'shipping_zone' => array( 'id' => 4 ) ) );
		$this->respond( 'POST /shipping/methods', array() );

		$out = $this->call(
			'fluentcart-shipping-zone-save',
			array(
				'name'      => 'EU',
				'countries' => array( 'de', 'FR', 'de' ),
				'methods'   => array(
					array(
						'title'  => 'Flat',
						'type'   => 'flat_rate',
						'amount' => 5,
					),
				),
			)
		);

		$this->assertSame(
			array(
				'zone_id'       => 4,
				'methods_added' => 1,
			),
			$out
		);
		$this->assertSame( 'selection', $this->calls[0]['params']['region'] );
		$this->assertSame( array( 'DE', 'FR' ), $this->calls[0]['params']['meta']['countries'] );
		$this->assertSame( 4, $this->calls[1]['params']['zone_id'] );
		$this->assertSame( 1, $this->calls[1]['params']['is_enabled'] );
	}

	public function test_an_update_uses_put_and_no_countries_means_the_whole_world(): void {
		$this->respond( 'PUT /shipping/zones/4', array( 'shipping_zone' => array( 'id' => 4 ) ) );

		$out = $this->call(
			'fluentcart-shipping-zone-save',
			array(
				'zone_id' => 4,
				'name'    => 'World',
			)
		);

		$this->assertSame(
			array(
				'zone_id'       => 4,
				'methods_added' => 0,
			),
			$out
		);
		$this->assertSame( 'PUT', $this->calls[0]['method'] );
		$this->assertSame( 'all', $this->calls[0]['params']['region'] );
	}

	public function test_zone_input_is_validated_before_anything_is_sent(): void {
		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-shipping-zone-save',
				array(
					'name'      => 'X',
					'countries' => array( 'Germany' ),
				)
			)->get_error_code()
		);
		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-shipping-zone-save',
				array(
					'name'    => 'X',
					'methods' => array( array( 'title' => 'only title' ) ),
				)
			)->get_error_code()
		);
		$this->assertSame( array(), $this->calls );
	}

	public function test_a_failed_method_add_still_names_the_zone(): void {
		$this->respond( 'POST /shipping/zones', array( 'shipping_zone' => array( 'id' => 4 ) ) );
		$this->respond( 'POST /shipping/methods', new WP_Error( 'bad', 'bad method', array( 'status' => 422 ) ) );

		$out = $this->call(
			'fluentcart-shipping-zone-save',
			array(
				'name'    => 'EU',
				'methods' => array(
					array(
						'title' => 'F',
						'type'  => 'flat_rate',
					),
				),
			)
		);

		$this->assertSame( 4, $out->get_error_data()['zone_id'] );
	}

	public function test_tax_rate_create_and_update(): void {
		$this->respond( 'POST /tax/country/rate', array( 'tax_rate' => array( 'id' => 6 ) ) );
		$this->respond( 'PUT /tax/country/rate/6', array( 'tax_rate' => array( 'id' => 6 ) ) );

		$created = $this->call(
			'fluentcart-tax-rate-save',
			array(
				'country'  => 'de',
				'rate'     => 19,
				'name'     => 'VAT',
				'shipping' => true,
			)
		);
		$this->assertSame( array( 'tax_rate_id' => 6 ), $created );
		$this->assertSame( 'DE', $this->calls[0]['params']['country'] );
		$this->assertSame( 1, $this->calls[0]['params']['for_shipping'] );
		$this->assertSame( 0, $this->calls[0]['params']['is_compound'] );

		$updated = $this->call(
			'fluentcart-tax-rate-save',
			array(
				'tax_rate_id' => 6,
				'country'     => 'DE',
				'rate'        => 7,
				'name'        => 'VAT',
			)
		);
		$this->assertSame( array( 'tax_rate_id' => 6 ), $updated );
		$this->assertSame( 'PUT', $this->calls[1]['method'] );
	}

	public function test_tax_rate_input_is_validated(): void {
		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-tax-rate-save',
				array(
					'country' => 'Germany',
					'rate'    => 1,
					'name'    => 'x',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-tax-rate-save',
				array(
					'country' => 'DE',
					'rate'    => 150,
					'name'    => 'x',
				)
			)->get_error_code()
		);
		$this->assertSame( array(), $this->calls );
	}

	// ------------------------------------------------------------------
	// Report
	// ------------------------------------------------------------------

	public function test_the_report_sums_cents_into_store_currency(): void {
		$GLOBALS['wpdb'] = new class() extends wpdb {
			public function get_results( ?string $query = null, string $output = 'ARRAY_A' ): array {
				$this->queries[] = (string) $query;
				if ( str_contains( (string) $query, 'fct_product_variations' ) ) {
					return array( array( 'post_id' => '12' ) );
				}

				return array(
					array(
						'currency'    => 'USD',
						'order_count' => '3',
						'gross'       => '30000',
						'refunds'     => '2550',
					),
				);
			}
		};

		$out = $this->call(
			'fluentcart-store-report',
			array(
				'from' => '2026-01-01',
				'to'   => '2026-01-31',
			)
		);

		$this->assertSame( 3, $out['order_count'] );
		$this->assertSame( 300.0, $out['gross_sales'] );
		$this->assertSame( 25.5, $out['refunds_total'] );
		$this->assertSame( 274.5, $out['net_sales'] );
		$this->assertSame( 'USD', $out['currency'] );
		$this->assertSame( array( 12 ), $out['low_stock_products'] );
		$this->assertStringContainsString( "created_at >= '2026-01-01 00:00:00'", $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( "created_at <= '2026-01-31 23:59:59'", $GLOBALS['wpdb']->queries[0] );
	}

	public function test_the_report_window_is_bounded_and_dates_are_validated(): void {
		$this->assertSame(
			'window_too_long',
			$this->call(
				'fluentcart-store-report',
				array(
					'from' => '2026-01-01',
					'to'   => '2026-06-01',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-store-report',
				array(
					'from' => 'nope',
					'to'   => '2026-06-01',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'invalid_input',
			$this->call(
				'fluentcart-store-report',
				array(
					'from' => '2026-02-01',
					'to'   => '2026-01-01',
				)
			)->get_error_code()
		);
	}
}
