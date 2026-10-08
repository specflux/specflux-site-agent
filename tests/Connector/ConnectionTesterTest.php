<?php
/**
 * Classification of the loopback "Test connection" results.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Connector\ConnectionTester;
use WP_Error;

final class ConnectionTesterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_remote_post_responses'] = array();
		$GLOBALS['senroflux_test_remote_post_calls']     = array();
	}

	/** @param array<string,mixed> $headers */
	private function reply( int $code, mixed $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => $headers,
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
		);
	}

	private function queue( array ...$responses ): void {
		$GLOBALS['senroflux_test_remote_post_responses'] = $responses;
	}

	public function test_a_working_server_reports_its_tool_count_and_threads_the_session(): void {
		$this->queue(
			$this->reply( 200, array( 'result' => array( 'protocolVersion' => '2025-06-18' ) ), array( 'mcp-session-id' => 'sess-1' ) ),
			$this->reply( 200, array( 'result' => array( 'tools' => array( array( 'name' => 'a' ), array( 'name' => 'b' ), array( 'name' => 'c' ) ) ) ) )
		);

		$result = ConnectionTester::run( 'https://x.test/mcp', 'Basic abc' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 3, $result['tools'] );
		$calls = $GLOBALS['senroflux_test_remote_post_calls'];
		$this->assertCount( 2, $calls );
		$this->assertSame( 'initialize', json_decode( $calls[0]['args']['body'], true )['method'] );
		$this->assertSame( 'tools/list', json_decode( $calls[1]['args']['body'], true )['method'] );
		$this->assertSame( 'sess-1', $calls[1]['args']['headers']['Mcp-Session-Id'] );
		$this->assertSame( '2025-06-18', $calls[1]['args']['headers']['MCP-Protocol-Version'] );
		$this->assertSame( 'Basic abc', $calls[1]['args']['headers']['Authorization'] );
	}

	/** @return array<string,array{0:int,1:string}> */
	public static function failures(): array {
		return array(
			'401 is auth'     => array( 401, ConnectionTester::AUTH ),
			'403 is auth'     => array( 403, ConnectionTester::AUTH ),
			'404 not found'   => array( 404, ConnectionTester::NOT_FOUND ),
			'500 is protocol' => array( 500, ConnectionTester::PROTOCOL ),
		);
	}

	/** @dataProvider failures */
	public function test_http_failures_are_named( int $code, string $category ): void {
		$this->queue( $this->reply( $code, '' ) );

		$result = ConnectionTester::run( 'https://x.test/mcp', 'Basic abc' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( $category, $result['category'] );
		$this->assertCount( 1, $GLOBALS['senroflux_test_remote_post_calls'], 'Stops at the first failure.' );
	}

	public function test_a_transport_error_is_a_connection_failure(): void {
		$GLOBALS['senroflux_test_remote_post_responses'] = array( new WP_Error( 'http_request_failed', 'cURL error 7' ) );

		$result = ConnectionTester::run( 'https://x.test/mcp', 'Basic abc' );

		$this->assertSame( ConnectionTester::CONNECTION, $result['category'] );
		$this->assertStringContainsString( 'cURL error 7', $result['message'] );
	}

	public function test_a_json_rpc_error_or_garbage_body_is_a_protocol_failure(): void {
		$this->queue( $this->reply( 200, array( 'error' => array( 'code' => -32600 ) ) ) );
		$this->assertSame( ConnectionTester::PROTOCOL, ConnectionTester::run( 'https://x.test/mcp', 'Basic abc' )['category'] );

		$this->queue( $this->reply( 200, '<html>not json</html>' ) );
		$this->assertSame( ConnectionTester::PROTOCOL, ConnectionTester::run( 'https://x.test/mcp', 'Basic abc' )['category'] );
	}

	public function test_auth_failing_on_the_second_call_is_still_reported(): void {
		$this->queue( $this->reply( 200, array( 'result' => array() ) ), $this->reply( 403, '' ) );

		$this->assertSame( ConnectionTester::AUTH, ConnectionTester::run( 'https://x.test/mcp', 'Basic abc' )['category'] );
	}
}
