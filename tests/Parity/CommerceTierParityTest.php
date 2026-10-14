<?php
/**
 * S19 commerce tier parity: Specflux Site Agent's own `verbFor()` + verb map against
 * the shared fixture, and a byte-identical check against Agent Safety's copy
 * when the sibling repo is checked out.
 *
 * TARGET REPO PATH: tests/Parity/CommerceTierParityTest.php
 *
 * SCOPE (stage 13): every fixture row this pack now registers a role for
 * (`orders-query`, `order-add-note` both flags) is exercised against
 * `verbFor()` + `verbMap()`, same as the stage-12 catalogue rows. The
 * fixture also carries `order-update-status` and `product-delete` rows —
 * abilities S19 deliberately does NOT make pack roles ("product-delete and
 * order-update-status are deliberately not roles") — those are asserted the
 * OTHER way: `verbFor()` returns the ability id unchanged (the base-class
 * direct-allow fallback) and neither base name appears in any `roleVerbs()`
 * entry, rather than being skipped as out of scope.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Parity;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Commerce\CommercePack;
use Specflux\SiteAgent\Tools\VerbTier;

final class CommerceTierParityTest extends TestCase {

	/** Ability base names this pack registers a role for (S19, full table). */
	private const PACK_ABILITIES = array( 'products-query', 'product-create', 'product-update', 'orders-query', 'order-add-note', 'order-update-status', 'product-delete' );

	/**
	 * Ability base names that are still deliberately NOT pack roles (the
	 * MCP-bridge create/update names). `order-update-status` and
	 * `product-delete` became roles in C4-W and are classified above.
	 */
	private const DELIBERATELY_NOT_ROLES = array( 'orders-create', 'orders-update', 'products-update' );

	private function fixturePath(): string {
		return __DIR__ . '/commerce-tier-parity.json';
	}

	/** @return list<array{ability:string,args:array<string,mixed>,tier:int}> */
	private function fixture(): array {
		$decoded = json_decode( (string) file_get_contents( $this->fixturePath() ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.
		$this->assertIsArray( $decoded, 'the parity fixture must be valid JSON' );

		return $decoded;
	}

	public function test_the_fixture_file_exists_and_parses(): void {
		$this->assertFileExists( $this->fixturePath() );
		$this->assertNotEmpty( $this->fixture() );
	}

	/**
	 * Specflux Site Agent's own `verbFor()` + `verbMap()` classify every fixture row for
	 * an ability this pack registers a role for at the same tier the fixture
	 * (and, on the Agent Safety side, `TierClassifier`) expects.
	 */
	public function test_specflux_site_agent_classifies_every_pack_ability_fixture_row_at_the_fixture_tier(): void {
		$pack      = new CommercePack();
		$verbMap   = $pack->verbMap();
		$exercised = 0;

		foreach ( $this->fixture() as $case ) {
			$ability = (string) ( $case['ability'] ?? '' );
			$base    = str_contains( $ability, '/' ) ? substr( $ability, strrpos( $ability, '/' ) + 1 ) : $ability;

			if ( ! in_array( $base, self::PACK_ABILITIES, true ) ) {
				continue; // order-update-status / product-delete — deliberately not roles, see below.
			}

			$args = is_array( $case['args'] ?? null ) ? $case['args'] : array();
			$verb = $pack->verbFor( $ability, $args );
			$tier = VerbTier::tierFor( $verb, $verbMap );

			$this->assertSame(
				(int) $case['tier'],
				$tier,
				sprintf( 'ability %s args %s => verb %s expected tier %d, got %d', $ability, wp_json_encode( $args ), $verb, (int) $case['tier'], $tier )
			);
			++$exercised;
		}

		$this->assertGreaterThan( 0, $exercised, 'the fixture must actually exercise the pack\'s abilities' );
	}

	/**
	 * The MCP-bridge names (`orders-create`, `orders-update`, `products-update`)
	 * are deliberately not pack roles: `verbFor()` falls through to the
	 * base-class direct-allow default (the ability id unchanged), and no
	 * `roleVerbs()` entry names them.
	 */
	public function test_the_mcp_bridge_names_are_deliberately_not_roles(): void {
		$pack       = new CommercePack();
		$role_verbs = $pack->roleVerbs();

		foreach ( $this->fixture() as $case ) {
			$ability = (string) ( $case['ability'] ?? '' );
			$base    = str_contains( $ability, '/' ) ? substr( $ability, strrpos( $ability, '/' ) + 1 ) : $ability;

			if ( ! in_array( $base, self::DELIBERATELY_NOT_ROLES, true ) ) {
				continue;
			}

			$args = is_array( $case['args'] ?? null ) ? $case['args'] : array();
			$this->assertSame( $ability, $pack->verbFor( $ability, $args ), "$ability must fall through to the ability id unchanged" );
		}

		foreach ( $role_verbs as $role => $verbs ) {
			foreach ( $verbs as $verb ) {
				foreach ( self::DELIBERATELY_NOT_ROLES as $not_role ) {
					$this->assertStringNotContainsString(
						$not_role,
						$verb,
						"role $role must not declare a verb naming $not_role"
					);
				}
			}
		}
	}

	public function test_a_price_or_stock_meta_write_on_product_update_is_a_price_change(): void {
		$pack = new CommercePack();

		foreach ( array( '_price', '_regular_price', '_sale_price', '_stock', '_manage_stock' ) as $key ) {
			$verb = $pack->verbFor(
				'woocommerce/product-update',
				array(
					'id'        => 5,
					'meta_data' => array(
						array(
							'key'   => $key,
							'value' => '1',
						),
					),
				)
			);
			$this->assertSame( 2, \Specflux\SiteAgent\Tools\VerbTier::tierFor( $verb, $pack->verbMap() ), $key );
		}
		$verb = $pack->verbFor(
			'woocommerce/product-update',
			array(
				'id'        => 5,
				'meta_data' => array(
					array(
						'key'   => '_note',
						'value' => 'x',
					),
				),
			)
		);
		$this->assertSame( 1, \Specflux\SiteAgent\Tools\VerbTier::tierFor( $verb, $pack->verbMap() ) );
	}

	/**
	 * The two copies (this repo's and Agent Safety's) must be byte-identical
	 * — the shared-file mechanism S19 documents in place of a composer
	 * package. Skips with a clear message when the sibling repo is not
	 * checked out next to this one (a normal, non-failing state for a
	 * Specflux Site Agent-only checkout), but fails under CI=true, where the sibling
	 * checkout is part of the job and its absence means the check ran on
	 * nothing.
	 */
	public function test_byte_identical_to_agent_safetys_copy_when_the_sibling_repo_is_present(): void {
		// SPECFLUX_SITE_AGENT_PARITY_SIBLING points at the producer's fixture when the
		// checkout is not laid out as ../agent-safety (e.g. a Belayline Agent Governance worktree).
		$override = getenv( 'SPECFLUX_SITE_AGENT_PARITY_SIBLING' );
		$sibling  = ( false !== $override && '' !== $override )
			? $override
			: dirname( __DIR__, 3 ) . '/agent-safety/plugin/tests/Fixtures/commerce-tier-parity.json';

		if ( ! is_readable( $sibling ) ) {
			if ( 'true' === getenv( 'CI' ) ) {
				$this->fail( 'CI=true but Agent Safety\'s parity fixture is unavailable at ' . $sibling );
			}
			$this->markTestSkipped( 'Agent Safety is not checked out alongside Specflux Site Agent (checked: ' . $sibling . ') — nothing to diff against.' );
		}

		$this->assertSame(
			file_get_contents( $sibling ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local sibling-repo fixture, not a remote URL.
			file_get_contents( $this->fixturePath() ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.
			'Specflux Site Agent\'s and Agent Safety\'s copies of the parity fixture have drifted apart'
		);
	}
}
