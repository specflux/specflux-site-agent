<?php
/**
 * Small shared response helpers for the JSON endpoints.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector\OAuth;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Errors per RFC 6749 §5.2 / RFC 7591 §3.2.2: JSON `error` + a generic description, never echoing input. */
final class Http {

	private const DESCRIPTIONS = array(
		'invalid_request'         => 'The request is missing or has an invalid parameter.',
		'invalid_client'          => 'Unknown client.',
		'invalid_grant'           => 'The grant is invalid, expired or already used.',
		'unauthorized_client'     => 'The client may not use this grant.',
		'unsupported_grant_type'  => 'Unsupported grant type.',
		'invalid_scope'           => 'Invalid scope.',
		'invalid_target'          => 'Invalid resource.',
		'invalid_redirect_uri'    => 'Invalid redirect URI.',
		'invalid_client_metadata' => 'Invalid client metadata.',
		'rate_limited'            => 'Too many requests.',
		'temporarily_unavailable' => 'Temporarily unavailable.',
		'not_found'               => 'Not found.',
	);

	/**
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 * @return array{status:int,body:array<string,string>}
	 */
	public static function error( string $code, int $status = 400 ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'error'             => $code,
				'error_description' => self::DESCRIPTIONS[ $code ] ?? 'Request failed.',
			),
		);
	}

	/**
	 * Wrap a logic result in a REST response that must never be cached (RFC 6749 §5.1).
	 *
	 * @param array{status:int,body:array<string,mixed>} $result Result.
	 */
	public static function toResponse( array $result ): mixed {
		$response = new \WP_REST_Response( $result['body'], $result['status'] );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
