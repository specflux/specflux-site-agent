<?php
/**
 * FluentCart tier parity (C4-F): Specflux Site Agent's FluentCart platform against
 * Belayline Agent Governance's FluentCartVerbCatalog (the tier source of truth), read as a
 * file, never loaded as code.
 *
 * Point SPECFLUX_SITE_AGENT_FLUENTCART_CATALOG at the producer's
 * `plugin/src/Integrations/FluentCart/FluentCartVerbCatalog.php` (default:
 * `../agent-safety/plugin/...`). Unreadable: skipped locally, a FAILURE under
 * CI=true, where the sibling checkout is part of the job and its absence
 * means the check ran on nothing.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Parity;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Commerce\CommercePack;
use Specflux\SiteAgent\Packs\Commerce\Platforms\FluentCart\FluentCartPlatform;
use Specflux\SiteAgent\Tools\VerbTier;

final class FluentCartTierParityTest extends TestCase {

	/** Native abilities Specflux Site Agent names (a rename in Belayline Agent Governance's catalog must break us loudly). */
	private const NATIVES_USED = array( 'manage-coupon', 'refund-order', 'get-sales-report', 'get-product', 'get-order' );

	private FluentCartPlatform $platform;

	protected function setUp(): void {
		$this->platform = new FluentCartPlatform();
	}

	private function catalogPath(): string {
		$override = getenv( 'SPECFLUX_SITE_AGENT_FLUENTCART_CATALOG' );

		return ( false !== $override && '' !== $override )
			? $override
			: dirname( __DIR__, 3 ) . '/agent-safety/plugin/src/Integrations/FluentCart/FluentCartVerbCatalog.php';
	}

	/**
	 * Belayline Agent Governance's base tier per native ability, parsed from the catalog file.
	 *
	 * @return array<string,int> Ability base name => tier (0/1/2).
	 */
	private function governanceTiers(): array {
		$path = $this->catalogPath();
		if ( ! is_readable( $path ) ) {
			if ( 'true' === getenv( 'CI' ) ) {
				$this->fail( 'CI=true but Belayline Agent Governance\'s FluentCart catalog is unavailable at ' . $path );
			}
			$this->markTestSkipped( 'Belayline Agent Governance is not checked out alongside Specflux Site Agent (checked: ' . $path . ', override with SPECFLUX_SITE_AGENT_FLUENTCART_CATALOG).' );
		}

		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local sibling-repo source file, not a remote URL.
		preg_match_all( "/'fluent-cart\/([a-z-]+)'\s*=>\s*Tier::(Reversible|SideEffecting|Irreversible)/", $source, $matches, PREG_SET_ORDER );

		$tiers = array();
		foreach ( $matches as $match ) {
			$tiers[ $match[1] ] = array(
				'Reversible'    => 0,
				'SideEffecting' => 1,
				'Irreversible'  => 2,
			)[ $match[2] ];
		}

		return $tiers;
	}

	private function tierOf( string $base, array $input = array() ): int {
		$verb = $this->platform->verbFor( $base, $input );

		// An unmapped verb is the pack's own fail-closed Tier 2 (VerbTier).
		return null === $verb ? 2 : VerbTier::tierFor( $verb, $this->platform->verbTiers() );
	}

	public function test_the_catalog_parses_and_still_has_the_abilities_we_call(): void {
		$tiers = $this->governanceTiers();

		$this->assertGreaterThanOrEqual( 30, count( $tiers ), 'the catalog parse found its 33 FluentCart abilities' );
		foreach ( self::NATIVES_USED as $base ) {
			$this->assertArrayHasKey( $base, $tiers, "Belayline Agent Governance no longer classifies fluent-cart/$base" );
		}
	}

	public function test_no_native_ability_is_ever_classified_looser_than_agent_governance_does(): void {
		foreach ( $this->governanceTiers() as $base => $governance ) {
			// The most favourable input for each: a draft coupon is Tier 1 in both.
			$input = 'manage-coupon' === $base ? array(
				'action' => 'create',
				'status' => 'inactive',
			) : array();

			$this->assertGreaterThanOrEqual( $governance, $this->tierOf( $base, $input ), "fluent-cart/$base is looser in Specflux Site Agent than in Belayline Agent Governance" );
		}
	}

	public function test_every_native_we_map_has_exactly_governances_tier(): void {
		$tiers = $this->governanceTiers();

		foreach ( self::NATIVES_USED as $base ) {
			$input = 'manage-coupon' === $base ? array(
				'action' => 'create',
				'status' => 'inactive',
			) : array();

			$this->assertNotNull( $this->platform->verbFor( $base, $input ), "$base is mapped to a pack verb" );
			$this->assertSame( 'manage-coupon' === $base ? 1 : $tiers[ $base ], $this->tierOf( $base, $input ), $base );
		}
		$this->assertSame( 0, $this->tierOf( 'get-sales-report' ) );
		$this->assertSame( 0, $this->tierOf( 'get-product' ) );
		$this->assertSame( 0, $this->tierOf( 'get-order' ) );
		$this->assertSame( 2, $this->tierOf( 'refund-order' ) );
	}

	public function test_the_unmapped_irreversible_natives_stay_fail_closed(): void {
		$tiers = $this->governanceTiers();

		foreach ( array( 'change-order-status', 'change-subscription-status' ) as $base ) {
			$this->assertSame( 2, $tiers[ $base ], "Belayline Agent Governance has $base at Tier 2" );
			$this->assertNull( $this->platform->verbFor( $base, array() ), "$base is not a pack role" );
			$this->assertSame( 2, $this->tierOf( $base ) );
		}
	}

	/**
	 * The CouponActivationElevationRule matrix: what makes `manage-coupon`
	 * Tier 2 in Belayline Agent Governance must be Tier 2 here too.
	 */
	public function test_manage_coupon_elevation_matches_governances_rule(): void {
		$this->governanceTiers(); // Skip/fail gate: nothing to compare to without the sibling.

		$cases = array(
			array(
				array(
					'action' => 'create',
					'status' => 'inactive',
				),
				1,
			),
			array( array( 'action' => 'create' ), 2 ),
			array(
				array(
					'action' => 'create',
					'status' => 'active',
				),
				2,
			),
			array(
				array(
					'action' => 'create',
					'status' => 'banana',
				),
				2,
			),
			array(
				array(
					'action'    => 'update',
					'coupon_id' => 1,
					'status'    => 'active',
				),
				2,
			),
			array(
				array(
					'action'    => 'update',
					'coupon_id' => 1,
					'status'    => 'inactive',
				),
				1,
			),
			array(
				array(
					'action'    => 'deactivate',
					'coupon_id' => 1,
				),
				1,
			),
			array(
				array(
					'action'    => 'update',
					'coupon_id' => 0,
					'amount'    => 5,
				),
				2,
			),
		);
		foreach ( $cases as [ $input, $tier ] ) {
			$this->assertSame( $tier, $this->tierOf( 'manage-coupon', $input ), (string) wp_json_encode( $input ) );
		}
	}

	public function test_dry_run_never_relaxes_a_write(): void {
		$this->governanceTiers();

		$this->assertSame(
			2,
			$this->tierOf(
				'refund-order',
				array(
					'order_id' => 1,
					'dry_run'  => true,
				)
			),
			'Belayline Agent Governance\'s DryRunRelaxation is disabled; so is ours'
		);
		$this->assertSame(
			2,
			$this->tierOf(
				'fluentcart-orders-refund',
				array(
					'order_id' => 1,
					'dry_run'  => true,
				)
			)
		);
	}

	public function test_governances_ungrantable_native_verbs_are_ungrantable_here(): void {
		$path = dirname( $this->catalogPath() ) . '/FluentCartIntegration.php';
		$this->governanceTiers();
		$this->assertFileIsReadable( $path );

		$source = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local sibling-repo source file, not a remote URL.
		$this->assertMatchesRegularExpression( "/UNGRANTABLE_VERBS\s*=\s*\[[^\]]*'fluent-cart\/refund-order'/", $source, 'Belayline Agent Governance still lists refund-order as ungrantable' );

		$this->assertContains( 'commerce/refund', ( new CommercePack() )->ungrantableVerbs() );
		$this->assertSame( 'commerce/refund', $this->platform->verbFor( 'refund-order', array() ) );
	}

	public function test_governances_ability_fixture_has_every_native_we_name(): void {
		$this->governanceTiers();
		$fixture = dirname( $this->catalogPath(), 4 ) . '/tests/Fixtures/fluentcart-1.7.0-abilities.json';
		$this->assertFileIsReadable( $fixture );

		$decoded = json_decode( (string) file_get_contents( $fixture ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local sibling-repo fixture, not a remote URL.
		$this->assertIsArray( $decoded );
		$names = array_column( is_array( $decoded['abilities'] ?? null ) ? $decoded['abilities'] : array(), 'ability' );

		foreach ( self::NATIVES_USED as $base ) {
			$this->assertContains( 'fluent-cart/' . $base, $names, "fluent-cart/$base is not in FluentCart 1.7.0's ability set" );
		}
	}
}
