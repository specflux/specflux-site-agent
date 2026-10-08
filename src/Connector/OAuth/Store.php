<?php
/**
 * Persistence for OAuth clients, codes, grants and tokens.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

use Specflux\SenroFlux\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Storage choice: options (autoload OFF) and transients, keyed by the SHA-256
 * of the secret. The plugin's only schema is runs/steps; adding a table for a
 * handful of rows would add a migration and a wpdb seam for no gain, and
 * every hot-path lookup here is one primary-key read.
 *
 * - client:   option  senroflux_oc_<client id>          (+ index option)
 * - code:     transient senroflux_oac_<hash>  60 s      (+ "used" marker 1 day, for reuse detection)
 * - grant:    option  senroflux_og_<grant id>           (+ index option) = one authorization / token family
 * - refresh:  option  senroflux_or_<hash>; once rotated it becomes a tombstone senroflux_ox_<hash>
 * - access:   transient senroflux_oat_<hash>  1 h
 *
 * Single-use is enforced with the delete: only the caller whose DELETE
 * actually removed the row proceeds, so two racing redemptions cannot both win.
 * Plaintext secrets are never stored and never logged.
 */
final class Store {

	private const CLIENT_INDEX = 'senroflux_oauth_clients';
	private const GRANT_INDEX  = 'senroflux_oauth_grants';

	/** How many rotated refresh hashes a family remembers for reuse detection. */
	private const RETIRED_KEPT = 5;

	/** How long a redeemed code stays recognisable as "already used". */
	private const USED_CODE_TTL = DAY_IN_SECONDS;

	// ---- Clients ---------------------------------------------------------

	/**
	 * Save a client (id, name, redirect_uris, created).
	 *
	 * @param array<string,mixed> $client Client record.
	 */
	public static function saveClient( array $client ): void {
		$id = (string) $client['id'];
		update_option( 'senroflux_oc_' . $id, $client, false );
		$index        = self::clientIndex();
		$index[ $id ] = (int) $client['created'];
		update_option( self::CLIENT_INDEX, $index, false );
	}

	/**
	 * One stored client, or null.
	 *
	 * @param string $id Client id.
	 * @return array{id:string,name:string,redirect_uris:list<string>,created:int}|null
	 */
	public static function client( string $id ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $id ) ) {
			return null;
		}
		$raw = get_option( 'senroflux_oc_' . $id, null );
		if ( ! is_array( $raw ) || ! isset( $raw['redirect_uris'] ) || ! is_array( $raw['redirect_uris'] ) ) {
			return null;
		}

		return array(
			'id'            => $id,
			'name'          => (string) ( $raw['name'] ?? '' ),
			'redirect_uris' => array_values( array_filter( array_map( 'strval', $raw['redirect_uris'] ) ) ),
			'created'       => (int) ( $raw['created'] ?? 0 ),
		);
	}

	/**
	 * Client id => created, oldest first.
	 *
	 * @return array<string,int>
	 */
	public static function clientIndex(): array {
		$index = get_option( self::CLIENT_INDEX, array() );
		$index = is_array( $index ) ? $index : array();
		asort( $index );

		return array_map( 'intval', $index );
	}

	/** Delete a client and revoke every grant it holds. */
	public static function deleteClient( string $id ): void {
		foreach ( self::grantIndex() as $grant_id => $meta ) {
			if ( $meta['client'] === $id ) {
				self::revokeGrant( (string) $grant_id );
			}
		}
		delete_option( 'senroflux_oc_' . $id );
		$index = self::clientIndex();
		unset( $index[ $id ] );
		update_option( self::CLIENT_INDEX, $index, false );
	}

	/** Does any live grant belong to this client? */
	public static function clientHasGrants( string $id ): bool {
		foreach ( self::grantIndex() as $meta ) {
			if ( $meta['client'] === $id ) {
				return true;
			}
		}

		return false;
	}

	// ---- Authorization codes ---------------------------------------------

	/**
	 * Store a fresh code (hashed key) for {@see OAuth::CODE_TTL} seconds.
	 *
	 * @param string              $code Plaintext code.
	 * @param array<string,mixed> $data Bound client, redirect_uri, user, scope, resource, challenge, exp.
	 */
	public static function putCode( string $code, array $data ): void {
		set_transient( 'senroflux_oac_' . OAuth::hash( $code ), $data, OAuth::CODE_TTL );
	}

	/**
	 * Redeem a code: returns its data exactly once, null for unknown, used or racing callers.
	 *
	 * @param string $code Plaintext code.
	 * @return array<string,mixed>|null
	 */
	public static function consumeCode( string $code ): ?array {
		$key  = 'senroflux_oac_' . OAuth::hash( $code );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( true !== delete_transient( $key ) ) {
			return null; // Someone else redeemed it first.
		}

		return $data;
	}

	/** Remember which grant a redeemed code produced, so reuse can revoke it. */
	public static function markCodeUsed( string $code, string $grant_id ): void {
		set_transient( 'senroflux_oau_' . OAuth::hash( $code ), $grant_id, self::USED_CODE_TTL );
	}

	/** The grant a previously redeemed code produced, if still remembered. */
	public static function grantOfUsedCode( string $code ): ?string {
		$grant = get_transient( 'senroflux_oau_' . OAuth::hash( $code ) );

		return is_string( $grant ) && '' !== $grant ? $grant : null;
	}

	// ---- Grants ----------------------------------------------------------

	/**
	 * Grant id => {user, client}.
	 *
	 * @return array<string,array{user:int,client:string}>
	 */
	public static function grantIndex(): array {
		$raw = get_option( self::GRANT_INDEX, array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $id => $meta ) {
			if ( is_string( $id ) && is_array( $meta ) ) {
				$out[ $id ] = array(
					'user'   => (int) ( $meta['user'] ?? 0 ),
					'client' => (string) ( $meta['client'] ?? '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * A grant record, or null (unknown, malformed or revoked).
	 *
	 * @param string $id Grant id.
	 * @return array<string,mixed>|null
	 */
	public static function grant( string $id ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return null;
		}
		$raw = get_option( 'senroflux_og_' . $id, null );

		return is_array( $raw ) && isset( $raw['user'], $raw['client'], $raw['resource'], $raw['refresh'] ) ? $raw : null;
	}

	/**
	 * Create and index a grant.
	 *
	 * @param int    $user     User id.
	 * @param string $client   Client id.
	 * @param string $scope    Granted scope string.
	 * @param string $audience Audience (the server URL).
	 * @param string $refresh  Hash of the first refresh token.
	 * @return string The grant id.
	 */
	public static function createGrant( int $user, string $client, string $scope, string $audience, string $refresh ): string {
		$id  = bin2hex( random_bytes( 16 ) );
		$now = Clock::now();
		update_option(
			'senroflux_og_' . $id,
			array(
				'user'        => $user,
				'client'      => $client,
				'scope'       => $scope,
				'resource'    => $audience,
				'created'     => $now,
				'refresh'     => $refresh,
				'refresh_exp' => $now + OAuth::REFRESH_TTL,
				'retired'     => array(),
			),
			false
		);
		$index        = self::grantIndex();
		$index[ $id ] = array(
			'user'   => $user,
			'client' => $client,
		);
		update_option( 'senroflux_oauth_grants', $index, false );

		return $id;
	}

	/**
	 * Rewrite a grant record.
	 *
	 * @param string              $id     Grant id.
	 * @param array<string,mixed> $record Full record.
	 */
	public static function saveGrant( string $id, array $record ): void {
		update_option( 'senroflux_og_' . $id, $record, false );
	}

	/** Revoke one grant: its refresh tokens and every access token it issued stop working. */
	public static function revokeGrant( string $id ): void {
		$grant = self::grant( $id );
		if ( null !== $grant ) {
			delete_option( 'senroflux_or_' . (string) $grant['refresh'] );
			foreach ( (array) ( $grant['retired'] ?? array() ) as $hash ) {
				delete_option( 'senroflux_ox_' . (string) $hash );
			}
		}
		// Access tokens are transients checked against the grant, so removing the grant revokes them.
		delete_option( 'senroflux_og_' . $id );
		$index = self::grantIndex();
		unset( $index[ $id ] );
		update_option( self::GRANT_INDEX, $index, false );
	}

	/** `delete_user` hook: a deleted user's tokens die with them. */
	public static function revokeForUser( mixed $user_id ): void {
		foreach ( self::grantIndex() as $id => $meta ) {
			if ( $meta['user'] === (int) $user_id ) {
				self::revokeGrant( (string) $id );
			}
		}
	}

	/** Cron: drop grants whose refresh token has expired. */
	public static function gc(): void {
		$now = Clock::now();
		foreach ( array_keys( self::grantIndex() ) as $id ) {
			$grant = self::grant( (string) $id );
			if ( null === $grant || (int) ( $grant['refresh_exp'] ?? 0 ) < $now ) {
				self::revokeGrant( (string) $id );
			}
		}
	}

	// ---- Refresh tokens --------------------------------------------------

	/** Store a live refresh token (by hash). */
	public static function putRefresh( string $hash, string $grant_id, int $exp ): void {
		update_option(
			'senroflux_or_' . $hash,
			array(
				'g'   => $grant_id,
				'exp' => $exp,
			),
			false
		);
	}

	/**
	 * Look at a refresh token without consuming it.
	 *
	 * @param string $token Plaintext refresh token.
	 * @return array{g:string,exp:int}|null
	 */
	public static function peekRefresh( string $token ): ?array {
		$rec = get_option( 'senroflux_or_' . OAuth::hash( $token ), null );

		return is_array( $rec ) && isset( $rec['g'] ) ? array(
			'g'   => (string) $rec['g'],
			'exp' => (int) ( $rec['exp'] ?? 0 ),
		) : null;
	}

	/**
	 * Claim a refresh token for rotation. Exactly one caller gets 'ok' for a live token;
	 * presenting a token that was already rotated (or lost the race) is 'reuse'.
	 *
	 * @param string $token Plaintext refresh token.
	 * @return array{status:string,grant:?string}
	 */
	public static function claimRefresh( string $token ): array {
		$hash = OAuth::hash( $token );
		$rec  = self::peekRefresh( $token );
		if ( null !== $rec ) {
			if ( true !== delete_option( 'senroflux_or_' . $hash ) ) {
				return array(
					'status' => 'reuse',
					'grant'  => $rec['g'],
				);
			}

			return array(
				'status' => $rec['exp'] >= Clock::now() ? 'ok' : 'expired',
				'grant'  => $rec['g'],
			);
		}

		$tomb = get_option( 'senroflux_ox_' . $hash, null );
		if ( is_array( $tomb ) && isset( $tomb['g'] ) ) {
			return array(
				'status' => 'reuse',
				'grant'  => (string) $tomb['g'],
			);
		}

		return array(
			'status' => 'unknown',
			'grant'  => null,
		);
	}

	/**
	 * Replace a grant's refresh token: remember the old hash as a tombstone, install the new one.
	 *
	 * @param string $grant_id Grant id.
	 * @param string $old_hash Hash of the token just rotated.
	 * @param string $new_hash Hash of the replacement.
	 */
	public static function rotate( string $grant_id, string $old_hash, string $new_hash ): void {
		$grant = self::grant( $grant_id );
		if ( null === $grant ) {
			return;
		}
		update_option( 'senroflux_ox_' . $old_hash, array( 'g' => $grant_id ), false );
		$retired   = array_values( array_filter( (array) ( $grant['retired'] ?? array() ), 'is_string' ) );
		$retired[] = $old_hash;
		$excess    = count( $retired ) - self::RETIRED_KEPT;
		for ( $i = 0; $i < $excess; $i++ ) {
			delete_option( 'senroflux_ox_' . (string) array_shift( $retired ) );
		}
		$exp                  = Clock::now() + OAuth::REFRESH_TTL;
		$grant['retired']     = $retired;
		$grant['refresh']     = $new_hash;
		$grant['refresh_exp'] = $exp;
		self::saveGrant( $grant_id, $grant );
		self::putRefresh( $new_hash, $grant_id, $exp );
	}

	// ---- Access tokens ---------------------------------------------------

	/** Store an access token (by hash) with the scope it carries. */
	public static function putAccess( string $hash, string $grant_id, string $scope, int $exp ): void {
		set_transient(
			'senroflux_oat_' . $hash,
			array(
				'g'     => $grant_id,
				'scope' => $scope,
				'exp'   => $exp,
			),
			max( 1, $exp - Clock::now() )
		);
	}

	/**
	 * An access token's record, or null.
	 *
	 * @param string $token Plaintext access token.
	 * @return array{g:string,scope:string,exp:int}|null
	 */
	public static function access( string $token ): ?array {
		$rec = get_transient( 'senroflux_oat_' . OAuth::hash( $token ) );
		if ( ! is_array( $rec ) || ! isset( $rec['g'], $rec['exp'] ) ) {
			return null;
		}

		return array(
			'g'     => (string) $rec['g'],
			'scope' => (string) ( $rec['scope'] ?? '' ),
			'exp'   => (int) $rec['exp'],
		);
	}
}
