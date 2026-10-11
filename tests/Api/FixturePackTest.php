<?php
/**
 * S23: a third-party pack built from ONLY `@api` symbols registers, resolves
 * its roles, gets its verbs tiered, and imports nothing outside the declared
 * surface.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Api;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\PackRegistry;
use Specflux\SiteAgent\Tests\Api\Fixtures\FixturePack;

final class FixturePackTest extends TestCase {

	// Not PSR-4 autoloaded (tests/ has no autoload-dev mapping); loaded here,
	// inside a method, rather than as a file-level side effect (PSR1.Files.SideEffects).
	public static function setUpBeforeClass(): void {
		require_once __DIR__ . '/Fixtures/FixturePack.php';
		require_once __DIR__ . '/Fixtures/FixtureAbility.php';
	}

	protected function setUp(): void {
		remove_all_filters( 'specflux_site_agent_packs' );
		$GLOBALS['specflux_site_agent_test_abilities'] = array();
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_packs' );
		$GLOBALS['specflux_site_agent_test_abilities'] = array();
	}

	/**
	 * The fixture pack registers through `specflux_site_agent_packs` (`@api`) exactly
	 * as a real third-party pack would.
	 */
	public function test_registers_through_specflux_site_agent_packs_filter(): void {
		add_filter(
			'specflux_site_agent_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new FixturePack();

				return $packs;
			}
		);

		$registry = PackRegistry::fromFilters();
		$pack     = $registry->get( 'fixture' );

		$this->assertNotNull( $pack, 'FixturePack must survive PackRegistry::register()\'s S19 namespace-governance check.' );
		$this->assertSame( 'fixture', $pack->name() );
	}

	/**
	 * Roles resolve to the polyfill namespace (no fixture ability needs to be
	 * registered for this — the last `abilityNamespaces()` entry is always
	 * accepted without an existence check).
	 */
	public function test_resolves_its_roles(): void {
		$pack     = new FixturePack();
		$resolved = $pack->resolveAbilities();

		$this->assertSame(
			array(
				'read'  => 'specflux/fixture-read',
				'write' => 'specflux/fixture-write',
			),
			$resolved
		);
		$this->assertSame(
			array( 'specflux/fixture-read', 'specflux/fixture-write' ),
			$pack->allowList()
		);
	}

	/**
	 * The fixture ability exists in the abilities fixture registry (proving
	 * a real registration is possible), and the pack's own verbMap() tiers
	 * both resolved verbs.
	 */
	public function test_gets_its_verbs_tiered(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['specflux/fixture-read'] =
			new \SpecfluxSiteAgent_Test_Fake_Ability( 'specflux/fixture-read' );

		$pack = new FixturePack();

		$this->assertTrue( wp_has_ability( 'specflux/fixture-read' ) );

		$verb_map = $pack->verbMap();
		$this->assertSame( 0, $verb_map['specflux/fixture-read'] );
		$this->assertSame( 1, $verb_map['specflux/fixture-write'] );

		// agentSafetyVerbMap() (@api) — the base's default roleVerbs()
		// implementation (no override here) means every role fails closed
		// to Tier 2 unless the pack states which verbs each role can
		// produce; FixturePack does not override roleVerbs(), so this
		// documents the fail-closed default rather than asserting a tier
		// the pack never actually declared.
		$as_map = $pack->agentSafetyVerbMap();
		$this->assertSame( 2, $as_map['specflux/fixture-read'] );
		$this->assertSame( 2, $as_map['specflux/fixture-write'] );
	}

	/**
	 * Static check: FixturePack.php imports and references ONLY the
	 * declared `@api` Specflux Site Agent symbols (Pack, Skill, SkillSource,
	 * SetupCheck) — never an `@internal` one. Scans the fixture's own `use`
	 * statements and every fully-qualified `Specflux\SiteAgent\...`
	 * occurrence in its body.
	 */
	public function test_uses_only_api_symbols(): void {
		$allowed = array(
			'Specflux\\SiteAgent\\Packs\\Pack',
			'Specflux\\SiteAgent\\Skills\\Skill',
			'Specflux\\SiteAgent\\Skills\\SkillSource',
			'Specflux\\SiteAgent\\Setup\\SetupCheck',
		);

		$source = (string) file_get_contents( __DIR__ . '/Fixtures/FixturePack.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local test fixture, not a remote URL.

		preg_match_all( '/use\s+(Specflux\\\\SiteAgent\\\\[A-Za-z0-9_\\\\]+);/', $source, $use_matches );
		foreach ( $use_matches[1] as $imported ) {
			$this->assertContains(
				$imported,
				$allowed,
				sprintf( 'FixturePack.php imports non-@api symbol "%s".', $imported )
			);
		}

		preg_match_all( '/\\\\?Specflux\\\\SiteAgent\\\\[A-Za-z0-9_\\\\]+/', $source, $fqcn_matches );
		foreach ( $fqcn_matches[0] as $reference ) {
			$normalized = ltrim( $reference, '\\' );
			// The fixture's OWN namespace (Tests\Api\Fixtures) is not a
			// dependency on plugin internals — only a real plugin symbol
			// reference counts.
			if ( str_starts_with( $normalized, 'Specflux\\SiteAgent\\Tests\\' ) ) {
				continue;
			}
			$this->assertContains(
				$normalized,
				$allowed,
				sprintf( 'FixturePack.php references non-@api symbol "%s".', $normalized )
			);
		}
	}
}
