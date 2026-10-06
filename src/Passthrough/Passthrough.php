<?php
/**
 * Abilities from other plugins: governed passthrough (0.4 S6).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Passthrough;

use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The site-wide list of other plugins' abilities the admin has switched on.
 *
 * Everything here fails closed: nothing is on by default, an enabled ability
 * is exposed only while it is still registered, its input schema still hashes
 * to what the admin enabled, it is outside every namespace SenroFlux or a
 * pack owns, and (in SenroGate mode) SenroGate reports it reviewed. Nothing an
 * ability reports about itself loosens a decision: annotations only tighten.
 */
final class Passthrough {

	/** Option holding name => array{enabled:bool,read:bool,hash:string}. */
	public const OPTION = 'senroflux_passthrough';

	/** The most abilities that may be enabled site-wide. */
	public const MAX_ENABLED = 40;

	/** Refusal code: more than {@see MAX_ENABLED} ticked. */
	public const ERROR_TOO_MANY = 'passthrough_too_many';

	/** Namespaces that never pass through, whatever a pack says. */
	private const ALWAYS_EXCLUDED = array( 'senroflux/', 'agent-safety/' );

	/**
	 * The saved configuration, sanitised: only well-formed entries survive.
	 *
	 * @return array<string,array{enabled:bool,read:bool,hash:string}>
	 */
	public static function config(): array {
		$raw = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$config = array();
		foreach ( $raw as $name => $entry ) {
			if ( ! is_string( $name ) || ! self::validName( $name ) || ! is_array( $entry ) ) {
				continue;
			}
			$config[ $name ] = array(
				'enabled' => true === ( $entry['enabled'] ?? false ),
				'read'    => true === ( $entry['read'] ?? false ),
				'hash'    => is_string( $entry['hash'] ?? null ) ? $entry['hash'] : '',
			);
		}

		return $config;
	}

	/** Is this a well-formed ability name (`namespace/name`)? */
	public static function validName( string $name ): bool {
		return 1 === preg_match( '#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)+$#', $name );
	}

	/**
	 * Namespace prefixes a passthrough ability must NOT be in: SenroFlux's,
	 * Agent Safety's and every namespace any registered pack resolves.
	 *
	 * @return list<string>
	 */
	public static function excludedPrefixes(): array {
		$prefixes = self::ALWAYS_EXCLUDED;
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			foreach ( $pack->abilityNamespaces() as $namespace ) {
				$prefixes[] = $namespace;
			}
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Registered abilities eligible for passthrough, name => ability, sorted.
	 *
	 * @return array<string,object>
	 */
	public static function candidates(): array {
		$abilities = function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array();
		$excluded  = self::excludedPrefixes();
		$found     = array();

		foreach ( $abilities as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = (string) $ability->get_name();
			if ( ! self::validName( $name ) ) {
				continue;
			}
			foreach ( $excluded as $prefix ) {
				if ( str_starts_with( $name, $prefix ) ) {
					continue 2;
				}
			}
			$found[ $name ] = $ability;
		}

		ksort( $found );

		return $found;
	}

	/** Stable hash of an ability's input schema ('' schema hashes too). */
	public static function schemaHash( object $ability ): string {
		$schema = method_exists( $ability, 'get_input_schema' ) ? $ability->get_input_schema() : null;

		return hash( 'sha256', (string) wp_json_encode( self::canonical( $schema ) ) );
	}

	/** Key-sort associative arrays so registration order never changes the hash. */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	/** Do the ability's annotations say destructive? Annotations only tighten. */
	public static function isDestructive( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		$ann  = is_array( $meta ) ? ( $meta['annotations'] ?? null ) : null;

		return is_array( $ann ) && true === ( $ann['destructive'] ?? null );
	}

	/**
	 * Why an enabled ability is not currently usable, or null when it is on
	 * for the admin's side (gate mode is judged separately).
	 *
	 * @param array{enabled:bool,read:bool,hash:string} $entry Saved entry.
	 * @return string|null 'off' | 'missing' | 'changed' | null.
	 */
	public static function problem( array $entry, ?object $ability ): ?string {
		if ( ! $entry['enabled'] ) {
			return 'off';
		}
		if ( null === $ability ) {
			return 'missing';
		}

		return '' !== $entry['hash'] && hash_equals( $entry['hash'], self::schemaHash( $ability ) ) ? null : 'changed';
	}

	/**
	 * Enabled, present, unchanged and not hidden: before the gate-mode rule.
	 *
	 * @return array<string,object> name => ability.
	 */
	private static function usable(): array {
		$candidates = self::candidates();
		$usable     = array();

		foreach ( self::config() as $name => $entry ) {
			$ability = $candidates[ $name ] ?? null;
			if ( null !== self::problem( $entry, $ability ) || null === $ability ) {
				continue;
			}
			$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
			$ours = is_array( $meta ) && is_array( $meta['senroflux'] ?? null ) ? $meta['senroflux'] : array();
			if ( true === ( $ours['hidden'] ?? false ) ) {
				continue;
			}
			$usable[ $name ] = $ability;
		}

		return $usable;
	}

	/**
	 * Exact ability names to append to a run's allow-list. In SenroGate mode
	 * only abilities SenroGate reports reviewed; built-in mode needs no review.
	 *
	 * @return list<string>
	 */
	public static function exposedNames( GateMode $mode ): array {
		$names = array();
		foreach ( array_keys( self::usable() ) as $name ) {
			if ( GateMode::BuiltIn === $mode || AbilityReviewProbe::isReviewed( $name ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Enabled, unchanged abilities SenroGate mode is withholding for want of a
	 * review (feeds the setup check).
	 *
	 * @return list<string>
	 */
	public static function awaitingReview(): array {
		$names = array();
		foreach ( array_keys( self::usable() ) as $name ) {
			if ( ! AbilityReviewProbe::isReviewed( $name ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * The verb => tier entries for every exposed passthrough ability. Tier 0
	 * only in built-in mode, for an ability the admin marked Read whose
	 * annotations don't say destructive; everything else is tier 2.
	 *
	 * @return array<string,int>
	 */
	public static function tierMap( GateMode $mode ): array {
		$config = self::config();
		$usable = self::usable();
		$tiers  = array();
		foreach ( self::exposedNames( $mode ) as $name ) {
			$read           = GateMode::BuiltIn === $mode && true === $config[ $name ]['read'] && ! self::isDestructive( $usable[ $name ] );
			$tiers[ $name ] = $read ? VerbTier::TIER_0 : VerbTier::TIER_2;
		}

		return $tiers;
	}

	/**
	 * `senroflux_verb_map` callback for runs without a pack: the passthrough
	 * tiers are merged LAST, so no other callback can loosen them.
	 *
	 * @param mixed $map The map so far.
	 * @return array<string,int>
	 */
	public static function filterVerbMap( mixed $map ): array {
		$map = is_array( $map ) ? $map : array();

		return array_merge( $map, self::tierMap( Plugin::currentGateMode() ) );
	}

	/**
	 * Save the screen's submission, replacing the stored config.
	 *
	 * Refuses (saving nothing) when more than {@see MAX_ENABLED} are ticked.
	 * A ticked ability's schema hash is recorded NOW, so ticking a drifted
	 * ability is the explicit re-enable.
	 *
	 * @param list<string>        $enabled Ticked ability names (unsanitised).
	 * @param array<string,mixed> $read    name => 'read' | 'changes' (unsanitised).
	 * @return true|WP_Error
	 */
	public static function save( array $enabled, array $read ): true|WP_Error {
		$candidates = self::candidates();
		$names      = array();
		foreach ( $enabled as $name ) {
			if ( is_string( $name ) && isset( $candidates[ $name ] ) ) {
				$names[ $name ] = true;
			}
		}

		if ( count( $names ) > self::MAX_ENABLED ) {
			return new WP_Error(
				self::ERROR_TOO_MANY,
				sprintf(
					/* translators: %d: the cap. */
					__( 'At most %d abilities from other plugins can be enabled. Nothing was saved; untick some and try again.', 'senroflux' ),
					self::MAX_ENABLED
				)
			);
		}

		$config = array();
		foreach ( array_keys( $names ) as $name ) {
			$config[ $name ] = array(
				'enabled' => true,
				'read'    => 'read' === ( $read[ $name ] ?? '' ),
				'hash'    => self::schemaHash( $candidates[ $name ] ),
			);
		}

		update_option( self::OPTION, $config, false );

		return true;
	}
}
