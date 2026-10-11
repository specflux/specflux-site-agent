<?php
/**
 * Plugin-level tests for 0.3 S6: withheld roles are computed once at
 * start(), dropped from the run's tool set, persisted, and surfaced on
 * every read — a user holding every capability sees nothing withheld.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Tools\ToolRegistry;
use WP_Error;
use wpdb;

final class PluginWithheldRolesTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		remove_all_filters( 'specflux_site_agent_packs' );
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'] = array();
		$GLOBALS['wpdb']                                     = new wpdb();
		Plugin::set_dependency_probe( true ); // AS mode: preflight's binding check is the fixture's own (always bound).
		// 0.3 S11: in AS mode the run capability is checked too, alongside the
		// binding. This fixture models a Contributor — holds the pack's run
		// capability (`edit_posts`) but not `upload_files` — never an
		// unprivileged user who couldn't even reach the pack picker.
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'][1]['edit_posts'] = true;
	}

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_packs' );
		unset( $GLOBALS['wpdb'], $GLOBALS['specflux_site_agent_test_user_caps_by_id'] );
		Plugin::reset();
	}

	/** A fixture pack whose `generate` role requires `upload_files` (S6's own worked example). */
	private function registerFixturePack(): void {
		add_filter(
			'specflux_site_agent_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new class() extends Pack {
					public function __construct() {
						parent::__construct(
							array(
								'generate' => 'generate-image',
								'read'     => 'read-content',
							)
						);
					}

					public function name(): string {
						return 'fixture';
					}

					public function verbMap(): array {
						return array(
							'specflux/generate-image' => 0,
							'specflux/read-content'   => 0,
						);
					}

					public function roleCapabilities(): array {
						return array( 'generate' => 'upload_files' );
					}

					public function withheldRoleNotice( array $withheld ): ?string {
						return in_array( 'generate', $withheld, true )
							? 'This run cannot add images.'
							: null;
					}

					public function runCapability(): string {
						return 'edit_posts';
					}

					protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
						unset( $user_id );

						return null; // Always bound — this test is not about S13.
					}
				};

				return $packs;
			}
		);
	}

	public function test_a_user_lacking_the_capability_gets_the_role_withheld(): void {
		$this->registerFixturePack();
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'][1]['upload_files'] = false;

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$run_id = $result['run']['id'];

		// Dropped from the run's tool set: the allow-list no longer admits
		// the withheld role's resolved ability, but keeps the other role's.
		$this->assertNotContains( 'specflux/generate-image', $result['run']['allow'] );
		$this->assertContains( 'specflux/read-content', $result['run']['allow'] );

		// Persisted, and surfaced on every read (S6/S12).
		$this->assertSame( array( 'generate' ), $result['run']['withheld_roles'] );

		$fresh = Plugin::instance()->get( $run_id );
		$this->assertIsArray( $fresh );
		$this->assertSame( array( 'generate' ), $fresh['run']['withheld_roles'] );

		// Stage 22b (S6): the pack's own words ride on the read, so the run
		// view can show them at start without the client knowing any pack.
		$this->assertSame( 'This run cannot add images.', $fresh['run']['withheld_notice'] );
		$this->assertSame( 'This run cannot add images.', $result['run']['withheld_notice'] );
	}

	public function test_a_user_holding_the_capability_has_nothing_withheld(): void {
		$this->registerFixturePack();
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'][1]['upload_files'] = true;

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['run']['withheld_roles'] );
		$this->assertNull( $result['run']['withheld_notice'], 'a user holding every capability sees nothing' );
		$this->assertContains( 'specflux/generate-image', $result['run']['allow'] );
	}

	/** ToolRegistry::forRun() is the actual tool-set seam the model sees. */
	public function test_the_withheld_ability_never_becomes_a_tool(): void {
		$this->registerFixturePack();
		$GLOBALS['specflux_site_agent_test_abilities']                          = array(
			'specflux/generate-image' => new \SpecfluxSiteAgent_Test_Fake_Ability( 'specflux/generate-image' ),
			'specflux/read-content'   => new \SpecfluxSiteAgent_Test_Fake_Ability( 'specflux/read-content' ),
		);
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'][1]['upload_files'] = false;

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );
		$this->assertIsArray( $result );

		$prop = new \ReflectionMethod( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		/** @var \Specflux\SiteAgent\Run\Runner $runner */
		$runner = $prop->invoke( Plugin::instance() );

		$run      = $runner->store()->getRun( $result['run']['id'] );
		$registry = ToolRegistry::forRun( $run );

		$this->assertFalse( $registry->admits( 'specflux/generate-image' ) );
		$this->assertTrue( $registry->admits( 'specflux/read-content' ) );
	}
}
