<?php
/**
 * Test-only WordPress comment/user shims for the Comments and Users ability
 * tests. Every function is `function_exists`-guarded so real WordPress wins.
 *
 * Knobs: $GLOBALS['senroflux_test_comments'] (id => stdClass, comment_approved
 * as in core: '1', '0', 'spam', 'trash'), $GLOBALS['senroflux_test_users']
 * (id => SenroFlux_Test_User), $GLOBALS['senroflux_test_roles'],
 * $GLOBALS['senroflux_test_editable_roles'], $GLOBALS['senroflux_test_super_admins'].
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

if ( ! defined( 'EMPTY_TRASH_DAYS' ) ) {
	define( 'EMPTY_TRASH_DAYS', 30 );
}

if ( ! class_exists( 'SenroFlux_Test_User', false ) ) {
	/** Minimal WP_User stand-in; carries secret fields to prove they never leak. */
	class SenroFlux_Test_User {

		public string $user_pass           = '$P$hash';
		public string $user_activation_key = 'activation-key';
		/** @var array<string,mixed> */
		public array $caps = array( 'administrator' => true );
		/** @var array<string,mixed> */
		public array $capabilities    = array( 'manage_options' => true );
		public string $session_tokens = 'tokens';

		/**
		 * @param list<string> $roles Roles.
		 */
		public function __construct(
			public int $ID,
			public string $user_login = 'user',
			public string $display_name = 'User',
			public string $user_email = 'user@example.test',
			public array $roles = array( 'subscriber' ),
			public string $user_url = '',
			public string $user_registered = '2026-01-02 03:04:05'
		) {
		}

		public function set_role( string $role ): void {
			$this->roles = array( $role );
		}
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	/** Like core: addslashes on strings, recursively on arrays. */
	function wp_slash( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( 'wp_slash', $value );
		}

		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): object {
		$id = (int) ( $GLOBALS['senroflux_test_current_user_id'] ?? 0 );

		return $GLOBALS['senroflux_test_users'][ $id ] ?? new SenroFlux_Test_User( $id );
	}
}

if ( ! function_exists( 'get_comment' ) ) {
	function get_comment( mixed $id ): ?object {
		return $GLOBALS['senroflux_test_comments'][ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'get_comments' ) ) {
	/**
	 * Supports status/post_id/parent/search/number/offset/count.
	 *
	 * @param array<string,mixed> $args Query args.
	 */
	function get_comments( array $args = array() ): array|int {
		$status  = (string) ( $args['status'] ?? 'all' );
		$matches = array();
		foreach ( (array) ( $GLOBALS['senroflux_test_comments'] ?? array() ) as $comment ) {
			$a = (string) $comment->comment_approved;
			if ( 'all' === $status && ! in_array( $a, array( '0', '1' ), true ) ) {
				continue;
			}
			if ( 'approve' === $status && '1' !== $a ) {
				continue;
			}
			if ( 'hold' === $status && '0' !== $a ) {
				continue;
			}
			if ( in_array( $status, array( 'spam', 'trash' ), true ) && $status !== $a ) {
				continue;
			}
			if ( isset( $args['post_id'] ) && (int) $comment->comment_post_ID !== (int) $args['post_id'] ) {
				continue;
			}
			if ( isset( $args['parent'] ) && (int) $comment->comment_parent !== (int) $args['parent'] ) {
				continue;
			}
			if ( isset( $args['search'] ) && false === stripos( $comment->comment_content, (string) $args['search'] ) ) {
				continue;
			}
			$matches[] = $comment;
		}

		if ( ! empty( $args['count'] ) ) {
			return count( $matches );
		}

		return array_slice( $matches, (int) ( $args['offset'] ?? 0 ), isset( $args['number'] ) ? (int) $args['number'] : null );
	}
}

if ( ! function_exists( 'wp_set_comment_status' ) ) {
	function wp_set_comment_status( int $id, string $status, bool $wp_error = false ): bool|WP_Error {
		unset( $wp_error );
		if ( ! isset( $GLOBALS['senroflux_test_comments'][ $id ] ) ) {
			return false;
		}
		$GLOBALS['senroflux_test_comments'][ $id ]->comment_approved = 'approve' === $status ? '1' : '0';

		return true;
	}
}

if ( ! function_exists( 'wp_spam_comment' ) ) {
	function wp_spam_comment( int $id ): bool {
		$GLOBALS['senroflux_test_comment_prev'][ $id ]               = $GLOBALS['senroflux_test_comments'][ $id ]->comment_approved;
		$GLOBALS['senroflux_test_comments'][ $id ]->comment_approved = 'spam';

		return true;
	}
}

if ( ! function_exists( 'wp_trash_comment' ) ) {
	function wp_trash_comment( int $id ): bool {
		$GLOBALS['senroflux_test_comment_prev'][ $id ]               = $GLOBALS['senroflux_test_comments'][ $id ]->comment_approved;
		$GLOBALS['senroflux_test_comments'][ $id ]->comment_approved = 'trash';

		return true;
	}
}

if ( ! function_exists( 'wp_unspam_comment' ) ) {
	function wp_unspam_comment( int $id ): bool {
		$GLOBALS['senroflux_test_comments'][ $id ]->comment_approved = $GLOBALS['senroflux_test_comment_prev'][ $id ] ?? '0';

		return true;
	}
}

if ( ! function_exists( 'wp_untrash_comment' ) ) {
	function wp_untrash_comment( int $id ): bool {
		$GLOBALS['senroflux_test_comments'][ $id ]->comment_approved = $GLOBALS['senroflux_test_comment_prev'][ $id ] ?? '0';

		return true;
	}
}

if ( ! function_exists( 'wp_new_comment' ) ) {
	/**
	 * Records the (slashed) data it was handed; inserts an approved comment.
	 *
	 * @param array<string,mixed> $data Comment data.
	 */
	function wp_new_comment( array $data, bool $wp_error = false ): int|false|WP_Error {
		unset( $wp_error );
		$GLOBALS['senroflux_test_new_comment_data'] = $data;
		$id                      = max( array_merge( array( 0 ), array_keys( $GLOBALS['senroflux_test_comments'] ?? array() ) ) ) + 1;
		$c                       = new stdClass();
		$c->comment_ID           = $id;
		$c->comment_post_ID      = $data['comment_post_ID'];
		$c->comment_parent       = $data['comment_parent'];
		$c->comment_content      = stripslashes( $data['comment_content'] );
		$c->comment_approved     = $GLOBALS['senroflux_test_new_comment_approved'] ?? '1';
		$c->comment_author       = stripslashes( $data['comment_author'] );
		$c->comment_author_email = $data['comment_author_email'];
		$c->comment_author_url   = $data['comment_author_url'];
		$c->comment_date_gmt     = '2026-05-06 07:08:09';
		$c->comment_type         = 'comment';
		$GLOBALS['senroflux_test_comments'][ $id ] = $c;

		return $id;
	}
}

if ( ! function_exists( 'get_role' ) ) {
	function get_role( string $role ): ?object {
		return in_array( $role, $GLOBALS['senroflux_test_roles'] ?? array(), true ) ? (object) array( 'name' => $role ) : null;
	}
}

if ( ! function_exists( 'get_editable_roles' ) ) {
	/** @return array<string,array<string,mixed>> */
	function get_editable_roles(): array {
		$out = array();
		foreach ( $GLOBALS['senroflux_test_editable_roles'] ?? array() as $role ) {
			$out[ $role ] = array( 'name' => $role );
		}

		return $out;
	}
}

if ( ! function_exists( 'is_super_admin' ) ) {
	function is_super_admin( int $id = 0 ): bool {
		return in_array( $id, $GLOBALS['senroflux_test_super_admins'] ?? array(), true );
	}
}

if ( ! class_exists( 'WP_User_Query', false ) ) {
	/** Supports search (*term*), role, number, paged over senroflux_test_users. */
	class WP_User_Query {

		/** @var list<object> */
		private array $results = array();

		private int $total = 0;

		/** @param array<string,mixed> $args Query args. */
		public function __construct( array $args = array() ) {
			$matches = array();
			$needle  = isset( $args['search'] ) ? trim( (string) $args['search'], '*' ) : '';
			foreach ( (array) ( $GLOBALS['senroflux_test_users'] ?? array() ) as $user ) {
				if ( isset( $args['role'] ) && ! in_array( $args['role'], $user->roles, true ) ) {
					continue;
				}
				if ( '' !== $needle && false === stripos( $user->user_login . ' ' . $user->display_name . ' ' . $user->user_email, $needle ) ) {
					continue;
				}
				$matches[] = $user;
			}
			$this->total   = count( $matches );
			$number        = (int) ( $args['number'] ?? 10 );
			$page          = max( 1, (int) ( $args['paged'] ?? 1 ) );
			$this->results = array_slice( $matches, ( $page - 1 ) * $number, $number );
		}

		/** @return list<object> */
		public function get_results(): array {
			return $this->results;
		}

		public function get_total(): int {
			return $this->total;
		}
	}
}
