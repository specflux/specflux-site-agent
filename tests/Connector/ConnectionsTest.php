<?php
/**
 * Resolving the request's connection, and who may use the connector.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Connections;
use Specflux\SenroFlux\Connector\Connector;

final class ConnectionsTest extends ConnectorTestCase {

	public function test_a_cookie_session_is_never_a_connection(): void {
		$this->enableConnector();
		Connections::add( self::USER, self::UUID, Connection::SCOPE_READ_WRITE );
		$GLOBALS['senroflux_test_app_password'] = null;

		$this->assertNull( Connections::current() );
		$this->assertNull( Connector::authorise() );
	}

	public function test_an_application_password_outside_the_registry_is_refused(): void {
		$this->enableConnector();
		Connections::add( self::USER, self::UUID );
		$GLOBALS['senroflux_test_app_password'] = 'aaaaaaaa-0000-0000-0000-000000000000';

		$this->assertNull( Connections::current() );
		$this->assertNull( Connector::authorise() );
	}

	public function test_a_registered_application_password_resolves_with_its_scope(): void {
		$this->enableConnector();
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$connection = Connections::current();

		$this->assertNotNull( $connection );
		$this->assertSame( self::USER, $connection->userId );
		$this->assertSame( self::UUID, $connection->uuid );
		$this->assertTrue( $connection->mayWrite() );
		$this->assertNotNull( Connector::authorise() );
		$this->assertTrue( Connector::permission() );
	}

	public function test_a_garbled_stored_scope_is_sanitised_to_read_only(): void {
		$this->enableConnector();
		$GLOBALS['senroflux_test_user_meta'][ self::USER ][ Connections::META_KEY ] = array( self::UUID => array( 'scope' => 'root' ) );
		$GLOBALS['senroflux_test_app_password']                                     = self::UUID;

		$connection = Connections::current();

		$this->assertNotNull( $connection );
		$this->assertSame( Connection::SCOPE_READ, $connection->scope );
		$this->assertFalse( $connection->mayWrite() );
	}

	public function test_a_user_without_the_capability_is_denied(): void {
		$this->enableConnector();
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$GLOBALS['senroflux_test_user_caps'] = array();

		$this->assertNull( Connector::authorise() );
		$this->assertFalse( Connector::permission() );
	}

	public function test_nothing_authorises_while_the_connector_is_off(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		Connector::set_adapter_version_probe( '0.7.0' );

		$this->assertNull( Connector::authorise() );
	}

	public function test_remove_drops_a_connection(): void {
		$this->enableConnector();
		$this->connectAs();
		Connections::remove( self::USER, self::UUID );

		$this->assertNull( Connections::current() );
	}
}
