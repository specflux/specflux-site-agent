<?php
/**
 * A rendered response from the browser-facing authorize flow.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Status + headers + body, or a redirect. Pure data so the flow is testable; {@see Authorize::emit()} sends it. */
final class Page {

	/**
	 * @param int                  $status   HTTP status.
	 * @param string               $body     Complete HTML (already escaped).
	 * @param string|null          $location Redirect target, or null.
	 * @param array<string,string> $headers  Extra headers.
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body = '',
		public readonly ?string $location = null,
		public readonly array $headers = array(),
	) {
	}
}
