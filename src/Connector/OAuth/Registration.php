<?php
/**
 * Dynamic Client Registration endpoint (RFC 7591).
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
 * `POST /wp-json/senroflux/oauth/register`. Open by design (RFC 7591 open
 * registration is what lets Claude and ChatGPT connect with no setup), so it
 * is contained instead: behind the connector switch, rate limited per IP,
 * strict redirect URI validation, a hard cap on stored clients, and a client
 * can do nothing until a logged-in administrator approves it on the consent
 * screen.
 */
final class Registration {

	/** Registrations allowed per IP per hour. */
	public const LIMIT_PER_HOUR = 10;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoute' ) );
	}

	public static function registerRoute(): void {
		register_rest_route(
			OAuth::REST_NAMESPACE,
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST adapter.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest( $request ): mixed {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : (array) $request->get_body_params();

		return Http::toResponse( self::handle( $params, OAuth::clientIp() ) );
	}

	/**
	 * The endpoint's logic.
	 *
	 * @param array<string,mixed> $params Client metadata.
	 * @param string              $ip     Caller address (rate limit key).
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function handle( array $params, string $ip ): array {
		if ( ! Connector::enabled() ) {
			return Http::error( 'not_found', 404 );
		}
		if ( ! RateLimit::hit( 'dcr:' . $ip, self::LIMIT_PER_HOUR, HOUR_IN_SECONDS ) ) {
			return Http::error( 'rate_limited', 429 );
		}

		$result = Clients::register( $params );
		if ( ! isset( $result['client'] ) ) {
			return Http::error( (string) ( $result['error'] ?? 'invalid_client_metadata' ), (int) ( $result['status'] ?? 400 ) );
		}
		$client = $result['client'];

		return array(
			'status' => 201,
			'body'   => array(
				'client_id'                  => $client['id'],
				'client_id_issued_at'        => Clock::now(),
				'client_name'                => $client['name'],
				'redirect_uris'              => $client['redirect_uris'],
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			),
		);
	}
}
