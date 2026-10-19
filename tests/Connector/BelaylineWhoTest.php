<?php
/**
 * Belayline's Overview map names identities through the belayline_map_who
 * filter; Site Agent answers for its own signed-in AI apps.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\BelaylineWho;
use Specflux\SiteAgent\Connector\OAuth\Store;
use Specflux\SiteAgent\Plugin;

final class BelaylineWhoTest extends ConnectorTestCase {

	private function grantFor( string $client_name ): string {
		Store::saveClient(
			array(
				'id'            => 'claudeclient01',
				'name'          => $client_name,
				'redirect_uris' => array( 'https://claude.ai/callback' ),
				'created'       => 1,
			)
		);

		return Store::createGrant( self::USER, 'claudeclient01', 'specflux:read', 'http://example.test/x', 'h' );
	}

	public function test_a_signed_in_app_is_named_after_the_app(): void {
		$grant = $this->grantFor( 'Claude' );

		$named = BelaylineWho::name( null, 'oauth:' . $grant );

		$this->assertSame( 'Claude', $named['label'] );
		$this->assertSame( 'AI app', $named['sub'] );
	}

	public function test_a_revoked_grant_still_gets_a_readable_label_and_never_the_raw_token(): void {
		$named = BelaylineWho::name( null, 'oauth:' . str_repeat( 'a', 32 ) );

		$this->assertIsArray( $named );
		$this->assertStringNotContainsString( 'oauth:', $named['label'] );
		$this->assertStringNotContainsString( 'aaaa', $named['label'] );
	}

	public function test_tokens_that_are_not_ours_pass_through_untouched(): void {
		$this->assertNull( BelaylineWho::name( null, 'wc:7' ) );
		$this->assertNull( BelaylineWho::name( null, 'user:3' ) );
		$this->assertNull( BelaylineWho::name( null, 'oauth-lookalike:1' ) );
	}

	public function test_a_name_another_plugin_already_gave_is_kept(): void {
		$grant   = $this->grantFor( 'Claude' );
		$already = array(
			'label' => 'Someone else',
			'sub'   => '',
		);

		$this->assertSame( $already, BelaylineWho::name( $already, 'oauth:' . $grant ) );
	}

	public function test_a_non_string_token_is_ignored(): void {
		$this->assertNull( BelaylineWho::name( null, 42 ) );
	}

	public function test_the_plugin_hooks_the_belayline_filter(): void {
		Plugin::instance()->boot();

		$this->assertArrayHasKey( 'belayline_map_who', $GLOBALS['specflux_site_agent_test_filters'] );
		$grant = $this->grantFor( 'Claude' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Belayline's hook, not ours.
		$named = apply_filters( 'belayline_map_who', null, 'oauth:' . $grant );
		$this->assertSame( 'Claude', $named['label'] ?? null );
	}
}
