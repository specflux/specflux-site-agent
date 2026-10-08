<?php
/**
 * What AI apps did through the connector, as a short rolling list (K4).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Schema;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * One row per tool call: when, which connection, which user, which ability,
 * how it ended. Arguments and results are never stored. Rows older than
 * {@see RETENTION_DAYS} are pruned by a daily cron event.
 */
final class ActivityLog {

	/** The call ran. */
	public const RAN = 'ran';

	/** The call is parked for an admin. */
	public const WAITING = 'waiting';

	/** The call was refused. */
	public const REFUSED = 'refused';

	/** The call ran and failed. */
	public const ERROR = 'error';

	/** Rows older than this many days are deleted. */
	public const RETENTION_DAYS = 30;

	/** The cron hook that prunes. */
	public const CRON_HOOK = 'senroflux_connector_prune_activity';

	/** Hook the daily prune. */
	public static function register(): void {
		add_action( self::CRON_HOOK, array( self::class, 'prune' ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/** Make sure the daily prune is scheduled (activation, and self-healing on admin loads). */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( Clock::now() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/** Remove the daily prune (deactivation). */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** Is this a known outcome? */
	public static function outcomeKnown( string $outcome ): bool {
		return in_array( $outcome, array( self::RAN, self::WAITING, self::REFUSED, self::ERROR ), true );
	}

	/**
	 * Record one call.
	 *
	 * @param Connection $connection The connection that made it.
	 * @param string     $ability   The ability (or tool name when it has none).
	 * @param string     $outcome   One of the outcome constants.
	 */
	public static function record( Connection $connection, string $ability, string $outcome ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::outcomeKnown( $outcome ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; a write.
		$wpdb->insert(
			Schema::activityTable( $wpdb ),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s', Clock::now() ),
				'uuid'       => $connection->uuid,
				'user_id'    => $connection->userId,
				'ability'    => substr( $ability, 0, 191 ),
				'outcome'    => $outcome,
			),
			array( '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * The latest rows, newest first.
	 *
	 * @param int         $limit Most rows.
	 * @param string|null $uuid  Only this connection, or null for all.
	 * @return list<array{created_at:string,uuid:string,user_id:int,ability:string,outcome:string}>
	 */
	public static function recent( int $limit = 50, ?string $uuid = null ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}

		$table = Schema::activityTable( $wpdb );
		$limit = max( 1, min( 200, $limit ) );
		$where = null === $uuid ? '' : $wpdb->prepare( ' WHERE uuid = %s', $uuid ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name and an int limit.
		$rows = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY created_at DESC, id DESC LIMIT {$limit}", ARRAY_A );

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'uuid'       => (string) ( $row['uuid'] ?? '' ),
				'user_id'    => (int) ( $row['user_id'] ?? 0 ),
				'ability'    => (string) ( $row['ability'] ?? '' ),
				'outcome'    => (string) ( $row['outcome'] ?? '' ),
			);
		}

		return $out;
	}

	/** Delete rows older than the retention window. */
	public static function prune(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', Clock::now() - self::RETENTION_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- trusted table name; the cutoff is bound by prepare().
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::activityTable( $wpdb ) . ' WHERE created_at < %s', $cutoff ) );
	}
}
