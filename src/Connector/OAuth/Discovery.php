<?php
/**
 * Discovery documents and the 401 challenge.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

use Specflux\SiteAgent\Connector\Connector;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Protected resource metadata (RFC 9728) and authorization server metadata
 * (RFC 8414), served at the site root through `parse_request` when WordPress
 * owns the root with pretty permalinks, and always through REST as a fallback
 * that the 401 `WWW-Authenticate` header points at when the root is not ours.
 *
 * The issuer is always the REST namespace URL and only the path-inserted
 * documents for this resource and issuer are answered: the bare root documents
 * belong to whatever owns the site root (another MCP or OAuth plugin, the host),
 * and a client that read theirs would register and fetch tokens there.
 *
 * Limitation: with WordPress in a subdirectory, or plain permalinks, the
 * root `/.well-known/` paths belong to the web server, not WordPress. Then
 * the 401 points at the REST copy of the resource metadata and the issuer is
 * the REST namespace URL, whose path-appended `/.well-known/openid-configuration`
 * (MCP auth "Authorization Server Metadata Discovery", option 3) is a REST route.
 */
final class Discovery {

	public const PRM_PATH = '/.well-known/oauth-protected-resource';
	public const AS_PATH  = '/.well-known/oauth-authorization-server';
	public const OIDC     = '/.well-known/openid-configuration';

	public static function register(): void {
		add_action( 'parse_request', array( self::class, 'maybeServe' ), 0 );
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );
	}

	/** Can WordPress answer `/.well-known/...` at the host root? (Root install + pretty permalinks.) */
	public static function rootServed(): bool {
		$home_path = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		return '' === trim( $home_path, '/' ) && '' !== (string) get_option( 'permalink_structure', '' );
	}

	/** The authorization server identifier. */
	public static function issuer(): string {
		return untrailingslashit( rest_url( OAuth::REST_NAMESPACE ) );
	}

	/** The URL the 401 challenge hands to clients. */
	public static function metadataUrl(): string {
		if ( self::rootServed() ) {
			return home_url( self::PRM_PATH . self::resourcePath() );
		}

		return rest_url( OAuth::REST_NAMESPACE . '/protected-resource' );
	}

	/** The server URL's path, e.g. `/wp-json/specflux-site-agent/mcp`. */
	public static function resourcePath(): string {
		return (string) wp_parse_url( OAuth::serverUrl(), PHP_URL_PATH );
	}

	/**
	 * RFC 9728 §2 document. `resource` is the exact server URL (Claude requires an exact match).
	 *
	 * @return array<string,mixed>
	 */
	public static function protectedResource(): array {
		return array(
			'resource'                 => OAuth::serverUrl(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => OAuth::supportedScopes(),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => 'Specflux Site Agent',
		);
	}

	/**
	 * RFC 8414 §2 document. S256 only; public clients only; no client_credentials.
	 *
	 * @return array<string,mixed>
	 */
	public static function authorizationServer(): array {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => OAuth::authorizeUrl(),
			'token_endpoint'                        => rest_url( OAuth::REST_NAMESPACE . '/token' ),
			'registration_endpoint'                 => rest_url( OAuth::REST_NAMESPACE . '/register' ),
			'scopes_supported'                      => OAuth::supportedScopes(),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'client_id_metadata_document_supported' => true,
		);
	}

	/**
	 * The document for a request path (relative to the home path), or null.
	 *
	 * @param string $path Path such as `/.well-known/oauth-authorization-server`.
	 * @return array<string,mixed>|null
	 */
	public static function documentFor( string $path ): ?array {
		$path = '/' . trim( $path, '/' );
		if ( self::PRM_PATH . self::resourcePath() === $path ) {
			return self::protectedResource();
		}
		$issuer_path = (string) wp_parse_url( self::issuer(), PHP_URL_PATH );
		if ( '' !== $issuer_path && ( self::AS_PATH . $issuer_path === $path || self::OIDC . $issuer_path === $path ) ) {
			return self::authorizationServer();
		}

		return null;
	}

	/** `parse_request` (priority 0): answer a well-known path and stop; ignore everything else. */
	public static function maybeServe(): void {
		if ( ! OAuth::serving() || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed, compared, never output.
		$home = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $home && str_starts_with( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		if ( ! str_starts_with( $path, '/.well-known/' ) ) {
			return;
		}
		$doc = self::documentFor( $path );
		if ( null === $doc ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::send( $doc, $method );
	}

	/**
	 * Emit a metadata document and end the request. Public metadata, so CORS-open for browser-based clients.
	 *
	 * @param array<string,mixed> $doc    Document.
	 * @param string              $method HTTP method.
	 */
	private static function send( array $doc, string $method ): void {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type, MCP-Protocol-Version' );
		if ( 'OPTIONS' === $method ) {
			status_header( 204 );
			exit;
		}
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD, OPTIONS' );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		if ( 'GET' === $method ) {
			echo wp_json_encode( $doc, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		}
		exit;
	}

	/** REST copies of both documents. */
	public static function registerRoutes(): void {
		$routes = array(
			'/protected-resource'               => 'restResource',
			'/authorization-server'             => 'restServer',
			'/.well-known/openid-configuration' => 'restServer',
		);
		foreach ( $routes as $route => $callback ) {
			register_rest_route(
				OAuth::REST_NAMESPACE,
				$route,
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, $callback ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	/** REST callback. */
	public static function restResource(): mixed {
		return OAuth::serving() ? rest_ensure_response( self::protectedResource() ) : new \WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}

	/** REST callback. */
	public static function restServer(): mixed {
		return OAuth::serving() ? rest_ensure_response( self::authorizationServer() ) : new \WP_Error( 'not_found', 'Not found.', array( 'status' => 404 ) );
	}

	/**
	 * Build a `WWW-Authenticate` value (RFC 6750 §3, RFC 9728 §5.1; MCP auth requires
	 * `resource_metadata` on 401 and recommends `scope`).
	 *
	 * @param string|null $error       `invalid_token`, `insufficient_scope` or null (no credential at all).
	 * @param string      $description Optional generic description.
	 */
	public static function challenge( ?string $error = null, string $description = '' ): string {
		$parts = array();
		if ( null !== $error ) {
			$parts[] = 'error="' . $error . '"';
			if ( '' !== $description ) {
				$parts[] = 'error_description="' . $description . '"';
			}
		}
		$parts[] = 'resource_metadata="' . self::metadataUrl() . '"';
		// Clients request this verbatim; the consent screen still defaults to read only.
		$parts[] = 'scope="' . implode( ' ', OAuth::supportedScopes() ) . '"';

		return 'Bearer ' . implode( ', ', $parts );
	}
}
