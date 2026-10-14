<?php
/**
 * The Specflux Site Agent-owned registry of connections (K1).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Which application passwords may open the connector, per user, with a scope
 * each. A connection is an application password Specflux Site Agent created and
 * registered here; one made anywhere else opens nothing. The Connect screen
 * (K3) writes this registry; K1/K2 only read it.
 */
final class Connections {

	/** User meta: uuid => array{scope:string}. */
	public const META_KEY = 'specflux_site_agent_connections';

	/**
	 * The current request's connection, or null.
	 *
	 * Null for every request that did not authenticate with an application
	 * password (a logged-in browser session included: the connector is for
	 * external apps), and for any application password not in the registry.
	 */
	public static function current(): ?Connection {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) || ! function_exists( 'get_current_user_id' ) ) {
			return null;
		}

		$user_id = (int) get_current_user_id();
		$uuid    = rest_get_authenticated_app_password();
		if ( $user_id <= 0 || ! is_string( $uuid ) || '' === $uuid ) {
			// Not an application password: an OAuth bearer token may still have signed this request in.
			$oauth = apply_filters( 'specflux_site_agent_connector_connection', null );

			return $oauth instanceof Connection ? $oauth : null;
		}

		$registry = self::forUser( $user_id );
		if ( ! isset( $registry[ $uuid ] ) ) {
			return null;
		}

		return new Connection( $user_id, $uuid, $registry[ $uuid ]['scope'] );
	}

	/**
	 * One user's registry, sanitised: only well-formed entries survive.
	 *
	 * @param int $user_id User id.
	 * @return array<string,array{scope:string}>
	 */
	public static function forUser( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $uuid => $entry ) {
			if ( ! is_string( $uuid ) || '' === $uuid ) {
				continue;
			}
			$out[ $uuid ] = array( 'scope' => Connection::sanitizeScope( is_array( $entry ) ? ( $entry['scope'] ?? null ) : null ) );
		}

		return $out;
	}

	/** Register (or re-scope) an application password as a connection. */
	public static function add( int $user_id, string $uuid, string $scope = Connection::SCOPE_READ ): void {
		if ( $user_id <= 0 || '' === $uuid ) {
			return;
		}

		$registry          = self::forUser( $user_id );
		$registry[ $uuid ] = array( 'scope' => Connection::sanitizeScope( $scope ) );
		update_user_meta( $user_id, self::META_KEY, $registry );
	}

	/** Drop a connection from the registry. */
	public static function remove( int $user_id, string $uuid ): void {
		$registry = self::forUser( $user_id );
		unset( $registry[ $uuid ] );
		update_user_meta( $user_id, self::META_KEY, $registry );
	}

	/** Forget connections whose application password is deleted outside the Connect screen. */
	public static function register(): void {
		add_action( 'wp_delete_application_password', array( self::class, 'forgetDeleted' ), 10, 2 );
	}

	/**
	 * Core's `wp_delete_application_password` action, also fired per item when all are deleted.
	 *
	 * @param int   $user_id User id.
	 * @param mixed $item    The deleted application password.
	 */
	public static function forgetDeleted( int $user_id, mixed $item ): void {
		$uuid = is_array( $item ) ? ( $item['uuid'] ?? null ) : null;
		if ( ! is_string( $uuid ) || ! isset( self::forUser( $user_id )[ $uuid ] ) ) {
			return;
		}

		self::remove( $user_id, $uuid );
	}
}
