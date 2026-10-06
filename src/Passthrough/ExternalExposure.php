<?php
/**
 * Opt-in: let external AI apps (via the MCP adapter) use SenroFlux's own
 * abilities (0.4 S8).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Passthrough;

use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Tools\HarnessTools;
use Specflux\SenroFlux\Tools\PlanTools;
use Specflux\SenroFlux\Tools\SuggestBriefTool;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Decides `meta.mcp.public` for every `senroflux/*` ability, in ONE place:
 * a `wp_register_ability_args` filter scoped to the `senroflux/` namespace.
 *
 * The MCP adapter lets `meta.mcp.public` win over `meta.public`, so this sets
 * it explicitly in both directions: false whenever the switch is off, SenroGate
 * is absent, the ability is a harness-only tool or it is marked
 * `senroflux.hidden` (so a `meta.public` set elsewhere can never expose it);
 * true only for the rest while the switch is on AND SenroGate is active.
 */
final class ExternalExposure {

	/** Option holding the admin's switch (stored '1' / '0'). */
	public const OPTION = 'senroflux_external_apps';

	private const NAMESPACE = 'senroflux/';

	/** Hook the filter. Call before abilities register. */
	public static function register(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core Abilities API hook.
		add_filter( 'wp_register_ability_args', array( self::class, 'filterArgs' ), 10, 2 );
	}

	/** Sanitise the posted value to a bool. */
	public static function sanitize( mixed $value ): bool {
		return true === $value || '1' === $value || 1 === $value || 'on' === $value;
	}

	/** The stored switch, default OFF. */
	public static function stored(): bool {
		return self::sanitize( function_exists( 'get_option' ) ? get_option( self::OPTION, '0' ) : '0' );
	}

	/** Is SenroGate active? (The same detection the gate mode uses.) */
	public static function senrogatePresent(): bool {
		return GateMode::AgentSafety === Plugin::currentGateMode();
	}

	/** The effective value: the stored switch, and only while SenroGate is present. */
	public static function effective(): bool {
		return self::stored() && self::senrogatePresent();
	}

	/**
	 * Harness-only tools never leave the harness.
	 *
	 * @param string $name Full ability name.
	 */
	public static function isHarnessOnly( string $name ): bool {
		return in_array( $name, array( HarnessTools::TOOL_NAME, PlanTools::TOOL_NAME, SuggestBriefTool::TOOL_NAME ), true );
	}

	/**
	 * `wp_register_ability_args` callback.
	 *
	 * @param mixed  $args Registration args.
	 * @param string $name Ability name.
	 * @return mixed
	 */
	public static function filterArgs( $args, $name = '' ) {
		if ( ! is_array( $args ) || ! is_string( $name ) || 0 !== strpos( $name, self::NAMESPACE ) ) {
			return $args;
		}

		$meta = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		$ours = isset( $meta['senroflux'] ) && is_array( $meta['senroflux'] ) ? $meta['senroflux'] : array();
		$mcp  = isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) ? $meta['mcp'] : array();

		$hidden = true === ( $ours['hidden'] ?? false );
		$public = ! $hidden && ! self::isHarnessOnly( $name ) && self::effective();

		$mcp['public'] = $public;
		$meta['mcp']   = $mcp;
		$args['meta']  = $meta;

		return $args;
	}
}
