<?php
/**
 * The OAuth 2.1 authorization server for the Specflux Site Agent connector: wiring and shared helpers.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Lets Claude.ai, ChatGPT and other OAuth-only MCP clients sign in to the
 * connector. It is an authorization server for exactly one resource: the
 * Specflux Site Agent MCP route. Tokens never authenticate any other route
 * ({@see Bearer}); everything fails closed.
 *
 * Specs followed (cited at the rule they produce): MCP authorization
 * 2025-11-25 ("MCP auth"), RFC 9728 (protected resource metadata), RFC 8414
 * (authorization server metadata), RFC 7591 (DCR), RFC 8707 (resource
 * indicators), RFC 7636 (PKCE), RFC 6749/6750, OAuth 2.1 draft-13.
 */
final class OAuth {

	public const SCOPE_READ  = 'specflux:read';
	public const SCOPE_WRITE = 'specflux:write';

	/** REST namespace of the registration/token/metadata endpoints. */
	public const REST_NAMESPACE = 'specflux-site-agent/oauth';

	/** admin-post action of the authorize + consent page. */
	public const AUTHORIZE_ACTION = 'specflux_site_agent_oauth_authorize';

	public const CODE_TTL    = 60;
	public const ACCESS_TTL  = HOUR_IN_SECONDS;
	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/** Cron hook that prunes grants whose refresh token has expired. */
	public const GC_HOOK = 'specflux_site_agent_oauth_gc';

	/**
	 * Can this site serve OAuth at all? False on a subsite of a SUBDIRECTORY
	 * multisite network: the `.well-known` routes only exist at the domain root,
	 * which belongs to the main site. App-password clients are unaffected.
	 *
	 * Assumption, not yet proven live: subdomain and domain-mapped subsites each
	 * own a domain root, so they keep OAuth. Verified in the multisite smoke.
	 */
	public static function availableOnThisSite(): bool {
		return ! ( function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'is_subdomain_install' ) && ! is_subdomain_install()
			&& function_exists( 'is_main_site' ) && ! is_main_site() );
	}

	/** The OAuth endpoints answer only when the connector is on and this site can host them. */
	public static function serving(): bool {
		return Connector::enabled() && self::availableOnThisSite();
	}

	/** Hook everything. Cheap: no storage is touched until a matching request arrives. */
	public static function register(): void {
		Discovery::register();
		Registration::register();
		Token::register();
		Authorize::register();
		Bearer::register();
		SignedInApps::register();

		add_action( 'delete_user', array( Store::class, 'revokeForUser' ) );
		add_action( self::GC_HOOK, array( Store::class, 'gc' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) && ! wp_next_scheduled( self::GC_HOOK ) ) {
			wp_schedule_event( Clock::now() + HOUR_IN_SECONDS, 'daily', self::GC_HOOK );
		}
	}

	/** The one protected resource's identifier: the exact MCP server URL (RFC 8707 §2, MCP auth "Canonical Server URI"). */
	public static function serverUrl(): string {
		return rest_url( Connector::ROUTE_NAMESPACE . '/' . Connector::ROUTE );
	}

	/** Where the browser is sent to authorize. */
	public static function authorizeUrl(): string {
		return admin_url( 'admin-post.php?action=' . self::AUTHORIZE_ACTION );
	}

	/**
	 * Every scope this server issues.
	 *
	 * @return list<string>
	 */
	public static function supportedScopes(): array {
		return array( self::SCOPE_READ, self::SCOPE_WRITE );
	}

	/** 256 bits of randomness, hex. Used for codes and tokens. */
	public static function secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** Tokens and codes are stored only as this hash. */
	public static function hash( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	/** Base64url without padding (RFC 7636 appendix A). */
	public static function base64url( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 encoding, not obfuscation.
	}

	/**
	 * The scopes in a space-separated string that this server knows, deduplicated, order kept.
	 *
	 * @param string $scope Space-separated scopes.
	 * @return list<string>
	 */
	public static function knownScopes( string $scope ): array {
		$out = array();
		foreach ( self::words( $scope ) as $one ) {
			if ( in_array( $one, self::supportedScopes(), true ) && ! in_array( $one, $out, true ) ) {
				$out[] = $one;
			}
		}

		return $out;
	}

	/**
	 * Whitespace-separated words.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	public static function words( string $text ): array {
		$parts = preg_split( '/\s+/', trim( $text ) );

		return false === $parts ? array() : $parts;
	}

	/** Does this granted scope string allow writes? */
	public static function allowsWrite( string $scope ): bool {
		return in_array( self::SCOPE_WRITE, self::knownScopes( $scope ), true );
	}

	/** The client address as PHP saw it. Proxies: override with the `specflux_site_agent_oauth_client_ip` filter. */
	public static function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/** Filters the address DCR rate limits use (only filter this behind a trusted reverse proxy). `@internal`. */
		$ip = apply_filters( 'specflux_site_agent_oauth_client_ip', $ip );

		return is_string( $ip ) ? $ip : '';
	}
}
