<?php
/**
 * The config an AI app needs to reach the connector (K3).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Connector;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Pure rendering: given the server URL and a credential, the exact text to
 * paste into each supported app. Nothing here stores or logs the password.
 * Each shape below was checked against the vendor's own documentation; the
 * URL beside each template is where.
 */
final class ClientConfig {

	public const CLAUDE_CODE    = 'claude-code';
	public const CURSOR         = 'cursor';
	public const VS_CODE        = 'vscode';
	public const CLAUDE_DESKTOP = 'claude-desktop';
	public const OTHER          = 'other';

	/** The key the server is filed under in each app's config. */
	public const SERVER_NAME = 'specflux';

	/** @return list<string> Client ids that take an application-password connection. */
	public static function clients(): array {
		return array( self::CLAUDE_CODE, self::CURSOR, self::VS_CODE, self::CLAUDE_DESKTOP, self::OTHER );
	}

	/** Is this one of the clients above? */
	public static function known( string $client ): bool {
		return in_array( $client, self::clients(), true );
	}

	/** The HTTP Basic header value: base64 of `login:password`, spaces removed (core accepts the password with or without its display spaces). */
	public static function authorization( string $login, string $password ): string {
		return 'Basic ' . base64_encode( $login . ':' . preg_replace( '/\s+/', '', $password ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, not obfuscation.
	}

	/**
	 * The config for one client.
	 *
	 * @param string $client   A {@see clients()} id.
	 * @param string $url      The server URL.
	 * @param string $login    The WordPress user login.
	 * @param string $password The application password (plain; shown once, never kept).
	 * @return array{format:string,where:string,text:string}
	 */
	public static function render( string $client, string $url, string $login, string $password ): array {
		$auth = self::authorization( $login, $password );

		switch ( $client ) {
			case self::CLAUDE_CODE:
				// https://code.claude.com/docs/en/mcp ("claude mcp add --transport http <name> <url>" with "--header").
				return array(
					'format' => 'shell',
					'where'  => __( 'Run this in a terminal.', 'specflux-site-agent' ),
					'text'   => sprintf(
						'claude mcp add --transport http %s %s --header %s',
						self::SERVER_NAME,
						self::quote( $url ),
						self::quote( 'Authorization: ' . $auth )
					),
				);

			case self::CURSOR:
				// https://cursor.com/docs/context/mcp (remote server: "url" and "headers" under "mcpServers").
				return array(
					'format' => 'json',
					'where'  => __( 'Add this to .cursor/mcp.json in your project, or ~/.cursor/mcp.json for every project.', 'specflux-site-agent' ),
					'text'   => self::json(
						array(
							'mcpServers' => array(
								self::SERVER_NAME => array(
									'url'     => $url,
									'headers' => array( 'Authorization' => $auth ),
								),
							),
						)
					),
				);

			case self::VS_CODE:
				// https://code.visualstudio.com/docs/agents/reference/mcp-configuration ("servers" with "type": "http", "url", "headers").
				return array(
					'format' => 'json',
					'where'  => __( 'Add this to .vscode/mcp.json in your workspace.', 'specflux-site-agent' ),
					'text'   => self::json(
						array(
							'servers' => array(
								self::SERVER_NAME => array(
									'type'    => 'http',
									'url'     => $url,
									'headers' => array( 'Authorization' => $auth ),
								),
							),
						)
					),
				);

			case self::CLAUDE_DESKTOP:
				// https://github.com/Automattic/mcp-wordpress-remote (README: any full server URL as WP_API_URL;
				// application-password mode via WP_API_USERNAME / WP_API_PASSWORD with OAUTH_ENABLED "false").
				return array(
					'format' => 'json',
					'where'  => __( 'Add this to claude_desktop_config.json, then restart Claude Desktop. It needs Node.js installed.', 'specflux-site-agent' ),
					'text'   => self::json(
						array(
							'mcpServers' => array(
								self::SERVER_NAME => array(
									'command' => 'npx',
									'args'    => array( '-y', '@automattic/mcp-wordpress-remote' ),
									'env'     => array(
										'WP_API_URL'      => $url,
										'WP_API_USERNAME' => $login,
										'WP_API_PASSWORD' => preg_replace( '/\s+/', '', $password ),
										'OAUTH_ENABLED'   => 'false',
									),
								),
							),
						)
					),
				);

			default:
				return array(
					'format' => 'text',
					'where'  => __( 'Use the server URL and send this header with every request.', 'specflux-site-agent' ),
					'text'   => "URL: {$url}\nHeader: Authorization: {$auth}",
				);
		}
	}

	/**
	 * Pretty JSON with slashes left alone, as a person would write it.
	 *
	 * @param array<string,mixed> $value The config.
	 */
	private static function json( array $value ): string {
		return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/** Single-quote for a POSIX shell. */
	private static function quote( string $value ): string {
		return "'" . str_replace( "'", "'\\''", $value ) . "'";
	}
}
