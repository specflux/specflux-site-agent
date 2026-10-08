<?php
/**
 * Test-only stand-ins for the post-type, trash, revision and registered-meta
 * WordPress surface the `Packs\Content\PostTools` tests call.
 * TARGET REPO PATH: tests/stubs/post-tools.php
 *
 * Revisions live in the same `senroflux_test_posts` store as posts (a
 * revision is a post with post_type `revision` and a post_parent). Everything
 * is `function_exists`-guarded so a real WordPress load order wins.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

// Post-type registry: slug => show_in_rest. post and page are always present.
$GLOBALS['senroflux_test_post_types'] = array(
	'post' => true,
	'page' => true,
);

// Registered meta: post type => key => args (type, single, show_in_rest).
$GLOBALS['senroflux_test_registered_meta'] = array();

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $post_type ): ?object {
		$type = $GLOBALS['senroflux_test_post_types'][ $post_type ] ?? null;
		if ( null === $type || is_object( $type ) ) {
			return $type;
		}

		// A bare show_in_rest flag: expand it with core's default caps.
		return (object) array(
			'name'         => $post_type,
			'show_in_rest' => (bool) $type,
			'cap'          => (object) array(
				'edit_posts'    => 'edit_' . $post_type . 's',
				'create_posts'  => 'edit_' . $post_type . 's',
				'publish_posts' => 'publish_' . $post_type . 's',
				'delete_posts'  => 'delete_' . $post_type . 's',
			),
		);
	}
}

if ( ! function_exists( 'get_registered_meta_keys' ) ) {
	function get_registered_meta_keys( string $object_type, string $object_subtype = '' ): array {
		unset( $object_type );

		return (array) ( $GLOBALS['senroflux_test_registered_meta'][ $object_subtype ] ?? array() );
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( mixed $value ): mixed {
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $id ): string {
		return 'https://example.test/?p=' . $id;
	}
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( int $id ): ?object {
		$GLOBALS['senroflux_test_trash_calls'][] = $id;
		$post                                    = $GLOBALS['senroflux_test_posts'][ $id ] ?? null;
		if ( ! is_object( $post ) ) {
			return null;
		}
		$post->post_status = 'trash';

		return $post;
	}
}

if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( int $id ): ?object {
		$post = $GLOBALS['senroflux_test_posts'][ $id ] ?? null;
		if ( ! is_object( $post ) ) {
			return null;
		}
		$post->post_status = 'draft';

		return $post;
	}
}

// wp_get_post_revisions() comes from tests/stubs/admin.php (bootstrap): it reads
// $GLOBALS['senroflux_test_revisions'][ $post_id ], which the tests seed.

if ( ! function_exists( 'wp_get_post_revision' ) ) {
	function wp_get_post_revision( int $id ): ?object {
		$post = $GLOBALS['senroflux_test_posts'][ $id ] ?? null;

		return is_object( $post ) && 'revision' === $post->post_type ? $post : null;
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( object $post ): int|false {
		return str_contains( (string) $post->post_name, '-autosave-v' ) ? (int) $post->post_parent : false;
	}
}

if ( ! function_exists( 'wp_restore_post_revision' ) ) {
	function wp_restore_post_revision( int $id ): int|false|null {
		$GLOBALS['senroflux_test_restored_revisions'][] = $id;
		$revision                                       = $GLOBALS['senroflux_test_posts'][ $id ] ?? null;

		return is_object( $revision ) ? (int) $revision->post_parent : null;
	}
}
