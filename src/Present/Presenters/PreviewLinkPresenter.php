<?php
/**
 * Preview URL card (senroflux/get-preview-url).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Present\Presenters;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SenroFlux\Present\Fmt;
use Specflux\SenroFlux\Present\Links;
use Specflux\SenroFlux\Present\Presenter;

final class PreviewLinkPresenter implements Presenter {

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return 'senroflux/get-preview-url' === $ability && '' !== Fmt::url( $response['preview_url'] ?? null );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		return array(
			'type'  => 'link',
			'title' => __( 'Preview ready', 'senroflux' ),
			'links' => Fmt::links( array( Fmt::link( __( 'Open preview', 'senroflux' ), Fmt::url( $response['preview_url'] ?? null ), 'preview' ) ) ),
		);
	}
}
