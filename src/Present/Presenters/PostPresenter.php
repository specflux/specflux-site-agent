<?php
/**
 * Post / page card.
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

/**
 * create-post, update-post and publish-post answer `{id, status}` only, and
 * read-content answers a full post: the title is shown when the response has
 * one, else "Post #id". The Preview link is the draft-preview URL built from
 * the id; once the response says published it is the front-end link.
 */
final class PostPresenter implements Presenter {

	private const WRITE_ABILITIES = array( 'specflux/create-post', 'specflux/update-post', 'specflux/publish-post' );

	/** {@inheritDoc} */
	public function supports( string $ability, array $response ): bool {
		if ( ! is_int( $response['id'] ?? null ) || $response['id'] < 1 || ! is_string( $response['status'] ?? null ) ) {
			return false;
		}

		return in_array( $ability, self::WRITE_ABILITIES, true )
			|| ( is_string( $response['post_type'] ?? null ) && ! array_key_exists( 'regular_price', $response ) );
	}

	/** {@inheritDoc} */
	public function present( string $ability, array $response, Links $links ): array {
		$id        = (int) $response['id'];
		$status    = Fmt::text( $response['status'] );
		$is_page   = 'page' === ( $response['post_type'] ?? '' );
		$published = in_array( $status, array( 'publish', 'private' ), true );
		$title     = Fmt::first( $response, array( 'title_raw', 'title_rendered' ) );
		$link      = Fmt::first( $response, array( 'link' ) );
		if ( '' === $title ) {
			$title = Fmt::text( $links->title( $id ) );
		}
		if ( '' === $title ) {
			/* translators: 1: Post or Page, 2: object id. */
			$title = sprintf( __( '%1$s #%2$d', 'specflux-site-agent' ), $is_page ? __( 'Page', 'specflux-site-agent' ) : __( 'Post', 'specflux-site-agent' ), $id );
		}

		return array(
			'type'   => 'post',
			'title'  => $title,
			'status' => $status,
			'links'  => Fmt::links(
				array(
					Fmt::link( __( 'Edit', 'specflux-site-agent' ), $links->edit( $id ), 'edit' ),
					$published
						? Fmt::link( __( 'View', 'specflux-site-agent' ), '' !== $link ? $link : $links->view( $id, true ), 'view' )
						: Fmt::link( __( 'Preview', 'specflux-site-agent' ), $links->view( $id, false ), 'preview' ),
				)
			),
		);
	}
}
