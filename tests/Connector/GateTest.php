<?php
/**
 * The connector's two enforcement points on the adapter's filters.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\Gate;
use Specflux\SiteAgent\Tools\HarnessTools;
use WP_Error;

final class GateTest extends ConnectorTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->enableConnector();
		$this->standardAbilities();
	}

	/** @return list<string> */
	private function toolNames( mixed $tools ): array {
		return array_map( static fn ( object $tool ): string => $tool->getName(), (array) $tools );
	}

	private function allTools(): array {
		return array_map(
			fn ( string $name ) => $this->tool( $name ),
			array( 'specflux/read-thing', 'specflux/write-thing', 'shop/refund', 'specflux/hidden-thing', HarnessTools::TOOL_NAME )
		);
	}

	public function test_another_servers_tool_list_is_left_untouched(): void {
		$this->connectAs();
		$tools = $this->allTools();

		$this->assertSame( $tools, Gate::filterTools( $tools, $this->server( array(), 'mcp-adapter-default-server' ) ) );
	}

	public function test_our_list_is_narrowed_to_what_a_read_only_connection_may_see(): void {
		$this->connectAs( Connection::SCOPE_READ );
		$server = $this->server( array( 'specflux/read-thing', 'specflux/write-thing', 'shop/refund', 'specflux/hidden-thing', HarnessTools::TOOL_NAME ) );

		$this->assertSame( array( 'specflux-read-thing' ), $this->toolNames( Gate::filterTools( $this->allTools(), $server ) ) );
	}

	public function test_a_read_write_list_in_builtin_mode_drops_commerce_and_hidden_tools(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$server = $this->server( array( 'specflux/read-thing', 'specflux/write-thing', 'shop/refund', 'specflux/hidden-thing', HarnessTools::TOOL_NAME ) );

		$this->assertSame(
			array( 'specflux-read-thing', 'specflux-write-thing' ),
			$this->toolNames( Gate::filterTools( $this->allTools(), $server ) )
		);
	}

	public function test_an_unauthenticated_request_gets_an_empty_list(): void {
		$server = $this->server( array( 'specflux/read-thing' ) );

		$this->assertSame( array(), Gate::filterTools( $this->allTools(), $server ) );
	}

	public function test_a_tool_not_backed_by_an_ability_is_dropped(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$plain  = new \SpecfluxSiteAgent_Test_Fake_Mcp_Tool( 'plain', null );
		$server = $this->server( array() )->add( $plain );

		$this->assertSame( array(), Gate::filterTools( array( $plain ), $server ) );
	}

	public function test_pre_call_leaves_another_servers_call_untouched(): void {
		$args = array( 'x' => 1 );

		$this->assertSame( $args, Gate::preCall( $args, 'shop-refund', $this->tool( 'shop/refund' ), $this->server( array(), 'mcp-adapter-default-server' ) ) );
	}

	public function test_pre_call_refuses_an_unlisted_tool(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$result = Gate::preCall( array(), 'x', $this->tool( 'specflux/hidden-thing' ), $this->server( array( 'specflux/hidden-thing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_connector_unavailable', $result->get_error_code() );
	}

	public function test_pre_call_refuses_a_write_on_a_read_only_connection(): void {
		$this->connectAs( Connection::SCOPE_READ );
		$this->governanceMode();
		$result = Gate::preCall( array(), 'x', $this->tool( 'specflux/write-thing' ), $this->server( array( 'specflux/write-thing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_connector_unavailable', $result->get_error_code() );
	}

	public function test_pre_call_refuses_when_not_authenticated(): void {
		$result = Gate::preCall( array(), 'x', $this->tool( 'specflux/read-thing' ), $this->server( array( 'specflux/read-thing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_connector_denied', $result->get_error_code() );
	}

	public function test_pre_call_lets_a_read_through_with_its_arguments(): void {
		$this->connectAs();
		$args = array( 'id' => 5 );

		$this->assertSame( $args, Gate::preCall( $args, 'x', $this->tool( 'specflux/read-thing' ), $this->server( array( 'specflux/read-thing' ) ) ) );
	}

	public function test_pre_call_refuses_an_argument_dependent_write_on_a_read_only_connection(): void {
		$this->ability( 'specflux/maybe-write' );
		$this->pack(
			'argaware',
			array(
				'specflux/maybe-write'      => 0,
				'specflux/maybe-write#live' => 2,
			),
			false,
			static fn ( string $ability, array $input ): string => ! empty( $input['live'] ) ? $ability . '#live' : $ability
		);
		$this->connectAs( Connection::SCOPE_READ );
		$server = $this->server( array( 'specflux/maybe-write' ) );
		$tool   = $this->tool( 'specflux/maybe-write' );

		$this->assertSame( array( 'live' => false ), Gate::preCall( array( 'live' => false ), 'x', $tool, $server ) );

		$result = Gate::preCall( array( 'live' => true ), 'x', $tool, $server );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_connector_read_only', $result->get_error_code() );
	}
}
