<?php
/**
 * The connector's two enforcement points on the adapter's filters (K1).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Tools\VerbTier;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Narrows what one connection sees (`mcp_adapter_tools_list`) and decides
 * every call (`mcp_adapter_pre_tool_call`), for SenroFlux's server only. Both
 * re-resolve the connection from the request's own authentication; nothing is
 * remembered between them, so neither can be primed by a client.
 */
final class Gate {

	/** Is this the adapter server SenroFlux created? */
	private static function isOurs( mixed $server ): bool {
		return is_object( $server ) && method_exists( $server, 'get_server_id' ) && Connector::SERVER_ID === $server->get_server_id();
	}

	/**
	 * The ability behind a tool name on this server, or null when the tool is
	 * not ability-backed (SenroFlux registers only ability-backed tools).
	 *
	 * @param mixed  $server    The adapter server.
	 * @param string $tool_name The tool name.
	 */
	private static function abilityFor( mixed $server, string $tool_name ): ?string {
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_mcp_tool' ) ) {
			return null;
		}

		$tool = $server->get_mcp_tool( $tool_name );

		return is_object( $tool ) ? self::abilityOfTool( $tool ) : null;
	}

	/** The ability name an adapter tool is backed by, or null. */
	private static function abilityOfTool( object $tool ): ?string {
		if ( ! method_exists( $tool, 'is_ability_backed' ) || ! $tool->is_ability_backed() || ! method_exists( $tool, 'get_observability_context' ) ) {
			return null;
		}

		$context = $tool->get_observability_context();
		$name    = is_array( $context ) ? ( $context['ability_name'] ?? null ) : null;

		return is_string( $name ) && '' !== $name ? $name : null;
	}

	/**
	 * `mcp_adapter_tools_list` callback: drop what this connection may not use.
	 *
	 * @param mixed $tools  The Tool records.
	 * @param mixed $server The adapter server.
	 * @return mixed
	 */
	public static function filterTools( mixed $tools, mixed $server = null ): mixed {
		if ( ! self::isOurs( $server ) ) {
			return $tools;
		}

		$connection = Connector::authorise();
		if ( null === $connection || ! is_array( $tools ) ) {
			return array();
		}

		$mode = Plugin::currentGateMode();
		$kept = array();
		foreach ( $tools as $tool ) {
			$ability = is_object( $tool ) && method_exists( $tool, 'getName' ) ? self::abilityFor( $server, (string) $tool->getName() ) : null;
			if ( null !== $ability && ExposureSet::visibleTo( $ability, $connection, $mode ) ) {
				$kept[] = $tool;
			}
		}

		return $kept;
	}

	/**
	 * `mcp_adapter_pre_tool_call` callback: the decision for one call.
	 *
	 * Returns the arguments to let the call proceed, or a WP_Error, which the
	 * adapter turns into the tool's result without running the ability.
	 *
	 * @param mixed  $args      The call's arguments.
	 * @param string $tool_name The tool name.
	 * @param mixed  $tool      The adapter tool.
	 * @param mixed  $server    The adapter server.
	 * @return mixed
	 */
	public static function preCall( mixed $args, mixed $tool_name = '', mixed $tool = null, mixed $server = null ): mixed {
		if ( ! self::isOurs( $server ) ) {
			return $args;
		}

		$connection = Connector::authorise();
		$ability    = is_object( $tool ) ? self::abilityOfTool( $tool ) : null;
		if ( null === $connection || null === $ability ) {
			return self::denied( 'senroflux_connector_denied', __( 'This connection is not allowed to use SenroFlux.', 'senroflux' ) );
		}

		$call = is_array( $args ) ? $args : array();
		$mode = Plugin::currentGateMode();

		// Re-checked at call time: a client can name any tool, listed or not.
		if ( ! ExposureSet::visibleTo( $ability, $connection, $mode ) ) {
			return self::denied( 'senroflux_connector_unavailable', __( 'This tool is not available to this connection.', 'senroflux' ) );
		}

		// The list shows a tool by its ceiling; the call is tiered by its arguments.
		if ( Tiering::forCall( $ability, $call, $mode ) > VerbTier::TIER_0 && ! $connection->mayWrite() ) {
			return self::denied( 'senroflux_connector_read_only', __( 'This connection is read-only. Ask the site owner to give it read and write access.', 'senroflux' ) );
		}

		return $args;
	}

	/** A refusal the adapter returns to the client as the tool's error. */
	private static function denied( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}
}
