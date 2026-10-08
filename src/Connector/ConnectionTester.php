<?php
/**
 * The "Test connection" loopback check (K3).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Calls this site's own connector URL the way an AI app would: `initialize`,
 * then `tools/list`, with the new credential. Reports the tool count, or one
 * failure category an admin can act on. The credential is used for the two
 * requests and kept nowhere.
 */
final class ConnectionTester {

	public const OK         = 'ok';
	public const AUTH       = 'auth';
	public const NOT_FOUND  = 'not_found';
	public const CONNECTION = 'connection';
	public const PROTOCOL   = 'protocol';

	/** The MCP revision asked for; the adapter's own negotiation settles the rest. */
	private const PROTOCOL_VERSION = '2025-06-18';

	/**
	 * Run the check.
	 *
	 * @param string $url           The server URL.
	 * @param string $authorization The Authorization header value.
	 * @return array{ok:bool,category:string,message:string,tools:int}
	 */
	public static function run( string $url, string $authorization ): array {
		$headers = array(
			'Authorization' => $authorization,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json, text/event-stream',
		);

		$init   = self::post(
			$url,
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => self::PROTOCOL_VERSION,
					'capabilities'    => new \stdClass(),
					'clientInfo'      => array(
						'name'    => 'senroflux-connection-test',
						'version' => '1',
					),
				),
			)
		);
		$failed = self::classify( $init );
		if ( ! $failed['ok'] ) {
			return $failed;
		}

		// A session id and protocol version, when the server issued them, ride on the next call.
		$session = wp_remote_retrieve_header( $init, 'mcp-session-id' );
		if ( is_string( $session ) && '' !== $session ) {
			$headers['Mcp-Session-Id'] = $session;
		}
		$body    = json_decode( wp_remote_retrieve_body( $init ), true );
		$version = is_array( $body ) && is_array( $body['result'] ?? null ) ? ( $body['result']['protocolVersion'] ?? null ) : null;
		if ( is_string( $version ) && '' !== $version ) {
			$headers['MCP-Protocol-Version'] = $version;
		}

		return self::classify(
			self::post(
				$url,
				$headers,
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
				)
			),
			true
		);
	}

	/**
	 * Turn one HTTP response into a result.
	 *
	 * @param mixed $response    A wp_remote_post() return.
	 * @param bool  $count_tools True for the tools/list reply: count `result.tools`.
	 * @return array{ok:bool,category:string,message:string,tools:int}
	 */
	public static function classify( mixed $response, bool $count_tools = false ): array {
		if ( $response instanceof WP_Error ) {
			return self::result(
				self::CONNECTION,
				sprintf(
				/* translators: %s: the HTTP error text. */
					__( 'This site could not reach its own address (%s). A firewall, a loopback block or a wrong site URL can cause this.', 'senroflux' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return self::result( self::AUTH, __( 'The site refused the credential (401/403). The connector may be switched off, the application password may have been removed, or the account may lack permission.', 'senroflux' ) );
		}
		if ( 404 === $code ) {
			return self::result( self::NOT_FOUND, __( 'The connector address was not found (404). Check that permalinks are not set to Plain and that the MCP Adapter plugin is active.', 'senroflux' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return self::result(
				self::PROTOCOL,
				sprintf(
				/* translators: %d: HTTP status. */
					__( 'The server answered with HTTP %d.', 'senroflux' ),
					$code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || isset( $body['error'] ) || ! is_array( $body['result'] ?? null ) ) {
			return self::result( self::PROTOCOL, __( 'The server answered, but not with a valid MCP reply.', 'senroflux' ) );
		}

		if ( ! $count_tools ) {
			return self::result( self::OK, '' );
		}

		$tools = is_array( $body['result']['tools'] ?? null ) ? count( $body['result']['tools'] ) : 0;

		return array(
			'ok'       => true,
			'category' => self::OK,
			'message'  => sprintf(
				/* translators: %d: number of tools. */
				_n( 'Connected. %d tool is available to this connection.', 'Connected. %d tools are available to this connection.', $tools, 'senroflux' ),
				$tools
			),
			'tools'    => $tools,
		);
	}

	/**
	 * One JSON-RPC POST to this site.
	 *
	 * @param string               $url     Server URL.
	 * @param array<string,string> $headers Request headers.
	 * @param array<string,mixed>  $payload JSON-RPC message.
	 */
	private static function post( string $url, array $headers, array $payload ): mixed {
		return wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => (string) wp_json_encode( $payload ),
			)
		);
	}

	/**
	 * Build a non-success result.
	 *
	 * @return array{ok:bool,category:string,message:string,tools:int}
	 */
	private static function result( string $category, string $message ): array {
		return array(
			'ok'       => self::OK === $category,
			'category' => $category,
			'message'  => $message,
			'tools'    => 0,
		);
	}
}
