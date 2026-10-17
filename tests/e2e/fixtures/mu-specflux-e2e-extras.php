<?php
/**
 * Plugin Name: Specflux Site Agent E2E Extras
 * Description: E2E/CI-ONLY (stage E1). Three small fixtures the connector,
 *              OAuth and passthrough specs need and the fake provider does
 *              not cover:
 *              1. a version probe so the connector treats the mounted
 *                 mcp-adapter as ready (the pinned adapter checkout reports
 *                 0.6.1, below Connector::MIN_ADAPTER_VERSION, although it
 *                 carries the create_server() / transport contract the
 *                 connector uses);
 *              2. an ability from ANOTHER plugin's namespace
 *                 (`e2e-partner/ping`) for the passthrough spec;
 *              3. 41 extra such abilities, only while the option
 *                 `specflux_site_agent_e2e_bulk_passthrough` is '1', to
 *                 exercise the 40-ability cap.
 *
 * Mounted only in this worktree's own wp-env (.wp-env.json). Never ships:
 * `tests/` is excluded by .distignore.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// 1. The connector's adapter-version gate (see the header note).
add_action(
	'plugins_loaded',
	static function (): void {
		if (
			class_exists( 'WP\MCP\Core\McpAdapter' )
			&& class_exists( \Specflux\SiteAgent\Connector\Connector::class )
		) {
			\Specflux\SiteAgent\Connector\Connector::set_adapter_version_probe( '0.7.0' );
		}
	},
	1
);

// 2 + 3. Abilities from another plugin's namespace.
add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category(
			'e2e-partner',
			array(
				'label'       => 'E2E partner plugin',
				'description' => 'Abilities that pretend to come from another plugin.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function (): void {
		wp_register_ability(
			'e2e-partner/ping',
			array(
				'label'               => 'Partner ping',
				'description'         => 'Fixture from another plugin: answers pong and counts the call.',
				'category'            => 'e2e-partner',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function (): array {
					$n = (int) get_option( 'specflux_site_agent_e2e_ping_calls', 0 );
					update_option( 'specflux_site_agent_e2e_ping_calls', $n + 1, false );
					return array(
						'ok'   => true,
						'pong' => 'pong from the partner plugin',
					);
				},
				'permission_callback' => static fn (): bool => current_user_can( 'read' ),
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		if ( '1' !== get_option( 'specflux_site_agent_e2e_bulk_passthrough', '0' ) ) {
			return;
		}
		for ( $i = 1; $i <= 41; $i++ ) {
			wp_register_ability(
				sprintf( 'e2e-bulk/item-%02d', $i ),
				array(
					'label'               => sprintf( 'Bulk item %02d', $i ),
					'description'         => 'Fixture: one of 41 abilities that exist only to overflow the passthrough cap.',
					'category'            => 'e2e-partner',
					'input_schema'        => array( 'type' => 'object' ),
					'output_schema'       => array( 'type' => 'object' ),
					'execute_callback'    => static fn (): array => array( 'ok' => true ),
					'permission_callback' => static fn (): bool => current_user_can( 'read' ),
					'meta'                => array( 'show_in_rest' => true ),
				)
			);
		}
	}
);
