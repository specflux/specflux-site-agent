<?php
/**
 * A tiny duck-typed Abilities API ability, standing in for what a real
 * `specflux/fixture-read` registration would look like (S23 fixture pack).
 *
 * Deliberately outside the `Specflux\SiteAgent` namespace and imports
 * nothing from it — a real third-party ability registers through
 * `wp_register_ability()` and the Abilities API's own base class, neither of
 * which is Specflux Site Agent's concern; `FixturePack` never references this class
 * directly (it resolves `specflux/fixture-read` by NAME only, same as any
 * ability it did not author).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Api\Fixtures;

final class FixtureAbility {

	public function __construct( private readonly string $name ) {
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_description(): string {
		return 'S23 fixture ability.';
	}

	public function get_input_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	public function get_meta(): array {
		return array();
	}

	public function check_permissions( mixed $input = null ): bool {
		unset( $input );

		return true;
	}

	public function execute( mixed $input = null ): array {
		unset( $input );

		return array( 'ok' => true );
	}
}
