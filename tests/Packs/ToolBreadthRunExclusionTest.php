<?php
/**
 * Tool breadth is CONNECTOR-ONLY: the K5 abilities stay in the pack verb maps
 * (so Belayline Agent Governance classifies connector calls unchanged) and stay exposed through
 * the connector, but an in-admin run never has them in its allow-list or tool
 * surface, and a model naming one is refused as an unknown tool.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Packs;

use Specflux\SiteAgent\Admin\RunsScreen;
use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\ExposureSet;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Packs\ToolBreadth;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\Run;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Tests\Connector\ConnectorTestCase;
use Specflux\SiteAgent\Tools\ToolRegistry;
use SpecfluxSiteAgent_Test_Fake_Ability;

final class ToolBreadthRunExclusionTest extends ConnectorTestCase {

	/** @return list<string> Every breadth ability id, specflux/ namespace. */
	private function breadth(): array {
		return array_map( static fn ( string $t ): string => 'specflux/' . $t, ToolBreadth::allTemplates() );
	}

	private function registerContentPacks(): void {
		add_filter(
			'specflux_site_agent_packs',
			static function ( array $packs ): array {
				$packs['pages'] = new \Specflux\SiteAgent\Packs\Pages\PagesPack();
				$packs['posts'] = new \Specflux\SiteAgent\Packs\Posts\PostsPack();
				$packs['site']  = new \Specflux\SiteAgent\Packs\Site\SitePack();

				return $packs;
			}
		);
	}

	private function registerBreadthAbilities(): void {
		foreach ( $this->breadth() as $name ) {
			$GLOBALS['specflux_site_agent_test_abilities'][ $name ] = new SpecfluxSiteAgent_Test_Fake_Ability( $name );
		}
		$GLOBALS['specflux_site_agent_test_abilities']['specflux/read-content'] = new SpecfluxSiteAgent_Test_Fake_Ability( 'specflux/read-content' );
	}

	public function test_a_started_run_never_carries_a_breadth_ability(): void {
		$this->registerContentPacks(); // Built-in mode, driven from the Runs screen consumer.
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'][ self::USER ] = array(
			'edit_posts'         => true,
			'manage_options'     => true,
			'edit_pages'         => true,
			'upload_files'       => true,
			'moderate_comments'  => true,
			'list_users'         => true,
			'promote_users'      => true,
			'edit_theme_options' => true,
			'activate_plugins'   => true,
			'switch_themes'      => true,
		);

		foreach ( array( 'pages', 'posts', 'site' ) as $pack ) {
			$result = Plugin::instance()->start( RunsScreen::CONSUMER, 'Do something', array(), array(), $pack );
			$this->assertIsArray( $result, $pack . ': ' . ( is_object( $result ) ? $result->get_error_message() : '' ) );
			$this->assertSame( array(), array_values( array_intersect( $this->breadth(), $result['run']['allow'] ) ), "$pack run allow-list" );
			$this->assertNotEmpty( $result['run']['allow'], "$pack run still has its own abilities" );
		}
	}

	public function test_the_run_tool_surface_refuses_a_breadth_ability_even_under_a_wildcard_allow(): void {
		$this->registerBreadthAbilities();
		$run = new Run(
			id: 7,
			userId: 1,
			consumer: 'c',
			goal: 'g',
			status: RunStatus::Pending,
			allow: array( 'specflux/*' ),
			budget: Budget::defaults()
		);

		$registry = ToolRegistry::forRun( $run );

		$this->assertTrue( $registry->admits( 'specflux/read-content' ) );
		foreach ( $this->breadth() as $name ) {
			$this->assertFalse( $registry->admits( $name ), "$name admitted to a run" );
			$this->assertArrayNotHasKey( $name, $registry->declarations(), "$name declared to the model" );
		}
	}

	public function test_the_connector_still_exposes_every_breadth_ability_with_unchanged_scope_rules(): void {
		$this->registerBreadthAbilities();
		$this->registerContentPacks();
		ExposureSet::reset();

		$exposed = ExposureSet::names( GateMode::AgentSafety );
		foreach ( $this->breadth() as $name ) {
			$this->assertContains( $name, $exposed, "$name exposed to the connector" );
		}

		$read_only = new Connection( 1, 'u', Connection::SCOPE_READ );
		$this->assertTrue( ExposureSet::visibleTo( 'specflux/list-posts', $read_only, GateMode::AgentSafety ) );
		$this->assertFalse( ExposureSet::visibleTo( 'specflux/trash-post', $read_only, GateMode::AgentSafety ) );
		$this->assertTrue( ExposureSet::visibleTo( 'specflux/trash-post', new Connection( 1, 'u', Connection::SCOPE_READ_WRITE ), GateMode::AgentSafety ) );
	}

	public function test_pack_verb_maps_still_classify_every_breadth_ability(): void {
		$pack = new \Specflux\SiteAgent\Packs\Site\SitePack();
		foreach ( ToolBreadth::allTemplates() as $template ) {
			$this->assertArrayHasKey( 'specflux/' . $template, $pack->agentSafetyVerbMap(), $template );
		}
	}
}
