<?php
/**
 * Test-only stand-ins for the slice of mcp-adapter 0.7.0 the connector touches:
 * the adapter (`create_server`), a server (`get_server_id`, `get_mcp_tool`) and
 * a tool (`getName`, `is_ability_backed`, `get_observability_context`).
 *
 * Deliberately NOT named like the real classes: defining `WP\MCP\Core\McpAdapter`
 * would make every test believe the adapter plugin is installed.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'SenroFlux_Test_Fake_Mcp_Adapter', false ) ) {
	/** Records the positional arguments `create_server()` was called with. */
	class SenroFlux_Test_Fake_Mcp_Adapter {

		/** @var list<list<mixed>> */
		public array $created = array();

		public function create_server( ...$args ): void {
			$this->created[] = $args;
		}
	}

	/** A server that knows its id and a fixed set of tools by name. */
	class SenroFlux_Test_Fake_Mcp_Server {

		/** @var array<string,SenroFlux_Test_Fake_Mcp_Tool> */
		private array $tools = array();

		public function __construct( private string $server_id = 'senroflux' ) {
		}

		public function add( SenroFlux_Test_Fake_Mcp_Tool $tool ): self {
			$this->tools[ $tool->getName() ] = $tool;

			return $this;
		}

		public function get_server_id(): string {
			return $this->server_id;
		}

		public function get_mcp_tool( string $name ): ?SenroFlux_Test_Fake_Mcp_Tool {
			return $this->tools[ $name ] ?? null;
		}
	}

	/** An ability-backed (or not) tool. */
	class SenroFlux_Test_Fake_Mcp_Tool {

		public function __construct( private string $tool_name, private ?string $ability = null ) {
		}

		public function getName(): string {
			return $this->tool_name;
		}

		public function is_ability_backed(): bool {
			return null !== $this->ability;
		}

		/** @return array<string,mixed> */
		public function get_observability_context(): array {
			return null !== $this->ability ? array( 'ability_name' => $this->ability ) : array();
		}
	}
}
