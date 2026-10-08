<?php
/**
 * Tier a connector call from SenroFlux's own declarations (K1/K2).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Packs\PackRegistry;
use Specflux\SenroFlux\Passthrough\Passthrough;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Tools\VerbTier;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The same tiers runs use: a pack's verb map for the abilities its roles
 * resolve to, the passthrough marking for abilities from other plugins, and
 * tier 2 for anything else (an unmapped ability is irreversible, never free).
 * Nothing the tool, the model or the client says about itself is read here.
 */
final class Tiering {

	/**
	 * The tier of one concrete call.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $args    The call's arguments.
	 * @param GateMode            $mode    The call's pinned gate mode.
	 */
	public static function forCall( string $ability, array $args, GateMode $mode ): int {
		$passthrough = Passthrough::tierMap( $mode );
		if ( isset( $passthrough[ $ability ] ) ) {
			return $passthrough[ $ability ];
		}

		$pack = self::owner( $ability );
		if ( null === $pack ) {
			return VerbTier::TIER_2;
		}

		return VerbTier::tierFor( $pack->verbFor( $ability, $args ), $pack->verbMap() );
	}

	/**
	 * The highest tier any call to this ability can have (no arguments yet):
	 * what the tool list narrows on. A call is always re-tiered with its real
	 * arguments, so this only ever decides what is shown.
	 *
	 * @param string   $ability Ability name.
	 * @param GateMode $mode    The call's pinned gate mode.
	 */
	public static function ceiling( string $ability, GateMode $mode ): int {
		$passthrough = Passthrough::tierMap( $mode );
		if ( isset( $passthrough[ $ability ] ) ) {
			return $passthrough[ $ability ];
		}

		$pack = self::owner( $ability );
		if ( null === $pack ) {
			return VerbTier::TIER_2;
		}

		$map     = $pack->verbMap();
		$ceiling = VerbTier::tierFor( $pack->verbFor( $ability, array() ), $map );
		$roles   = $pack->resolveAbilities();
		foreach ( $pack->roleVerbs() as $role => $verbs ) {
			if ( ( $roles[ $role ] ?? null ) !== $ability ) {
				continue;
			}
			foreach ( $verbs as $verb ) {
				$ceiling = max( $ceiling, VerbTier::tierFor( $verb, $map ) );
			}
		}

		return $ceiling;
	}

	/**
	 * Does the pack that owns this ability refuse the built-in gate? (Commerce.)
	 *
	 * @param string $ability Ability name.
	 */
	public static function needsSenroGate( string $ability ): bool {
		$pack = self::owner( $ability );

		return null !== $pack && $pack->requiresAgentSafety();
	}

	/**
	 * The registered pack whose roles resolve to this ability, if any.
	 *
	 * @param string $ability Ability name.
	 */
	public static function owner( string $ability ): ?Pack {
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			if ( in_array( $ability, $pack->allowList(), true ) ) {
				return $pack;
			}
		}

		return null;
	}
}
