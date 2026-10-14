<?php
/**
 * Test-only shims for the Connect screen (K3): core's application passwords,
 * and an outbound `wp_remote_post()` that never touches the network.
 *
 * `wp_remote_post()` records each call in `$GLOBALS['specflux_site_agent_test_remote_post_calls']`
 * and pops the next response queued in `$GLOBALS['specflux_site_agent_test_remote_post_responses']`.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'WP_Application_Passwords', false ) ) {
	/** In-memory stand-in with core's method names and return shapes. */
	class WP_Application_Passwords {

		/** @return array<int,array<string,mixed>> */
		public static function get_user_application_passwords( $user_id ) {
			return $GLOBALS['specflux_site_agent_test_app_passwords'][ (int) $user_id ] ?? array();
		}

		/** @return array{0:string,1:array<string,mixed>}|WP_Error */
		public static function create_new_application_password( $user_id, $args = array() ) {
			if ( empty( $args['name'] ) ) {
				return new WP_Error( 'application_password_empty_name', 'An application name is required.' );
			}

			$count = count( self::get_user_application_passwords( $user_id ) ) + 1;
			$item  = array(
				'uuid'      => sprintf( '00000000-0000-4000-8000-%012d', $count ),
				'name'      => $args['name'],
				'created'   => 1_800_000_000,
				'last_used' => null,
			);

			$GLOBALS['specflux_site_agent_test_app_passwords'][ (int) $user_id ][] = $item;

			return array( 'abcd efgh ijkl mnop qrst uvwx', $item );
		}

		public static function delete_application_password( $user_id, $uuid ) {
			foreach ( self::get_user_application_passwords( $user_id ) as $i => $item ) {
				if ( $item['uuid'] === $uuid ) {
					unset( $GLOBALS['specflux_site_agent_test_app_passwords'][ (int) $user_id ][ $i ] );
					$GLOBALS['specflux_site_agent_test_app_passwords'][ (int) $user_id ] = array_values( $GLOBALS['specflux_site_agent_test_app_passwords'][ (int) $user_id ] );

					return true;
				}
			}

			return new WP_Error( 'application_password_not_found', 'Not found.' );
		}
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	/**
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['specflux_site_agent_test_remote_post_calls'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		$queue = &$GLOBALS['specflux_site_agent_test_remote_post_responses'];
		if ( ! is_array( $queue ) || array() === $queue ) {
			return new WP_Error( 'http_request_failed', 'No response queued.' );
		}

		return array_shift( $queue );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/** @param mixed $response A response shim array. */
	function wp_remote_retrieve_header( $response, string $header ): string {
		return is_array( $response ) ? (string) ( $response['headers'][ strtolower( $header ) ] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/** @param mixed $response A response shim array. */
	function wp_remote_retrieve_response_code( $response ): int {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/** @param mixed $response A response shim array. */
	function wp_remote_retrieve_body( $response ): string {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/** Cron shim: `$GLOBALS['specflux_site_agent_test_cron'][hook] = array{time,recurrence}`. */
	function wp_next_scheduled( string $hook ): int|false {
		return $GLOBALS['specflux_site_agent_test_cron'][ $hook ]['time'] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		$GLOBALS['specflux_site_agent_test_cron'][ $hook ] = array(
			'time'       => $timestamp,
			'recurrence' => $recurrence,
		);

		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook ): int {
		$had = isset( $GLOBALS['specflux_site_agent_test_cron'][ $hook ] ) ? 1 : 0;
		unset( $GLOBALS['specflux_site_agent_test_cron'][ $hook ] );

		return $had;
	}
}
