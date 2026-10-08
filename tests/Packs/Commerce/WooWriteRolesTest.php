<?php
/**
 * C4-W: the WooCommerce write roles — order status, product trash, stock,
 * variation price, sale schedule. Platform rules (inputs, verbs, tiers, ids)
 * plus the three `senroflux/` polyfills against the CRUD fakes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\Abilities;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Packs\Commerce\CommerceSummary;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;

final class WooWriteRolesTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/commerce.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_products']           = array();
		$GLOBALS['senroflux_test_orders']             = array();
		$GLOBALS['senroflux_test_product_rows']       = array();
		$GLOBALS['senroflux_test_product_saves']      = array();
		$GLOBALS['senroflux_test_stock_calls']        = array();
		$GLOBALS['senroflux_test_user_caps']          = array( 'manage_woocommerce' => true );

		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();
	}

	protected function tearDown(): void {
		$GLOBALS['senroflux_test_user_caps'] = array();
	}

	private function row( int $id, array $props ): void {
		$GLOBALS['senroflux_test_product_rows'][ $id ] = (object) $props;
	}

	private function call( string $name, array $input ): mixed {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "ability $name was not registered" );

		return $ability->execute( $input );
	}

	private function tier( string $ability, array $input ): int {
		$pack = new CommercePack();

		return VerbTier::tierFor( $pack->verbFor( $ability, $input ), $pack->verbMap() );
	}

	private function assertRefused( string $code, mixed $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	// ------------------------------------------------------------------
	// Roles and tiers
	// ------------------------------------------------------------------

	public function test_the_five_roles_resolve_and_polyfills_register(): void {
		$pack  = new CommercePack();
		$roles = $pack->roles();

		$this->assertSame( 'order-update-status', $roles['order-status'] );
		$this->assertSame( 'product-delete', $roles['product-trash'] );
		$this->assertSame( 'stock-adjust', $roles['stock'] );
		$this->assertSame( 'variation-price-update', $roles['variation-price'] );
		$this->assertSame( 'sale-schedule', $roles['sale-schedule'] );

		foreach ( array( 'stock-adjust', 'variation-price-update', 'sale-schedule' ) as $name ) {
			$this->assertNotNull( wp_get_ability( 'senroflux/' . $name ), $name );
		}
		// The two native roles are Woo's own: no polyfill is registered over them.
		$this->assertNull( wp_get_ability( 'senroflux/order-update-status' ) );
		$this->assertNull( wp_get_ability( 'senroflux/product-delete' ) );
	}

	/** @return array<string,array{string,array<string,mixed>,int}> */
	public static function tierRows(): array {
		return array(
			'status completed'  => array(
				'woocommerce/order-update-status',
				array(
					'id'     => 1,
					'status' => 'completed',
				),
				2,
			),
			'status on-hold'    => array(
				'woocommerce/order-update-status',
				array(
					'id'     => 1,
					'status' => 'on-hold',
				),
				2,
			),
			'status wc- prefix' => array(
				'woocommerce/order-update-status',
				array(
					'id'     => 1,
					'status' => 'wc-Completed',
				),
				2,
			),
			'status failed'     => array(
				'woocommerce/order-update-status',
				array(
					'id'     => 1,
					'status' => 'failed',
				),
				1,
			),
			'trash'             => array( 'woocommerce/product-delete', array( 'id' => 5 ), 1 ),
			'trash force false' => array(
				'woocommerce/product-delete',
				array(
					'id'    => 5,
					'force' => false,
				),
				1,
			),
			'force true'        => array(
				'woocommerce/product-delete',
				array(
					'id'    => 5,
					'force' => true,
				),
				2,
			),
			'force string'      => array(
				'woocommerce/product-delete',
				array(
					'id'    => 5,
					'force' => 'yes',
				),
				2,
			),
			'stock'             => array(
				'senroflux/stock-adjust',
				array(
					'id'       => 5,
					'mode'     => 'set',
					'quantity' => 3,
				),
				2,
			),
			'variation price'   => array(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 5,
					'variation_id'  => 6,
					'regular_price' => '9',
				),
				2,
			),
			'sale schedule'     => array(
				'senroflux/sale-schedule',
				array(
					'id'         => 5,
					'sale_price' => '4',
				),
				2,
			),
		);
	}

	/** @param array<string,mixed> $input */
	#[DataProvider( 'tierRows' )]
	public function test_tiers( string $ability, array $input, int $expected ): void {
		$this->assertSame( $expected, $this->tier( $ability, $input ) );
	}

	public function test_variation_price_and_sale_schedule_share_the_price_change_verb(): void {
		$pack = new CommercePack();
		$this->assertSame( 'commerce/price-change', $pack->verbFor( 'senroflux/variation-price-update', array() ) );
		$this->assertSame( 'commerce/price-change', $pack->verbFor( 'senroflux/sale-schedule', array() ) );
		$this->assertSame( array( 'commerce/price-change' ), $pack->roleVerbs()['variation-price'] );
	}

	public function test_native_input_properties_are_declared_for_shape_compat(): void {
		$platform = ( new CommercePack() )->platform();

		$this->assertSame( array( 'id', 'status', 'note' ), $platform->inputProperties( 'order-update-status' ) );
		$this->assertSame( array( 'id', 'force' ), $platform->inputProperties( 'product-delete' ) );
	}

	// ------------------------------------------------------------------
	// validateCall (before the ability runs)
	// ------------------------------------------------------------------

	public function test_a_force_delete_is_refused_whole(): void {
		$pack = new CommercePack();

		foreach ( array( true, 1, '1', 'true', 'yes' ) as $force ) {
			$this->assertRefused(
				'force_delete_refused',
				$pack->validateCall(
					'woocommerce/product-delete',
					array(
						'id'    => 5,
						'force' => $force,
					)
				)
			);
		}
		foreach ( array( false, 0, '0', 'false', '' ) as $force ) {
			$this->assertNull(
				$pack->validateCall(
					'woocommerce/product-delete',
					array(
						'id'    => 5,
						'force' => $force,
					)
				)
			);
		}
		$this->assertNull( $pack->validateCall( 'woocommerce/product-delete', array( 'id' => 5 ) ) );
	}

	public function test_order_status_needs_a_status(): void {
		$pack = new CommercePack();

		$this->assertRefused( 'invalid_input', $pack->validateCall( 'woocommerce/order-update-status', array( 'id' => 1 ) ) );
		$this->assertRefused(
			'invalid_input',
			$pack->validateCall(
				'woocommerce/order-update-status',
				array(
					'id'     => 1,
					'status' => '  ',
				)
			)
		);
		$this->assertNull(
			$pack->validateCall(
				'woocommerce/order-update-status',
				array(
					'id'     => 1,
					'status' => 'completed',
				)
			)
		);
	}

	/** @return array<string,array{string,array<string,mixed>,string}> */
	public static function badCalls(): array {
		return array(
			'stock neither'           => array( 'senroflux/stock-adjust', array( 'id' => 5 ), 'invalid_input' ),
			'stock both'              => array(
				'senroflux/stock-adjust',
				array(
					'id'           => 5,
					'mode'         => 'set',
					'quantity'     => 1,
					'stock_status' => 'instock',
				),
				'invalid_input',
			),
			'stock bad mode'          => array(
				'senroflux/stock-adjust',
				array(
					'id'       => 5,
					'mode'     => 'multiply',
					'quantity' => 1,
				),
				'invalid_input',
			),
			'stock negative'          => array(
				'senroflux/stock-adjust',
				array(
					'id'       => 5,
					'mode'     => 'set',
					'quantity' => -1,
				),
				'invalid_input',
			),
			'stock fractional'        => array(
				'senroflux/stock-adjust',
				array(
					'id'       => 5,
					'mode'     => 'set',
					'quantity' => 1.5,
				),
				'invalid_input',
			),
			'stock bad status'        => array(
				'senroflux/stock-adjust',
				array(
					'id'           => 5,
					'stock_status' => 'lots',
				),
				'invalid_input',
			),
			'stock no id'             => array(
				'senroflux/stock-adjust',
				array(
					'mode'     => 'set',
					'quantity' => 1,
				),
				'invalid_input',
			),
			'variation no price'      => array(
				'senroflux/variation-price-update',
				array(
					'product_id'   => 5,
					'variation_id' => 6,
				),
				'invalid_input',
			),
			'variation no parent'     => array(
				'senroflux/variation-price-update',
				array(
					'variation_id'  => 6,
					'regular_price' => '1',
				),
				'invalid_input',
			),
			'variation bad price'     => array(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 5,
					'variation_id'  => 6,
					'regular_price' => '$9',
				),
				'invalid_price',
			),
			'variation negative'      => array(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 5,
					'variation_id'  => 6,
					'regular_price' => -1,
				),
				'invalid_price',
			),
			'variation blank regular' => array(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 5,
					'variation_id'  => 6,
					'regular_price' => '',
				),
				'invalid_price',
			),
			'variation sale >= reg'   => array(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 5,
					'variation_id'  => 6,
					'regular_price' => '10',
					'sale_price'    => '10',
				),
				'sale_not_below_regular',
			),
			'sale no price'           => array( 'senroflux/sale-schedule', array( 'id' => 5 ), 'invalid_input' ),
			'sale bad date'           => array(
				'senroflux/sale-schedule',
				array(
					'id'                => 5,
					'sale_price'        => '4',
					'date_on_sale_from' => 'next friday',
				),
				'invalid_date',
			),
			'sale impossible date'    => array(
				'senroflux/sale-schedule',
				array(
					'id'                => 5,
					'sale_price'        => '4',
					'date_on_sale_from' => '2026-02-31',
				),
				'invalid_date',
			),
			'sale ends before start'  => array(
				'senroflux/sale-schedule',
				array(
					'id'                => 5,
					'sale_price'        => '4',
					'date_on_sale_from' => '2026-12-02',
					'date_on_sale_to'   => '2026-12-01',
				),
				'invalid_date',
			),
			'sale clear with dates'   => array(
				'senroflux/sale-schedule',
				array(
					'id'              => 5,
					'sale_price'      => '',
					'date_on_sale_to' => '2026-12-01',
				),
				'invalid_input',
			),
		);
	}

	/** @param array<string,mixed> $input */
	#[DataProvider( 'badCalls' )]
	public function test_validate_call_refuses( string $ability, array $input, string $code ): void {
		$this->assertRefused( $code, ( new CommercePack() )->validateCall( $ability, $input ) );
	}

	public function test_a_same_day_sale_window_is_valid(): void {
		$this->assertNull(
			( new CommercePack() )->validateCall(
				'senroflux/sale-schedule',
				array(
					'id'                => 5,
					'sale_price'        => '4',
					'date_on_sale_from' => '2026-12-01',
					'date_on_sale_to'   => '2026-12-01',
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// stock-adjust
	// ------------------------------------------------------------------

	public function test_stock_increase_decrease_and_set_use_the_stock_function(): void {
		$this->row(
			5,
			array(
				'manage_stock'   => true,
				'stock_quantity' => 10,
			)
		);

		$up = $this->call(
			'senroflux/stock-adjust',
			array(
				'id'       => 5,
				'mode'     => 'increase',
				'quantity' => 5,
			)
		);
		$this->assertSame( 15, $up['stock_quantity'] );
		$this->assertSame( 5, $up['product_id'] );
		$this->assertNull( $up['variation_id'] );

		$down = $this->call(
			'senroflux/stock-adjust',
			array(
				'id'       => 5,
				'mode'     => 'decrease',
				'quantity' => 3,
			)
		);
		$this->assertSame( 12, $down['stock_quantity'] );

		$set = $this->call(
			'senroflux/stock-adjust',
			array(
				'id'       => 5,
				'mode'     => 'set',
				'quantity' => 0,
			)
		);
		$this->assertSame( 0, $set['stock_quantity'] );

		$this->assertSame(
			array( array( 5, 'increase', 5 ), array( 5, 'decrease', 3 ), array( 5, 'set', 0 ) ),
			$GLOBALS['senroflux_test_stock_calls']
		);
	}

	public function test_stock_refuses_below_zero_and_leaves_stock_alone(): void {
		$this->row(
			5,
			array(
				'manage_stock'   => true,
				'stock_quantity' => 2,
			)
		);

		$this->assertRefused(
			'stock_below_zero',
			$this->call(
				'senroflux/stock-adjust',
				array(
					'id'       => 5,
					'mode'     => 'decrease',
					'quantity' => 3,
				)
			)
		);
		$this->assertSame( array(), $GLOBALS['senroflux_test_stock_calls'] );
		$this->assertSame( 2, $GLOBALS['senroflux_test_product_rows'][5]->stock_quantity );
	}

	public function test_stock_quantity_is_refused_on_an_unmanaged_product_and_status_is_refused_on_a_managed_one(): void {
		$this->row( 5, array( 'manage_stock' => false ) );
		$this->row(
			6,
			array(
				'manage_stock'   => true,
				'stock_quantity' => 1,
			)
		);

		$this->assertRefused(
			'stock_not_managed',
			$this->call(
				'senroflux/stock-adjust',
				array(
					'id'       => 5,
					'mode'     => 'set',
					'quantity' => 1,
				)
			)
		);
		$this->assertRefused(
			'stock_managed',
			$this->call(
				'senroflux/stock-adjust',
				array(
					'id'           => 6,
					'stock_status' => 'outofstock',
				)
			)
		);
	}

	public function test_stock_status_is_set_through_crud_on_an_unmanaged_product(): void {
		$this->row( 5, array( 'manage_stock' => false ) );

		$result = $this->call(
			'senroflux/stock-adjust',
			array(
				'id'           => 5,
				'stock_status' => 'outofstock',
			)
		);

		$this->assertSame( 'outofstock', $result['stock_status'] );
		$this->assertContains( array( 5, 'save' ), $GLOBALS['senroflux_test_product_saves'] );
	}

	public function test_stock_on_a_variation_reports_the_parent(): void {
		$this->row( 5, array( 'type' => 'variable' ) );
		$this->row(
			6,
			array(
				'type'           => 'variation',
				'parent_id'      => 5,
				'manage_stock'   => true,
				'stock_quantity' => 4,
			)
		);

		$result = $this->call(
			'senroflux/stock-adjust',
			array(
				'id'       => 6,
				'mode'     => 'increase',
				'quantity' => 1,
			)
		);

		$this->assertSame( 5, $result['product_id'] );
		$this->assertSame( 6, $result['variation_id'] );
		$this->assertSame( '5', ( new CommercePack() )->objectIdForWrite( 'commerce/stock-adjust', array( 'id' => 6 ), $result ) );
	}

	public function test_stock_on_a_missing_product_and_every_polyfill_needs_manage_woocommerce(): void {
		$this->assertRefused(
			'not_found',
			$this->call(
				'senroflux/stock-adjust',
				array(
					'id'       => 99,
					'mode'     => 'set',
					'quantity' => 1,
				)
			)
		);

		$inputs = array(
			'stock-adjust'           => array( 'id' => 5 ),
			'variation-price-update' => array(
				'product_id'   => 5,
				'variation_id' => 6,
			),
			'sale-schedule'          => array( 'id' => 5 ),
		);
		foreach ( $inputs as $name => $input ) {
			$ability                             = wp_get_ability( 'senroflux/' . $name );
			$GLOBALS['senroflux_test_user_caps'] = array(
				'manage_woocommerce' => true,
				'edit_post'          => true,
			);
			$this->assertTrue( $ability->check_permissions( $input ), $name );

			// No store capability.
			$GLOBALS['senroflux_test_user_caps'] = array( 'edit_post' => true );
			$this->assertNotTrue( $ability->check_permissions( $input ), $name . ' without manage_woocommerce' );

			// Store capability but no edit on the named object (each id in turn).
			foreach ( array_filter( $input ) as $key => $id ) {
				$GLOBALS['senroflux_test_user_caps'] = array(
					'manage_woocommerce' => true,
					'edit_post'          => true,
					'edit_post:' . $id   => false,
				);
				$this->assertNotTrue( $ability->check_permissions( $input ), $name . ' without edit on ' . $key );
			}

			// Store capability and edit, but no object named.
			$GLOBALS['senroflux_test_user_caps'] = array(
				'manage_woocommerce' => true,
				'edit_post'          => true,
			);
			$this->assertNotTrue( $ability->check_permissions( array() ), $name . ' naming nothing' );
		}
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_woocommerce' => true );
	}

	// ------------------------------------------------------------------
	// variation-price-update
	// ------------------------------------------------------------------

	public function test_variation_price_is_written_through_crud(): void {
		$this->row( 5, array( 'type' => 'variable' ) );
		$this->row(
			6,
			array(
				'type'          => 'variation',
				'parent_id'     => 5,
				'regular_price' => '10',
				'sale_price'    => '',
			)
		);

		$result = $this->call(
			'senroflux/variation-price-update',
			array(
				'product_id'    => 5,
				'variation_id'  => 6,
				'regular_price' => '12.50',
				'sale_price'    => 9,
			)
		);

		$this->assertSame(
			array(
				'product_id'    => 5,
				'variation_id'  => 6,
				'regular_price' => '12.50',
				'sale_price'    => '9',
			),
			$result
		);
		$this->assertContains( array( 6, 'save' ), $GLOBALS['senroflux_test_product_saves'] );
		$this->assertSame(
			'5',
			( new CommercePack() )->objectIdForWrite(
				'commerce/price-change',
				array(
					'product_id'   => 5,
					'variation_id' => 6,
				),
				$result
			)
		);
	}

	public function test_variation_price_refuses_a_variation_of_another_parent_or_a_non_variation(): void {
		$this->row( 5, array( 'type' => 'variable' ) );
		$this->row(
			6,
			array(
				'type'          => 'variation',
				'parent_id'     => 5,
				'regular_price' => '10',
			)
		);
		$this->row(
			7,
			array(
				'type'          => 'simple',
				'regular_price' => '10',
			)
		);

		$this->assertRefused(
			'not_found',
			$this->call(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 8,
					'variation_id'  => 6,
					'regular_price' => '1',
				)
			)
		);
		$this->assertRefused(
			'not_found',
			$this->call(
				'senroflux/variation-price-update',
				array(
					'product_id'    => 5,
					'variation_id'  => 7,
					'regular_price' => '1',
				)
			)
		);
		$this->assertSame( array(), $GLOBALS['senroflux_test_product_saves'] );
	}

	public function test_variation_sale_must_stay_below_the_stored_regular_price(): void {
		$this->row( 5, array( 'type' => 'variable' ) );
		$this->row(
			6,
			array(
				'type'          => 'variation',
				'parent_id'     => 5,
				'regular_price' => '10',
			)
		);

		$this->assertRefused(
			'sale_not_below_regular',
			$this->call(
				'senroflux/variation-price-update',
				array(
					'product_id'   => 5,
					'variation_id' => 6,
					'sale_price'   => '10',
				)
			)
		);
		$this->assertSame( '10', $GLOBALS['senroflux_test_product_rows'][6]->regular_price );
	}

	// ------------------------------------------------------------------
	// sale-schedule
	// ------------------------------------------------------------------

	public function test_a_sale_window_is_written_with_the_end_date_as_end_of_day(): void {
		$this->row( 5, array( 'regular_price' => '20' ) );

		$result = $this->call(
			'senroflux/sale-schedule',
			array(
				'id'                => 5,
				'sale_price'        => '15',
				'date_on_sale_from' => '2026-12-05',
				'date_on_sale_to'   => '2026-12-07',
			)
		);

		$this->assertSame( '15', $result['sale_price'] );
		$this->assertSame( '2026-12-05 00:00:00', $result['date_on_sale_from'] );
		$this->assertSame( '2026-12-07 23:59:59', $result['date_on_sale_to'] );
		$this->assertSame( '2026-12-07 23:59:59', $GLOBALS['senroflux_test_product_rows'][5]->date_on_sale_to );
		$this->assertSame( 5, $result['product_id'] );
	}

	public function test_omitted_dates_are_cleared_and_an_empty_price_ends_the_sale(): void {
		$this->row(
			5,
			array(
				'regular_price'     => '20',
				'sale_price'        => '15',
				'date_on_sale_from' => '2026-12-05 00:00:00',
				'date_on_sale_to'   => '2026-12-07 23:59:59',
			)
		);

		$open = $this->call(
			'senroflux/sale-schedule',
			array(
				'id'         => 5,
				'sale_price' => '14',
			)
		);
		$this->assertNull( $open['date_on_sale_from'] );
		$this->assertNull( $GLOBALS['senroflux_test_product_rows'][5]->date_on_sale_to );

		$ended = $this->call(
			'senroflux/sale-schedule',
			array(
				'id'         => 5,
				'sale_price' => '',
			)
		);
		$this->assertSame( '', $ended['sale_price'] );
		$this->assertSame( '', $GLOBALS['senroflux_test_product_rows'][5]->sale_price );
	}

	public function test_sale_schedule_refuses_a_sale_at_or_above_regular_a_variable_parent_and_a_missing_product(): void {
		$this->row( 5, array( 'regular_price' => '20' ) );
		$this->row( 6, array( 'type' => 'variable' ) );

		$this->assertRefused(
			'sale_not_below_regular',
			$this->call(
				'senroflux/sale-schedule',
				array(
					'id'         => 5,
					'sale_price' => '20',
				)
			)
		);
		$this->assertRefused(
			'unsupported_type',
			$this->call(
				'senroflux/sale-schedule',
				array(
					'id'         => 6,
					'sale_price' => '1',
				)
			)
		);
		$this->assertRefused(
			'not_found',
			$this->call(
				'senroflux/sale-schedule',
				array(
					'id'         => 99,
					'sale_price' => '1',
				)
			)
		);
		$this->assertSame( array(), $GLOBALS['senroflux_test_product_saves'] );
	}

	public function test_polyfills_revalidate_when_called_without_the_pack_gate(): void {
		$this->row( 5, array( 'regular_price' => '20' ) );

		$this->assertRefused(
			'invalid_date',
			$this->call(
				'senroflux/sale-schedule',
				array(
					'id'                => 5,
					'sale_price'        => '4',
					'date_on_sale_from' => 'soon',
				)
			)
		);
		$this->assertRefused( 'invalid_input', $this->call( 'senroflux/stock-adjust', array( 'id' => 5 ) ) );
	}

	// ------------------------------------------------------------------
	// Approval cards and ids
	// ------------------------------------------------------------------

	public function test_order_status_ids_and_the_card(): void {
		$pack = new CommercePack();

		$this->assertSame( 'order:77', $pack->objectIdPrefix( 'commerce/order-status-notify' ) . $pack->objectIdForWrite( 'commerce/order-status-notify', array( 'id' => 77 ), array() ) );

		$GLOBALS['senroflux_test_orders'][77] = (object) array( 'status' => 'processing' );
		$card                                 = CommerceSummary::filter(
			'fallback',
			'woocommerce/order-update-status',
			array(
				'id'     => 77,
				'status' => 'completed',
			)
		);
		$this->assertStringContainsString( 'order #77', $card );
		$this->assertStringContainsString( 'to completed', $card );

		// A Tier 1 status keeps the plain summary.
		$this->assertSame(
			'fallback',
			CommerceSummary::filter(
				'fallback',
				'woocommerce/order-update-status',
				array(
					'id'     => 77,
					'status' => 'failed',
				)
			)
		);
	}

	public function test_stock_and_sale_cards_show_current_beside_proposed(): void {
		$this->row(
			5,
			array(
				'manage_stock'   => true,
				'stock_quantity' => 10,
				'regular_price'  => '20',
				'name'           => 'Mug',
			)
		);

		$stock = CommerceSummary::filter(
			'fallback',
			'senroflux/stock-adjust',
			array(
				'id'       => 5,
				'mode'     => 'decrease',
				'quantity' => 4,
			)
		);
		$this->assertStringContainsString( 'current 10', $stock );
		$this->assertStringContainsString( 'proposed decrease 4', $stock );

		$sale = CommerceSummary::filter(
			'fallback',
			'senroflux/sale-schedule',
			array(
				'id'              => 5,
				'sale_price'      => '15',
				'date_on_sale_to' => '2026-12-07',
			)
		);
		$this->assertStringContainsString( 'proposed sale 15', $sale );
		$this->assertStringContainsString( 'sale to 2026-12-07', $sale );
	}

	public function test_a_variation_price_card_reads_the_variation(): void {
		$this->row(
			6,
			array(
				'type'          => 'variation',
				'parent_id'     => 5,
				'regular_price' => '10',
			)
		);

		$card = CommerceSummary::filter(
			'fallback',
			'senroflux/variation-price-update',
			array(
				'product_id'    => 5,
				'variation_id'  => 6,
				'regular_price' => '12',
			)
		);

		$this->assertStringContainsString( 'current regular 10', $card );
		$this->assertStringContainsString( 'proposed regular 12', $card );
	}
}
