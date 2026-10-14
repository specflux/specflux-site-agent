<?php
/**
 * A result presenter — turns one tool response into a display payload.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Present;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * `@api` (0.4): implement this and add the instance through the
 * `specflux_site_agent_result_presenters` filter to give a tool result a card.
 *
 * A presenter only formats the response the ability already returned, plus
 * links built from ids ({@see Links}); it never queries or fetches. The
 * payload it returns is plain data the Runs screen renders as text, so it
 * carries no markup.
 */
interface Presenter {

	/**
	 * Whether this presenter knows the response's shape.
	 *
	 * @param string              $ability  Ability name, e.g. `specflux/create-post`.
	 * @param array<string,mixed> $response The ability's return value.
	 */
	public function supports( string $ability, array $response ): bool;

	/**
	 * The display payload. Always has a string `type` and a string `title`.
	 *
	 * @param string              $ability  Ability name.
	 * @param array<string,mixed> $response The ability's return value.
	 * @param Links               $links    Link builder.
	 * @return array<string,mixed>
	 */
	public function present( string $ability, array $response, Links $links ): array;
}
