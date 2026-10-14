<?php
/**
 * ToolRegistry tests: the allow-list ∩ abilities intersection (stage 5).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Run;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Tools\ToolRegistry;
use SpecfluxSiteAgent_Test_Fake_Ability;

final class ToolRegistryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['specflux_site_agent_test_abilities'] = array(
			'agsafe-smoke/spend'   => new SpecfluxSiteAgent_Test_Fake_Ability( 'agsafe-smoke/spend', description: 'Spend an amount' ),
			'agsafe-smoke/blocked' => new SpecfluxSiteAgent_Test_Fake_Ability( 'agsafe-smoke/blocked', description: 'Blocked verb' ),
			'core/get-site-info'   => new SpecfluxSiteAgent_Test_Fake_Ability( 'core/get-site-info' ),
			'secret/hidden-tool'   => new SpecfluxSiteAgent_Test_Fake_Ability(
				'secret/hidden-tool',
				meta: array( 'specflux' => array( 'hidden' => true ) )
			),
			'secret/open-tool'     => new SpecfluxSiteAgent_Test_Fake_Ability( 'secret/open-tool' ),
		);
	}

	private function runWithAllow( array $allow ): Run {
		return new Run(
			id: 7,
			userId: 1,
			consumer: 'test-consumer',
			goal: 'goal',
			status: RunStatus::Pending,
			allow: $allow,
			budget: Budget::defaults()
		);
	}

	private function runWithAllowAndBudget( array $allow, array $budget_overrides ): Run {
		return new Run(
			id: 7,
			userId: 1,
			consumer: 'test-consumer',
			goal: 'goal',
			status: RunStatus::Pending,
			allow: $allow,
			budget: array_merge( Budget::defaults(), $budget_overrides )
		);
	}

	public function test_glob_allow_list_admits_only_matching_abilities(): void {
		$registry = ToolRegistry::forRun( $this->runWithAllow( array( 'agsafe-smoke/*' ) ) );

		$this->assertSame(
			array( 'agsafe-smoke/spend', 'agsafe-smoke/blocked' ),
			$registry->names(),
			'a namespace glob admits exactly that namespace, nothing else'
		);
		$this->assertTrue( $registry->admits( 'agsafe-smoke/spend' ) );
		$this->assertFalse( $registry->admits( 'core/get-site-info' ) );
	}

	public function test_exact_names_and_wildcards_mix(): void {
		$registry = ToolRegistry::forRun(
			$this->runWithAllow( array( 'agsafe-smoke/spend', 'secret/*', 'core/get-site-info' ) )
		);

		$this->assertContains( 'agsafe-smoke/spend', $registry->names() );
		$this->assertContains( 'secret/open-tool', $registry->names() );
		$this->assertContains( 'core/get-site-info', $registry->names() );
		$this->assertNotContains( 'agsafe-smoke/blocked', $registry->names() );
		$this->assertNotContains( 'secret/hidden-tool', $registry->names(), 'hidden meta wins over the allow-list' );
	}

	public function test_hidden_abilities_are_never_tools_even_under_star(): void {
		$registry = ToolRegistry::forRun( $this->runWithAllow( array( '*' ) ) );

		$this->assertFalse( $registry->admits( 'secret/hidden-tool' ) );
		$this->assertTrue( $registry->admits( 'secret/open-tool' ) );
	}

	public function test_a_harness_declaration_never_silently_shadows_a_same_named_ability(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['specflux/ask-user'] = new SpecfluxSiteAgent_Test_Fake_Ability(
			'specflux/ask-user',
			description: 'An ability wearing the harness tool\'s name'
		);

		$registry = ToolRegistry::forRun( $this->runWithAllow( array( '*' ) ) );
		$this->assertTrue( $registry->admits( 'specflux/ask-user' ), 'the ability is admitted before the merge' );

		$merged = $registry->withDeclarations( array( 'specflux/ask-user' => array( 'name' => 'specflux__ask-user' ) ) );

		// The harness declaration wins AND the colliding ability leaves the
		// admitted set: the model can never be shown the harness description
		// while calls under that name reach the executor.
		$declarations = $merged->declarations();
		$this->assertSame( 'specflux__ask-user', $declarations['specflux/ask-user']['name'] ?? null );
		$this->assertFalse( $merged->admits( 'specflux/ask-user' ), 'the shadowed ability is dropped, not silently kept' );
		$this->assertTrue( $merged->admits( 'agsafe-smoke/spend' ), 'unrelated abilities are untouched' );
	}

	public function test_declarations_use_the_wpab_function_name_mapping(): void {
		$registry = ToolRegistry::forRun( $this->runWithAllow( array( 'agsafe-smoke/spend' ) ) );

		$declarations = $registry->declarations();
		$declaration  = reset( $declarations );

		if ( is_object( $declaration ) ) {
			$this->assertSame( 'wpab__agsafe-smoke__spend', $declaration->getName() );
			return;
		}

		// Array shape (SDK-less context).
		$this->assertSame( 'wpab__agsafe-smoke__spend', $declaration['name'] );
		$this->assertSame( 'Spend an amount', $declaration['description'] ?? null );
	}

	// ------------------------------------------------------------------
	// 0.3 quality fix: images budget 0 withholds generate-image entirely.
	// ------------------------------------------------------------------

	public function test_generate_image_is_withheld_when_images_budget_is_zero(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['specflux/generate-image'] = new SpecfluxSiteAgent_Test_Fake_Ability(
			'specflux/generate-image',
			description: 'Generate an image'
		);

		$registry = ToolRegistry::forRun(
			$this->runWithAllowAndBudget( array( '*' ), array( Budget::IMAGES => 0 ) )
		);

		$this->assertFalse( $registry->admits( 'specflux/generate-image' ) );
		$this->assertNotContains( 'specflux/generate-image', $registry->names() );
	}

	public function test_generate_image_is_admitted_when_images_budget_is_positive(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['specflux/generate-image'] = new SpecfluxSiteAgent_Test_Fake_Ability(
			'specflux/generate-image',
			description: 'Generate an image'
		);

		$registry = ToolRegistry::forRun(
			$this->runWithAllowAndBudget( array( '*' ), array( Budget::IMAGES => 6 ) )
		);

		$this->assertTrue( $registry->admits( 'specflux/generate-image' ) );
		$this->assertContains( 'specflux/generate-image', $registry->names() );
	}
}
