<?php
/**
 * What the connector exposes (K1).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Passthrough\Passthrough;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\SuggestBriefTool;
use Specflux\SenroFlux\Tools\VerbTier;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The exposure set: SenroFlux's own abilities, every ability a registered
 * pack resolves (commerce only while a store platform is active, because only
 * then is its pack registered), and the abilities enabled on the "Abilities
 * from other plugins" screen. The harness-only tools and anything marked
 * `senroflux.hidden` never leave wp-admin.
 *
 * {@see names()} is the broadest set, fixed when the server is created;
 * {@see visibleTo()} is the narrowing for one connection, applied to the tool
 * list and again to every call.
 */
final class ExposureSet {

	private const NAMESPACE = 'senroflux/';

	/** @var array<string,list<string>> Request-scoped memo of {@see names()}, per gate mode. */
	private static array $memo = array();

	/** Test seam: forget the memoised sets (also useful after abilities register late). */
	public static function reset(): void {
		self::$memo = array();
	}

	/**
	 * Every ability the server registers as a tool, sorted.
	 *
	 * @param GateMode $mode The gate mode right now.
	 * @return list<string>
	 */
	public static function names( GateMode $mode ): array {
		if ( isset( self::$memo[ $mode->value ] ) ) {
			return self::$memo[ $mode->value ];
		}

		$names = array();

		foreach ( function_exists( 'wp_get_abilities' ) ? wp_get_abilities() : array() as $ability ) {
			if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && str_starts_with( (string) $ability->get_name(), self::NAMESPACE ) ) {
				$names[] = (string) $ability->get_name();
			}
		}
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			array_push( $names, ...$pack->allowList() );
		}
		array_push( $names, ...Passthrough::exposedNames( $mode ) );

		$exposed = array();
		foreach ( array_unique( $names ) as $name ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
			if ( is_object( $ability ) && ! self::isHarnessOnly( $name ) && ! self::isHidden( $ability ) ) {
				$exposed[] = $name;
			}
		}
		sort( $exposed );

		self::$memo[ $mode->value ] = $exposed;

		return $exposed;
	}

	/**
	 * May this connection see (and call) this ability right now?
	 *
	 * @param string     $ability   Ability name.
	 * @param Connection $connection The request's connection.
	 * @param GateMode   $mode      The call's pinned gate mode.
	 */
	public static function visibleTo( string $ability, Connection $connection, GateMode $mode ): bool {
		if ( ! in_array( $ability, self::names( $mode ), true ) ) {
			return false;
		}

		$ceiling = Tiering::ceiling( $ability, $mode );
		if ( $ceiling > VerbTier::TIER_0 && ! $connection->mayWrite() ) {
			return false;
		}

		// Without SenroGate, a pack that refuses the built-in gate offers reads only.
		return ! ( GateMode::BuiltIn === $mode && $ceiling > VerbTier::TIER_0 && Tiering::needsSenroGate( $ability ) );
	}

	/** Harness-only tools never leave the harness. */
	private static function isHarnessOnly( string $name ): bool {
		return in_array( $name, array( HarnessTools::TOOL_NAME, PlanTools::TOOL_NAME, SuggestBriefTool::TOOL_NAME ), true );
	}

	/** Is the ability marked `senroflux.hidden` by whoever registered it? */
	private static function isHidden( object $ability ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? $ability->get_meta() : array();
		$ours = is_array( $meta ) && is_array( $meta['senroflux'] ?? null ) ? $meta['senroflux'] : array();

		return true === ( $ours['hidden'] ?? false );
	}
}
