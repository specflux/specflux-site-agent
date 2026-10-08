<?php
/**
 * The "Signed-in apps" admin actions, and hook wiring.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector\OAuth;

use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\OAuth\Bearer;
use Specflux\SenroFlux\Connector\OAuth\OAuth;
use Specflux\SenroFlux\Connector\OAuth\SignedInApps;
use Specflux\SenroFlux\Connector\OAuth\Store;

require_once __DIR__ . '/OAuthTestCase.php';

final class SignedInAppsTest extends OAuthTestCase {

	public function test_revoke_kills_the_grant_and_its_tokens(): void {
		$t              = $this->tokens();
		$grant          = (string) array_key_first( Store::grantIndex() );
		$_POST['grant'] = $grant;

		SignedInApps::handleRevoke();

		$this->assertSame( array(), Store::grantIndex() );
		$this->asMcpRequest( $t['body']['access_token'] );
		$this->assertSame( 0, Bearer::authenticate( 0 ) );
	}

	public function test_revoke_needs_a_valid_nonce(): void {
		$this->tokens();
		$_POST['grant']                      = (string) array_key_first( Store::grantIndex() );
		$GLOBALS['senroflux_test_bad_nonce'] = true;

		$this->expectException( \RuntimeException::class );
		try {
			SignedInApps::handleRevoke();
		} finally {
			$this->assertNotSame( array(), Store::grantIndex() );
		}
	}

	public function test_revoke_needs_the_capability(): void {
		$this->tokens();
		$_POST['grant']                      = (string) array_key_first( Store::grantIndex() );
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => false );

		$this->expectException( \RuntimeException::class );
		try {
			SignedInApps::handleRevoke();
		} finally {
			$this->assertNotSame( array(), Store::grantIndex() );
		}
	}

	public function test_removing_a_client_revokes_everything_signed_in_through_it(): void {
		$t               = $this->tokens();
		$_POST['client'] = $t['client'];

		SignedInApps::handleRemove();

		$this->assertNull( Store::client( $t['client'] ) );
		$this->assertSame( array(), Store::grantIndex() );
		$this->assertSame( 'invalid_grant', $this->refreshWith( $t['body']['refresh_token'], $t['client'] )['body']['error'] );
	}

	public function test_removing_a_client_needs_the_capability(): void {
		$t                                   = $this->tokens();
		$_POST['client']                     = $t['client'];
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => false );

		try {
			SignedInApps::handleRemove();
			$this->fail( 'Expected a refusal.' );
		} catch ( \RuntimeException $e ) {
			$this->assertNotNull( Store::client( $t['client'] ) );
		}
	}

	public function test_the_connector_wires_the_oauth_layer(): void {
		Connector::register();

		$filters = $GLOBALS['senroflux_test_filters'];
		$this->assertArrayHasKey( 21, $filters['determine_current_user'] ?? array(), 'After core\'s application-password check at 20.' );
		$this->assertArrayHasKey( 'senroflux_connector_connection', $filters );
		$this->assertArrayHasKey( 'rest_pre_dispatch', $filters );
		$this->assertArrayHasKey( 'rest_post_dispatch', $filters );
	}
}
