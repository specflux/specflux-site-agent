<?php
/**
 * Plugin Name: Specflux Site Agent E2E Extras
 * Description: E2E/CI-ONLY (stage E1). Two small fixtures the connector,
 *              OAuth and passthrough specs need and the fake provider does
 *              not cover:
 *              1. an ability from ANOTHER plugin's namespace
 *                 (`e2e-partner/ping`) for the passthrough spec;
 *              2. 41 extra such abilities, only while the option
 *                 `specflux_site_agent_e2e_bulk_passthrough` is '1', to
 *                 exercise the 40-ability cap.
 *
 * Mounted only in this worktree's own wp-env (.wp-env.json). Never ships:
 * `tests/` is excluded by .distignore.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

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
		// Only while a spec asks for it, so other screens (pseudo-locale) never list it.
		if ( '1' === get_option( 'specflux_site_agent_e2e_partner', '0' ) ) {
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
		}

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
