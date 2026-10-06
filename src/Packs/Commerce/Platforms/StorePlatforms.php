<?php
/**
 * The store-platform registry and per-run selection (C4-0).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms;

use Specflux\SenroFlux\Packs\Commerce\Platforms\Woo\WooPlatform;
use Specflux\SenroFlux\Packs\Pack;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registered platforms, who is usable, and which one a run uses.
 *
 * Adding a platform: implement {@see StorePlatform} and append an instance on
 * the `senroflux_store_platforms` filter.
 *
 * @internal
 */
final class StorePlatforms {

	/** The admin's choice when several platforms are usable (stored platform id; '' = unset). */
	public const OPTION = 'senroflux_store_platform';

	/** Capability needed to change the choice. */
	public const CAPABILITY = 'manage_options';

	/**
	 * Every registered platform, keyed by id, in registration order. A filter
	 * entry that is not a StorePlatform, repeats an id, or whose namespace list
	 * does not end in `senroflux/` is dropped.
	 *
	 * @return array<string,StorePlatform>
	 */
	public static function all(): array {
		$platforms = array( new WooPlatform() );

		/**
		 * Register store platforms for the commerce pack.
		 *
		 * @internal Not a public extension point yet.
		 *
		 * @param list<StorePlatform> $platforms Platforms, WooCommerce first.
		 */
		$filtered = apply_filters( 'senroflux_store_platforms', $platforms );
		if ( ! is_array( $filtered ) ) {
			$filtered = $platforms;
		}

		$out = array();
		foreach ( $filtered as $platform ) {
			if ( ! $platform instanceof StorePlatform ) {
				continue;
			}
			$id = $platform->id();
			if ( '' === $id || isset( $out[ $id ] ) ) {
				continue;
			}
			$namespaces = $platform->abilityNamespaces();
			if ( array() === $namespaces || Pack::POLYFILL_NAMESPACE !== end( $namespaces ) ) {
				continue;
			}
			$out[ $id ] = $platform;
		}

		return $out;
	}

	/** One registered platform, or null. */
	public static function get( string $id ): ?StorePlatform {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * Platforms that are installed and recent enough.
	 *
	 * @return list<StorePlatform>
	 */
	public static function active(): array {
		return array_values( array_filter( self::all(), static fn ( StorePlatform $p ): bool => $p->isActive() ) );
	}

	/** Is any platform active (the commerce pack registers only then)? */
	public static function anyActive(): bool {
		return array() !== self::active();
	}

	/**
	 * Active platforms that SenroGate governs: the only ones a run may use.
	 *
	 * @return list<StorePlatform>
	 */
	public static function candidates(): array {
		return array_values( array_filter( self::active(), static fn ( StorePlatform $p ): bool => $p->isGoverned() ) );
	}

	/** Can a run pinned to this platform still continue (registered, active, governed)? */
	public static function usable( string $id ): bool {
		$platform = self::get( $id );

		return null !== $platform && $platform->isActive() && $platform->isGoverned();
	}

	/** The stored choice ('' when unset). */
	public static function chosenId(): string {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, '' ) : '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Pick the platform for a new run: the only candidate, else the one the
	 * setting names, else AMBIGUOUS.
	 */
	public static function select(): PlatformSelection {
		$candidates = self::candidates();

		if ( array() === $candidates ) {
			return new PlatformSelection( PlatformSelection::NONE, null, array() );
		}
		if ( 1 === count( $candidates ) ) {
			return new PlatformSelection( PlatformSelection::SINGLE, $candidates[0], $candidates );
		}

		$chosen = self::chosenId();
		foreach ( $candidates as $candidate ) {
			if ( '' !== $chosen && $candidate->id() === $chosen ) {
				return new PlatformSelection( PlatformSelection::CHOSEN, $candidate, $candidates );
			}
		}

		return new PlatformSelection( PlatformSelection::AMBIGUOUS, null, $candidates );
	}

	/**
	 * Validate and store the admin's choice. '' clears it; an unknown id is
	 * refused and nothing changes.
	 *
	 * @param string $id Platform id.
	 * @return true|WP_Error
	 */
	public static function save( string $id ): bool|WP_Error {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::CAPABILITY ) ) {
			return new WP_Error( 'store_platform_forbidden', 'Only an administrator can choose the store platform.', array( 'status' => 403 ) );
		}

		if ( '' === $id ) {
			delete_option( self::OPTION );

			return true;
		}

		if ( null === self::get( $id ) ) {
			return new WP_Error( 'store_platform_unknown', 'That store platform is not available.', array( 'status' => 400 ) );
		}

		update_option( self::OPTION, $id );

		return true;
	}

	/**
	 * `register_setting()` sanitiser: an unknown id keeps the previous value.
	 *
	 * @param mixed $value Posted value.
	 */
	public static function sanitizeOption( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return self::chosenId();
		}
		if ( '' === $value || null !== self::get( $value ) ) {
			return $value;
		}

		return self::chosenId();
	}
}
