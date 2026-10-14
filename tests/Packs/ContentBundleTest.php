<?php
/**
 * The combined `specflux-content` Belayline Agent Governance pack (onboarding O3): one role
 * binding unlocks the pages, posts and site packs, and nothing more.
 *
 * Separate-process on purpose: the Belayline Agent Governance pack stand-ins flip the
 * `class_exists` probes other tests rely on being false.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Packs;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Belayline\Packs\Pack as AsPack;
use Specflux\SiteAgent\Packs\ContentBundle;
use Specflux\SiteAgent\Packs\Pages\PagesPack;
use Specflux\SiteAgent\Packs\Posts\PostsPack;
use Specflux\SiteAgent\Packs\Site\SitePack;
use Specflux\SiteAgent\Plugin;

final class ContentBundleTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/stubs/blocks.php';
		require_once dirname( __DIR__ ) . '/stubs/agent-safety-pack.php';
		Plugin::reset();
		$GLOBALS['specflux_site_agent_test_agent_safety'] = new \SpecfluxSiteAgent_Test_AgentSafety();
		$GLOBALS['specflux_site_agent_test_users']        = array(
			7 => (object) array( 'roles' => array( 'administrator' ) ),
		);
		$GLOBALS['specflux_site_agent_test_as_bindings']  = array();
		$GLOBALS['specflux_site_agent_test_as_packs']     = array();
	}

	/** @return list<string> */
	private function union(): array {
		return array_values(
			array_unique(
				array_merge(
					( new PagesPack() )->allowList(),
					( new PostsPack() )->allowList(),
					( new SitePack() )->allowList()
				)
			)
		);
	}

	private function bind( AsPack $pack ): void {
		$GLOBALS['specflux_site_agent_test_as_packs']    = array( $pack->name => $pack );
		$GLOBALS['specflux_site_agent_test_as_bindings'] = array( 'role:administrator' => $pack->name );
	}

	private function binding_error( object $pack ): ?\WP_Error {
		$method = new \ReflectionMethod( $pack, 'agentSafetyBindingError' );
		$method->setAccessible( true );

		return $method->invoke( $pack, 7 );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_bundle_is_named_for_the_deep_link(): void {
		$bundle = ContentBundle::agentSafetyPack( new PagesPack(), new PostsPack(), new SitePack() );

		$this->assertSame( 'specflux-content', $bundle->name );
		$this->assertSame( 'specflux-content', ContentBundle::NAME );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_user_bound_to_the_bundle_passes_pages_posts_and_site_checks(): void {
		$this->bind( ContentBundle::agentSafetyPack( new PagesPack(), new PostsPack(), new SitePack() ) );

		$this->assertNull( $this->binding_error( new PagesPack() ), 'pages' );
		$this->assertNull( $this->binding_error( new PostsPack() ), 'posts' );
		$this->assertNull( $this->binding_error( new SitePack() ), 'site' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_site_pack_alone_does_not_satisfy_posts(): void {
		// The reason the bundle exists: if this ever starts passing, the
		// bundle is no longer needed and step 4 should say so.
		$this->bind( ( new SitePack() )->agentSafetyPack() );

		$this->assertNull( $this->binding_error( new SitePack() ) );
		$this->assertSame( 'pack_unbound', $this->binding_error( new PostsPack() )->get_error_code() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_bundle_missing_one_packs_abilities_fails_that_packs_check(): void {
		$without_site = new AsPack(
			'specflux-content',
			ContentBundle::allowList( new PagesPack(), new PostsPack() ),
			array(),
			array( 'tier2' => true )
		);
		$this->bind( $without_site );

		$this->assertNull( $this->binding_error( new PagesPack() ) );
		$this->assertSame( 'pack_unbound', $this->binding_error( new SitePack() )->get_error_code() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_bundle_requires_approval_for_tier_2(): void {
		$bundle = ContentBundle::agentSafetyPack( new PagesPack(), new PostsPack(), new SitePack() );

		$this->assertSame( array( 'tier2' => true ), $bundle->approvalByClass );
		$this->assertTrue( $bundle->requiresApproval( \Belayline\Policy\Tier::Irreversible ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_bundle_without_tier_2_approval_is_refused(): void {
		$this->bind( new AsPack( 'specflux-content', $this->union(), array(), array() ) );

		$this->assertSame( 'pack_unbound', $this->binding_error( new PagesPack() )->get_error_code() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_bundle_is_a_strict_union_of_the_three_allow_lists(): void {
		$bundle = ContentBundle::agentSafetyPack( new PagesPack(), new PostsPack(), new SitePack() );

		$this->assertEqualsCanonicalizing( $this->union(), $bundle->allow );
		$this->assertSame( count( $bundle->allow ), count( array_unique( $bundle->allow ) ), 'no duplicates' );

		$allowed_by_a_part = array_merge(
			( new PagesPack() )->allowList(),
			( new PostsPack() )->allowList(),
			( new SitePack() )->allowList()
		);
		foreach ( $bundle->allow as $ability ) {
			$this->assertContains( $ability, $allowed_by_a_part, $ability . ' is outside all three allow-lists' );
		}
		$this->assertNotEmpty( $bundle->allow );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_bundle_does_not_reach_the_commerce_abilities(): void {
		$bundle = ContentBundle::agentSafetyPack( new PagesPack(), new PostsPack(), new SitePack() );

		foreach ( $bundle->allow as $ability ) {
			$this->assertStringNotContainsString( 'product', $ability );
			$this->assertStringNotContainsString( 'order', str_replace( 'reorder-menu-items', '', $ability ) );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_covers_names_exactly_the_three_content_packs(): void {
		foreach ( array( 'pages', 'posts', 'site' ) as $name ) {
			$this->assertTrue( ContentBundle::covers( $name ), $name );
		}
		foreach ( array( 'commerce', 'specflux-content', '' ) as $name ) {
			$this->assertFalse( ContentBundle::covers( $name ), $name );
		}
	}
}
