<?php
/**
 * Store-platform selection (C4-0): 0/1/2 platforms, the admin setting, the
 * pinned platform on a pack, and the setup check.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Packs\Commerce\Platforms;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Commerce\CommercePack;
use Specflux\SiteAgent\Packs\Commerce\Platforms\PlatformSelection;
use Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatform;
use Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SiteAgent\Plugin;
use WP_Error;

final class StorePlatformsTest extends TestCase {

	protected function setUp(): void {
		require_once __DIR__ . '/FakePlatform.php';
		remove_all_filters( 'specflux_site_agent_store_platforms' );
		$GLOBALS['specflux_site_agent_test_options']   = array();
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => true );
		Plugin::reset();
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_store_platforms' );
		$GLOBALS['specflux_site_agent_test_abilities'] = array();
		$GLOBALS['specflux_site_agent_test_options']   = array();
		$GLOBALS['specflux_site_agent_test_user_caps'] = array();
		Plugin::set_dependency_probe( null );
		Plugin::reset();
	}

	/**
	 * @param list<StorePlatform> $platforms Platforms to register after Woo.
	 */
	private function register( array $platforms ): void {
		add_filter(
			'specflux_site_agent_store_platforms',
			static fn ( array $existing ): array => array_merge( $existing, $platforms ),
			10,
			1
		);
	}

	public function test_zero_platforms_selects_nothing(): void {
		// WooCommerce is never defined in the unit suite, so Woo is inactive.
		$selection = StorePlatforms::select();

		$this->assertSame( PlatformSelection::NONE, $selection->status );
		$this->assertNull( $selection->platform );
		$this->assertFalse( StorePlatforms::anyActive() );
	}

	public function test_exactly_one_governed_platform_is_selected_without_a_setting(): void {
		$this->register( array( new FakePlatform( 'fake-a' ) ) );

		$selection = StorePlatforms::select();

		$this->assertSame( PlatformSelection::SINGLE, $selection->status );
		$this->assertSame( 'fake-a', $selection->platform?->id() );
	}

	public function test_an_active_but_ungoverned_platform_is_not_a_candidate(): void {
		$this->register( array( new FakePlatform( 'fake-a', governed: false ) ) );

		$this->assertSame( PlatformSelection::NONE, StorePlatforms::select()->status );
		$this->assertTrue( StorePlatforms::anyActive(), 'active still registers the pack, so its setup check can explain' );
	}

	public function test_two_platforms_with_no_setting_is_ambiguous(): void {
		$this->register( array( new FakePlatform( 'fake-a' ), new FakePlatform( 'fake-b' ) ) );

		$selection = StorePlatforms::select();

		$this->assertSame( PlatformSelection::AMBIGUOUS, $selection->status );
		$this->assertNull( $selection->platform );
		$this->assertCount( 2, $selection->candidates );
	}

	public function test_two_platforms_the_setting_picks_one(): void {
		$this->register( array( new FakePlatform( 'fake-a' ), new FakePlatform( 'fake-b' ) ) );
		$this->assertTrue( StorePlatforms::save( 'fake-b' ) );

		$selection = StorePlatforms::select();

		$this->assertSame( PlatformSelection::CHOSEN, $selection->status );
		$this->assertSame( 'fake-b', $selection->platform?->id() );
	}

	public function test_a_setting_naming_a_platform_that_is_not_a_candidate_stays_ambiguous(): void {
		$this->register( array( new FakePlatform( 'fake-a' ), new FakePlatform( 'fake-b' ), new FakePlatform( 'fake-c', governed: false ) ) );
		$this->assertTrue( StorePlatforms::save( 'fake-c' ) );

		$this->assertSame( PlatformSelection::AMBIGUOUS, StorePlatforms::select()->status );
	}

	public function test_saving_an_unknown_id_is_rejected_and_changes_nothing(): void {
		$this->register( array( new FakePlatform( 'fake-a' ), new FakePlatform( 'fake-b' ) ) );
		StorePlatforms::save( 'fake-a' );

		$result = StorePlatforms::save( 'not-a-platform' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'store_platform_unknown', $result->get_error_code() );
		$this->assertSame( 'fake-a', StorePlatforms::chosenId() );
		$this->assertSame( 'fake-a', StorePlatforms::sanitizeOption( 'not-a-platform' ), 'the register_setting sanitiser keeps the previous value' );
		$this->assertSame( 'fake-b', StorePlatforms::sanitizeOption( 'fake-b' ) );
	}

	public function test_saving_needs_manage_options_and_empty_clears(): void {
		$this->register( array( new FakePlatform( 'fake-a' ) ) );
		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => false );

		$denied = StorePlatforms::save( 'fake-a' );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'store_platform_forbidden', $denied->get_error_code() );
		$this->assertSame( '', StorePlatforms::chosenId() );

		$GLOBALS['specflux_site_agent_test_user_caps'] = array( 'manage_options' => true );
		StorePlatforms::save( 'fake-a' );
		$this->assertSame( 'fake-a', StorePlatforms::chosenId() );
		$this->assertTrue( StorePlatforms::save( '' ) );
		$this->assertSame( '', StorePlatforms::chosenId() );
	}

	public function test_registration_drops_bad_entries(): void {
		$this->register(
			array(
				new FakePlatform( 'fake-a' ),
				new FakePlatform( 'fake-a' ), // Duplicate id.
				new FakePlatform( 'no-polyfill-ns', namespaces: array( 'fake/' ) ),
				new FakePlatform( '' ),
			)
		);
		add_filter(
			'specflux_site_agent_store_platforms',
			static fn ( array $existing ): array => array_merge( $existing, array( 'not a platform' ) ),
			10,
			1
		);

		$this->assertSame( array( 'woo', 'fake-a' ), array_keys( StorePlatforms::all() ) );
	}

	public function test_usable_means_registered_active_and_governed(): void {
		$fake = new FakePlatform( 'fake-a' );
		$this->register( array( $fake ) );

		$this->assertTrue( StorePlatforms::usable( 'fake-a' ) );
		$fake->active = false;
		$this->assertFalse( StorePlatforms::usable( 'fake-a' ), 'deactivated' );
		$fake->active   = true;
		$fake->governed = false;
		$this->assertFalse( StorePlatforms::usable( 'fake-a' ), 'no longer governed' );
		$this->assertFalse( StorePlatforms::usable( 'gone' ), 'unregistered' );
	}

	// ------------------------------------------------------------------
	// The pack follows the selected / pinned platform.
	// ------------------------------------------------------------------

	public function test_woo_is_what_the_pack_speaks_for_when_nothing_is_usable(): void {
		$pack = new CommercePack();

		$this->assertSame( 'woo', $pack->platform()->id() );
		$this->assertSame( 'manage_woocommerce', $pack->runCapability() );
		$this->assertSame( array( 'woocommerce/', 'specflux/' ), $pack->abilityNamespaces() );
	}

	public function test_the_pack_follows_the_only_usable_platform(): void {
		$this->register( array( new FakePlatform( 'fake-a', capability: 'manage_fake' ) ) );
		$GLOBALS['specflux_site_agent_test_abilities'] = array(
			'fake/fake-read' => new \SpecfluxSiteAgent_Test_Fake_Ability(
				'fake/fake-read',
				input_schema: array(
					'type'       => 'object',
					'properties' => array(),
				)
			),
		);
		$pack = new CommercePack();

		$this->assertSame( 'manage_fake', $pack->runCapability() );
		$this->assertSame( array( 'fake/', 'specflux/' ), $pack->abilityNamespaces() );
		$this->assertSame( array( 'read' => 'fake-read' ), $pack->roles() );
		$this->assertSame( array( 'read' => 'fake/fake-read' ), $pack->resolveAbilities() );
	}

	public function test_a_pinned_platform_beats_the_setting_and_clears_the_resolution_cache(): void {
		$this->register( array( new FakePlatform( 'fake-a', capability: 'cap_a' ), new FakePlatform( 'fake-b', capability: 'cap_b' ) ) );
		StorePlatforms::save( 'fake-a' );
		$GLOBALS['specflux_site_agent_test_abilities'] = array(
			'fake/fake-read' => new \SpecfluxSiteAgent_Test_Fake_Ability(
				'fake/fake-read',
				input_schema: array(
					'type'       => 'object',
					'properties' => array(),
				)
			),
		);
		$pack = new CommercePack();

		$this->assertSame( 'cap_a', $pack->runCapability() );
		$pack->resolveAbilities();

		$pack->useRunPlatform( 'fake-b' );
		$this->assertSame( 'cap_b', $pack->runCapability(), 'a resumed run keeps the platform it started on' );
		$this->assertSame( array( 'read' => 'fake/fake-read' ), $pack->resolveAbilities() );

		$pack->useRunPlatform( null );
		$this->assertSame( 'cap_a', $pack->runCapability() );
	}

	public function test_the_pack_verb_table_and_write_ids_come_from_the_platform(): void {
		$this->register( array( new FakePlatform( 'fake-a' ) ) );
		$pack = new CommercePack();

		$this->assertSame( array( 'fake/verb' => 1 ), $pack->verbMap() );
		$this->assertSame( 'fake/verb', $pack->verbFor( 'fake/fake-read', array() ) );
		$this->assertSame( 'unmapped/thing', $pack->verbFor( 'unmapped/thing', array() ), 'an unknown ability stays its own verb (fail closed)' );
	}

	public function test_the_ambiguity_setup_check_blocks_in_agent_safety_mode_only(): void {
		$this->register( array( new FakePlatform( 'fake-a' ), new FakePlatform( 'fake-b' ) ) );
		$pack = new CommercePack();

		// The platforms are governed by the fake itself, so the gate mode can be either.
		Plugin::set_dependency_probe( true );
		$checks = $pack->setupChecks( 1 );
		$this->assertSame( 'commerce/platform', $checks[0]->id() );
		$this->assertFalse( $checks[0]->passed() );
		$this->assertSame( 'store_platform_unselected', $checks[0]->errorCode() );

		StorePlatforms::save( 'fake-a' );
		$ids = array_map( static fn ( $c ) => $c->id(), $pack->setupChecks( 1 ) );
		$this->assertNotContains( 'commerce/platform', $ids );
	}
}
