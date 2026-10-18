<?php
/**
 * The activity list, its daily prune, and the built-in rate limit (K4).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use Specflux\SiteAgent\Connector\ActivityLog;
use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\ConnectScreen;
use Specflux\SiteAgent\Connector\Gate;
use Specflux\SiteAgent\Connector\OAuth\Store;
use Specflux\SiteAgent\Connector\RateLimit;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Schema;
use WP_Error;

final class ActivityTest extends ConnectorTestCase {

	private const WRITE = 'specflux/write-thing';
	private const READ  = 'specflux/read-thing';
	private const NOW   = 1_800_000_000;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['specflux_site_agent_test_transients'] = array();
		$this->enableConnector();
		$this->standardAbilities();
		Clock::useFixed( self::NOW );
	}

	private function call( string $ability, array $args = array() ): mixed {
		return Gate::preCall( $args, str_replace( '/', '-', $ability ), $this->tool( $ability ), $this->server( array( $ability ) ) );
	}

	/** Finish an allowed call the way the adapter would. */
	private function finish( string $ability, mixed $result ): mixed {
		return Gate::afterCall( $result, array(), str_replace( '/', '-', $ability ), $this->tool( $ability ), $this->server( array( $ability ) ) );
	}

	/** @return list<array<string,mixed>> */
	private function rows(): array {
		return array_values( $GLOBALS['wpdb']->tables['wp_specflux_site_agent_connector_activity'] ?? array() );
	}

	public function test_each_outcome_is_recorded_with_connection_user_and_ability(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$this->call( self::READ );
		$this->finish( self::READ, array( 'ok' => true ) );
		$this->call( self::WRITE, array( 'x' => 1 ) ); // Parks: waiting.
		$this->call( 'shop/refund' ); // Refused.
		$this->call( self::READ );
		$this->finish( self::READ, new WP_Error( 'boom', 'failed' ) );

		$this->assertSame(
			array( ActivityLog::RAN, ActivityLog::WAITING, ActivityLog::REFUSED, ActivityLog::ERROR ),
			array_column( $this->rows(), 'outcome' )
		);
		$first = $this->rows()[0];
		$this->assertSame( self::UUID, $first['uuid'] );
		$this->assertSame( self::USER, $first['user_id'] );
		$this->assertSame( self::READ, $first['ability'] );
	}

	public function test_no_arguments_or_results_are_stored(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );

		$this->call( self::WRITE, array( 'secret' => 'hunter2' ) );
		$this->call( self::READ );
		$this->finish( self::READ, array( 'private' => 'result-body' ) );

		foreach ( $this->rows() as $row ) {
			$this->assertSame( array( 'created_at', 'uuid', 'user_id', 'ability', 'outcome', 'id' ), array_keys( $row ) );
		}
		$this->assertStringNotContainsString( 'hunter2', (string) wp_json_encode( $this->rows() ) );
		$this->assertStringNotContainsString( 'result-body', (string) wp_json_encode( $this->rows() ) );
	}

	public function test_another_servers_calls_are_not_recorded(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$other = $this->server( array( self::READ ), 'someone-else' );

		Gate::preCall( array(), 'x', $this->tool( self::READ ), $other );
		Gate::afterCall( array(), array(), 'x', $this->tool( self::READ ), $other );

		$this->assertSame( array(), $this->rows() );
	}

	public function test_the_61st_call_in_a_minute_is_refused_with_the_seconds_to_wait(): void {
		$this->connectAs( Connection::SCOPE_READ );
		Clock::useFixed( self::NOW - ( self::NOW % 60 ) + 20 ); // 20 s into a window.

		for ( $i = 0; $i < RateLimit::LIMIT; ++$i ) {
			$this->assertNotInstanceOf( WP_Error::class, $this->call( self::READ ), "call $i" );
		}
		$refused = $this->call( self::READ );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'specflux_site_agent_connector_rate_limited', $refused->get_error_code() );
		$this->assertStringContainsString( 'Wait 40 seconds', $refused->get_error_message() );
		$this->assertSame( array( ActivityLog::REFUSED ), array_column( $this->rows(), 'outcome' ), 'Allowed calls are recorded when they finish.' );
	}

	public function test_a_call_after_the_window_is_allowed(): void {
		$this->connectAs( Connection::SCOPE_READ );
		Clock::useFixed( self::NOW - ( self::NOW % 60 ) + 20 );
		for ( $i = 0; $i <= RateLimit::LIMIT; ++$i ) {
			$this->call( self::READ );
		}

		Clock::useFixed( self::NOW - ( self::NOW % 60 ) + 60 );

		$this->assertNotInstanceOf( WP_Error::class, $this->call( self::READ ) );
	}

	public function test_the_limit_is_per_connection(): void {
		$this->connectAs( Connection::SCOPE_READ );
		for ( $i = 0; $i <= RateLimit::LIMIT; ++$i ) {
			$this->call( self::READ );
		}

		$this->connectAs( Connection::SCOPE_READ, self::USER, 'another-connection' );

		$this->assertNotInstanceOf( WP_Error::class, $this->call( self::READ ) );
	}

	public function test_agent_governance_mode_is_not_rate_limited(): void {
		$this->governanceMode();
		$this->connectAs( Connection::SCOPE_READ );

		for ( $i = 0; $i < RateLimit::LIMIT + 5; ++$i ) {
			$this->assertNotInstanceOf( WP_Error::class, $this->call( self::READ ), "call $i" );
		}
	}

	public function test_prune_removes_day_31_and_keeps_day_29(): void {
		$this->connectAs( Connection::SCOPE_READ );
		$connection = new Connection( self::USER, self::UUID, Connection::SCOPE_READ );
		Clock::useFixed( self::NOW - 31 * DAY_IN_SECONDS );
		ActivityLog::record( $connection, 'old', ActivityLog::RAN );
		Clock::useFixed( self::NOW - 29 * DAY_IN_SECONDS );
		ActivityLog::record( $connection, 'recent', ActivityLog::RAN );
		Clock::useFixed( self::NOW );

		ActivityLog::prune();

		$this->assertSame( array( 'recent' ), array_column( $this->rows(), 'ability' ) );
	}

	public function test_recent_is_newest_first_capped_and_filterable(): void {
		$one = new Connection( self::USER, 'uuid-one', Connection::SCOPE_READ );
		$two = new Connection( self::USER, 'uuid-two', Connection::SCOPE_READ );
		for ( $i = 0; $i < 60; ++$i ) {
			Clock::useFixed( self::NOW + $i );
			ActivityLog::record( 0 === $i % 2 ? $one : $two, 'tool-' . $i, ActivityLog::RAN );
		}

		$all = ActivityLog::recent( 50 );
		$this->assertCount( 50, $all );
		$this->assertSame( 'tool-59', $all[0]['ability'] );
		$this->assertSame( array( 'uuid-one' ), array_values( array_unique( array_column( ActivityLog::recent( 50, 'uuid-one' ), 'uuid' ) ) ) );
	}

	public function test_the_daily_prune_is_scheduled_once_and_unscheduled(): void {
		ActivityLog::schedule();
		$first = wp_next_scheduled( ActivityLog::CRON_HOOK );
		ActivityLog::schedule();

		$this->assertNotFalse( $first );
		$this->assertSame( 'daily', $GLOBALS['specflux_site_agent_test_cron'][ ActivityLog::CRON_HOOK ]['recurrence'] );
		$this->assertSame( $first, wp_next_scheduled( ActivityLog::CRON_HOOK ), 'A second call does not reschedule.' );

		ActivityLog::unschedule();

		$this->assertFalse( wp_next_scheduled( ActivityLog::CRON_HOOK ) );
	}

	public function test_the_screen_lists_recent_calls_without_details(): void {
		$this->connectAs( Connection::SCOPE_READ_WRITE );
		$this->call( self::WRITE, array( 'secret' => 'hunter2' ) );

		ob_start();
		ConnectScreen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Recent activity', $html );
		$this->assertStringContainsString( self::WRITE, $html );
		$this->assertStringContainsString( 'Waiting for approval', $html );
		// The waiting-calls section shows the arguments to the person who has to approve them; the activity log never does.
		$this->assertStringContainsString( 'id="specflux-site-agent-activity"', $html );
		$this->assertStringNotContainsString( 'hunter2', substr( $html, (int) strpos( $html, 'id="specflux-site-agent-activity"' ) ) );
	}

	public function test_the_uuid_column_fits_an_oauth_connection_id(): void {
		// OAuth connections are keyed 'oauth:<grant id>'; MySQL refuses a longer value, so the row is lost.
		$this->assertSame( 1, preg_match( '/uuid VARCHAR\((\d+)\)/', Schema::activityColumns(), $m ) );
		$grant = Store::createGrant( self::USER, 'client', 'specflux:read', 'http://example.test/wp-json/specflux-site-agent/mcp', 'hash' );

		$this->assertGreaterThanOrEqual( strlen( 'oauth:' . $grant ), (int) $m[1] );
	}
}
