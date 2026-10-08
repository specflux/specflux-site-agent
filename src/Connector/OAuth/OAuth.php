<?php
/**
 * The OAuth 2.1 authorization server for the SenroFlux connector: wiring and shared helpers.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Lets Claude.ai, ChatGPT and other OAuth-only MCP clients sign in to the
 * connector. It is an authorization server for exactly one resource: the
 * SenroFlux MCP route. Tokens never authenticate any other route
 * ({@see Bearer}); everything fails closed.
 *
 * Specs followed (cited at the rule they produce): MCP authorization
 * 2025-11-25 ("MCP auth"), RFC 9728 (protected resource metadata), RFC 8414
 * (authorization server metadata), RFC 7591 (DCR), RFC 8707 (resource
 * indicators), RFC 7636 (PKCE), RFC 6749/6750, OAuth 2.1 draft-13.
 */
final class OAuth {

	public const SCOPE_READ  = 'senroflux:read';
	public const SCOPE_WRITE = 'senroflux:write';

	/** REST namespace of the registration/token/metadata endpoints. */
	public const REST_NAMESPACE = 'senroflux/oauth';

	/** admin-post action of the authorize + consent page. */
	public const AUTHORIZE_ACTION = 'senroflux_oauth_authorize';

	public const CODE_TTL    = 60;
	public const ACCESS_TTL  = HOUR_IN_SECONDS;
	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/** Cron hook that prunes grants whose refresh token has expired. */
	public const GC_HOOK = 'senroflux_oauth_gc';

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

	/** The client address as PHP saw it. Proxies: override with the `senroflux_oauth_client_ip` filter. */
	public static function clientIp(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/** Filters the address DCR rate limits use (only filter this behind a trusted reverse proxy). `@internal`. */
		$ip = apply_filters( 'senroflux_oauth_client_ip', $ip );

		return is_string( $ip ) ? $ip : '';
	}
}
