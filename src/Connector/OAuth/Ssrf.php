<?php
/**
 * SSRF guard for fetching client metadata documents.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The authorization server fetches a URL chosen by an unknown client (MCP auth
 * "Authorization Server Abuse Protection"; client-id-metadata-document §6).
 * A URL is fetchable only if it is https on the default port, has no userinfo,
 * and every address its host resolves to is public. WordPress's own
 * `reject_unsafe_urls` is also set on the request; this guard is stricter and
 * is the one the tests pin.
 *
 * Known gap: DNS can change between this check and the connection (rebinding).
 * The fetch carries no cookies or credentials and its response is only parsed
 * as a small JSON document, which bounds what a rebind could leak.
 */
final class Ssrf {

	/** @var (callable(string):list<string>)|null Test seam: host => addresses. */
	private static $resolver = null;

	/**
	 * Test seam: replace DNS resolution.
	 *
	 * @param (callable(string):list<string>)|null $resolver Resolver or null for real DNS.
	 */
	public static function setResolver( ?callable $resolver ): void {
		self::$resolver = $resolver;
	}

	/** May the server fetch this URL? */
	public static function allowed( string $url ): bool {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || ! isset( $parts['host'] ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) {
			return false;
		}
		$host = trim( $parts['host'], '[]' );
		if ( '' === $host || 'localhost' === strtolower( $host ) || str_ends_with( strtolower( $host ), '.localhost' ) || str_ends_with( strtolower( $host ), '.local' ) || str_ends_with( strtolower( $host ), '.internal' ) ) {
			return false;
		}

		$addresses = false !== filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve( $host );
		if ( array() === $addresses ) {
			return false;
		}
		foreach ( $addresses as $address ) {
			if ( ! self::publicAddress( $address ) ) {
				return false;
			}
		}

		return true;
	}

	/** Is this IP globally routable (not private, loopback, link-local, reserved)? */
	public static function publicAddress( string $ip ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		// IPv4-mapped IPv6 (::ffff:10.0.0.1) must be judged as the IPv4 address.
		if ( 1 === preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) ) {
			$ip = $m[1];
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		// Ranges PHP's flags miss: carrier-grade NAT, benchmarking, and IPv6 ULA/link-local.
		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );

			return false !== $long && ! self::inV4( $long, '100.64.0.0', 10 ) && ! self::inV4( $long, '198.18.0.0', 15 ) && ! self::inV4( $long, '169.254.0.0', 16 ) && ! self::inV4( $long, '0.0.0.0', 8 );
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}
		$first = ord( $packed[0] );
		$next  = ord( $packed[1] );

		return 0xfc !== ( $first & 0xfe ) && ! ( 0xfe === $first && 0x80 === ( $next & 0xc0 ) );
	}

	private static function inV4( int $ip, string $base, int $bits ): bool {
		$mask = -1 << ( 32 - $bits );

		return ( $ip & $mask ) === ( (int) ip2long( $base ) & $mask );
	}

	/**
	 * Every A/AAAA address of a host; empty when it does not resolve.
	 *
	 * @param string $host Hostname.
	 * @return list<string>
	 */
	private static function resolve( string $host ): array {
		if ( null !== self::$resolver ) {
			return array_values( ( self::$resolver )( $host ) );
		}
		$out = array();
		foreach ( (array) dns_get_record( $host, DNS_A | DNS_AAAA ) as $record ) {
			$ip = $record['ip'] ?? $record['ipv6'] ?? null;
			if ( is_string( $ip ) ) {
				$out[] = $ip;
			}
		}

		return $out;
	}
}
