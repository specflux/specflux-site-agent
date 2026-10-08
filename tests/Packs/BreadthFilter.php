<?php
/**
 * Test helper: drops the K5 tool-breadth entries from a pack map so the
 * pre-existing pack tests keep pinning the original contract exactly.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs;

use Specflux\SenroFlux\Packs\ToolBreadth;

final class BreadthFilter {

	/**
	 * @param array<string,mixed> $map A roles / verbs / capabilities / Agent Safety map.
	 * @return array<string,mixed>
	 */
	public static function strip( array $map ): array {
		$groups    = array( ToolBreadth::CONTENT, ToolBreadth::COMMENTS, ToolBreadth::SITE );
		$roles     = array_keys( ToolBreadth::roles( $groups ) );
		$templates = ToolBreadth::allTemplates();

		return array_filter(
			$map,
			static function ( $key ) use ( $roles, $templates ): bool {
				$key  = (string) $key;
				$last = false === strrpos( $key, '/' ) ? $key : substr( $key, (int) strrpos( $key, '/' ) + 1 );

				return ! in_array( $key, $roles, true ) && ! in_array( $last, $templates, true );
			},
			ARRAY_FILTER_USE_KEY
		);
	}
}
