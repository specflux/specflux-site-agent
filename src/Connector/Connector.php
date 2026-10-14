<?php
/**
 * The Specflux Site Agent MCP connector: setting, feature detection, server (K1).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

use Specflux\SiteAgent\Admin\ScreenCapability;
use Specflux\SiteAgent\MultisiteGuard;
use Specflux\SiteAgent\Plugin;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Lets external AI apps (Claude, ChatGPT, Cursor...) use Specflux Site Agent's abilities
 * through one MCP server at `/wp-json/specflux-site-agent/mcp`, built on the WordPress.org
 * MCP adapter. Specflux Site Agent writes no transport and never bundles the adapter: it
 * feature-detects the plugin and creates its server on `mcp_adapter_init`.
 *
 * Everything here fails closed. The setting is off until an admin turns it on;
 * the server exists only while the adapter is present and recent enough; a
 * request opens it only with a registered application password, held by a user
 * with the connector capability.
 */
final class Connector {

	/** Option holding the admin's switch (stored '1' / '0'). */
	public const OPTION = 'specflux_site_agent_connector_enabled';

	/** The server id the adapter knows this server by. */
	public const SERVER_ID = 'specflux';

	/** REST namespace and route: `/wp-json/specflux-site-agent/mcp`. */
	public const ROUTE_NAMESPACE = 'specflux-site-agent';
	public const ROUTE           = 'mcp';

	/** The capability the connection's user needs, unless a site filters it. */
	public const DEFAULT_CAPABILITY = 'manage_options';

	/** The filter a site uses to move the connector to another capability. */
	public const CAPABILITY_FILTER = 'specflux_site_agent_connector_capability';

	/**
	 * The oldest mcp-adapter Specflux Site Agent supports. 0.7.0 is the first release on
	 * wordpress.org, the one the connector was built and checked against, and
	 * it reworked custom transports, so anything older has an unknown
	 * `create_server()` / `HttpTransport` contract.
	 */
	public const MIN_ADAPTER_VERSION = '0.7.0';

	/** The adapter's singleton class, and the transport Specflux Site Agent asks it for. */
	public const ADAPTER_CLASS   = 'WP\MCP\Core\McpAdapter';
	public const TRANSPORT_CLASS = 'WP\MCP\Transport\HttpTransport';

	/** Test seam: a forced adapter version ('' = absent), or null for the real check. */
	private static ?string $adapter_version_probe = null;

	/** The connection authorised for this request's MCP route, if any. */
	private static ?Connection $serving = null;

	/** Hook the connector. The hooks do nothing for another plugin's server. */
	public static function register(): void {
		add_action( 'mcp_adapter_init', array( self::class, 'createServer' ) );
		add_filter( 'mcp_adapter_tools_list', array( Gate::class, 'filterTools' ), 10, 3 );
		// Priority 20: after Belayline Agent Governance's wrapper (10), so ours is outermost. Abilities
		// register on `wp_abilities_api_init` (init), long after plugins_loaded.
		add_filter( 'wp_register_ability_args', array( Gate::class, 'wrapAbilityArgs' ), 20, 2 );
		add_filter( 'mcp_adapter_pre_tool_call', array( Gate::class, 'preCall' ), 10, 4 );
		add_filter( 'mcp_adapter_tool_call_result', array( Gate::class, 'afterCall' ), 10, 5 );
		Connections::register();
		ActivityLog::register();
		ApprovalsScreen::register();
		ConnectScreen::register();
		OAuth\OAuth::register();
	}

	/** Sanitise the posted value to a bool. */
	public static function sanitize( mixed $value ): bool {
		return true === $value || '1' === $value || 1 === $value || 'on' === $value;
	}

	/** The stored switch, default OFF. */
	public static function stored(): bool {
		return self::sanitize( function_exists( 'get_option' ) ? get_option( self::OPTION, '0' ) : '0' );
	}

	/** Is the connector usable at all? Switch on, not multisite, adapter present and recent. */
	public static function enabled(): bool {
		return self::stored() && ! MultisiteGuard::refused() && self::adapterReady();
	}

	/** The installed mcp-adapter version, or null when the adapter is absent. */
	public static function adapterVersion(): ?string {
		if ( null !== self::$adapter_version_probe ) {
			return '' === self::$adapter_version_probe ? null : self::$adapter_version_probe;
		}

		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			return null;
		}

		return defined( self::ADAPTER_CLASS . '::VERSION' ) ? (string) constant( self::ADAPTER_CLASS . '::VERSION' ) : '0';
	}

	/** Is the adapter present and at least {@see MIN_ADAPTER_VERSION}? */
	public static function adapterReady(): bool {
		$version = self::adapterVersion();

		return null !== $version && version_compare( $version, self::MIN_ADAPTER_VERSION, '>=' );
	}

	/**
	 * Test seam: force the adapter version ('' = absent); null restores reality.
	 *
	 * @param string|null $version Forced version.
	 */
	public static function set_adapter_version_probe( ?string $version ): void {
		self::$adapter_version_probe = $version;
	}

	/**
	 * The capability the connection's user must hold. Fails closed to the
	 * capability nobody has when a filter returns something unusable.
	 */
	public static function capability(): string {
		/** Filters the capability the connector requires of the connection's user. `@internal`. */
		$capability = apply_filters( 'specflux_site_agent_connector_capability', self::DEFAULT_CAPABILITY );

		return ( is_string( $capability ) && '' !== $capability ) ? $capability : ScreenCapability::NOBODY;
	}

	/**
	 * The request's connection when the connector may serve it, else null.
	 *
	 * Enabled, an application password Specflux Site Agent registered, and a user who
	 * holds the connector capability. A cookie session is never a connection.
	 */
	public static function authorise(): ?Connection {
		if ( ! self::enabled() ) {
			return null;
		}

		$connection = Connections::current();
		if ( null === $connection || ! user_can( $connection->userId, self::capability() ) ) {
			return null;
		}

		return $connection;
	}

	/**
	 * The transport's permission callback: deny unless {@see authorise()}.
	 *
	 * @param mixed $request The REST request (unused: the user is already resolved).
	 */
	public static function permission( mixed $request = null ): bool {
		unset( $request );

		self::$serving = self::authorise();

		return null !== self::$serving;
	}

	/**
	 * The connection this request is serving on Specflux Site Agent's MCP route, set by
	 * {@see permission()} and null everywhere else (harness runs, other
	 * servers, cron, CLI).
	 */
	public static function serving(): ?Connection {
		return self::$serving;
	}

	/** Test seam: forget (or force) the connection being served. */
	public static function set_serving( ?Connection $connection = null ): void {
		self::$serving = $connection;
	}

	/**
	 * `mcp_adapter_init` callback: create Specflux Site Agent's one server.
	 *
	 * The tool list is the broadest exposure set. It is fixed here, before any
	 * request's connection is known, so {@see Gate::filterTools()} narrows it
	 * per request and {@see Gate::preCall()} enforces it per call.
	 *
	 * @param mixed $adapter The adapter singleton.
	 */
	public static function createServer( mixed $adapter ): void {
		if ( ! self::enabled() || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'Specflux Site Agent',
			'Pages, posts, media, site settings and store tools for this WordPress site. Changes follow the site\'s approval rules.',
			SPECFLUX_SITE_AGENT_API_VERSION,
			array( self::TRANSPORT_CLASS ),
			null,
			null,
			ExposureSet::names( Plugin::currentGateMode() ),
			array(),
			array(),
			array( self::class, 'permission' )
		);
	}
}
