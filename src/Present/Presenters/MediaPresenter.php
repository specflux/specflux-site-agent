<?php
/**
 * Media card — thumbnail and alt text.
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

final class MediaPresenter implements Presenter {

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		return is_int( $response['attachment_id'] ?? null ) && '' !== Fmt::url( $response['url'] ?? null );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$id    = (int) $response['attachment_id'];
		$alt   = Fmt::text( $response['alt'] ?? '' );
		$title = Fmt::first( $response, array( 'title', 'alt' ) );
		if ( '' === $title ) {
			$title = Fmt::text( $links->title( $id ) );
		}

		return array(
			'type'          => 'media',
			'title'         => '' !== $title ? $title : sprintf( /* translators: %d: attachment id. */ __( 'Image #%d', 'specflux-site-agent' ), $id ),
			'thumbnail_url' => Fmt::url( $response['url'] ),
			'alt'           => $alt,
			'links'         => Fmt::links( array( Fmt::link( __( 'Edit', 'specflux-site-agent' ), $links->edit( $id ), 'edit' ) ) ),
		);
	}
}
