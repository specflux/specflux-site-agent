<?php
/**
 * The connector's two enforcement points on the adapter's filters (K1).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
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

		return GateMode::BuiltIn === $mode ? self::builtInDecision( $args, $connection, $ability, $call ) : $args;
	}

	/**
	 * Built-in mode (SenroGate absent): reads run; anything else parks until
	 * a site admin approves that exact call, then runs once.
	 *
	 * The adapter can only pass a call on or short-circuit it with a WP_Error,
	 * so a parked call reaches the client as an error result whose message
	 * tells the agent what to ask for and to repeat the call afterwards.
	 *
	 * @param mixed               $args       The call's arguments, returned to let it run.
	 * @param Connection          $connection The request's connection.
	 * @param string              $ability    The ability behind the tool.
	 * @param array<string,mixed> $call       The arguments as an array.
	 */
	private static function builtInDecision( mixed $args, Connection $connection, string $ability, array $call ): mixed {
		$tier = Tiering::forCall( $ability, $call, GateMode::BuiltIn );
		if ( VerbTier::TIER_0 === $tier ) {
			return $args;
		}

		// Refused outright, never parked: commerce writes need SenroGate (a
		// call can tier above its listed ceiling), and the connector's own
		// settings are not for an AI app to change.
		if ( Tiering::needsSenroGate( $ability ) ) {
			return self::denied( 'senroflux_connector_needs_senrogate', __( 'Changes to the store need the SenroGate plugin. This site does not have it, so this call is refused.', 'senroflux' ) );
		}
		if ( self::touchesConnectorSettings( $call ) ) {
			return self::denied( 'senroflux_connector_settings', __( 'AI apps cannot change the connector\'s own settings.', 'senroflux' ) );
		}

		$hash = Approvals::hash( $connection->userId, $connection->uuid, $ability, $call );
		if ( Approvals::consume( $hash ) ) {
			return $args;
		}
		Approvals::park( $connection->userId, $connection->uuid, $ability, $call );

		return new WP_Error(
			'senroflux_connector_awaiting_approval',
			sprintf(
				/* translators: %s: wp-admin URL of the approvals tab. */
				__( 'This change was not made. It needs the site owner\'s approval first. Ask them to open %s and approve it, then repeat exactly the same call. The approval works once, for 15 minutes.', 'senroflux' ),
				ApprovalsScreen::url()
			)
		);
	}

	/**
	 * Does any string value in the arguments, at any depth, equal one of the
	 * connector's own option or meta names? Prose that merely mentions a name
	 * (a post about the connector) is fine; naming the record is not.
	 *
	 * @param mixed $value The call's arguments, or any nested value.
	 */
	private static function touchesConnectorSettings( mixed $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( self::touchesConnectorSettings( $item ) ) {
					return true;
				}
			}

			return false;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}

		$name = strtolower( trim( $value ) );

		return in_array( $name, array( Connector::OPTION, Connections::META_KEY, Approvals::OPTION ), true )
			|| str_starts_with( $name, Approvals::GRANT_PREFIX );
	}

	/** A refusal the adapter returns to the client as the tool's error. */
	private static function denied( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message );
	}
}
