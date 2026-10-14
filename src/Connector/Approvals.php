<?php
/**
 * Approvals for connector calls in built-in mode (K2).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The park-and-repeat store. A parked call is a pending entry keyed by the
 * sha256 of (user, connection, ability, canonical arguments). An admin's
 * approval lets that exact hash run once within {@see APPROVAL_TTL}; a changed
 * argument is a different hash and parks again. One option, autoload off,
 * pruned every time it is read.
 */
final class Approvals {

	/** Option holding hash => entry. */
	public const OPTION = 'specflux_site_agent_connector_approvals';

	/** Prefix of the per-approval grant option; the suffix is the call's hash. */
	public const GRANT_PREFIX = 'specflux_site_agent_cxa_';

	/** An approval can be spent within this many seconds of being granted. */
	public const APPROVAL_TTL = 900;

	/** A call nobody approved is forgotten after this many seconds. */
	public const PENDING_TTL = 86400;

	/** The most pending calls kept; the oldest is dropped past this, so an agent cannot grow the option without bound. */
	public const MAX_PENDING = 100;

	/**
	 * The hash a call is approved and repeated under.
	 *
	 * @param int                 $user_id User id.
	 * @param string              $uuid    Connection (application password) UUID.
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $args    The call's arguments.
	 */
	public static function hash( int $user_id, string $uuid, string $ability, array $args ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $user_id, $uuid, $ability, self::canonical( $args ) ) ) );
	}

	/**
	 * Recursively key-sorted form, so argument order never changes the hash.
	 * Lists keep their order: it is meaningful.
	 *
	 * @param mixed $value Any decoded JSON value.
	 */
	public static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	/**
	 * Park a call (idempotent: repeating an unapproved call keeps its entry).
	 *
	 * @param int                 $user_id User id.
	 * @param string              $uuid    Connection UUID.
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $args    The call's arguments.
	 * @return string The hash.
	 */
	public static function park( int $user_id, string $uuid, string $ability, array $args ): string {
		$hash    = self::hash( $user_id, $uuid, $ability, $args );
		$entries = self::entries();
		if ( ! isset( $entries[ $hash ] ) ) {
			$entries[ $hash ] = array(
				'created'  => Clock::now(),
				'user'     => $user_id,
				'uuid'     => $uuid,
				'ability'  => $ability,
				'args'     => self::canonical( $args ),
				'approved' => 0,
			);
			self::save( self::capPending( $entries ) );
		}

		return $hash;
	}

	/** Mark a pending call approved, starting its 15 minutes. False when it is gone. */
	public static function approve( string $hash ): bool {
		$entries = self::entries();
		if ( ! isset( $entries[ $hash ] ) ) {
			return false;
		}

		$entries[ $hash ]['approved'] = Clock::now();
		self::save( $entries );
		// The grant is what is spent: one row, deleted by exactly one caller.
		update_option( self::GRANT_PREFIX . $hash, $entries[ $hash ]['approved'], false );

		return true;
	}

	/** Remove a call, pending or approved. */
	public static function deny( string $hash ): void {
		$entries = self::entries();
		unset( $entries[ $hash ] );
		self::save( $entries );
		delete_option( self::GRANT_PREFIX . $hash );
	}

	/**
	 * Spend an approval: true exactly once per approval, within its window.
	 *
	 * @param string $hash The call's hash.
	 */
	public static function consume( string $hash ): bool {
		$key     = self::GRANT_PREFIX . $hash;
		$granted = (int) get_option( $key, 0 );

		// A DELETE that removes the row succeeds for one caller only, so two
		// concurrent identical calls cannot both spend one approval.
		if ( $granted <= 0 || ! delete_option( $key ) ) {
			return false;
		}

		$entries = self::entries();
		if ( isset( $entries[ $hash ] ) ) {
			unset( $entries[ $hash ] );
			self::save( $entries );
		}

		return Clock::now() - $granted <= self::APPROVAL_TTL;
	}

	/**
	 * Calls still waiting for an admin, oldest first.
	 *
	 * @return array<string,array{created:int,user:int,uuid:string,ability:string,args:array<string,mixed>,approved:int}>
	 */
	public static function pending(): array {
		return array_filter( self::entries(), static fn ( array $entry ): bool => $entry['approved'] <= 0 );
	}

	/**
	 * The stored entries, pruned and well-formed. Pending calls older than
	 * {@see PENDING_TTL} and approvals older than {@see APPROVAL_TTL} are
	 * dropped (and the option rewritten when anything was).
	 *
	 * @return array<string,array{created:int,user:int,uuid:string,ability:string,args:array<string,mixed>,approved:int}>
	 */
	private static function entries(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$now   = Clock::now();
		$clean = array();
		foreach ( $raw as $hash => $entry ) {
			if ( ! is_string( $hash ) || ! is_array( $entry ) || ! is_string( $entry['ability'] ?? null ) || ! is_string( $entry['uuid'] ?? null ) ) {
				continue;
			}
			$created  = (int) ( $entry['created'] ?? 0 );
			$approved = (int) ( $entry['approved'] ?? 0 );
			$expired  = $approved > 0 ? $now - $approved > self::APPROVAL_TTL : $now - $created > self::PENDING_TTL;
			if ( $expired ) {
				if ( $approved > 0 ) {
					delete_option( self::GRANT_PREFIX . $hash );
				}
				continue;
			}
			$clean[ $hash ] = array(
				'created'  => $created,
				'user'     => (int) ( $entry['user'] ?? 0 ),
				'uuid'     => $entry['uuid'],
				'ability'  => $entry['ability'],
				'args'     => is_array( $entry['args'] ?? null ) ? $entry['args'] : array(),
				'approved' => $approved,
			);
		}

		if ( count( $clean ) !== count( $raw ) ) {
			self::save( $clean );
		}

		return $clean;
	}

	/**
	 * Keep at most {@see MAX_PENDING} unapproved entries, dropping the oldest.
	 *
	 * @param array<string,array{created:int,user:int,uuid:string,ability:string,args:array<string,mixed>,approved:int}> $entries Entries.
	 * @return array<string,array{created:int,user:int,uuid:string,ability:string,args:array<string,mixed>,approved:int}>
	 */
	private static function capPending( array $entries ): array {
		$pending = array_filter( $entries, static fn ( array $entry ): bool => $entry['approved'] <= 0 );
		if ( count( $pending ) <= self::MAX_PENDING ) {
			return $entries;
		}

		uasort( $pending, static fn ( array $a, array $b ): int => $a['created'] <=> $b['created'] );
		foreach ( array_slice( array_keys( $pending ), 0, count( $pending ) - self::MAX_PENDING ) as $drop ) {
			unset( $entries[ $drop ] );
		}

		return $entries;
	}

	/**
	 * Persist, autoload off.
	 *
	 * @param array<string,array<string,mixed>> $entries Entries.
	 */
	private static function save( array $entries ): void {
		update_option( self::OPTION, $entries, false );
	}
}
