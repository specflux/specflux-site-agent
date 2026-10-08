<?php
/**
 * Connector feature detection, the off-by-default switch, multisite refusal
 * and the arguments the server is created with.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\ExposureSet;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Tools\HarnessTools;
use SenroFlux_Test_Fake_Mcp_Adapter;

final class ConnectorServerTest extends ConnectorTestCase {

	public function test_the_switch_is_off_by_default(): void {
		Connector::set_adapter_version_probe( '0.7.0' );

		$this->assertFalse( Connector::stored() );
		$this->assertFalse( Connector::enabled() );
	}

	public function test_an_absent_adapter_disables_the_connector_and_creates_nothing(): void {
		update_option( Connector::OPTION, '1' );
		Connector::set_adapter_version_probe( '' );
		$adapter = new SenroFlux_Test_Fake_Mcp_Adapter();

		Connector::createServer( $adapter );

		$this->assertNull( Connector::adapterVersion() );
		$this->assertFalse( Connector::enabled() );
		$this->assertSame( array(), $adapter->created );
	}

	public function test_an_adapter_older_than_the_minimum_is_refused(): void {
		update_option( Connector::OPTION, '1' );
		Connector::set_adapter_version_probe( '0.6.9' );
		$adapter = new SenroFlux_Test_Fake_Mcp_Adapter();

		Connector::createServer( $adapter );

		$this->assertFalse( Connector::adapterReady() );
		$this->assertFalse( Connector::enabled() );
		$this->assertSame( array(), $adapter->created );
	}

	public function test_the_minimum_version_itself_is_accepted(): void {
		update_option( Connector::OPTION, '1' );
		Connector::set_adapter_version_probe( Connector::MIN_ADAPTER_VERSION );

		$this->assertTrue( Connector::enabled() );
	}

	public function test_multisite_is_refused_even_with_everything_else_in_place(): void {
		$this->enableConnector();
		$GLOBALS['senroflux_test_multisite'] = true;
		$adapter                             = new SenroFlux_Test_Fake_Mcp_Adapter();

		Connector::createServer( $adapter );

		$this->assertFalse( Connector::enabled() );
		$this->assertSame( array(), $adapter->created );
	}

	public function test_switched_off_creates_nothing_even_with_a_good_adapter(): void {
		Connector::set_adapter_version_probe( '0.7.0' );
		$adapter = new SenroFlux_Test_Fake_Mcp_Adapter();

		Connector::createServer( $adapter );

		$this->assertSame( array(), $adapter->created );
	}

	public function test_create_server_passes_route_transport_permission_and_the_exposure_set(): void {
		$this->enableConnector();
		$this->standardAbilities();
		$adapter = new SenroFlux_Test_Fake_Mcp_Adapter();

		Connector::createServer( $adapter );

		$this->assertCount( 1, $adapter->created );
		$args = $adapter->created[0];
		$this->assertSame( 'senroflux', $args[0] );
		$this->assertSame( 'senroflux', $args[1] );
		$this->assertSame( 'mcp', $args[2] );
		$this->assertSame( array( Connector::TRANSPORT_CLASS ), $args[6] );
		$this->assertSame( 'WP\MCP\Transport\HttpTransport', $args[6][0] );
		$this->assertSame( ExposureSet::names( GateMode::BuiltIn ), $args[9] );
		$this->assertSame( array( Connector::class, 'permission' ), $args[12] );
		$this->assertTrue( is_callable( $args[12] ) );
	}

	public function test_harness_only_and_hidden_abilities_are_not_in_the_server_tool_list(): void {
		$this->enableConnector();
		$this->standardAbilities();
		$adapter = new SenroFlux_Test_Fake_Mcp_Adapter();

		Connector::createServer( $adapter );

		$tools = $adapter->created[0][9];
		$this->assertContains( 'senroflux/read-thing', $tools );
		$this->assertContains( 'senroflux/write-thing', $tools );
		$this->assertNotContains( HarnessTools::TOOL_NAME, $tools );
		$this->assertNotContains( 'senroflux/hidden-thing', $tools );
	}

	public function test_the_capability_filter_moves_the_requirement_and_fails_closed_on_junk(): void {
		$this->assertSame( 'manage_options', Connector::capability() );

		add_filter( 'senroflux_connector_capability', static fn (): string => 'edit_pages' );
		$this->assertSame( 'edit_pages', Connector::capability() );

		$GLOBALS['senroflux_test_filters'] = array();
		add_filter( 'senroflux_connector_capability', static fn (): string => '' );
		$this->assertSame( 'do_not_allow', Connector::capability() );
	}
}
