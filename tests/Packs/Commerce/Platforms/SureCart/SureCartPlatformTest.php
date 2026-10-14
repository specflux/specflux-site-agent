<?php
/**
 * The SureCart store platform (C4-S): detection, role map, tiers, shapes,
 * cards, lookups and the status polyfill. No network: every SureCart model
 * call goes through {@see SureCartRemote}, replaced here with a fake.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Packs\Commerce\Platforms\SureCart;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Commerce\Abilities;
use Specflux\SiteAgent\Packs\Commerce\CommercePack;
use Specflux\SiteAgent\Packs\Commerce\CommerceSummary;
use Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SiteAgent\Packs\Commerce\Platforms\SureCart\SureCartPlatform;
use Specflux\SiteAgent\Packs\Commerce\Platforms\SureCart\SureCartProductStatus;
use Specflux\SiteAgent\Packs\Commerce\Platforms\SureCart\SureCartRemote;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Tools\VerbTier;
use WP_Error;

final class SureCartPlatformTest extends TestCase {

	/** @var list<array{string,string,mixed}> */
	private array $calls = array();

	/** @var array<string,array<string,mixed>|null> `Model:id` => data (null = cannot load). */
	private array $store = array();

	/** @var array<string,mixed> */
	private array $env = array();

	protected function setUp(): void {
		remove_all_filters( 'specflux_site_agent_store_platforms' );
		remove_all_filters( 'specflux_site_agent_packs' );
		remove_all_filters( SureCartPlatform::AMOUNT_THRESHOLD_FILTER );
		$GLOBALS['specflux_site_agent_test_options']            = array();
		$GLOBALS['specflux_site_agent_test_abilities']          = array();
		$GLOBALS['specflux_site_agent_test_user_caps']          = array();
		$GLOBALS['specflux_site_agent_test_ability_categories'] = array();
		require_once dirname( __DIR__, 4 ) . '/stubs/blocks.php';
		Plugin::reset();
		Abilities::reset();

		$this->calls = array();
		$this->store = array();
		$this->env   = array(
			'version'   => '4.9.3',
			'api'       => true,
			'connected' => true,
			'enabled'   => true,
			'gated'     => true,
		);

		SureCartRemote::use(
			function ( string $op, string $model, mixed $payload ): mixed {
				$this->calls[] = array( $op, $model, $payload );
				if ( 'find' === $op ) {
					return $this->store[ $model . ':' . $payload ] ?? null;
				}

				return $this->store[ 'update:' . $model ] ?? array_merge( array( 'id' => $payload['id'] ), $payload );
			}
		);
	}

	protected function tearDown(): void {
		SureCartRemote::use( null );
		remove_all_filters( 'specflux_site_agent_store_platforms' );
		remove_all_filters( SureCartPlatform::AMOUNT_THRESHOLD_FILTER );
		Plugin::reset();
	}

	private function platform(): SureCartPlatform {
		return new SureCartPlatform( fn (): array => $this->env );
	}

	/** Register the platform and return a commerce pack speaking for it. */
	private function pack(): CommercePack {
		$platform = $this->platform();
		add_filter( 'specflux_site_agent_store_platforms', static fn ( array $p ): array => array_merge( $p, array( $platform ) ), 10, 1 );
		$pack = new CommercePack();
		$pack->useRunPlatform( 'surecart' );

		return $pack;
	}

	private function tier( CommercePack $pack, string $ability, array $input ): int {
		return VerbTier::tierFor( $pack->verbFor( $ability, $input ), $pack->verbMap() );
	}

	// ------------------------------------------------------------------
	// Detection
	// ------------------------------------------------------------------

	public function test_it_identifies_itself(): void {
		$p = $this->platform();
		$this->assertSame( 'surecart', $p->id() );
		$this->assertSame( 'SureCart', $p->label() );
		$this->assertSame( '4.9.3', $p->minVersion() );
		$this->assertSame( array( 'surecart/', 'specflux/' ), $p->abilityNamespaces() );
		$this->assertSame( 'manage_sc_shop_settings', $p->runCapability() );
		$this->assertSame( 'manage_options', $p->restoreCapability() );
	}

	public function test_it_is_active_only_when_installed_connected_and_enabled(): void {
		$this->assertTrue( $this->platform()->isActive() );
		$this->assertNull( $this->platform()->inactiveReason() );
		$this->assertNull( $this->platform()->inactiveMessage() );
	}

	/** @return array<string,array{array<string,mixed>,string,string}> */
	public static function inactiveCases(): array {
		return array(
			'not installed'    => array( array( 'version' => null ), 'not_installed', 'not installed' ),
			'too old'          => array( array( 'version' => '4.9.2' ), 'below_min_version', '4.9.3' ),
			'unreadable'       => array( array( 'version' => '' ), 'below_min_version', '4.9.3' ),
			'no abilities api' => array( array( 'api' => false ), 'abilities_api_missing', '6.9' ),
			'not connected'    => array( array( 'connected' => false ), 'store_not_connected', 'Connect your SureCart store' ),
			'toggle off'       => array( array( 'enabled' => false ), 'abilities_disabled', 'Enable abilities' ),
		);
	}

	/**
	 * @dataProvider inactiveCases
	 * @param array<string,mixed> $override Env override.
	 */
	public function test_an_unusable_install_says_why( array $override, string $reason, string $message ): void {
		$this->env = array_merge( $this->env, $override );
		$p         = $this->platform();

		$this->assertFalse( $p->isActive() );
		$this->assertSame( $reason, $p->inactiveReason() );
		$this->assertStringContainsString( $message, (string) $p->inactiveMessage() );
	}

	public function test_without_surecart_loaded_the_live_detection_says_not_installed(): void {
		$p = new SureCartPlatform();

		$this->assertSame( 'not_installed', $p->inactiveReason() );
		$this->assertFalse( $p->isGoverned(), 'with no Belayline Agent Governance SureCart module nothing governs surecart/' );
	}

	public function test_governed_follows_governances_module(): void {
		$this->assertTrue( $this->platform()->isGoverned() );
		$this->env['gated'] = false;
		$this->assertFalse( $this->platform()->isGoverned() );
	}

	public function test_plugin_govern_registers_the_platform(): void {
		Plugin::set_dependency_probe( true );
		Plugin::instance()->govern();

		$this->assertInstanceOf( SureCartPlatform::class, StorePlatforms::get( 'surecart' ) );
		$this->assertNotNull( StorePlatforms::get( 'woo' ), 'WooCommerce is still first and still there' );
		Plugin::set_dependency_probe( null );
	}

	// ------------------------------------------------------------------
	// Roles
	// ------------------------------------------------------------------

	public function test_every_role_is_native_except_status_and_each_has_verbs_in_the_tier_map(): void {
		$pack  = $this->pack();
		$roles = $pack->roles();

		$this->assertSame(
			array( 'read', 'catalogue', 'create', 'update', 'price', 'status', 'coupon-create', 'coupon-code', 'order-read', 'refund', 'store-report' ),
			array_keys( $roles )
		);
		$this->assertSame( array_keys( $roles ), array_keys( $pack->roleVerbs() ) );
		foreach ( $pack->roleVerbs() as $role => $verbs ) {
			$this->assertNotEmpty( $verbs, $role );
			foreach ( $verbs as $verb ) {
				$this->assertArrayHasKey( $verb, $pack->verbMap(), "$role: $verb" );
			}
		}
	}

	public function test_roles_resolve_to_surecart_abilities_when_registered_else_the_polyfill(): void {
		$pack = $this->pack();
		foreach ( array( 'get-product', 'update-price', 'create-refund' ) as $name ) {
			$GLOBALS['specflux_site_agent_test_abilities'][ 'surecart/' . $name ] = new \SpecfluxSiteAgent_Test_Fake_Ability(
				'surecart/' . $name,
				input_schema: array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array(),
						'amount' => array(),
						'charge' => array(),
						'reason' => array(),
					),
				)
			);
		}
		$resolved = $pack->resolveAbilities();

		$this->assertSame( 'surecart/get-product', $resolved['read'] );
		$this->assertSame( 'surecart/update-price', $resolved['price'] );
		$this->assertSame( 'surecart/create-refund', $resolved['refund'] );
		$this->assertSame( 'specflux/surecart-product-status', $resolved['status'], 'status is polyfill-only' );
		$this->assertSame( 'specflux/surecart-create-product', $resolved['create'], 'absent native falls back' );
	}

	public function test_the_polyfill_set_is_only_the_status_ability(): void {
		$this->assertSame( array( 'surecart-product-status' ), $this->platform()->polyfills() );
	}

	public function test_unsupported_roles_are_not_mapped(): void {
		$roles = array_keys( $this->platform()->roleMap() );
		foreach ( array( 'image', 'search', 'image-generate', 'coupon-enable', 'order-note', 'shipping-write', 'tax-write', 'report-save' ) as $role ) {
			$this->assertNotContains( $role, $roles, $role );
		}
	}

	// ------------------------------------------------------------------
	// Tiers (Belayline Agent Governance's, mirrored)
	// ------------------------------------------------------------------

	/** @return array<string,array{string,array<string,mixed>,int}> */
	public static function tierCases(): array {
		return array(
			'get-product'                  => array( 'surecart/get-product', array( 'id' => 'p' ), 0 ),
			'list-products'                => array( 'surecart/list-products', array(), 0 ),
			'get-order'                    => array( 'surecart/get-order', array( 'id' => 'o' ), 0 ),
			'dashboard'                    => array( 'surecart/get-store-dashboard', array(), 0 ),
			'create-product'               => array(
				'surecart/create-product',
				array(
					'name'  => 'x',
					'price' => 500,
				),
				1,
			),
			'update-product'               => array(
				'surecart/update-product',
				array(
					'id'   => 'p',
					'name' => 'x',
				),
				1,
			),
			'price rename'                 => array(
				'surecart/update-price',
				array(
					'id'   => 'p',
					'name' => 'x',
				),
				1,
			),
			'price amount'                 => array(
				'surecart/update-price',
				array(
					'id'     => 'p',
					'amount' => 1999,
				),
				2,
			),
			'price amount zero'            => array(
				'surecart/update-price',
				array(
					'id'     => 'p',
					'amount' => 0,
				),
				2,
			),
			'price amount null'            => array(
				'surecart/update-price',
				array(
					'id'     => 'p',
					'amount' => null,
				),
				1,
			),
			'price archived'               => array(
				'surecart/update-price',
				array(
					'id'       => 'p',
					'archived' => true,
				),
				2,
			),
			'price archived false'         => array(
				'surecart/update-price',
				array(
					'id'       => 'p',
					'archived' => false,
				),
				1,
			),
			'price archived array'         => array(
				'surecart/update-price',
				array(
					'id'       => 'p',
					'archived' => array( 'x' ),
				),
				2,
			),
			'coupon small percent'         => array(
				'surecart/create-coupon',
				array(
					'name'        => 'c',
					'percent_off' => 49.9,
				),
				1,
			),
			'coupon 50 percent'            => array(
				'surecart/create-coupon',
				array(
					'name'        => 'c',
					'percent_off' => 50,
				),
				2,
			),
			'coupon string percent'        => array(
				'surecart/create-coupon',
				array(
					'name'        => 'c',
					'percent_off' => '75',
				),
				2,
			),
			'coupon amount at threshold'   => array(
				'surecart/create-coupon',
				array(
					'name'       => 'c',
					'amount_off' => 10000,
				),
				1,
			),
			'coupon amount over threshold' => array(
				'surecart/create-coupon',
				array(
					'name'       => 'c',
					'amount_off' => 10001,
				),
				2,
			),
			'coupon odd percent'           => array(
				'surecart/create-coupon',
				array(
					'name'        => 'c',
					'percent_off' => array( 1 ),
				),
				2,
			),
			'coupon pack vocabulary'       => array(
				'surecart/create-coupon',
				array(
					'code'          => 'BIG',
					'discount_type' => 'percent',
					'amount'        => 60,
				),
				2,
			),
			'promotion'                    => array(
				'surecart/create-promotion',
				array(
					'coupon' => 'c',
					'code'   => 'X',
				),
				1,
			),
			'refund'                       => array(
				'surecart/create-refund',
				array(
					'charge' => 'c',
					'amount' => 100,
					'reason' => 'duplicate',
				),
				2,
			),
			'status published'             => array(
				'specflux/surecart-product-status',
				array(
					'id'     => 'p',
					'status' => 'published',
				),
				2,
			),
			'status draft'                 => array(
				'specflux/surecart-product-status',
				array(
					'id'     => 'p',
					'status' => 'draft',
				),
				1,
			),
		);
	}

	/**
	 * @dataProvider tierCases
	 * @param array<string,mixed> $input Input.
	 */
	public function test_tiers_match_governances( string $ability, array $input, int $tier ): void {
		$this->assertSame( $tier, $this->tier( $this->pack(), $ability, $input ) );
	}

	public function test_the_discount_amount_threshold_follows_governances_filter_and_survives_a_broken_one(): void {
		$pack  = $this->pack();
		$input = array(
			'name'       => 'c',
			'amount_off' => 6000,
		);

		$this->assertSame( 1, $this->tier( $pack, 'surecart/create-coupon', $input ) );
		add_filter( SureCartPlatform::AMOUNT_THRESHOLD_FILTER, static fn (): int => 5000, 10, 1 );
		$this->assertSame( 2, $this->tier( $pack, 'surecart/create-coupon', $input ) );
		remove_all_filters( SureCartPlatform::AMOUNT_THRESHOLD_FILTER );
		add_filter( SureCartPlatform::AMOUNT_THRESHOLD_FILTER, static fn (): string => 'nope', 10, 1 );
		$this->assertSame( 1, $this->tier( $pack, 'surecart/create-coupon', $input ), 'a broken filter falls back to the default 10000' );
	}

	public function test_refund_is_ungrantable_and_an_unknown_ability_falls_through(): void {
		$pack = $this->pack();

		$this->assertContains( 'commerce/refund', $pack->ungrantableVerbs() );
		$this->assertSame( 'surecart/delete-coupon', $pack->verbFor( 'surecart/delete-coupon', array() ) );
	}

	public function test_the_status_polyfill_is_governed_at_its_highest_verb_tier(): void {
		$map = $this->pack()->agentSafetyVerbMap();

		$this->assertSame( 2, $map['specflux/surecart-product-status'] );
		$this->assertSame( 2, $map['specflux/surecart-update-price'] );
		$this->assertSame( 0, $map['specflux/surecart-get-product'] );
		$this->assertSame( 2, $map['specflux/surecart-create-refund'] );
	}

	// ------------------------------------------------------------------
	// Shapes
	// ------------------------------------------------------------------

	public function test_the_coupon_shape_maps_the_packs_vocabulary_and_leaves_native_input_alone(): void {
		$pack = $this->pack();
		$GLOBALS['specflux_site_agent_test_abilities']['surecart/create-coupon'] = new \SpecfluxSiteAgent_Test_Fake_Ability(
			'surecart/create-coupon',
			input_schema: array(
				'type'       => 'object',
				'properties' => array( 'name' => array() ),
			)
		);

		$shaped = $pack->shapeInput(
			'surecart/create-coupon',
			array(
				'code'          => 'SPRING',
				'discount_type' => 'percent',
				'amount'        => 20,
				'usage_limit'   => 5,
				'product_ids'   => array( 7 ),
			)
		);
		$this->assertEquals(
			array(
				'name'            => 'SPRING',
				'percent_off'     => 20,
				'max_redemptions' => 5,
				'product_ids'     => array( '7' ),
			),
			$shaped
		);

		$native = array(
			'name'       => 'N',
			'amount_off' => 500,
			'currency'   => 'usd',
		);
		$this->assertSame( $native, $pack->shapeInput( 'surecart/create-coupon', $native ) );
		$this->assertSame(
			array( 'code' => 'SPRING' ),
			$pack->shapeInput( 'specflux/surecart-create-coupon', array( 'code' => 'SPRING' ) ),
			'the polyfill takes the pack input as is'
		);
	}

	public function test_the_promotion_shape_accepts_coupon_id(): void {
		$pack = $this->pack();
		$GLOBALS['specflux_site_agent_test_abilities']['surecart/create-promotion'] = new \SpecfluxSiteAgent_Test_Fake_Ability(
			'surecart/create-promotion',
			input_schema: array(
				'type'       => 'object',
				'properties' => array(
					'coupon' => array(),
					'code'   => array(),
				),
			)
		);

		$this->assertSame(
			array(
				'code'   => 'SPRING',
				'coupon' => 'cp_1',
			),
			$pack->shapeInput(
				'surecart/create-promotion',
				array(
					'code'      => 'SPRING',
					'coupon_id' => 'cp_1',
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// validateCall
	// ------------------------------------------------------------------

	public function test_the_description_rule_applies_to_surecarts_product_abilities(): void {
		$pack = $this->pack();

		$bad = $pack->validateCall(
			'surecart/update-product',
			array(
				'id'          => 'p',
				'description' => '<script>x</script>',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertInstanceOf(
			WP_Error::class,
			$pack->validateCall(
				'surecart/create-product',
				array(
					'name'        => 'n',
					'description' => '<script>x</script>',
				)
			)
		);
		$this->assertNull(
			$pack->validateCall(
				'surecart/update-product',
				array(
					'id'          => 'p',
					'description' => '<p>fine</p>',
				)
			)
		);
		$this->assertNull(
			$pack->validateCall(
				'surecart/update-price',
				array(
					'id'          => 'p',
					'description' => '<script>',
				)
			)
		);
	}

	// ------------------------------------------------------------------
	// objectIdForWrite
	// ------------------------------------------------------------------

	public function test_written_object_ids(): void {
		$pack = $this->pack();

		$this->assertSame( 'prod_1', $pack->objectIdForWrite( 'commerce/product-create-draft', array(), array( 'product' => array( 'id' => 'prod_1' ) ) ) );
		$this->assertSame( 'prod_1', $pack->objectIdForWrite( 'commerce/product-update', array( 'id' => 'prod_1' ), array( 'product' => array( 'id' => 'prod_1' ) ) ) );
		$this->assertSame(
			'prod_1',
			$pack->objectIdForWrite(
				'commerce/product-publish',
				array(),
				array(
					'product' => array(
						'id'     => 'prod_1',
						'status' => 'published',
					),
				)
			)
		);
		$this->assertSame(
			'prod_9',
			$pack->objectIdForWrite(
				'commerce/price-change',
				array( 'id' => 'pr_1' ),
				array(
					'price' => array(
						'id'      => 'pr_1',
						'product' => 'prod_9',
					),
				)
			),
			'a price is tracked as its product'
		);
		$this->assertSame( 'prod_9', $pack->objectIdForWrite( 'commerce/price-change', array(), array( 'price' => array( 'product' => array( 'id' => 'prod_9' ) ) ) ) );
		$this->assertNull( $pack->objectIdForWrite( 'commerce/price-change', array( 'id' => 'pr_1' ), array( 'price' => array( 'id' => 'pr_1' ) ) ), 'never guess a price id as a product id' );
		$this->assertSame( 'cp_1', $pack->objectIdForWrite( 'commerce/coupon-draft', array(), array( 'coupon' => array( 'id' => 'cp_1' ) ) ) );
		$this->assertSame( 'cp_1', $pack->objectIdForWrite( 'commerce/coupon-draft', array( 'coupon' => 'cp_1' ), array( 'promotion' => array( 'id' => 'pm_1' ) ) ), 'a promotion is tracked as its coupon' );
		$this->assertSame( 'cp_2', $pack->objectIdForWrite( 'commerce/coupon-enable', array(), array( 'coupon' => array( 'id' => 'cp_2' ) ) ) );
		$this->assertNull( $pack->objectIdForWrite( 'commerce/refund', array( 'charge' => 'ch_1' ), array( 'refund' => array( 'id' => 'rf_1' ) ) ) );
		$this->assertNull( $pack->objectIdForWrite( 'commerce/product-update', array(), array( 'product' => array( 'id' => '../x' ) ) ) );
		$this->assertNull( $pack->objectIdForWrite( 'commerce/product-update', array(), array() ) );
	}

	// ------------------------------------------------------------------
	// Approval cards
	// ------------------------------------------------------------------

	public function test_price_card_shows_before_and_after_and_survives_a_failed_lookup(): void {
		$this->store['Price:pr_1'] = array(
			'id'       => 'pr_1',
			'name'     => 'Monthly',
			'amount'   => 1999,
			'currency' => 'usd',
		);
		$pack                      = $this->pack();
		$this->assertSame(
			'commerce/price-change',
			$pack->verbFor(
				'surecart/update-price',
				array(
					'id'     => 'pr_1',
					'amount' => 2499,
				)
			)
		);

		$card = CommerceSummary::filter(
			'plain',
			'surecart/update-price',
			array(
				'id'     => 'pr_1',
				'amount' => 2499,
			)
		);
		$this->assertStringContainsString( 'from 19.99 USD to 24.99 USD', $card );
		$this->assertStringContainsString( 'Monthly', $card );

		$this->store['Price:pr_1'] = null;
		$card                      = CommerceSummary::filter(
			'plain',
			'surecart/update-price',
			array(
				'id'       => 'pr_1',
				'amount'   => 2499,
				'archived' => true,
			)
		);
		$this->assertStringContainsString( 'set the price to 24.99', $card );
		$this->assertStringContainsString( 'archive it', $card );
	}

	public function test_refund_card(): void {
		$this->store['Charge:ch_1'] = array(
			'id'       => 'ch_1',
			'currency' => 'jpy',
		);
		$this->pack();

		$card = CommerceSummary::filter(
			'plain',
			'surecart/create-refund',
			array(
				'charge' => 'ch_1',
				'amount' => 500,
				'reason' => 'requested_by_customer',
			)
		);
		$this->assertStringContainsString( '500 JPY', $card );
		$this->assertStringContainsString( 'requested by customer', $card );
		$this->assertStringContainsString( 'cannot be undone', $card );
	}

	public function test_publish_card_and_big_discount_card(): void {
		$this->store['Product:prod_1'] = array(
			'id'   => 'prod_1',
			'name' => 'Mug',
		);
		$this->pack();

		$this->assertStringContainsString(
			'Publish SureCart product Mug',
			CommerceSummary::filter(
				'plain',
				'specflux/surecart-product-status',
				array(
					'id'     => 'prod_1',
					'status' => 'published',
				)
			)
		);
		$this->assertStringContainsString(
			'60% off',
			CommerceSummary::filter(
				'plain',
				'surecart/create-coupon',
				array(
					'name'        => 'BIG',
					'percent_off' => 60,
				)
			)
		);
		$this->assertSame(
			'plain',
			CommerceSummary::filter(
				'plain',
				'surecart/create-coupon',
				array(
					'name'        => 'ok',
					'percent_off' => 10,
				)
			),
			'a Tier-1 coupon gets no card'
		);
	}

	// ------------------------------------------------------------------
	// describeObject
	// ------------------------------------------------------------------

	public function test_describe_loads_each_kind_and_never_throws_on_failure(): void {
		$p                         = $this->platform();
		$this->store['Product:p1'] = array(
			'id'        => 'p1',
			'name'      => 'Mug',
			'status'    => 'published',
			'permalink' => 'https://x.test/mug',
		);
		$this->store['Coupon:c1']  = array(
			'id'       => 'c1',
			'name'     => 'SPRING',
			'archived' => true,
		);
		$this->store['Order:o1']   = array(
			'id'     => 'o1',
			'number' => '0042',
			'status' => 'paid',
		);
		$this->store['Price:pr1']  = array(
			'id'       => 'pr1',
			'name'     => 'Yearly',
			'product'  => 'p1',
			'archived' => false,
		);

		$this->assertSame( 'Mug', $p->describeObject( 'p1' )['title'] );
		$this->assertSame( 'published', $p->describeObject( 'product:p1' )['status'] );
		$this->assertSame( 'https://x.test/mug', $p->describeObject( 'product:p1' )['preview_url'] );
		$this->assertSame( 'archived', $p->describeObject( 'coupon:c1' )['status'] );
		$this->assertSame( 'Order 0042', $p->describeObject( 'order:o1' )['title'] );
		$this->assertSame( 'Yearly', $p->describeObject( 'price:pr1' )['title'] );
		$this->assertSame( 'surecart_coupon', $p->describeObject( 'coupon:c1' )['object_type'] );

		$missing = $p->describeObject( 'order:gone' );
		$this->assertStringContainsString( "couldn't load", $missing['title'] );
		$this->assertSame( 'unknown', $missing['status'] );
		$this->assertNull( $missing['edit_url'] );

		$this->assertNull( $p->describeObject( 'zone:5' ), 'an id that is not ours is left to the default lookup' );
		$this->assertStringContainsString( "couldn't load", $p->describeObject( 'order:../../x' )['title'], 'a malformed id is never sent to SureCart' );
		$this->assertSame( array(), array_filter( $this->calls, static fn ( array $c ): bool => str_contains( (string) $c[2], '..' ) ) );
	}

	public function test_a_throwing_transport_is_a_failed_lookup_not_a_fatal(): void {
		SureCartRemote::use(
			static function (): never {
				throw new \RuntimeException( 'network down' );
			}
		);

		$this->assertStringContainsString( "couldn't load", $this->platform()->describeObject( 'product:p1' )['title'] );
		$this->assertInstanceOf(
			WP_Error::class,
			SureCartRemote::update(
				'Product',
				array(
					'id'     => 'p1',
					'status' => 'draft',
				)
			)
		);
	}

	public function test_the_live_remote_with_no_surecart_loaded_answers_null(): void {
		SureCartRemote::use( null );

		$this->assertNull( SureCartRemote::find( 'Product', 'p1' ) );
		$this->assertNull( SureCartRemote::find( 'Customer', 'p1' ), 'only the models the platform needs' );
	}

	// ------------------------------------------------------------------
	// The status polyfill
	// ------------------------------------------------------------------

	private function registerPolyfills(): void {
		$platform = $this->platform();
		add_filter( 'specflux_site_agent_store_platforms', static fn ( array $p ): array => array_merge( $p, array( $platform ) ), 10, 1 );
		Abilities::registerCategory();
		Abilities::register();
	}

	public function test_the_status_polyfill_is_registered_with_the_platform_and_patches_the_status(): void {
		$this->registerPolyfills();
		$ability = wp_get_ability( 'specflux/surecart-product-status' );
		$this->assertNotNull( $ability );

		$out = $ability->execute(
			array(
				'id'     => 'prod_1',
				'status' => 'published',
			)
		);

		$this->assertSame(
			array(
				'success' => true,
				'product' => array(
					'id'     => 'prod_1',
					'status' => 'published',
				),
			),
			$out
		);
		$this->assertSame(
			array(
				'update',
				'Product',
				array(
					'id'     => 'prod_1',
					'status' => 'published',
				),
			),
			$this->calls[0]
		);
	}

	public function test_the_status_polyfill_refuses_bad_input_without_calling_surecart(): void {
		$this->assertInstanceOf(
			WP_Error::class,
			SureCartProductStatus::execute(
				array(
					'id'     => 'p',
					'status' => 'archived',
				)
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			SureCartProductStatus::execute(
				array(
					'id'     => '../p',
					'status' => 'draft',
				)
			)
		);
		$this->assertInstanceOf( WP_Error::class, SureCartProductStatus::execute( array( 'status' => 'draft' ) ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_the_status_polyfill_passes_a_surecart_error_through(): void {
		$this->store['update:Product'] = null;
		SureCartRemote::use( static fn (): WP_Error => new WP_Error( 'rejected', 'No.' ) );

		$out = SureCartProductStatus::execute(
			array(
				'id'     => 'p',
				'status' => 'draft',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $out );
		$this->assertSame( 'rejected', $out->get_error_code() );
	}

	public function test_the_polyfill_needs_surecarts_own_edit_capability(): void {
		$this->registerPolyfills();
		$ability = wp_get_ability( 'specflux/surecart-product-status' );

		$this->assertFalse( $ability->check_permissions( array() ) );
		$GLOBALS['specflux_site_agent_test_user_caps']['edit_sc_products'] = true;
		$this->assertTrue( $ability->check_permissions( array() ) );
	}
}
