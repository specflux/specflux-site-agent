<?php
/**
 * Platform seams (C4): native-name role mapping with input shaping, governed
 * namespaces from registered platforms, the restore capability, approval-card
 * and report-lookup hooks, and the pin at Plugin::start.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce\Platforms;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Approval\ChangesBridge;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Packs\Commerce\CommerceSummary;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SenroFlux\Packs\Commerce\ReportLookup;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Run;
use Specflux\SenroFlux\Run\RunStatus;
use Specflux\SenroFlux\Tools\ToolExecutor;
use wpdb;

final class PlatformSeamsTest extends TestCase {

	private FakePlatform $fake;

	protected function setUp(): void {
		require_once __DIR__ . '/FakePlatform.php';
		remove_all_filters( 'senroflux_store_platforms' );
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_options']   = array();
		$GLOBALS['senroflux_test_abilities'] = array();
		$GLOBALS['senroflux_test_user_caps'] = array();
		Plugin::reset();

		$this->fake = new FakePlatform( 'fake-a' );
		$fake       = $this->fake;
		add_filter( 'senroflux_store_platforms', static fn ( array $p ): array => array_merge( $p, array( $fake ) ), 10, 1 );
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_store_platforms' );
		remove_all_filters( 'senroflux_packs' );
		$GLOBALS['senroflux_test_abilities'] = array();
		unset( $GLOBALS['wpdb'] );
		Plugin::set_dependency_probe( null );
		Plugin::reset();
	}

	private function nativeRole( ?callable $shape ): void {
		$this->fake->roles = array(
			'read' => array(
				'polyfill' => 'fake-read',
				'native'   => 'manage-fake',
				'shape'    => $shape,
			),
		);
	}

	private function registerNative(): void {
		$GLOBALS['senroflux_test_abilities']['fake/manage-fake'] = new \SenroFlux_Test_Fake_Ability(
			'fake/manage-fake',
			input_schema: array(
				'type'       => 'object',
				'properties' => array(),
			)
		);
	}

	// ------------------------------------------------------------------
	// 1. Native-name mapping + shaping
	// ------------------------------------------------------------------

	public function test_a_string_role_value_still_means_the_same_name_everywhere(): void {
		$this->assertSame(
			array(
				'polyfill' => 'fake-read',
				'native'   => 'fake-read',
				'shape'    => null,
			),
			StorePlatforms::roleSpec( 'fake-read' )
		);
	}

	public function test_a_role_resolves_to_the_native_name_when_registered(): void {
		$this->nativeRole( null );
		$this->registerNative();
		$pack = new CommercePack();

		$this->assertSame( array( 'read' => 'fake-read' ), $pack->roles(), 'roles() keeps the polyfill template' );
		$this->assertSame( array( 'read' => 'fake/manage-fake' ), $pack->resolveAbilities() );
		$this->assertArrayHasKey( 'senroflux/fake-read', $pack->agentSafetyVerbMap(), 'the verb map is keyed by the polyfill id' );
	}

	public function test_a_role_falls_back_to_the_polyfill_name_when_the_native_is_absent(): void {
		$this->nativeRole( null );

		$this->assertSame( array( 'read' => 'senroflux/fake-read' ), ( new CommercePack() )->resolveAbilities() );
	}

	public function test_shape_applies_to_the_native_ability_only(): void {
		$this->nativeRole( static fn ( array $in ): array => array( 'wrapped' => $in ) );
		$this->registerNative();
		$pack = new CommercePack();

		$this->assertSame( array( 'wrapped' => array( 'a' => 1 ) ), $pack->shapeInput( 'fake/manage-fake', array( 'a' => 1 ) ) );
		$this->assertSame( array( 'a' => 1 ), $pack->shapeInput( 'senroflux/fake-read', array( 'a' => 1 ) ), 'the polyfill takes the pack input as is' );
		$this->assertSame( array( 'a' => 1 ), $pack->shapeInput( 'fake/unrelated', array( 'a' => 1 ) ) );
	}

	public function test_the_executor_hands_the_ability_the_shaped_input(): void {
		$seen = null;
		$GLOBALS['senroflux_test_abilities']['fake/manage-fake']             = new \SenroFlux_Test_Fake_Ability(
			'fake/manage-fake',
			input_schema: array(
				'type'       => 'object',
				'properties' => array(),
			)
		);
		$GLOBALS['senroflux_test_abilities']['fake/manage-fake']->on_execute = static function ( $input ) use ( &$seen ): void {
			$seen = $input;
		};

		( new ToolExecutor() )->call(
			'fake/manage-fake',
			array( 'a' => 1 ),
			null,
			null,
			static fn ( string $ability, array $in ): array => array( 'shaped' => $in['a'] )
		);

		$this->assertSame( array( 'shaped' => 1 ), $seen );
	}

	// ------------------------------------------------------------------
	// 2. Governed namespaces
	// ------------------------------------------------------------------

	private function packWith( string $name, array $namespaces ): Pack {
		return new class( $name, $namespaces ) extends Pack {
			public function __construct( private string $pack_name, private array $namespaces ) {
				parent::__construct( array( 'read' => 'x' ) );
			}

			public function name(): string {
				return $this->pack_name;
			}

			public function abilityNamespaces(): array {
				return $this->namespaces;
			}

			public function verbMap(): array {
				return array();
			}

			protected function agentSafetyBindingError( int $user_id ): ?\WP_Error {
				unset( $user_id );

				return null;
			}
		};
	}

	public function test_a_registered_platforms_namespace_is_eligible_and_an_unregistered_one_is_refused(): void {
		$this->assertContains( 'fake/', StorePlatforms::governedNamespaces() );
		$this->assertContains( 'woocommerce/', StorePlatforms::governedNamespaces() );
		$this->assertNotContains( 'senroflux/', StorePlatforms::governedNamespaces() );

		$GLOBALS['senroflux_test_doing_it_wrong'] = array();
		$registry                                 = new PackRegistry();
		$registry->register( $this->packWith( 'ok', array( 'fake/', 'senroflux/' ) ) );
		$registry->register( $this->packWith( 'nope', array( 'surecart/', 'senroflux/' ) ) );

		$this->assertNotNull( $registry->get( 'ok' ) );
		$this->assertNull( $registry->get( 'nope' ), 'surecart/ is not eligible until a platform registers it' );
		$this->assertNotNull( ( new PackRegistry() )->register( $this->packWith( 'woo', array( 'woocommerce/', 'senroflux/' ) ) )->get( 'woo' ), 'woocommerce/ stays eligible via WooPlatform' );
	}

	// ------------------------------------------------------------------
	// 3. Platform hooks
	// ------------------------------------------------------------------

	public function test_the_restore_capability_comes_from_the_runs_platform(): void {
		$bridge = new ChangesBridge();
		$run    = static fn ( ?string $pack, ?string $platform ): Run => new Run( 1, 1, 'c', 'g', RunStatus::Running, array(), array(), pack: $pack, storePlatform: $platform );

		$this->assertSame( 'manage_options', $bridge->capabilityFor( $run( 'commerce', 'fake-a' ) ) );
		$this->assertSame( 'manage_woocommerce', $bridge->capabilityFor( $run( 'commerce', 'woo' ) ) );
		$this->assertSame( 'manage_options', $bridge->capabilityFor( $run( 'commerce', null ) ), 'an unpinned run follows the selected platform' );
		$this->fake->active = false;
		$this->assertSame( 'manage_woocommerce', $bridge->capabilityFor( $run( 'commerce', null ) ), 'with no usable platform the Woo answer stays' );
		$this->assertSame( 'manage_options', $bridge->capabilityFor( $run( 'pages', null ) ) );
	}

	public function test_a_platform_can_supply_the_approval_card_and_report_lookup(): void {
		// The only usable platform is the fake one (Woo is not active in the unit suite).
		// `orders-refund` is Tier 2 under the commerce verb table; the platform's card wins.
		$this->assertSame( 'fake card for commerce/refund', CommerceSummary::filter( 'plain', 'senroflux/orders-refund', array() ) );

		$lookup = ReportLookup::resolve( 'order:7' );
		$this->assertSame( 'Fake order:7', $lookup['title'] ?? null );
	}

	// ------------------------------------------------------------------
	// 4. The pin at start
	// ------------------------------------------------------------------

	public function test_the_real_commerce_pack_pins_the_selected_platform(): void {
		$this->assertSame( 'fake-a', ( new CommercePack() )->pinForRun() );
		$this->assertNull( $this->packWith( 'plain', array( 'core/', 'senroflux/' ) )->pinForRun(), 'other packs pin nothing' );
	}

	public function test_start_pins_what_the_pack_asks_it_to_and_clears_the_scope(): void {
		$GLOBALS['wpdb']                                   = new wpdb();
		$GLOBALS['senroflux_test_current_user_id']         = 1;
		$GLOBALS['senroflux_test_user_caps']['edit_posts'] = true;
		$GLOBALS['senroflux_test_user_caps_by_id'][1]['edit_posts'] = true;
		Plugin::set_dependency_probe( true );

		$pack = new class() extends Pack {
			public ?string $scoped = 'untouched';

			public function __construct() {
				parent::__construct( array( 'read' => 'read-content' ) );
			}

			public function name(): string {
				return 'pinning';
			}

			public function verbMap(): array {
				return array( 'senroflux/read-content' => 0 );
			}

			public function runCapability(): string {
				return 'edit_posts';
			}

			public function pinForRun(): ?string {
				return 'fake-a';
			}

			public function useRunPlatform( ?string $pinned ): void {
				$this->scoped = $pinned;
			}

			protected function agentSafetyBindingError( int $user_id ): ?\WP_Error {
				unset( $user_id );

				return null;
			}
		};
		add_filter( 'senroflux_packs', static fn ( array $packs ): array => $packs + array( 'pinning' => $pack ), 10, 1 );

		$result = Plugin::instance()->start( 'specflux-mac', 'Do it', array(), array(), 'pinning' );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$method = new \ReflectionMethod( Plugin::class, 'runner' );
		$run    = $method->invoke( Plugin::instance() )->store()->getRun( (int) $result['run']['id'] );
		$this->assertSame( 'fake-a', $run?->storePlatform, 'the pin is on the run row' );
		$this->assertNull( $pack->scoped, 'the start-time scope is cleared afterwards' );
	}
}
