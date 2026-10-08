<?php
/**
 * A resolved OAuth client.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Connector\OAuth;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/** Immutable: id, display name, exact redirect URIs, and whether it came from a metadata document. */
final class Client {

	/**
	 * @param string       $id           Client id (opaque, or an https URL for metadata-document clients).
	 * @param string       $name         Display name (plain text, escape on output).
	 * @param list<string> $redirectUris Exact-match redirect URIs.
	 * @param bool         $document     True when resolved from a client ID metadata document.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly array $redirectUris,
		public readonly bool $document = false,
	) {
	}

	/** Is this exact string one of the client's registered redirect URIs? */
	public function allowsRedirect( string $uri ): bool {
		return in_array( $uri, $this->redirectUris, true );
	}
}
