<?php
/**
 * Preview URL card (specflux/get-preview-url).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Present\Presenters;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SiteAgent\Present\Fmt;
use Specflux\SiteAgent\Present\Links;
use Specflux\SiteAgent\Present\Presenter;

final class PreviewLinkPresenter implements Presenter {

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return 'specflux/get-preview-url' === $ability && '' !== Fmt::url( $response['preview_url'] ?? null );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		return array(
			'type'  => 'link',
			'title' => __( 'Preview ready', 'specflux-site-agent' ),
			'links' => Fmt::links( array( Fmt::link( __( 'Open preview', 'specflux-site-agent' ), Fmt::url( $response['preview_url'] ?? null ), 'preview' ) ) ),
		);
	}
}
