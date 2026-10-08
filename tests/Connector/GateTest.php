<?php
/**
 * The connector's two enforcement points on the adapter's filters.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Gate;
use Specflux\SenroFlux\Tools\HarnessTools;
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
			array( 'senroflux/read-thing', 'senroflux/write-thing', 'shop/refund', 'senroflux/hidden-thing', HarnessTools::TOOL_NAME )
		);
	}

	public function test_another_servers_tool_list_is_left_untouched(): void {
		$this->connectAs();
		$tools = $this->allTools();

		$this->assertSame( $tools, Gate::filterTools( $tools, $this->server( array(), 'mcp-adapter-default-server' ) ) );
	}

	public function test_our_list_is_narrowed_to_what_a_read_only_connection_may_see(): void {
		$this->connectAs( Connection::SCOPE_READ );
		$server = $this->server( array( 'senroflux/read-thing', 'senroflux/write-thing', 'shop/refund', 'senroflux/hidden-thing', HarnessTools::TOOL_NAME ) );

		$this->assertSame( array( 'senroflux-read-thing' ), $this->toolNames( Gate::filterTools( $this->allTools(), $server ) ) );
	}

	public function test_a_read_write_list_in_builtin_mode_drops_commerce_and_hidden_tools(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$server = $this->server( array( 'senroflux/read-thing', 'senroflux/write-thing', 'shop/refund', 'senroflux/hidden-thing', HarnessTools::TOOL_NAME ) );

		$this->assertSame(
			array( 'senroflux-read-thing', 'senroflux-write-thing' ),
			$this->toolNames( Gate::filterTools( $this->allTools(), $server ) )
		);
	}

	public function test_an_unauthenticated_request_gets_an_empty_list(): void {
		$server = $this->server( array( 'senroflux/read-thing' ) );

		$this->assertSame( array(), Gate::filterTools( $this->allTools(), $server ) );
	}

	public function test_a_tool_not_backed_by_an_ability_is_dropped(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$plain  = new \SenroFlux_Test_Fake_Mcp_Tool( 'plain', null );
		$server = $this->server( array() )->add( $plain );

		$this->assertSame( array(), Gate::filterTools( array( $plain ), $server ) );
	}

	public function test_pre_call_leaves_another_servers_call_untouched(): void {
		$args = array( 'x' => 1 );

		$this->assertSame( $args, Gate::preCall( $args, 'shop-refund', $this->tool( 'shop/refund' ), $this->server( array(), 'mcp-adapter-default-server' ) ) );
	}

	public function test_pre_call_refuses_an_unlisted_tool(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$result = Gate::preCall( array(), 'x', $this->tool( 'senroflux/hidden-thing' ), $this->server( array( 'senroflux/hidden-thing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_unavailable', $result->get_error_code() );
	}

	public function test_pre_call_refuses_a_write_on_a_read_only_connection(): void {
		$this->connectAs( Connection::SCOPE_READ );
		$this->senroGateMode();
		$result = Gate::preCall( array(), 'x', $this->tool( 'senroflux/write-thing' ), $this->server( array( 'senroflux/write-thing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_unavailable', $result->get_error_code() );
	}

	public function test_pre_call_refuses_when_not_authenticated(): void {
		$result = Gate::preCall( array(), 'x', $this->tool( 'senroflux/read-thing' ), $this->server( array( 'senroflux/read-thing' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_denied', $result->get_error_code() );
	}

	public function test_pre_call_lets_a_read_through_with_its_arguments(): void {
		$this->connectAs();
		$args = array( 'id' => 5 );

		$this->assertSame( $args, Gate::preCall( $args, 'x', $this->tool( 'senroflux/read-thing' ), $this->server( array( 'senroflux/read-thing' ) ) ) );
	}

	public function test_pre_call_refuses_an_argument_dependent_write_on_a_read_only_connection(): void {
		$this->ability( 'senroflux/maybe-write' );
		$this->pack(
			'argaware',
			array(
				'senroflux/maybe-write'      => 0,
				'senroflux/maybe-write#live' => 2,
			),
			false,
			static fn ( string $ability, array $input ): string => ! empty( $input['live'] ) ? $ability . '#live' : $ability
		);
		$this->connectAs( Connection::SCOPE_READ );
		$server = $this->server( array( 'senroflux/maybe-write' ) );
		$tool   = $this->tool( 'senroflux/maybe-write' );

		$this->assertSame( array( 'live' => false ), Gate::preCall( array( 'live' => false ), 'x', $tool, $server ) );

		$result = Gate::preCall( array( 'live' => true ), 'x', $tool, $server );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_read_only', $result->get_error_code() );
	}
}
