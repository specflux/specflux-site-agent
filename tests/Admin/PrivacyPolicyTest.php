<?php
/**
 * The suggested privacy-policy text.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Admin\PrivacyPolicy;

final class PrivacyPolicyTest extends TestCase {

	public function test_it_registers_on_admin_init(): void {
		$GLOBALS['specflux_site_agent_test_actions'] = array();

		( new PrivacyPolicy() )->register();

		$this->assertNotEmpty( $GLOBALS['specflux_site_agent_test_actions']['admin_init'] ?? array() );
	}

	public function test_the_text_covers_storage_the_ai_provider_and_openverse(): void {
		$text = ( new PrivacyPolicy() )->content();

		$this->assertStringContainsString( 'stores', $text );
		$this->assertStringContainsString( 'AI provider', $text );
		$this->assertStringContainsString( 'Openverse', $text );
	}
}
