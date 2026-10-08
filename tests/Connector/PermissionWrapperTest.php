<?php
/**
 * The ability permission wrapper: scope and exposure refusals run before an
 * ability's own permission callback (where a governance plugin may file an
 * approval), but only for the connection SenroFlux's MCP route is serving.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\ActivityLog;
use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\Gate;
use WP_Error;

final class PermissionWrapperTest extends ConnectorTestCase {

	private int $calls = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->enableConnector();
		$this->standardAbilities();
		$this->calls                          = 0;
		$GLOBALS['senroflux_test_transients'] = array();
	}

	/** The permission callback of an ability as registration would leave it. */
	private function wrapped( string $ability, mixed $inner_result = true ): callable {
		$args = Gate::wrapAbilityArgs(
			array(
				'permission_callback' => function () use ( $inner_result ) {
					++$this->calls;

					return $inner_result;
				},
			),
			$ability
		);

		return $args['permission_callback'];
	}

	/** Serve a connection the way the transport's permission callback does. */
	private function serve( string $scope ): void {
		$this->connectAs( $scope );
		$this->assertTrue( Connector::permission() );
	}

	/** @return list<array<string,mixed>> */
	private function rows(): array {
		return array_values( $GLOBALS['wpdb']->tables['wp_senroflux_connector_activity'] ?? array() );
	}

	public function test_the_wrapper_runs_after_senrogates_priority(): void {
		Connector::register();

		$priorities = array_keys( $GLOBALS['senroflux_test_filters']['wp_register_ability_args'] ?? array() );
		$this->assertNotEmpty( $priorities );
		$this->assertGreaterThan( 10, min( $priorities ) );
	}

	/** An ability listed as a read whose arguments can make it a write. */
	private function argumentDependentWrite(): void {
		$this->ability( 'senroflux/maybe-write' );
		$this->pack(
			'argaware',
			array(
				'senroflux/maybe-write'      => 0,
				'senroflux/maybe-write#live' => 1,
			),
			false,
			static fn ( string $ability, array $input ): string => ! empty( $input['live'] ) ? $ability . '#live' : $ability
		);
	}

	public function test_a_read_only_connection_is_refused_before_the_inner_callback(): void {
		$this->argumentDependentWrite();
		$this->serve( Connection::SCOPE_READ );

		$result = ( $this->wrapped( 'senroflux/maybe-write' ) )( array( 'live' => true ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_read_only', $result->get_error_code() );
		$this->assertSame( 0, $this->calls );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( ActivityLog::REFUSED, $this->rows()[0]['outcome'] );
		$this->assertSame( 'senroflux/maybe-write', $this->rows()[0]['ability'] );
	}

	public function test_a_write_tool_on_a_read_only_connection_is_refused_as_unavailable(): void {
		$this->serve( Connection::SCOPE_READ );

		$result = ( $this->wrapped( 'senroflux/write-thing' ) )( array() );

		$this->assertSame( 'senroflux_connector_unavailable', $result->get_error_code() );
		$this->assertSame( 0, $this->calls );
		$this->assertSame( ActivityLog::REFUSED, $this->rows()[0]['outcome'] );
	}

	public function test_an_ability_outside_the_exposure_set_is_refused(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		$result = ( $this->wrapped( 'senroflux/hidden-thing' ) )( array() );

		$this->assertSame( 'senroflux_connector_unavailable', $result->get_error_code() );
		$this->assertSame( 0, $this->calls );
	}

	public function test_commerce_writes_are_refused_without_senrogate(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		$result = ( $this->wrapped( 'shop/refund' ) )( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 0, $this->calls );
	}

	public function test_a_read_write_connection_reaches_the_inner_callback(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		$this->assertTrue( ( $this->wrapped( 'senroflux/write-thing' ) )( array() ) );
		$this->assertSame( 1, $this->calls );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_a_read_call_on_a_read_only_connection_reaches_the_inner_callback(): void {
		$this->serve( Connection::SCOPE_READ );

		$this->assertTrue( ( $this->wrapped( 'senroflux/read-thing' ) )( array() ) );
		$this->assertSame( 1, $this->calls );
	}

	public function test_an_unauthorised_request_never_sets_the_serving_slot(): void {
		$this->assertFalse( Connector::permission() );
		$this->assertNull( Connector::serving() );
	}

	/** @return array<string,array{0:mixed}> */
	public static function innerResults(): array {
		return array(
			'true'     => array( true ),
			'false'    => array( false ),
			'wp_error' => array( new WP_Error( 'inner', 'inner says no' ) ),
		);
	}

	/** @dataProvider innerResults */
	public function test_when_nothing_is_served_the_wrapper_is_a_pure_pass_through( mixed $inner ): void {
		// A connection exists on the request, but the MCP route did not serve it.
		$this->connectAs( Connection::SCOPE_READ );
		$this->assertNull( Connector::serving() );

		$result = ( $this->wrapped( 'senroflux/write-thing', $inner ) )( array() );

		$this->assertSame( $inner, $result );
		$this->assertSame( 1, $this->calls );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_refused_calls_still_count_against_the_rate_limit(): void {
		$GLOBALS['senroflux_test_transients'] = array();
		\Specflux\SenroFlux\Run\Clock::useFixed( 1_800_000_020 );
		$this->serve( Connection::SCOPE_READ );
		$permission = $this->wrapped( 'senroflux/write-thing' );

		for ( $i = 0; $i < \Specflux\SenroFlux\Connector\RateLimit::LIMIT; ++$i ) {
			$this->assertSame( 'senroflux_connector_unavailable', $permission( array() )->get_error_code() );
		}

		$this->assertSame( 'senroflux_connector_rate_limited', $permission( array() )->get_error_code() );
	}

	public function test_a_non_callable_permission_callback_is_left_alone(): void {
		$args = array( 'permission_callback' => null );

		$this->assertSame( $args, Gate::wrapAbilityArgs( $args, 'senroflux/write-thing' ) );
	}

	public function test_a_call_senrogate_parks_is_logged_as_waiting_once(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		$result = ( $this->wrapped( 'senroflux/write-thing', new WP_Error( 'approval_required', 'needs human approval' ) ) )( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( ActivityLog::WAITING, $rows[0]['outcome'] );
	}

	public function test_another_inner_error_is_logged_as_refused(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		( $this->wrapped( 'senroflux/write-thing', new WP_Error( 'agent_safety_denied', 'no' ) ) )( array() );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( ActivityLog::REFUSED, $rows[0]['outcome'] );
	}

	public function test_an_inner_false_is_logged_as_refused(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		$this->assertFalse( ( $this->wrapped( 'senroflux/write-thing', false ) )( array() ) );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( ActivityLog::REFUSED, $rows[0]['outcome'] );
	}

	public function test_an_inner_pass_leaves_logging_to_the_pre_call_hook(): void {
		$this->serve( Connection::SCOPE_READ_WRITE );

		$this->assertTrue( ( $this->wrapped( 'senroflux/write-thing', true ) )( array() ) );
		$this->assertSame( array(), $this->rows() );
	}
}
