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
// Entry-point knobs: senroflux_test_is_admin, senroflux_test_doing_ajax, senroflux_test_doing_cron (globals).
if ( ! function_exists( 'is_admin' ) ) {
	/** Test knob. */
	function is_admin(): bool {
		return ! empty( $GLOBALS['senroflux_test_is_admin'] );
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/** Test knob. */
	function wp_doing_ajax(): bool {
		return ! empty( $GLOBALS['senroflux_test_doing_ajax'] );
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	/** Test knob. */
	function wp_doing_cron(): bool {
		return ! empty( $GLOBALS['senroflux_test_doing_cron'] );
	}
}
if ( ! function_exists( 'wp_normalize_path' ) ) {
	/** Forward slashes, collapsed. */
	function wp_normalize_path( string $path ): string {
		return preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', $path ) );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	/** Exactly one trailing slash. */
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
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
if ( ! function_exists( 'wp_get_current_user' ) ) {
	/** Not used by the OAuth code under test. */
	function wp_get_current_user(): object {
		return (object) array( 'ID' => get_current_user_id() );
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
