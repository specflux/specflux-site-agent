<?php
/**
 * Belayline sees a signed-in AI app's call as its own credential (`oauth:<grant>`),
 * never as the person who signed it in.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector\OAuth;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Specflux\SiteAgent\Connector\BelaylineWho;
use Specflux\SiteAgent\Connector\OAuth\BelaylineSeam;
use Specflux\SiteAgent\Connector\OAuth\Bearer;
use Specflux\SiteAgent\Connector\OAuth\OAuth;
use Specflux\SiteAgent\Connector\OAuth\Store;

require_once __DIR__ . '/OAuthTestCase.php';

final class BelaylineIdentityTest extends OAuthTestCase {

	private function withBelayline(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/belayline-identity-provider.php';
	}

	/** @return list<object> */
	private function contributed(): array {
		OAuth::register();

		return apply_filters( 'belayline_identity_providers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Belayline's own filter, applied as it does.
	}

	private function grantId( array $tokens ): string {
		$ids = array_keys( Store::grantIndex() );
		$this->assertCount( 1, $ids );

		return (string) $ids[0];
	}

	public function test_the_grant_token_is_returned_for_a_bearer_authenticated_request(): void {
		$this->withBelayline();
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );

		$provider = $this->contributed()[0];

		$this->assertSame( array( 'oauth:' . $this->grantId( $t ) ), $provider->currentTokens() );
	}

	public function test_nothing_is_returned_for_a_cookie_session_or_an_application_password(): void {
		$this->withBelayline();
		$this->tokens();
		$provider = $this->contributed()[0];

		// Cookie session: logged in as the user, no bearer ever authenticated.
		Bearer::reset();
		$this->assertSame( array(), $provider->currentTokens() );

		// Application password: a (valid-looking) header but not the OAuth path.
		$GLOBALS['specflux_site_agent_test_app_password'] = 'uuid-1';
		$this->asMcpRequest( null );
		$this->assertSame( array(), $provider->currentTokens() );
	}

	public function test_a_token_on_another_route_never_yields_a_grant(): void {
		$this->withBelayline();
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'], '/wp-json/wp/v2/users' );
		Bearer::authenticate( 0 );

		$this->assertSame( array(), $this->contributed()[0]->currentTokens() );
	}

	public function test_a_different_user_than_the_grants_is_not_labelled_with_it(): void {
		$this->withBelayline();
		$t = $this->tokens();
		$this->asMcpRequest( $t['body']['access_token'] );
		Bearer::authenticate( 0 );
		$GLOBALS['specflux_site_agent_test_current_user_id'] = self::USER + 1;

		$this->assertSame( array(), $this->contributed()[0]->currentTokens() );
	}

	public function test_active_grants_are_bindable_by_app_name(): void {
		$this->withBelayline();
		$t        = $this->tokens();
		$grant    = $this->grantId( $t );
		$provider = $this->contributed()[0];

		$bindable = $provider->bindableTokens();

		$this->assertSame( array( 'oauth:' . $grant ), array_keys( $bindable ) );
		$this->assertNotSame( '', $bindable[ 'oauth:' . $grant ] );
		$this->assertNotSame( '', $provider->label() );

		Store::revokeGrant( $grant );
		$this->assertSame( array(), $provider->bindableTokens() );
	}

	public function test_belayline_map_who_names_the_app_from_the_same_token(): void {
		$this->withBelayline();
		$t = $this->tokens();
		$this->contributed();
		$grant = $this->grantId( $t );

		$named = apply_filters( 'belayline_map_who', null, 'oauth:' . $grant ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Belayline's own filter, applied as it does.

		$this->assertSame( 'AI app', $named['sub'] );
		$this->assertNotSame( '', $named['label'] );
		$this->assertSame( array(), array_filter( array( $named['label'] ), static fn ( $l ) => str_contains( $l, $grant ) ) );
	}

	public function test_the_provider_is_contributed_when_belaylines_interface_exists(): void {
		$this->withBelayline();

		$this->assertTrue( BelaylineSeam::available() );
		$this->assertCount( 1, $this->contributed() );
	}

	public function test_a_non_array_filter_value_is_passed_through_untouched(): void {
		$this->withBelayline();

		$this->assertSame( 'x', BelaylineSeam::contribute( 'x' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_nothing_is_registered_and_nothing_breaks_without_belayline(): void {
		$this->assertFalse( interface_exists( 'Belayline\\Plugin\\Identity\\IdentityProvider' ) );
		$this->assertFalse( BelaylineSeam::available() );

		OAuth::register();
		$this->assertSame( array(), apply_filters( 'belayline_identity_providers', array() ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Belayline's own filter, applied as it does.
		$this->assertFalse( class_exists( 'Specflux\\SiteAgent\\Connector\\OAuth\\BelaylineIdentity', false ) );
		$this->assertNull( BelaylineWho::name( null, 'user:3' ) );
	}
}
