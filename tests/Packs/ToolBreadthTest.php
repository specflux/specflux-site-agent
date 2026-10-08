<?php
/**
 * Tool breadth (0.5 K5): every new ability is carried by the right packs, with
 * the tier the precedent demands, and the three content packs hand the SAME
 * classification to SenroGate through `agent_safety_verb_map`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Content\Comments;
use Specflux\SenroFlux\Packs\Content\PostTools;
use Specflux\SenroFlux\Packs\ContentBundle;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\Pages\PagesPack;
use Specflux\SenroFlux\Packs\Posts\PostsPack;
use Specflux\SenroFlux\Packs\Site\Inventory;
use Specflux\SenroFlux\Packs\Site\Menus;
use Specflux\SenroFlux\Packs\Site\SitePack;
use Specflux\SenroFlux\Packs\Site\Settings;
use Specflux\SenroFlux\Packs\Site\Users;
use Specflux\SenroFlux\Packs\ToolBreadth;
use Specflux\SenroFlux\Tools\VerbTier;

final class ToolBreadthTest extends TestCase {

	/** Ability base name => the tier its verb must carry. */
	private const TIERS = array(
		'list-posts'         => 0,
		'trash-post'         => 1,
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

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
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
				$this->assertContains( 'senroflux/' . $template, $allow, "$name carries $template" );
			}
		}

		$pages = $this->packs()['pages']->allowList();
		$this->assertNotContains( 'senroflux/set-user-role', $pages );
		$this->assertNotContains( 'senroflux/update-settings', $pages );
		$this->assertNotContains( 'senroflux/approve-comment', $pages );
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
				$verb = $pack->verbFor( 'senroflux/' . $template, array() );
				$this->assertSame( $name . '/' . $template, $verb, 'verb is <pack>/<ability>' );
				$this->assertSame( self::TIERS[ $template ], VerbTier::tierFor( $verb, $map ), "$verb tier" );
			}
		}
	}

	public function test_senrogate_gets_the_same_verb_and_tier_from_every_pack_that_carries_an_ability(): void {
		$maps = array();
		foreach ( $this->packs() as $name => $pack ) {
			$maps[ $name ] = $pack->agentSafetyVerbMap();
			foreach ( $this->expected( $name ) as $template ) {
				$this->assertSame(
					self::TIERS[ $template ],
					$maps[ $name ][ 'senroflux/' . $template ] ?? null,
					"agent_safety_verb_map: $name / senroflux/$template"
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
			$this->assertContains( 'senroflux/' . $template, $union, $template );
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
			$this->assertArrayHasKey( 'senroflux/' . $template, $GLOBALS['senroflux_test_abilities'], "$template is registered" );
			$ability = $GLOBALS['senroflux_test_abilities'][ 'senroflux/' . $template ];
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
