<?php
/**
 * Tool breadth (0.5 K5): every new ability is carried by the right packs, with
 * the tier the precedent demands, and the three content packs hand the SAME
 * classification to Belayline Agent Governance through `belayline_verb_map`.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Content\Comments;
use Specflux\SiteAgent\Packs\Content\PostTools;
use Specflux\SiteAgent\Packs\ContentBundle;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Packs\Pages\PagesPack;
use Specflux\SiteAgent\Packs\Posts\PostsPack;
use Specflux\SiteAgent\Packs\Site\Inventory;
use Specflux\SiteAgent\Packs\Site\Menus;
use Specflux\SiteAgent\Packs\Site\SitePack;
use Specflux\SiteAgent\Packs\Site\Settings;
use Specflux\SiteAgent\Packs\Site\Users;
use Specflux\SiteAgent\Packs\ToolBreadth;
use Specflux\SiteAgent\Tools\VerbTier;

final class ToolBreadthTest extends TestCase {

	/** Ability base name => the tier its verb must carry. */
	private const TIERS = array(
		'list-posts'         => 0,
		'trash-post'         => 2,
		'restore-post'       => 1,
		'list-revisions'     => 0,
		'restore-revision'   => 2,
		'list-post-meta'     => 0,
		'set-post-meta'      => 1,
		'list-comments'      => 0,
		'moderate-comment'   => 1,
		'approve-comment'    => 2,
		'reply-to-comment'   => 2,
		'list-users'         => 0,
		'read-user'          => 0,
		'set-user-role'      => 2,
		'read-settings'      => 0,
		'update-settings'    => 2,
		'read-menus'         => 0,
		'add-menu-item'      => 2,
		'remove-menu-item'   => 2,
		'reorder-menu-items' => 2,
		'list-plugins'       => 0,
		'list-themes'        => 0,
	);

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/stubs/blocks.php';

		$GLOBALS['specflux_site_agent_test_abilities']          = array();
		$GLOBALS['specflux_site_agent_test_ability_categories'] = array();
	}

	/** @return array<string,Pack> */
	private function packs(): array {
		return array(
			'pages' => new PagesPack(),
			'posts' => new PostsPack(),
			'site'  => new SitePack(),
		);
	}

	/** @return list<string> The base names a pack must carry. */
	private function expected( string $pack ): array {
		$groups = match ( $pack ) {
			'pages' => array( ToolBreadth::CONTENT ),
			'posts' => array( ToolBreadth::CONTENT, ToolBreadth::COMMENTS ),
			default => array( ToolBreadth::CONTENT, ToolBreadth::COMMENTS, ToolBreadth::SITE ),
		};

		return array_values( ToolBreadth::roles( $groups ) );
	}

	public function test_every_ability_has_an_expected_tier_and_nothing_is_untiered(): void {
		$this->assertEqualsCanonicalizing( array_keys( self::TIERS ), ToolBreadth::allTemplates() );
	}

	public function test_each_pack_carries_exactly_its_groups(): void {
		foreach ( $this->packs() as $name => $pack ) {
			$allow = $pack->allowList();
			foreach ( $this->expected( $name ) as $template ) {
				$this->assertContains( 'specflux/' . $template, $allow, "$name carries $template" );
			}
		}

		$pages = $this->packs()['pages']->allowList();
		$this->assertNotContains( 'specflux/set-user-role', $pages );
		$this->assertNotContains( 'specflux/update-settings', $pages );
		$this->assertNotContains( 'specflux/approve-comment', $pages );
	}

	public function test_every_pack_verb_is_tiered_as_specified_and_roleverbs_matches_the_verb_map(): void {
		foreach ( $this->packs() as $name => $pack ) {
			$map = $pack->verbMap();
			foreach ( $pack->roleVerbs() as $role => $verbs ) {
				foreach ( $verbs as $verb ) {
					$this->assertArrayHasKey( $verb, $map, "$name/$role verb is mapped" );
				}
			}

			foreach ( $this->expected( $name ) as $template ) {
				$verb = $pack->verbFor( 'specflux/' . $template, array() );
				$this->assertSame( $name . '/' . $template, $verb, 'verb is <pack>/<ability>' );
				$this->assertSame( self::TIERS[ $template ], VerbTier::tierFor( $verb, $map ), "$verb tier" );
			}
		}
	}

	public function test_trashing_a_post_needs_approval_in_every_pack_but_restoring_does_not(): void {
		foreach ( array( 'pages', 'posts', 'site' ) as $name ) {
			$pack = $this->packs()[ $name ];
			$map  = $pack->verbMap();
			$this->assertSame( 2, VerbTier::tierFor( $name . '/trash-post', $map ), "$name/trash-post is Tier 2" );
			$this->assertSame( 1, VerbTier::tierFor( $name . '/restore-post', $map ), "$name/restore-post stays Tier 1" );
			$this->assertSame( 2, $pack->agentSafetyVerbMap()['specflux/trash-post'] ?? null, "$name Belayline Agent Governance classification" );
		}
	}

	public function test_agent_governance_gets_the_same_verb_and_tier_from_every_pack_that_carries_an_ability(): void {
		$maps = array();
		foreach ( $this->packs() as $name => $pack ) {
			$maps[ $name ] = $pack->agentSafetyVerbMap();
			foreach ( $this->expected( $name ) as $template ) {
				$this->assertSame(
					self::TIERS[ $template ],
					$maps[ $name ][ 'specflux/' . $template ] ?? null,
					"belayline_verb_map: $name / specflux/$template"
				);
			}
		}
	}

	public function test_no_hard_delete_or_install_ability_exists(): void {
		foreach ( ToolBreadth::allTemplates() as $template ) {
			$this->assertDoesNotMatchRegularExpression( '/delete|install|activate|create-user|update-plugin/', $template );
		}
	}

	public function test_the_content_bundle_unions_every_new_ability(): void {
		$union = ContentBundle::allowList( ...array_values( $this->packs() ) );
		foreach ( ToolBreadth::allTemplates() as $template ) {
			$this->assertContains( 'specflux/' . $template, $union, $template );
		}
	}

	public function test_capability_gated_roles_name_their_real_capability(): void {
		$site = new SitePack();
		$caps = $site->roleCapabilities();

		$this->assertSame( 'promote_users', $caps['set-user-role'] );
		$this->assertSame( 'list_users', $caps['list-users'] );
		$this->assertSame( 'manage_options', $caps['update-settings'] );
		$this->assertSame( 'moderate_comments', $caps['approve-comment'] );
		$this->assertSame( 'activate_plugins', $caps['list-plugins'] );
		$this->assertSame( 'switch_themes', $caps['list-themes'] );
		$this->assertSame( 'edit_theme_options', $caps['reorder-menu-items'] );
		// The media caps the packs already declared are untouched.
		$this->assertSame( 'upload_files', $caps['upload'] );
	}

	public function test_only_post_scoped_writes_are_tracked_as_posts(): void {
		$site = new SitePack();

		$this->assertSame( 'id', $site->objectIdKey( 'site/trash-post' ) );
		$this->assertSame( 'post_id', $site->objectIdKey( 'site/set-post-meta' ) );
		$this->assertSame( 'post_id', $site->objectIdKey( 'site/restore-revision' ) );
		foreach ( array( 'moderate-comment', 'set-user-role', 'update-settings', 'add-menu-item' ) as $verb ) {
			$this->assertSame( ToolBreadth::UNTRACKED, $site->objectIdKey( 'site/' . $verb ), "$verb is not a post" );
		}
		// Existing verbs keep their keys.
		$this->assertSame( 'attachment_id', $site->objectIdKey( 'site/read-media' ) );
	}

	/**
	 * The packs' client-side input keys must exist in the registered ability's
	 * schema, or the S9 shape-compat seam would silently route around it.
	 */
	public function test_pack_input_keys_are_all_properties_of_the_registered_abilities(): void {
		PostTools::reset();
		Comments::reset();
		Users::reset();
		Settings::reset();
		Menus::reset();
		Inventory::reset();
		PostTools::register();
		Comments::register();
		Users::register();
		Settings::register();
		Menus::register();
		Inventory::register();

		$site = new SitePack();
		$ref  = new \ReflectionMethod( $site, 'inputProperties' );
		$ref->setAccessible( true );

		foreach ( ToolBreadth::allTemplates() as $template ) {
			$this->assertArrayHasKey( 'specflux/' . $template, $GLOBALS['specflux_site_agent_test_abilities'], "$template is registered" );
			$ability = $GLOBALS['specflux_site_agent_test_abilities'][ 'specflux/' . $template ];
			$schema  = $ability->get_input_schema();
			$props   = array_keys( is_array( $schema ) ? ( $schema['properties'] ?? array() ) : array() );

			foreach ( $ref->invoke( $site, $template ) as $key ) {
				$this->assertContains( $key, $props, "$template accepts `$key`" );
			}
			if ( 'list-themes' !== $template ) {
				$this->assertNotSame( array(), $ref->invoke( $site, $template ), "$template declares its input keys" );
			}
		}
	}
}
