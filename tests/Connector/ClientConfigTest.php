<?php
/**
 * The per-app config text: shapes match each vendor's documented keys.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Connector\ClientConfig;

final class ClientConfigTest extends TestCase {

	private const URL      = 'https://example.test/wp-json/specflux-site-agent/mcp';
	private const PASSWORD = 'abcd efgh ijkl mnop qrst uvwx';
	private const AUTH     = 'Basic YWRtaW46YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4'; // admin:abcdefghijklmnopqrstuvwx

	/** @return array<string,mixed> */
	private function json( string $client ): array {
		$config = ClientConfig::render( $client, self::URL, 'admin', self::PASSWORD );
		$this->assertSame( 'json', $config['format'] );
		$decoded = json_decode( $config['text'], true );
		$this->assertIsArray( $decoded );

		return $decoded;
	}

	public function test_the_basic_header_drops_the_display_spaces(): void {
		$this->assertSame( self::AUTH, ClientConfig::authorization( 'admin', self::PASSWORD ) );
		$this->assertSame( self::AUTH, ClientConfig::authorization( 'admin', 'abcdefghijklmnopqrstuvwx' ) );
	}

	public function test_cursor_has_url_and_headers_under_mcp_servers(): void {
		$config = $this->json( ClientConfig::CURSOR );

		$this->assertSame( array( 'mcpServers' ), array_keys( $config ) );
		$server = $config['mcpServers']['specflux'];
		$this->assertSame( array( 'url', 'headers' ), array_keys( $server ) );
		$this->assertSame( self::URL, $server['url'] );
		$this->assertSame( array( 'Authorization' => self::AUTH ), $server['headers'] );
	}

	public function test_vscode_has_http_type_url_and_headers_under_servers(): void {
		$config = $this->json( ClientConfig::VS_CODE );

		$this->assertSame( array( 'servers' ), array_keys( $config ) );
		$server = $config['servers']['specflux-site-agent'];
		$this->assertSame( array( 'type', 'url', 'headers' ), array_keys( $server ) );
		$this->assertSame( 'http', $server['type'] );
		$this->assertSame( array( 'Authorization' => self::AUTH ), $server['headers'] );
	}

	public function test_claude_desktop_runs_the_automattic_proxy_with_app_password_env(): void {
		$server = $this->json( ClientConfig::CLAUDE_DESKTOP )['mcpServers']['specflux'];

		$this->assertSame( array( 'command', 'args', 'env' ), array_keys( $server ) );
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame( array( '-y', '@automattic/mcp-wordpress-remote' ), $server['args'] );
		$this->assertSame(
			array(
				'WP_API_URL'      => self::URL,
				'WP_API_USERNAME' => 'admin',
				'WP_API_PASSWORD' => 'abcdefghijklmnopqrstuvwx',
				'OAUTH_ENABLED'   => 'false',
			),
			$server['env']
		);
	}

	public function test_claude_code_is_one_add_command_with_the_header(): void {
		$config = ClientConfig::render( ClientConfig::CLAUDE_CODE, self::URL, 'admin', self::PASSWORD );

		$this->assertSame( 'shell', $config['format'] );
		$this->assertSame(
			"claude mcp add --transport http specflux 'https://example.test/wp-json/specflux-site-agent/mcp' --header 'Authorization: " . self::AUTH . "'",
			$config['text']
		);
	}

	public function test_other_gives_the_url_and_header(): void {
		$config = ClientConfig::render( ClientConfig::OTHER, self::URL, 'admin', self::PASSWORD );

		$this->assertStringContainsString( 'URL: ' . self::URL, $config['text'] );
		$this->assertStringContainsString( 'Header: Authorization: ' . self::AUTH, $config['text'] );
	}

	public function test_a_quote_in_the_url_cannot_break_out_of_the_shell_command(): void {
		$config = ClientConfig::render( ClientConfig::CLAUDE_CODE, "https://x.test/a'b", 'admin', self::PASSWORD );

		$this->assertStringContainsString( "'https://x.test/a'\\''b'", $config['text'] );
	}
}
