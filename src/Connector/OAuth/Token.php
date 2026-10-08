<?php
/**
 * The token endpoint.
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
 * `POST /wp-json/senroflux/oauth/token`. Grants: `authorization_code` (PKCE
 * S256) and `refresh_token` (rotating, family-revoking on reuse). No
 * `client_credentials`. Only lookups by hash; no remote calls.
 */
final class Token {

	private const VERIFIER_PATTERN = '/^[A-Za-z0-9\-._~]{43,128}$/';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'registerRoute' ) );
	}

	public static function registerRoute(): void {
		register_rest_route(
			OAuth::REST_NAMESPACE,
			'/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST adapter: form-urlencoded and JSON bodies are both accepted.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function rest( $request ): mixed {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : (array) $request->get_body_params();

		return Http::toResponse( self::handle( $params ) );
	}

	/**
	 * The endpoint's logic.
	 *
	 * @param array<string,mixed> $params Token request parameters.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function handle( array $params ): array {
		if ( ! Connector::enabled() ) {
			return Http::error( 'not_found', 404 );
		}
		// Scalars only: an array-valued parameter is never legitimate and is a common type-juggling probe.
		foreach ( $params as $value ) {
			if ( ! is_string( $value ) ) {
				return Http::error( 'invalid_request' );
			}
		}
		$type = $params['grant_type'] ?? '';
		if ( 'authorization_code' === $type ) {
			return self::authorizationCode( $params );
		}
		if ( 'refresh_token' === $type ) {
			return self::refresh( $params );
		}

		return Http::error( '' === $type ? 'invalid_request' : 'unsupported_grant_type' );
	}

	/**
	 * @param array<string,string> $p Parameters.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function authorizationCode( array $p ): array {
		$code      = $p['code'] ?? '';
		$verifier  = $p['code_verifier'] ?? '';
		$client_id = $p['client_id'] ?? '';
		$redirect  = $p['redirect_uri'] ?? '';
		if ( '' === $code || '' === $client_id || '' === $redirect || '' === $verifier ) {
			return Http::error( 'invalid_request' );
		}
		// RFC 7636 §4.1: the verifier is 43-128 unreserved characters.
		if ( 1 !== preg_match( self::VERIFIER_PATTERN, $verifier ) ) {
			return Http::error( 'invalid_grant' );
		}

		$data = Store::consumeCode( $code );
		if ( null === $data ) {
			// A code redeemed twice means it leaked: revoke what the first redemption issued (RFC 6749 §4.1.2).
			$grant = Store::grantOfUsedCode( $code );
			if ( null !== $grant ) {
				Store::revokeGrant( $grant );
			}

			return Http::error( 'invalid_grant' );
		}

		if (
			(int) ( $data['exp'] ?? 0 ) < Clock::now()
			|| ! hash_equals( (string) $data['client_id'], $client_id )
			|| ! hash_equals( (string) $data['redirect_uri'], $redirect )
			// PKCE S256, constant-time (RFC 7636 §4.6).
			|| ! hash_equals( (string) $data['challenge'], OAuth::base64url( hash( 'sha256', $verifier, true ) ) )
		) {
			return Http::error( 'invalid_grant' );
		}
		if ( isset( $p['resource'] ) && $p['resource'] !== (string) $data['resource'] ) {
			return Http::error( 'invalid_target' );
		}
		if ( ! hash_equals( OAuth::serverUrl(), (string) $data['resource'] ) ) {
			return Http::error( 'invalid_target' );
		}
		$user = (int) $data['user'];
		if ( ! user_can( $user, Connector::capability() ) ) {
			return Http::error( 'invalid_grant' );
		}

		$refresh = OAuth::secret();
		$grant   = Store::createGrant( $user, $client_id, (string) $data['scope'], (string) $data['resource'], OAuth::hash( $refresh ) );
		Store::putRefresh( OAuth::hash( $refresh ), $grant, Clock::now() + OAuth::REFRESH_TTL );
		Store::markCodeUsed( $code, $grant );

		return self::issue( $grant, (string) $data['scope'], $refresh );
	}

	/**
	 * @param array<string,string> $p Parameters.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function refresh( array $p ): array {
		$token     = $p['refresh_token'] ?? '';
		$client_id = $p['client_id'] ?? '';
		if ( '' === $token || '' === $client_id ) {
			return Http::error( 'invalid_request' );
		}
		if ( isset( $p['resource'] ) && ! hash_equals( OAuth::serverUrl(), $p['resource'] ) ) {
			return Http::error( 'invalid_target' );
		}

		// Look before consuming: a token presented by the wrong client, or asking for a bad scope,
		// must not burn the real client's token.
		$scope = null;
		$peek  = Store::peekRefresh( $token );
		if ( null !== $peek ) {
			$grant = Store::grant( $peek['g'] );
			if ( null === $grant || ! hash_equals( (string) $grant['client'], $client_id ) ) {
				return Http::error( 'invalid_grant' );
			}
			$scope = (string) $grant['scope'];
			// A refresh may narrow the scope, never widen it (RFC 6749 §6).
			if ( isset( $p['scope'] ) && '' !== $p['scope'] ) {
				$asked   = array_values( array_filter( OAuth::words( $p['scope'] ) ) );
				$allowed = OAuth::knownScopes( $scope );
				if ( array() === $asked || array() !== array_diff( $asked, $allowed ) ) {
					return Http::error( 'invalid_scope' );
				}
				$scope = implode( ' ', array_unique( $asked ) );
			}
		}

		$claim = Store::claimRefresh( $token );
		if ( 'reuse' === $claim['status'] ) {
			// A rotated token came back: either it was stolen or the client lost state. Kill the whole family (OAuth 2.1 §4.3.1).
			if ( null !== $claim['grant'] ) {
				Store::revokeGrant( $claim['grant'] );
			}

			return Http::error( 'invalid_grant' );
		}
		if ( 'ok' !== $claim['status'] || null === $claim['grant'] ) {
			return Http::error( 'invalid_grant' );
		}

		$grant_id = $claim['grant'];
		$grant    = Store::grant( $grant_id );
		if ( null === $grant || ! hash_equals( (string) $grant['client'], $client_id ) || ! hash_equals( OAuth::serverUrl(), (string) $grant['resource'] ) ) {
			return Http::error( 'invalid_grant' );
		}
		if ( ! user_can( (int) $grant['user'], Connector::capability() ) ) {
			Store::revokeGrant( $grant_id );

			return Http::error( 'invalid_grant' );
		}

		$scope = $scope ?? (string) $grant['scope'];

		$new = OAuth::secret();
		Store::rotate( $grant_id, OAuth::hash( $token ), OAuth::hash( $new ) );

		return self::issue( $grant_id, $scope, $new );
	}

	/**
	 * Mint an access token for a grant and build the RFC 6749 §5.1 response.
	 *
	 * @param string $grant_id Grant.
	 * @param string $scope    Scope carried by this access token.
	 * @param string $refresh  Plaintext refresh token to hand over.
	 * @return array{status:int,body:array<string,mixed>}
	 */
	private static function issue( string $grant_id, string $scope, string $refresh ): array {
		$access = OAuth::secret();
		Store::putAccess( OAuth::hash( $access ), $grant_id, $scope, Clock::now() + OAuth::ACCESS_TTL );

		return array(
			'status' => 200,
			'body'   => array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => OAuth::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => $scope,
			),
		);
	}
}
