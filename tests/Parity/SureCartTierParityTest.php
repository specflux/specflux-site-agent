<?php
/**
 * SureCart tier parity (C4-S): SenroFlux's SureCart platform against
 * SenroGate's own SureCart catalog, which is the source of truth for tiers.
 *
 * Reads two SenroGate source files by regex (this repo cannot load them: they
 * are another plugin's classes):
 * `plugin/src/Integrations/SureCart/SureCartVerbCatalog.php` and
 * `DiscountElevationRule.php`.
 *
 * LOCATING THEM: set SENROFLUX_SURECART_SENROGATE_DIR to SenroGate's
 * `plugin/src/Integrations/SureCart` directory, e.g.
 *
 *     SENROFLUX_SURECART_SENROGATE_DIR=/path/to/senrogate/plugin/src/Integrations/SureCart composer test
 *
 * With it unset the tests skip (a normal state for a SenroFlux-only
 * checkout); under CI=true an unset or unreadable path fails instead, because
 * a skipped parity check proves nothing.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Parity;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Commerce\CommercePack;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SenroFlux\Packs\Commerce\Platforms\SureCart\SureCartPlatform;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Tools\VerbTier;

final class SureCartTierParityTest extends TestCase {

	private const ENV = 'SENROFLUX_SURECART_SENROGATE_DIR';

	private const TIERS = array(
		'Reversible'    => 0,
		'SideEffecting' => 1,
		'Irreversible'  => 2,
	);

	/** A benign input per native ability: no elevation rule fires. */
	private const BASE_INPUT = array(
		'create-coupon' => array(
			'name'        => 'c',
			'percent_off' => 10,
		),
		'update-price'  => array(
			'id'   => 'pr',
			'name' => 'n',
		),
	);

	protected function setUp(): void {
		remove_all_filters( 'senroflux_store_platforms' );
		Plugin::reset();
	}

	protected function tearDown(): void {
		remove_all_filters( 'senroflux_store_platforms' );
		Plugin::reset();
	}

	private function dir(): string {
		$dir = getenv( self::ENV );
		if ( false === $dir || '' === $dir || ! is_readable( $dir . '/SureCartVerbCatalog.php' ) ) {
			$message = self::ENV . ' does not point at SenroGate\'s plugin/src/Integrations/SureCart directory.';
			if ( 'true' === getenv( 'CI' ) ) {
				$this->fail( 'CI=true but ' . $message );
			}
			$this->markTestSkipped( $message );
		}

		return rtrim( $dir, '/' );
	}

	private function read( string $file ): string {
		return (string) file_get_contents( $this->dir() . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local sibling-repo source file, not a remote URL.
	}

	/** @return array<string,int> ability id => SenroGate tier */
	private function catalog(): array {
		preg_match_all( "/'(surecart\/[a-z-]+)'\s*=>\s*Tier::(\w+)/", $this->read( 'SureCartVerbCatalog.php' ), $m, PREG_SET_ORDER );
		$map = array();
		foreach ( $m as $row ) {
			$map[ $row[1] ] = self::TIERS[ $row[2] ];
		}

		return $map;
	}

	/** @return list<string> */
	private function ungrantable(): array {
		preg_match( '/UNGRANTABLE\s*=\s*\[(.*?)\];/s', $this->read( 'SureCartVerbCatalog.php' ), $block );
		preg_match_all( "/'(surecart\/[a-z-]+)'/", $block[1] ?? '', $m );

		return $m[1];
	}

	private function pack(): CommercePack {
		$platform = new SureCartPlatform( static fn (): array => array( 'gated' => true ) );
		add_filter( 'senroflux_store_platforms', static fn ( array $p ): array => array_merge( $p, array( $platform ) ), 10, 1 );
		$pack = new CommercePack();
		$pack->useRunPlatform( 'surecart' );

		return $pack;
	}

	/** @return list<string> Native ability names (name segments) the platform maps; the polyfill-only status role is not one. */
	private function nativeAbilities(): array {
		$names = array();
		foreach ( ( new SureCartPlatform() )->roleMap() as $value ) {
			$spec = StorePlatforms::roleSpec( $value );
			if ( $spec['native'] !== $spec['polyfill'] ) {
				$names[] = $spec['native'];
			}
		}

		return $names;
	}

	public function test_the_catalog_was_read(): void {
		$catalog = $this->catalog();

		$this->assertGreaterThanOrEqual( 50, count( $catalog ), 'SenroGate lists every one of SureCart\'s 54 abilities' );
		$this->assertNotEmpty( $this->ungrantable() );
	}

	public function test_every_native_ability_the_platform_maps_is_in_senrogates_catalog_at_the_same_base_tier(): void {
		$catalog  = $this->catalog();
		$pack     = $this->pack();
		$verbMap  = $pack->verbMap();
		$exercise = 0;

		foreach ( $this->nativeAbilities() as $name ) {
			$id = 'surecart/' . $name;
			$this->assertArrayHasKey( $id, $catalog, "SenroGate does not govern $id" );

			$verb = $pack->verbFor( $id, self::BASE_INPUT[ $name ] ?? array() );
			$this->assertSame( $catalog[ $id ], VerbTier::tierFor( $verb, $verbMap ), "$id: base tier ($verb)" );
			++$exercise;
		}

		$this->assertGreaterThanOrEqual( 10, $exercise );
	}

	public function test_ungrantable_abilities_the_platform_can_reach_are_ungrantable_here_too(): void {
		$pack = $this->pack();

		foreach ( $this->ungrantable() as $id ) {
			$name = substr( $id, strlen( 'surecart/' ) );
			if ( ! in_array( $name, $this->nativeAbilities(), true ) ) {
				continue; // Not a role here, so the allow-list never offers it.
			}
			$this->assertContains( $pack->verbFor( $id, array() ), $pack->ungrantableVerbs(), "$id is ungrantable in SenroGate" );
		}

		$this->assertContains( 'surecart/create-refund', $this->ungrantable() );
	}

	public function test_the_elevation_rules_read_the_same_thresholds_and_keys(): void {
		$discount = $this->read( 'DiscountElevationRule.php' );

		$this->assertSame( 1, preg_match( '/PERCENT_THRESHOLD\s*=\s*([\d.]+);/', $discount, $percent ) );
		$this->assertSame( SureCartPlatform::PERCENT_THRESHOLD, (float) $percent[1] );
		$this->assertSame( 1, preg_match( '/DEFAULT_AMOUNT_THRESHOLD\s*=\s*(\d+);/', $discount, $amount ) );
		$this->assertSame( SureCartPlatform::DEFAULT_AMOUNT_THRESHOLD, (int) $amount[1] );
		$this->assertSame( 1, preg_match( "/AMOUNT_THRESHOLD_FILTER\s*=\s*'([a-z_]+)';/", $discount, $filter ) );
		$this->assertSame( SureCartPlatform::AMOUNT_THRESHOLD_FILTER, $filter[1] );
		$this->assertStringContainsString( "'surecart/create-coupon'", $discount );

		$price = $this->read( 'PriceElevationRule.php' );
		$this->assertStringContainsString( "'surecart/update-price'", $price );
		$this->assertStringContainsString( "'amount'", $price );
		$this->assertStringContainsString( "'archived'", $price );
	}

	public function test_the_elevated_inputs_land_on_the_tier_senrogate_assigns(): void {
		$pack = $this->pack();
		$map  = $pack->verbMap();
		$tier = static fn ( string $ability, array $input ): int => VerbTier::tierFor( $pack->verbFor( $ability, $input ), $map );

		// Tier 2 when SenroGate's rules elevate...
		$this->assertSame( 2, $tier( 'surecart/update-price', array( 'amount' => 1 ) ) );
		$this->assertSame( 2, $tier( 'surecart/update-price', array( 'archived' => true ) ) );
		$this->assertSame( 2, $tier( 'surecart/create-coupon', array( 'percent_off' => SureCartPlatform::PERCENT_THRESHOLD ) ) );
		$this->assertSame( 2, $tier( 'surecart/create-coupon', array( 'amount_off' => SureCartPlatform::DEFAULT_AMOUNT_THRESHOLD + 1 ) ) );
		// ...and the catalog base otherwise.
		$this->assertSame( $this->catalog()['surecart/update-price'], $tier( 'surecart/update-price', array( 'name' => 'n' ) ) );
		$this->assertSame( $this->catalog()['surecart/create-coupon'], $tier( 'surecart/create-coupon', array( 'percent_off' => 1 ) ) );
	}
}
