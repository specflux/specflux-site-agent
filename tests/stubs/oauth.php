<?php
/**
 * WordPress shims the OAuth suites need beyond tests/bootstrap.php (function_exists-guarded).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

require_once __DIR__ . '/stock-image.php'; // wp_remote_get queue + retrieve helpers.

if ( ! function_exists( 'wp_parse_url' ) ) {
	/** Plain parse_url. */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/** Test site root. */
	function home_url( string $path = '' ): string {
		return 'http://example.test' . $path;
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	/** Strip trailing slashes. */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}
if ( ! function_exists( 'status_header' ) ) {
	/** No-op. */
	function status_header( int $code ): void {
		unset( $code );
	}
}
if ( ! function_exists( 'wp_login_url' ) ) {
	/** Deterministic login URL. */
	function wp_login_url( string $redirect = '' ): string {
		return 'http://example.test/wp-login.php?redirect_to=' . rawurlencode( $redirect );
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	/** Identity. */
	function rest_ensure_response( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	/** Recording shim. */
	function register_rest_route( string $rest_namespace, string $route, array $args = array() ): bool {
		$GLOBALS['senroflux_test_rest_routes'][ $rest_namespace . $route ] = $args;

		return true;
	}
}

if ( ! class_exists( 'SenroFlux_Test_Header_Response', false ) ) {
	/** REST response double that records headers. */
	class SenroFlux_Test_Header_Response {

		/** @var array<string,string> */
		public array $headers = array();

		public function __construct( private int $status ) {
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}
	}
}
if ( ! class_exists( 'SenroFlux_Test_Route_Request', false ) ) {
	/** REST request double that knows only its route. */
	class SenroFlux_Test_Route_Request {

		public function __construct( private string $route ) {
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}
