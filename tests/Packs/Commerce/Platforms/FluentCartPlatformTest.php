<?php
/**
 * The FluentCart store platform (C4-F): detection, role map and resolution
 * (MCP module on and off), native shaping, verbs and tiers, cards, lookups.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce\Platforms;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Packs\Commerce\CommerceSummary;
use Specflux\SenroFlux\Packs\Commerce\Platforms\FluentCart\FluentCartPlatform;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SenroFlux\Packs\Commerce\ReportLookup;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Tools\VerbTier;
use wpdb;

final class FluentCartPlatformTest extends TestCase {

	private FluentCartPlatform $platform;

	protected function setUp(): void {
		remove_all_filters( 'senroflux_store_platforms' );
		$GLOBALS['senroflux_test_options']   = array();
		$GLOBALS['senroflux_test_abilities'] = array();
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );
		$GLOBALS['wpdb']                     = new wpdb();
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		FluentCartPlatform::boot();
		FluentCartPlatform::useVersionProbe( static fn (): ?string => '1.7.0' );
		$this->platform = new FluentCartPlatform();
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_store_platforms' );
		FluentCartPlatform::useVersionProbe( null );
		$GLOBALS['senroflux_test_abilities'] = array();
		$GLOBALS['senroflux_test_user_caps'] = array();
		unset( $GLOBALS['wpdb'] );
		Plugin::set_dependency_probe( null );
		Plugin::reset();
	}

	private function pack(): CommercePack {
		$pack = new CommercePack();
		$pack->useRunPlatform( 'fluentcart' );

		return $pack;
	}

	/**
	 * @param array<string,mixed> $row Row.
	 */
	private function row( string $table, array $row ): void {
		$GLOBALS['wpdb']->tables[ 'wp_' . $table ][] = $row;
	}

	/** @param list<string> $names Native ability names under fluent-cart/. */
	private function registerNatives( array $names ): void {
		foreach ( $names as $name ) {
			$GLOBALS['senroflux_test_abilities'][ 'fluent-cart/' . $name ] = new \SenroFlux_Test_Fake_Ability(
				'fluent-cart/' . $name,
				input_schema: array(
					'type'       => 'object',
					'properties' => array(),
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// Detection and registration
	// ------------------------------------------------------------------

	public function test_it_registers_on_the_platform_filter_after_woo(): void {
		$this->assertSame( array( 'woo', 'fluentcart' ), array_keys( StorePlatforms::all() ) );
		$this->assertContains( 'fluent-cart/', StorePlatforms::governedNamespaces() );
		$this->assertNotContains( 'senroflux/', StorePlatforms::governedNamespaces() );
	}

	public function test_detection_not_installed_below_min_and_active(): void {
		FluentCartPlatform::useVersionProbe( static fn (): ?string => null );
		$this->assertFalse( $this->platform->isActive() );
		$this->assertSame( 'not_installed', $this->platform->inactiveReason() );

		FluentCartPlatform::useVersionProbe( static fn (): ?string => '1.6.9' );
		$this->assertFalse( $this->platform->isActive() );
		$this->assertSame( 'below_min_version', $this->platform->inactiveReason() );

		FluentCartPlatform::useVersionProbe( static fn (): ?string => '1.7.0' );
		$this->assertTrue( $this->platform->isActive() );
		$this->assertNull( $this->platform->inactiveReason() );
		$this->assertSame( '1.7.0', $this->platform->minVersion() );
	}

	public function test_it_is_governed_only_where_senrogate_governs_it(): void {
		$this->assertTrue( $this->platform->isGoverned() );
		Plugin::set_dependency_probe( false );
		Plugin::reset();
		$this->assertFalse( $this->platform->isGoverned() );
	}

	public function test_it_is_selectable_only_when_active_and_governed(): void {
		$this->assertSame( 'fluentcart', StorePlatforms::select()->platform?->id() );
		FluentCartPlatform::useVersionProbe( static fn (): ?string => '1.6.0' );
		$this->assertNull( StorePlatforms::select()->platform );
	}

	public function test_identity_and_capabilities(): void {
		$this->assertSame( 'fluentcart', $this->platform->id() );
		$this->assertSame( 'FluentCart', $this->platform->label() );
		$this->assertSame( array( 'fluent-cart/', 'senroflux/' ), $this->platform->abilityNamespaces() );
		$this->assertSame( 'manage_options', $this->platform->runCapability() );
		$this->assertSame( 'manage_options', $this->platform->restoreCapability() );
		$this->assertSame( 'manage_options', $this->pack()->runCapability() );
	}

	// ------------------------------------------------------------------
	// Role map and resolution
	// ------------------------------------------------------------------

	public function test_it_maps_every_pack_role_except_the_woo_only_ones(): void {
		$pack_roles = array_keys( $this->pack()->roleVerbs() );
		$mapped     = array_keys( $this->platform->roleMap() );

		$this->assertSame(
			array( 'catalogue', 'order-note', 'order-status', 'product-trash', 'stock', 'variation-price', 'sale-schedule' ),
			array_values( array_diff( $pack_roles, $mapped ) )
		);
		$this->assertSame( array(), array_diff( $mapped, $pack_roles ), 'no role the pack does not know' );
	}

	public function test_every_polyfill_a_role_names_is_declared_for_registration(): void {
		// `media-search` and `generate-image` are the media pack's, shared by every platform.
		foreach ( $this->platform->roleMap() as $value ) {
			$polyfill = StorePlatforms::roleSpec( $value )['polyfill'];
			if ( in_array( $polyfill, array( 'media-search', 'generate-image' ), true ) ) {
				continue;
			}
			$this->assertContains( $polyfill, $this->platform->polyfills() );
		}
	}

	public function test_with_the_mcp_module_off_every_role_is_a_senroflux_polyfill(): void {
		$resolved = $this->pack()->resolveAbilities();

		foreach ( $resolved as $role => $ability ) {
			$this->assertStringStartsWith( 'senroflux/', $ability, $role );
		}
		$this->assertSame( 'senroflux/fluentcart-orders-refund', $resolved['refund'] );
		$this->assertSame( 'senroflux/fluentcart-coupon-create', $resolved['coupon-create'] );
	}

	public function test_with_the_mcp_module_on_safe_roles_go_native_and_refund_stays_a_polyfill(): void {
		$this->registerNatives( array( 'manage-coupon', 'get-sales-report', 'get-product', 'get-order', 'refund-order' ) );
		$resolved = $this->pack()->resolveAbilities();

		$this->assertSame( 'fluent-cart/manage-coupon', $resolved['coupon-create'] );
		$this->assertSame( 'fluent-cart/manage-coupon', $resolved['coupon-enable'] );
		$this->assertSame( 'fluent-cart/get-sales-report', $resolved['store-report'] );
		$this->assertSame( 'fluent-cart/get-product', $resolved['read'] );
		$this->assertSame( 'fluent-cart/get-order', $resolved['order-read'] );
		$this->assertSame( 'senroflux/fluentcart-orders-refund', $resolved['refund'], 'native refund-order needs a dry-run/confirm-token pair a role call cannot give' );
		$this->assertSame( 'senroflux/fluentcart-product-create', $resolved['create'] );
		$this->assertSame( 'senroflux/save-store-report', $resolved['report-save'] );
	}

	// ------------------------------------------------------------------
	// Shapes
	// ------------------------------------------------------------------

	public function test_the_pack_shapes_native_calls_only(): void {
		$this->registerNatives( array( 'manage-coupon', 'get-sales-report', 'get-product', 'get-order' ) );
		$pack = $this->pack();

		$this->assertSame( array( 'product_id' => 5 ), $pack->shapeInput( 'fluent-cart/get-product', array( 'id' => 5 ) ) );
		$this->assertSame( array( 'order_id' => 9 ), $pack->shapeInput( 'fluent-cart/get-order', array( 'id' => 9 ) ) );
		$this->assertSame(
			array(
				'start_date' => '2026-01-01',
				'end_date'   => '2026-01-31',
			),
			$pack->shapeInput(
				'fluent-cart/get-sales-report',
				array(
					'from' => '2026-01-01',
					'to'   => '2026-01-31',
				)
			)
		);
		$this->assertSame( array( 'id' => 5 ), $pack->shapeInput( 'senroflux/fluentcart-product-read', array( 'id' => 5 ) ) );
	}

	public function test_a_coupon_create_is_always_pinned_to_an_inactive_draft(): void {
		$shaped = FluentCartPlatform::shapeCoupon(
			array(
				'code'          => ' SAVE10 ',
				'discount_type' => 'percent',
				'amount'        => 10,
				'expiry'        => '2026-12-31',
				'status'        => 'active',
				'action'        => 'update',
				'stackable'     => 'yes',
			)
		);

		$this->assertSame(
			array(
				'action'   => 'create',
				'status'   => 'inactive',
				'code'     => 'SAVE10',
				'type'     => 'percentage',
				'amount'   => 10,
				'end_date' => '2026-12-31',
			),
			$shaped,
			'a model-sent status/stackable cannot ride along'
		);
	}

	public function test_a_coupon_enable_only_flips_the_status(): void {
		$this->assertSame(
			array(
				'action'    => 'update',
				'coupon_id' => 4,
				'status'    => 'active',
			),
			FluentCartPlatform::shapeCoupon(
				array(
					'coupon_id' => 4,
					'amount'    => 99,
					'action'    => 'update',
				)
			),
			'no amount edit rides along'
		);
		$this->assertSame(
			array(
				'action'    => 'deactivate',
				'coupon_id' => 4,
			),
			FluentCartPlatform::shapeCoupon(
				array(
					'coupon_id' => 4,
					'action'    => 'deactivate',
				)
			),
			'a deactivate is never turned into an enable'
		);
	}

	public function test_discount_types_map_onto_fluentcarts_two(): void {
		$this->assertSame( 'fixed', FluentCartPlatform::couponType( 'fixed_cart' ) );
		$this->assertSame( 'fixed', FluentCartPlatform::couponType( 'fixed' ) );
		$this->assertSame( 'percentage', FluentCartPlatform::couponType( 'Percentage' ) );
		$this->assertNull( FluentCartPlatform::couponType( 'free_shipping' ) );
		$this->assertArrayNotHasKey(
			'type',
			FluentCartPlatform::shapeCoupon(
				array(
					'code'          => 'X',
					'discount_type' => 'buy_x_get_y',
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// Verbs and tiers
	// ------------------------------------------------------------------

	public function test_every_role_verb_has_a_tier_and_the_gate_tiers_are_right(): void {
		$pack   = $this->pack();
		$mapped = array_keys( $this->platform->roleMap() );
		foreach ( $pack->roleVerbs() as $role => $verbs ) {
			if ( ! in_array( $role, $mapped, true ) ) {
				continue; // A Woo-only role: this platform never resolves it.
			}
			foreach ( $verbs as $verb ) {
				$this->assertArrayHasKey( $verb, $this->platform->verbTiers(), $verb );
			}
		}

		$map = $pack->agentSafetyVerbMap();
		$this->assertSame( 2, $map['senroflux/fluentcart-orders-refund'] );
		$this->assertSame( 1, $map['senroflux/fluentcart-coupon-create'] );
		$this->assertSame( 2, $map['senroflux/fluentcart-coupon-enable'] );
		$this->assertSame( 2, $map['senroflux/fluentcart-product-update'], 'price/publish collapse the ability up to Tier 2' );
		$this->assertSame( 2, $map['senroflux/fluentcart-shipping-zone-save'] );
		$this->assertSame( 2, $map['senroflux/fluentcart-tax-rate-save'] );
		$this->assertSame( 0, $map['senroflux/fluentcart-store-report'] );
		$this->assertContains( 'commerce/refund', $pack->ungrantableVerbs() );
	}

	public function test_verb_for_polyfill_and_native_abilities(): void {
		$p = $this->platform;

		$this->assertSame( 'commerce/product-create-draft', $p->verbFor( 'fluentcart-product-create', array( 'name' => 'A' ) ) );
		$this->assertSame( 'commerce/product-publish', $p->verbFor( 'fluentcart-product-create', array( 'status' => ' Publish ' ) ) );
		$this->assertSame( 'commerce/product-update', $p->verbFor( 'fluentcart-product-update', array( 'description' => 'x' ) ) );
		$this->assertSame( 'commerce/price-change', $p->verbFor( 'fluentcart-product-update', array( 'sale_price' => '1' ) ) );
		$this->assertSame( 'commerce/product-update', $p->verbFor( 'fluentcart-product-update', array( 'sale_price' => null ) ) );
		$this->assertSame(
			'commerce/product-publish',
			$p->verbFor(
				'fluentcart-product-update',
				array(
					'status'        => 'future',
					'regular_price' => '2',
				)
			),
			'publish wins over price'
		);
		$this->assertSame( 'commerce/refund', $p->verbFor( 'fluentcart-orders-refund', array() ) );
		$this->assertSame( 'commerce/refund', $p->verbFor( 'refund-order', array() ) );
		$this->assertSame( 'commerce/store-report', $p->verbFor( 'get-sales-report', array() ) );
		$this->assertSame( 'commerce/product-read', $p->verbFor( 'get-product', array() ) );
		$this->assertNull( $p->verbFor( 'change-order-status', array() ), 'unmapped natives fall to fail-closed Tier 2 in the pack' );
		$this->assertSame( 'fluent-cart/change-order-status', $this->pack()->verbFor( 'fluent-cart/change-order-status', array() ) );
		$this->assertSame( 2, VerbTier::tierFor( 'fluent-cart/change-order-status', $p->verbTiers() ) );
	}

	public function test_manage_coupon_verbs_mirror_senrogates_activation_rule(): void {
		$p = $this->platform;
		$this->row(
			'fct_coupons',
			array(
				'id'     => 8,
				'status' => 'inactive',
			)
		);
		$this->row(
			'fct_coupons',
			array(
				'id'     => 9,
				'status' => 'active',
			)
		);

		$draft  = 'commerce/coupon-draft';
		$enable = 'commerce/coupon-enable';

		$this->assertSame(
			$draft,
			$p->verbFor(
				'manage-coupon',
				array(
					'action' => 'create',
					'code'   => 'A',
					'status' => 'inactive',
				)
			)
		);
		$this->assertSame(
			$enable,
			$p->verbFor(
				'manage-coupon',
				array(
					'action' => 'create',
					'code'   => 'A',
				)
			),
			'no status: FluentCart makes it active'
		);
		$this->assertSame(
			$enable,
			$p->verbFor(
				'manage-coupon',
				array(
					'action' => 'create',
					'code'   => 'A',
					'status' => 'active',
				)
			)
		);
		$this->assertSame(
			$enable,
			$p->verbFor(
				'manage-coupon',
				array(
					'action' => 'create',
					'status' => 'banana',
				)
			),
			'unrecognised status fails closed'
		);
		$this->assertSame(
			$draft,
			$p->verbFor(
				'manage-coupon',
				array(
					'action'    => 'deactivate',
					'coupon_id' => 9,
				)
			)
		);
		$this->assertSame(
			$draft,
			$p->verbFor(
				'manage-coupon',
				array(
					'action'    => 'update',
					'coupon_id' => 9,
					'status'    => 'inactive',
				)
			)
		);
		$this->assertSame(
			$enable,
			$p->verbFor(
				'manage-coupon',
				array(
					'action'    => 'update',
					'coupon_id' => 9,
					'status'    => 'active',
				)
			)
		);
		$this->assertSame(
			$draft,
			$p->verbFor(
				'manage-coupon',
				array(
					'action'    => 'update',
					'coupon_id' => 8,
					'amount'    => 5,
				)
			),
			'editing a stored-inactive coupon'
		);
		$this->assertSame(
			$enable,
			$p->verbFor(
				'manage-coupon',
				array(
					'action'    => 'update',
					'coupon_id' => 9,
					'amount'    => 5,
				)
			),
			'editing a live coupon'
		);
		$this->assertSame(
			$enable,
			$p->verbFor(
				'manage-coupon',
				array(
					'action'    => 'update',
					'coupon_id' => 404,
					'amount'    => 5,
				)
			),
			'unknown coupon fails closed'
		);
		// The pack's own (action-less) inputs.
		$this->assertSame(
			$draft,
			$p->verbFor(
				'manage-coupon',
				array(
					'code'   => 'A',
					'type'   => 'fixed',
					'amount' => 1,
				)
			)
		);
		$this->assertSame( $enable, $p->verbFor( 'manage-coupon', array( 'coupon_id' => 8 ) ) );
	}

	// ------------------------------------------------------------------
	// validateCall / objectIdForWrite
	// ------------------------------------------------------------------

	public function test_descriptions_are_validated_before_the_product_polyfills_run(): void {
		$this->assertNull( $this->platform->validateCall( 'fluentcart-product-create', array( 'description' => '<p>fine</p>' ) ) );
		$this->assertNotNull( $this->platform->validateCall( 'fluentcart-product-update', array( 'short_description' => '<script>x</script>' ) ) );
		$this->assertNotNull( $this->platform->validateCall( 'fluentcart-product-create', array( 'description' => '<a href="javascript:alert(1)">x</a>' ) ) );
		$this->assertNull( $this->platform->validateCall( 'fluentcart-coupon-create', array( 'description' => '<script>x</script>' ) ), 'only the product abilities carry descriptions' );
		$this->assertNull( $this->pack()->validateCall( 'senroflux/fluentcart-product-create', array( 'description' => 'plain' ) ) );
	}

	public function test_object_ids_come_from_flat_polyfill_and_enveloped_native_outputs(): void {
		$p = $this->platform;

		$this->assertSame( '12', $p->objectIdForWrite( 'commerce/product-create-draft', array(), array( 'product_id' => 12 ) ) );
		$this->assertSame( '5', $p->objectIdForWrite( 'commerce/price-change', array( 'id' => 5 ), array() ) );
		$this->assertSame( '3', $p->objectIdForWrite( 'commerce/coupon-draft', array(), array( 'coupon_id' => 3 ) ) );
		$this->assertSame( '4', $p->objectIdForWrite( 'commerce/coupon-draft', array(), array( 'data' => array( 'coupon_id' => 4 ) ) ), 'native envelope' );
		$this->assertSame( '6', $p->objectIdForWrite( 'commerce/coupon-enable', array( 'coupon_id' => 6 ), array( 'summary' => 'x' ) ) );
		$this->assertSame( '7', $p->objectIdForWrite( 'commerce/refund', array(), array( 'order_id' => 7 ) ) );
		$this->assertSame( '8', $p->objectIdForWrite( 'commerce/shipping-write', array(), array( 'zone_id' => 8 ) ) );
		$this->assertSame( '9', $p->objectIdForWrite( 'commerce/tax-write', array(), array( 'tax_rate_id' => 9 ) ) );
		$this->assertNull( $p->objectIdForWrite( 'commerce/tax-write', array(), array( 'tax_rate_id' => 0 ) ) );
		$this->assertNull( $p->objectIdForWrite( 'commerce/store-report', array(), array() ) );
	}

	// ------------------------------------------------------------------
	// Cards and lookups
	// ------------------------------------------------------------------

	public function test_the_refund_card_reads_fluentcarts_order(): void {
		$this->row(
			'fct_orders',
			array(
				'id'           => 7,
				'status'       => 'completed',
				'currency'     => 'USD',
				'mode'         => 'live',
				'total_paid'   => 5000,
				'total_refund' => 1000,
			)
		);

		$card = CommerceSummary::filter(
			'plain',
			'senroflux/fluentcart-orders-refund',
			array(
				'order_id' => 7,
				'amount'   => 12.5,
				'reason'   => 'damaged',
			)
		);

		$this->assertStringContainsString( 'Refund order #7', $card );
		$this->assertStringContainsString( 'paid 50.00 USD, already refunded 10.00 USD, remaining refundable 40.00 USD', $card );
		$this->assertStringContainsString( 'refund amount 12.50 USD', $card );
		$this->assertStringContainsString( 'LIVE order', $card );
		$this->assertStringContainsString( '&quot;damaged&quot;', $card );
		$this->assertStringNotContainsString( 'WooCommerce', $card );
	}

	public function test_a_card_for_an_unknown_object_defers_to_the_default(): void {
		$this->assertNull( $this->platform->approvalSummary( 'commerce/refund', 'fluentcart-orders-refund', array( 'order_id' => 404 ) ) );
		$this->assertNull( $this->platform->approvalSummary( 'commerce/coupon-enable', 'manage-coupon', array( 'coupon_id' => 404 ) ) );
		$this->assertNull( $this->platform->approvalSummary( 'commerce/report-save', 'save-store-report', array() ) );
	}

	public function test_the_coupon_card_names_the_stored_coupon(): void {
		$this->row(
			'fct_coupons',
			array(
				'id'        => 3,
				'code'      => 'SAVE10',
				'type'      => 'percentage',
				'amount'    => 10,
				'status'    => 'inactive',
				'end_date'  => '2026-12-31 00:00:00',
				'use_count' => 2,
			)
		);

		$card = $this->platform->approvalSummary( 'commerce/coupon-enable', 'fluentcart-coupon-enable', array( 'coupon_id' => 3 ) );

		$this->assertSame( 'Enable coupon &quot;SAVE10&quot; — discount 10% percentage — ends 2026-12-31 00:00:00 — used 2 times so far', $card );

		$fixed = $this->platform->approvalSummary( 'commerce/coupon-enable', 'manage-coupon', array( 'coupon_id' => 3 ) );
		$this->assertSame( $card, $fixed, 'native and polyfill share the card' );
	}

	public function test_the_other_tier2_cards(): void {
		$price = (string) $this->platform->approvalSummary(
			'commerce/price-change',
			'fluentcart-product-update',
			array(
				'id'            => 5,
				'regular_price' => '19.99',
				'sale_price'    => '',
			)
		);
		$this->assertStringContainsString( 'proposed regular 19.99', $price );
		$this->assertStringNotContainsString( 'sale', $price );

		$publish = (string) $this->platform->approvalSummary( 'commerce/product-publish', 'fluentcart-product-create', array( 'name' => 'Mug' ) );
		$this->assertSame( 'Publish new product &quot;Mug&quot;', $publish );

		$zone = (string) $this->platform->approvalSummary(
			'commerce/shipping-write',
			'fluentcart-shipping-zone-save',
			array(
				'name'      => 'EU',
				'countries' => array( 'DE', 'FR' ),
				'methods'   => array(
					array(
						'title' => 'Flat',
						'type'  => 'flat_rate',
					),
				),
			)
		);
		$this->assertSame( 'Create shipping zone &quot;EU&quot; — DE, FR — 1 shipping method(s) added', $zone );

		$tax = (string) $this->platform->approvalSummary(
			'commerce/tax-write',
			'fluentcart-tax-rate-save',
			array(
				'country' => 'de',
				'rate'    => 19,
				'name'    => 'VAT',
			)
		);
		$this->assertStringContainsString( 'Create tax rate &quot;VAT&quot; — DE at 19%', $tax );
	}

	public function test_describe_object_covers_orders_coupons_customers_zones_and_rates(): void {
		$this->row(
			'fct_orders',
			array(
				'id'     => 7,
				'status' => 'processing',
			)
		);
		$this->row(
			'fct_coupons',
			array(
				'id'     => 3,
				'code'   => 'SAVE10',
				'status' => 'active',
			)
		);
		$this->row(
			'fct_customers',
			array(
				'id'         => 2,
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'email'      => 'a@example.test',
				'status'     => 'active',
			)
		);
		$this->row(
			'fct_shipping_zones',
			array(
				'id'   => 4,
				'name' => 'EU',
			)
		);
		$this->row(
			'fct_tax_rates',
			array(
				'id'   => 6,
				'name' => 'VAT',
			)
		);

		$order = $this->platform->describeObject( 'order:7' );
		$this->assertSame( 'fluentcart_order', $order['object_type'] ?? null );
		$this->assertSame( 'Order #7', $order['title'] ?? null );
		$this->assertSame( 'processing', $order['status'] ?? null );
		$this->assertStringContainsString( 'page=fluent-cart#/orders/7/view', (string) ( $order['edit_url'] ?? '' ) );

		$this->assertSame( 'SAVE10', $this->platform->describeObject( 'coupon:3' )['title'] ?? null );
		$this->assertSame( 'Ada Lovelace', $this->platform->describeObject( 'customer:2' )['title'] ?? null );
		$this->assertSame( 'EU', $this->platform->describeObject( 'zone:4' )['title'] ?? null );
		$this->assertSame( 'VAT', $this->platform->describeObject( 'taxrate:6' )['title'] ?? null );

		$this->assertSame( 'unknown', $this->platform->describeObject( 'order:404' )['object_type'] ?? null );
		$this->assertNull( $this->platform->describeObject( 'page:12' ), 'saved report pages keep the default lookup' );
		$this->assertNull( $this->platform->describeObject( '55' ), 'products keep the default post lookup' );
	}

	public function test_the_report_resolves_fluentcart_objects_through_the_platform(): void {
		$this->row(
			'fct_orders',
			array(
				'id'     => 7,
				'status' => 'completed',
			)
		);

		$this->assertSame( 'fluentcart_order', ReportLookup::resolve( 'order:7' )['object_type'] ?? null );
	}

	public function test_a_missing_database_is_a_quiet_unknown_not_a_fatal(): void {
		unset( $GLOBALS['wpdb'] );

		$this->assertSame( 'unknown', $this->platform->describeObject( 'order:7' )['object_type'] ?? null );
		$this->assertNull( $this->platform->approvalSummary( 'commerce/refund', 'x', array( 'order_id' => 7 ) ) );
	}
}
