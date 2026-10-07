<?php
/**
 * An in-process call to FluentCart's own REST routes (C4-F).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms\FluentCart;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * `rest_do_request()` against `/fluent-cart/...` as the current user.
 *
 * Going through the route (not FluentCart's Resource classes) means
 * FluentCart's own policy and permission checks run for free, and a private
 * API move cannot break us without the route breaking too. Tests swap the
 * dispatcher with {@see self::useDispatcher()}; production never does.
 *
 * @internal
 */
final class FluentCartRest {

	/** FluentCart's REST namespace (`config/app.php` `rest_namespace`). */
	public const NAMESPACE = '/fluent-cart';

	/** @var (callable(string,string,array<string,mixed>):mixed)|null */
	private static $dispatcher = null;

	/**
	 * Test seam: replace the dispatcher. `fn( method, route, params ): array|WP_Error`.
	 *
	 * @param (callable(string,string,array<string,mixed>):mixed)|null $dispatcher Dispatcher, or null to restore.
	 */
	public static function useDispatcher( ?callable $dispatcher ): void {
		self::$dispatcher = $dispatcher;
	}

	/**
	 * @param string              $method HTTP method.
	 * @param string              $route  Route under the FluentCart namespace, leading slash, e.g. `/products`.
	 * @param array<string,mixed> $params Query params for GET, body params otherwise.
	 * @return array<string,mixed>|WP_Error The response data, or the failure.
	 */
	public static function call( string $method, string $route, array $params = array() ): array|WP_Error {
		if ( null !== self::$dispatcher ) {
			$result = ( self::$dispatcher )( $method, $route, $params );

			return ( $result instanceof WP_Error || is_array( $result ) ) ? $result : array();
		}

		if ( ! function_exists( 'rest_do_request' ) || ! class_exists( '\WP_REST_Request' ) ) {
			return new WP_Error( 'gateway_unavailable', __( 'FluentCart is not reachable.', 'senroflux' ), array( 'status' => 400 ) );
		}

		$request = new \WP_REST_Request( strtoupper( $method ), self::NAMESPACE . $route );
		if ( 'GET' === strtoupper( $method ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$status   = $response->get_status();

		if ( $status >= 400 ) {
			$code    = is_array( $data ) && is_string( $data['code'] ?? null ) ? $data['code'] : 'fluentcart_rest_error';
			$message = is_array( $data ) && is_string( $data['message'] ?? null ) && '' !== $data['message']
				? $data['message']
				: __( 'FluentCart refused the request.', 'senroflux' );

			return new WP_Error( $code, $message, array( 'status' => $status ) );
		}

		return is_array( $data ) ? $data : array();
	}
}
