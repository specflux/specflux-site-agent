<?php
/**
 * Resolving the request's connection, and who may use the connector.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\Connections;
use Specflux\SiteAgent\Connector\Connector;

final class ConnectionsTest extends ConnectorTestCase {

	public function test_deleting_the_application_password_elsewhere_forgets_the_connection(): void {
		$GLOBALS['specflux_site_agent_test_action_priorities'] = array();
		Connector::register();
		$hooked = array_column( $GLOBALS['specflux_site_agent_test_action_priorities']['wp_delete_application_password'] ?? array(), 0 );
		$this->assertContains( array( Connections::class, 'forgetDeleted' ), $hooked );

		Connections::add( self::USER, self::UUID );
		Connections::add( self::USER, 'bbbbbbbb-0000-0000-0000-000000000000' );

		Connections::forgetDeleted( self::USER, array( 'uuid' => self::UUID ) );

		$this->assertSame( array( 'bbbbbbbb-0000-0000-0000-000000000000' ), array_keys( Connections::forUser( self::USER ) ) );
	}

	public function test_deleting_an_unregistered_application_password_leaves_the_registry_alone(): void {
		Connections::add( self::USER, self::UUID );

		Connections::forgetDeleted( self::USER, array( 'uuid' => 'not-ours' ) );
		Connections::forgetDeleted( self::USER, 'not-an-item' );
		Connections::forgetDeleted( 99, array( 'uuid' => self::UUID ) );

		$this->assertArrayHasKey( self::UUID, Connections::forUser( self::USER ) );
	}

	public function test_a_cookie_session_is_never_a_connection(): void {
		$this->enableConnector();
		Connections::add( self::USER, self::UUID, Connection::SCOPE_READ_WRITE );
		$GLOBALS['specflux_site_agent_test_app_password'] = null;

		$this->assertNull( Connections::current() );
		$this->assertNull( Connector::authorise() );
	}

	public function test_an_application_password_outside_the_registry_is_refused(): void {
		$this->enableConnector();
		Connections::add( self::USER, self::UUID );
		$GLOBALS['specflux_site_agent_test_app_password'] = 'aaaaaaaa-0000-0000-0000-000000000000';

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
		$GLOBALS['specflux_site_agent_test_user_meta'][ self::USER ][ Connections::META_KEY ] = array( self::UUID => array( 'scope' => 'root' ) );
		$GLOBALS['specflux_site_agent_test_app_password']                                     = self::UUID;

		$connection = Connections::current();

		$this->assertNotNull( $connection );
		$this->assertSame( Connection::SCOPE_READ, $connection->scope );
		$this->assertFalse( $connection->mayWrite() );
	}

	public function test_a_user_without_the_capability_is_denied(): void {
		$this->enableConnector();
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$GLOBALS['specflux_site_agent_test_user_caps'] = array();

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

	public function test_a_connection_registered_on_one_site_is_not_valid_on_another(): void {
		$GLOBALS['specflux_site_agent_test_multisite'] = true;
		$GLOBALS['wpdb']->prefix                       = 'wp_2_';
		Connections::add( self::USER, self::UUID, Connection::SCOPE_READ_WRITE );
		$this->assertArrayHasKey( self::UUID, Connections::forUser( self::USER ) );

		$GLOBALS['wpdb']->prefix = 'wp_3_';

		$this->assertSame( array(), Connections::forUser( self::USER ) );
		$GLOBALS['specflux_site_agent_test_app_password'] = self::UUID;
		$this->assertNull( Connections::current() );
	}

	public function test_single_site_adopts_the_legacy_unprefixed_registry_and_drops_it(): void {
		$GLOBALS['specflux_site_agent_test_user_meta'][ self::USER ][ Connections::META_KEY ] = array( self::UUID => array( 'scope' => Connection::SCOPE_READ_WRITE ) );
		$GLOBALS['specflux_site_agent_test_app_password']                                     = self::UUID;

		$connection = Connections::current();

		$this->assertNotNull( $connection );
		$this->assertTrue( $connection->mayWrite() );
		$meta = $GLOBALS['specflux_site_agent_test_user_meta'][ self::USER ];
		$this->assertArrayNotHasKey( Connections::META_KEY, $meta );
		$this->assertArrayHasKey( 'wp_' . Connections::META_KEY, $meta );
	}

	public function test_on_multisite_the_legacy_unprefixed_registry_grants_nothing(): void {
		$GLOBALS['specflux_site_agent_test_multisite'] = true;
		$GLOBALS['wpdb']->prefix                       = 'wp_2_';
		$GLOBALS['specflux_site_agent_test_user_meta'][ self::USER ][ Connections::META_KEY ] = array( self::UUID => array( 'scope' => Connection::SCOPE_READ_WRITE ) );
		$GLOBALS['specflux_site_agent_test_app_password']                                     = self::UUID;

		$this->assertSame( array(), Connections::forUser( self::USER ) );
		$this->assertNull( Connections::current() );
		$this->assertArrayHasKey( Connections::META_KEY, $GLOBALS['specflux_site_agent_test_user_meta'][ self::USER ], 'left in place for the Uninstaller, not migrated' );
	}
}
