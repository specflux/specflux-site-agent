<?php
/**
 * Client registration, lookup and client ID metadata documents.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

use Specflux\SenroFlux\Run\Clock;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Two ways a client_id becomes known: Dynamic Client Registration (RFC 7591;
 * a random id stored here) or a Client ID Metadata Document (MCP auth "Client
 * ID Metadata Documents": the client_id is an https URL, fetched and cached).
 */
final class Clients {

	/** Cap on stored DCR clients; the oldest unused are pruned to make room. */
	public const MAX_CLIENTS = 500;

	public const MAX_NAME_LENGTH   = 100;
	public const MAX_REDIRECT_URIS = 10;

	/** CIMD: response size cap, fetch timeout and cache lifetimes. */
	public const CIMD_MAX_BYTES    = 65536;
	public const CIMD_TIMEOUT      = 5;
	public const CIMD_CACHE_TTL    = HOUR_IN_SECONDS;
	public const CIMD_FAILURE_TTL  = MINUTE_IN_SECONDS;
	public const CIMD_FETCHES_HOUR = 60;

	/** Resolve a client_id to a client, or null. Never throws. */
	public static function resolve( string $client_id ): ?Client {
		if ( str_starts_with( $client_id, 'https://' ) ) {
			return self::fromDocument( $client_id );
		}
		$stored = Store::client( $client_id );
		if ( null === $stored ) {
			return null;
		}

		return new Client( $stored['id'], $stored['name'], $stored['redirect_uris'] );
	}

	/** A display name for a client id without fetching anything (metadata-document clients show their host). */
	public static function resolveStoredName( string $client_id ): string {
		if ( str_starts_with( $client_id, 'https://' ) ) {
			return (string) wp_parse_url( $client_id, PHP_URL_HOST );
		}
		$stored = Store::client( $client_id );

		return null === $stored ? 'Unknown app' : $stored['name'];
	}

	/**
	 * Register a DCR client. Validation errors use RFC 7591 §3.2.2 codes.
	 *
	 * @param array<string,mixed> $meta Posted client metadata.
	 * @return array{client?:array<string,mixed>,error?:string,status?:int}
	 */
	public static function register( array $meta ): array {
		$uris = $meta['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > self::MAX_REDIRECT_URIS || ! array_is_list( $uris ) ) {
			return self::fail( 'invalid_redirect_uri' );
		}
		foreach ( $uris as $uri ) {
			if ( ! RedirectUri::valid( $uri ) ) {
				return self::fail( 'invalid_redirect_uri' );
			}
		}
		// Public clients only: no secrets are issued, PKCE is the proof (MCP auth "Authorization Code Protection").
		if ( isset( $meta['token_endpoint_auth_method'] ) && 'none' !== $meta['token_endpoint_auth_method'] ) {
			return self::fail( 'invalid_client_metadata' );
		}
		foreach ( array(
			'grant_types'    => array( 'authorization_code', 'refresh_token' ),
			'response_types' => array( 'code' ),
		) as $field => $allowed ) {
			if ( isset( $meta[ $field ] ) && ( ! is_array( $meta[ $field ] ) || array() !== array_diff( $meta[ $field ], $allowed ) ) ) {
				return self::fail( 'invalid_client_metadata' );
			}
		}
		if ( ! self::makeRoom() ) {
			return self::fail( 'temporarily_unavailable', 503 );
		}

		$client = array(
			'id'            => 'snf_' . bin2hex( random_bytes( 16 ) ),
			'name'          => self::cleanName( $meta['client_name'] ?? '' ),
			'redirect_uris' => array_values( array_unique( array_map( 'strval', $uris ) ) ),
			'created'       => Clock::now(),
		);
		Store::saveClient( $client );

		return array( 'client' => $client );
	}

	/** Plain-text, length-capped display name; falls back to a neutral label. */
	public static function cleanName( mixed $name ): string {
		$name = is_string( $name ) ? trim( wp_strip_all_tags( $name ) ) : '';
		$name = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', $name ) );
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::MAX_NAME_LENGTH ) : substr( $name, 0, self::MAX_NAME_LENGTH );

		return '' === $name ? 'Unnamed app' : $name;
	}

	/**
	 * Keep the registry under the cap: drop the oldest clients that hold no grant.
	 *
	 * @return bool False when nothing can be pruned and the registry is full.
	 */
	private static function makeRoom(): bool {
		$index = Store::clientIndex();
		foreach ( array_keys( $index ) as $id ) {
			if ( count( $index ) < self::MAX_CLIENTS ) {
				return true;
			}
			if ( ! Store::clientHasGrants( (string) $id ) ) {
				Store::deleteClient( (string) $id );
				unset( $index[ $id ] );
			}
		}

		return count( $index ) < self::MAX_CLIENTS;
	}

	/**
	 * @param string $error  RFC 7591 error code.
	 * @param int    $status HTTP status.
	 * @return array{error:string,status:int}
	 */
	private static function fail( string $error, int $status = 400 ): array {
		return array(
			'error'  => $error,
			'status' => $status,
		);
	}

	// ---- Client ID Metadata Documents ------------------------------------

	/** Fetch (or load from cache) the metadata document a URL-formatted client_id points at. */
	private static function fromDocument( string $url ): ?Client {
		if ( strlen( $url ) > RedirectUri::MAX_LENGTH || 1 === preg_match( '/[\s\\\\\x00-\x1f\x7f#]/', $url ) ) {
			return null;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		// MCP auth: https scheme AND a path component; no dot segments.
		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) || '/' === $parts['path'] || 1 === preg_match( '#/\.\.?(/|$)#', $parts['path'] ) ) {
			return null;
		}

		$key    = 'senroflux_oauth_cimd_' . OAuth::hash( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['uris'] ) ? new Client( $url, (string) $cached['name'], array_values( $cached['uris'] ), true ) : null;
		}

		$client = self::fetch( $url );
		set_transient(
			$key,
			null === $client ? array( 'failed' => true ) : array(
				'name' => $client->name,
				'uris' => $client->redirectUris,
			),
			null === $client ? self::CIMD_FAILURE_TTL : self::CIMD_CACHE_TTL
		);

		return $client;
	}

	private static function fetch( string $url ): ?Client {
		if ( ! Ssrf::allowed( $url ) || ! self::fetchBudget() ) {
			return null;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::CIMD_TIMEOUT,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::CIMD_MAX_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'SenroFlux OAuth (client metadata fetch)',
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) >= self::CIMD_MAX_BYTES ) {
			return null;
		}
		$doc = json_decode( $body, true );
		// The document must name itself with exactly the URL it was fetched from.
		if ( ! is_array( $doc ) || ( $doc['client_id'] ?? null ) !== $url ) {
			return null;
		}
		$uris = $doc['redirect_uris'] ?? null;
		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > self::MAX_REDIRECT_URIS ) {
			return null;
		}
		foreach ( $uris as $uri ) {
			if ( ! RedirectUri::valid( $uri ) ) {
				return null;
			}
		}
		// Public clients only. A document that prefers client authentication but also supports none
		// (ChatGPT's) is taken as public: discovery advertises none only, and PKCE is always required.
		$supported = $doc['token_endpoint_auth_methods_supported'] ?? null;
		if ( isset( $doc['token_endpoint_auth_method'] ) && 'none' !== $doc['token_endpoint_auth_method']
			&& ! ( is_array( $supported ) && in_array( 'none', $supported, true ) ) ) {
			return null;
		}
		$name = self::cleanName( $doc['client_name'] ?? '' );
		if ( 'Unnamed app' === $name ) {
			$name = (string) wp_parse_url( $url, PHP_URL_HOST );
		}

		return new Client( $url, $name, array_values( array_map( 'strval', $uris ) ), true );
	}

	/** A global hourly ceiling on outbound metadata fetches, so the endpoint cannot be used as a request cannon. */
	private static function fetchBudget(): bool {
		return RateLimit::hit( 'cimd-fetch', self::CIMD_FETCHES_HOUR, HOUR_IN_SECONDS );
	}
}
