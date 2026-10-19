<?php
/**
 * Ability label lookups stay quiet for names that are not registered abilities.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Tools;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Tools\AbilityLabels;

final class AbilityLabelsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['specflux_site_agent_test_abilities']       = array();
		$GLOBALS['specflux_site_agent_test_doing_it_wrong'] = array();
	}

	public function test_a_virtual_tool_falls_back_to_the_slug_sentence_without_a_notice(): void {
		$this->assertSame( 'Propose plan', AbilityLabels::display( 'specflux/propose-plan' ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_doing_it_wrong'] );
	}

	public function test_a_registered_ability_still_uses_its_label(): void {
		$GLOBALS['specflux_site_agent_test_abilities']['content/publish-page'] = new class() {
			/** Label. */
			public function get_label(): string {
				return 'Publish a page';
			}
		};

		$this->assertSame( 'Publish a page', AbilityLabels::display( 'content/publish-page' ) );
		$this->assertSame( 'Publish a page', AbilityLabels::display( 'wpab__content__publish-page' ) );
	}
}
