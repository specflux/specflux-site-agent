<?php
/**
 * Redirect URI rules.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * MCP auth "Communication Security": every redirect URI is `https`, or a
 * loopback `http` URI (OAuth 2.1 §8.4.2: `localhost`, `127.0.0.1`, `[::1]`,
 * any port). No fragments (RFC 6749 §3.1.2), no userinfo, no whitespace,
 * control characters or backslashes (browsers read `\` as `/`, which turns a
 * "registered" host into a different one). Compared as exact strings.
 */
final class RedirectUri {

	public const MAX_LENGTH = 512;

	private const LOOPBACK = array( 'localhost', '127.0.0.1', '[::1]' );

	/** May this string be registered / presented as a redirect URI? */
	public static function valid( mixed $uri ): bool {
		if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > self::MAX_LENGTH ) {
			return false;
		}
		if ( 1 === preg_match( '/[\s\\\\\x00-\x1f\x7f#]/', $uri ) ) {
			return false;
		}
		$parts = parse_url( $uri ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- need the scheme-agnostic behaviour and no WP dependency.
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( 'https' === $scheme ) {
			return true;
		}

		return 'http' === $scheme && in_array( $host, self::LOOPBACK, true );
	}

	/** Is this a loopback (same-machine) redirect? */
	public static function isLoopback( string $uri ): bool {
		$host = strtolower( (string) parse_url( $uri, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		return in_array( $host, self::LOOPBACK, true );
	}

	/** The host (and port) to show the person approving. */
	public static function displayHost( string $uri ): string {
		$parts = parse_url( $uri ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}

		return $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
