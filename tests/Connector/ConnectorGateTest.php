<?php
/**
 * The connector gate (K2): built-in mode parks writes for approval, SenroGate
 * mode defers.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\Approvals;
use Specflux\SenroFlux\Connector\ApprovalsScreen;
use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Gate;
use Specflux\SenroFlux\Run\Clock;
use WP_Error;

final class ConnectorGateTest extends ConnectorTestCase {

	private const WRITE = 'senroflux/write-thing';
	private const READ  = 'senroflux/read-thing';

	protected function setUp(): void {
		parent::setUp();
		$this->enableConnector();
		$this->standardAbilities();
		Clock::useFixed( 1_800_000_000 );
	}

	/** Run one call through the gate as the current connection. */
	private function call( string $ability, array $args ): mixed {
		return Gate::preCall( $args, str_replace( '/', '-', $ability ), $this->tool( $ability ), $this->server( array( $ability ) ) );
	}

	private function hash( array $args, string $ability = self::WRITE ): string {
		return Approvals::hash( self::USER, self::UUID, $ability, $args );
	}

	public function test_a_read_runs_without_parking(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$this->assertSame( array( 'id' => 1 ), $this->call( self::READ, array( 'id' => 1 ) ) );
		$this->assertSame( array(), Approvals::pending() );
	}

	public function test_a_write_parks_and_the_message_carries_the_approval_url(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call( self::WRITE, array( 'title' => 'Hello' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_awaiting_approval', $result->get_error_code() );
		$this->assertStringContainsString( ApprovalsScreen::url(), $result->get_error_message() );
		$this->assertStringContainsString( 'page=senroflux-runs&tab=ai-apps', $result->get_error_message() );
		$this->assertStringContainsString( 'repeat exactly the same call', $result->get_error_message() );
		$pending = Approvals::pending();
		$this->assertArrayHasKey( $this->hash( array( 'title' => 'Hello' ) ), $pending );
		$this->assertSame( self::WRITE, $pending[ $this->hash( array( 'title' => 'Hello' ) ) ]['ability'] );
	}

	public function test_an_unapproved_repeat_stays_parked(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'a' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $this->call( self::WRITE, array( 'a' => 1 ) ) );
		$this->assertCount( 1, Approvals::pending() );
	}

	public function test_the_same_call_after_approval_runs_exactly_once(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$args = array(
			'title' => 'Hello',
			'meta'  => array(
				'b' => 2,
				'a' => 1,
			),
		);
		$this->call( self::WRITE, $args );
		$this->assertTrue( Approvals::approve( $this->hash( $args ) ) );

		// Key order in the repeat does not matter: the hash is canonical.
		$repeat = array(
			'meta'  => array(
				'a' => 1,
				'b' => 2,
			),
			'title' => 'Hello',
		);
		$this->assertSame( $repeat, $this->call( self::WRITE, $repeat ) );

		$second = $this->call( self::WRITE, $repeat );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'senroflux_connector_awaiting_approval', $second->get_error_code() );
	}

	public function test_changed_arguments_park_again_and_are_not_covered_by_the_approval(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'title' => 'Hello' ) );
		Approvals::approve( $this->hash( array( 'title' => 'Hello' ) ) );

		$result = $this->call( self::WRITE, array( 'title' => 'Hello!' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( $this->hash( array( 'title' => 'Hello!' ) ), Approvals::pending() );
		// The original approval is untouched and still spendable.
		$this->assertSame( array( 'title' => 'Hello' ), $this->call( self::WRITE, array( 'title' => 'Hello' ) ) );
	}

	public function test_another_connection_cannot_spend_the_approval(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		Approvals::approve( $this->hash( array( 'x' => 1 ) ) );

		$this->connectAs( Connection::SCOPE_READ_WRITE, self::USER, 'ffffffff-0000-0000-0000-000000000000' );

		$this->assertInstanceOf( WP_Error::class, $this->call( self::WRITE, array( 'x' => 1 ) ) );
	}

	public function test_an_approval_older_than_fifteen_minutes_parks_again(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		Approvals::approve( $this->hash( array( 'x' => 1 ) ) );

		Clock::useFixed( 1_800_000_000 + Approvals::APPROVAL_TTL + 1 );
		$result = $this->call( self::WRITE, array( 'x' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		// It is parked afresh, unapproved.
		$this->assertArrayHasKey( $this->hash( array( 'x' => 1 ) ), Approvals::pending() );
	}

	public function test_an_approval_just_inside_the_window_still_runs(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		Approvals::approve( $this->hash( array( 'x' => 1 ) ) );

		Clock::useFixed( 1_800_000_000 + Approvals::APPROVAL_TTL );

		$this->assertSame( array( 'x' => 1 ), $this->call( self::WRITE, array( 'x' => 1 ) ) );
	}

	public function test_a_pending_call_nobody_approved_expires_after_a_day(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );

		Clock::useFixed( 1_800_000_000 + Approvals::PENDING_TTL + 1 );

		$this->assertSame( array(), Approvals::pending() );
		$this->assertSame( array(), get_option( Approvals::OPTION ) );
	}

	public function test_deny_removes_the_call_so_a_repeat_parks_fresh(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		Approvals::approve( $this->hash( array( 'x' => 1 ) ) );
		Approvals::deny( $this->hash( array( 'x' => 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $this->call( self::WRITE, array( 'x' => 1 ) ) );
		$this->assertFalse( Approvals::consume( $this->hash( array( 'x' => 1 ) ) ) );
	}

	public function test_a_commerce_write_is_refused_not_parked_in_builtin_mode(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call( 'shop/refund', array( 'order' => 9 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_unavailable', $result->get_error_code() );
		$this->assertSame( array(), Approvals::pending() );
	}

	public function test_a_commerce_call_that_only_tiers_up_with_its_arguments_is_still_refused(): void {
		$this->ability( 'shop/maybe' );
		$this->pack(
			'shop2',
			array(
				'shop/maybe'       => 0,
				'shop/maybe#risky' => 2,
			),
			true,
			static fn ( string $ability, array $input ): string => ! empty( $input['risky'] ) ? $ability . '#risky' : $ability
		);
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call( 'shop/maybe', array( 'risky' => true ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_needs_senrogate', $result->get_error_code() );
		$this->assertSame( array(), Approvals::pending() );
	}

	public function test_the_connectors_own_settings_are_refused_not_parked(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call(
			self::WRITE,
			array(
				'option' => 'senroflux_connector_enabled',
				'value'  => '1',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'senroflux_connector_settings', $result->get_error_code() );
		$this->assertSame( array(), Approvals::pending() );
	}

	public function test_a_post_that_merely_mentions_a_setting_name_is_not_refused(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call( self::WRITE, array( 'content' => 'How the senroflux_connector_enabled option works' ) );

		$this->assertSame( 'senroflux_connector_awaiting_approval', $result->get_error_code() );
	}

	public function test_a_nested_setting_name_is_refused_whatever_its_case(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call( self::WRITE, array( 'meta' => array( array( 'key' => '  SenroFlux_Connections ' ) ) ) );

		$this->assertSame( 'senroflux_connector_settings', $result->get_error_code() );
	}

	public function test_a_grant_option_name_is_refused(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$result = $this->call( self::WRITE, array( 'option' => Approvals::GRANT_PREFIX . str_repeat( 'a', 64 ) ) );

		$this->assertSame( 'senroflux_connector_settings', $result->get_error_code() );
	}

	public function test_a_second_spend_fails_even_if_the_list_entry_is_stale(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		$hash = $this->hash( array( 'x' => 1 ) );
		Approvals::approve( $hash );
		$stale = get_option( Approvals::OPTION );

		$this->assertTrue( Approvals::consume( $hash ) );
		update_option( Approvals::OPTION, $stale ); // A racer's write put the list entry back.

		$this->assertFalse( Approvals::consume( $hash ) );
	}

	public function test_an_expired_grant_cannot_be_spent_and_is_removed(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		$hash = $this->hash( array( 'x' => 1 ) );
		Approvals::approve( $hash );
		Clock::useFixed( 1_800_000_000 + Approvals::APPROVAL_TTL + 1 );

		$this->assertFalse( Approvals::consume( $hash ) );
		$this->assertFalse( get_option( Approvals::GRANT_PREFIX . $hash ) );
	}

	public function test_deny_removes_the_grant(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'x' => 1 ) );
		$hash = $this->hash( array( 'x' => 1 ) );
		Approvals::approve( $hash );
		$this->assertNotFalse( get_option( Approvals::GRANT_PREFIX . $hash ) );

		Approvals::deny( $hash );

		$this->assertFalse( get_option( Approvals::GRANT_PREFIX . $hash ) );
	}

	public function test_a_read_only_connection_never_reaches_parking(): void {
		$this->connectAs( Connection::SCOPE_READ );

		$result = $this->call( self::WRITE, array( 'x' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotSame( 'senroflux_connector_awaiting_approval', $result->get_error_code() );
		$this->assertSame( array(), Approvals::pending() );
	}

	public function test_senrogate_mode_defers_and_parks_nothing(): void {
		$this->senroGateMode();
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$this->assertSame( array( 'title' => 'Hello' ), $this->call( self::WRITE, array( 'title' => 'Hello' ) ) );
		$this->assertSame( array(), Approvals::pending() );
		$this->assertSame( array(), get_option( Approvals::OPTION, array() ) );
	}

	public function test_pending_entries_are_capped(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		for ( $i = 0; $i < Approvals::MAX_PENDING + 5; ++$i ) {
			Clock::useFixed( 1_800_000_000 + $i );
			$this->call( self::WRITE, array( 'n' => $i ) );
		}

		$pending = Approvals::pending();
		$this->assertCount( Approvals::MAX_PENDING, $pending );
		$this->assertArrayNotHasKey( $this->hash( array( 'n' => 0 ) ), $pending );
		$this->assertArrayHasKey( $this->hash( array( 'n' => Approvals::MAX_PENDING + 4 ) ), $pending );
	}
}
