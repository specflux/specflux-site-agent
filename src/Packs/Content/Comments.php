<?php
/**
 * The comment-moderation registrar: `specflux/list-comments` (read),
 * `specflux/moderate-comment` (hide / restore, never approve or delete),
 * `specflux/approve-comment` (makes a comment public — its own, higher tier)
 * and `specflux/reply-to-comment`.
 *
 * Every permission_callback checks a REAL capability AND the per-object cap for
 * the comment/post named in the input; every execute re-checks object access
 * (defence in depth — a permission_callback can be bypassed by a direct call).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Content;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the four comment abilities.
 */
final class Comments {

	/** Longest reply, in characters (stated in the ability description). */
	private const MAX_REPLY_LENGTH = 2000;

	/** Largest page size list-comments will honour. */
	private const MAX_PER_PAGE = 100;

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

	/** Wire the ability registration hook (call once, from the composition root). */
	public static function boot(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_init', array( self::class, 'register' ) );
	}

	/** Forget the per-request registered flag (test-only). */
	public static function reset(): void {
		self::$registered = false;
	}

	/** Register the four abilities. Idempotent per request. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerList();
		self::registerModerate();
		self::registerApprove();
		self::registerReply();
	}

	/** specflux/list-comments. */
	private static function registerList(): void {
		wp_register_ability(
			'specflux/list-comments',
			array(
				'label'               => __( 'List comments', 'specflux-site-agent' ),
				'description'         => __( 'List comments you are allowed to edit, newest first. Filter by post_id, status (approved, hold, spam, trash, or all = approved and held; default hold), search text and parent comment id. per_page is at most 100 (default 20). The author email is included only if you may moderate comments; IP addresses and user agents are never returned. Comments you cannot edit are left out of the list and the total.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'post_id'  => array( 'type' => 'integer' ),
						'status'   => array(
							'type' => 'string',
							'enum' => array( 'approved', 'hold', 'spam', 'trash', 'all' ),
						),
						'search'   => array( 'type' => 'string' ),
						'parent'   => array( 'type' => 'integer' ),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array(
							'type'    => 'integer',
							'maximum' => self::MAX_PER_PAGE,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'comments', 'total', 'total_pages' ),
					'additionalProperties' => false,
					'properties'           => array(
						'comments'    => array(
							'type'  => 'array',
							'items' => self::commentSchema(),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeList( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input   = is_array( $input ) ? $input : array();
					$post_id = (int) ( $input['post_id'] ?? 0 );

					return current_user_can( 'edit_posts' )
						&& ( 0 === $post_id || current_user_can( 'edit_post', $post_id ) );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/** specflux/moderate-comment. */
	private static function registerModerate(): void {
		wp_register_ability(
			'specflux/moderate-comment',
			array(
				'label'               => __( 'Moderate a comment', 'specflux-site-agent' ),
				'description'         => __( 'Hide or restore one comment: unapprove (back to held), spam, trash, or restore (from spam or trash to its previous status). Never approves and never permanently deletes. Use approve-comment to publish a held comment.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'action' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'action' => array(
							'type' => 'string',
							'enum' => array( 'unapprove', 'spam', 'trash', 'restore' ),
						),
					),
				),
				'output_schema'       => self::statusOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeModerate( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayModerate( is_array( $input ) ? $input : array() );
				},
				// Reversible: spam/trash keep the previous status in comment
				// meta and `restore` brings it back. destructive=false matters:
				// Belayline Agent Governance parks destructive=true calls as irreversible.
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/** specflux/approve-comment. */
	private static function registerApprove(): void {
		wp_register_ability(
			'specflux/approve-comment',
			array(
				'label'               => __( 'Approve a comment', 'specflux-site-agent' ),
				'description'         => __( 'Approve one held comment, which makes it public on the site. Only a comment that is currently held can be approved; restore a spam or trashed comment first.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => self::statusOutputSchema(),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeApprove( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayModerate( is_array( $input ) ? $input : array() );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}

	/** specflux/reply-to-comment. */
	private static function registerReply(): void {
		wp_register_ability(
			'specflux/reply-to-comment',
			array(
				'label'               => __( 'Reply to a comment', 'specflux-site-agent' ),
				'description'         => __( 'Post a reply, as you, to an approved comment on a published post. The reply is up to 2000 characters of text and passes through WordPress comment filters, so HTML you are not allowed to post is stripped. The reply\'s status is reported back and follows the site\'s moderation rules.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'comment_id', 'content' ),
					'additionalProperties' => false,
					'properties'           => array(
						'comment_id' => array( 'type' => 'integer' ),
						'content'    => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'post_id', 'parent', 'status' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'      => array( 'type' => 'integer' ),
						'post_id' => array( 'type' => 'integer' ),
						'parent'  => array( 'type' => 'integer' ),
						'status'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeReply( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayReply( is_array( $input ) ? $input : array() );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function commentSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'post_id'      => array( 'type' => 'integer' ),
				'parent'       => array( 'type' => 'integer' ),
				'status'       => array(
					'type' => 'string',
					'enum' => array( 'approved', 'hold', 'spam', 'trash' ),
				),
				'author_name'  => array( 'type' => 'string' ),
				'author_email' => array( 'type' => 'string' ),
				'author_url'   => array( 'type' => 'string' ),
				'date'         => array( 'type' => 'string' ),
				'content'      => array( 'type' => 'string' ),
				'type'         => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function statusOutputSchema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'id', 'status', 'previous_status' ),
			'additionalProperties' => false,
			'properties'           => array(
				'id'              => array( 'type' => 'integer' ),
				'status'          => array( 'type' => 'string' ),
				'previous_status' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $annotations Ability meta annotations.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ): array {
		return array(
			'annotations' => $annotations,
			'specflux'    => array( 'hidden' => false ),
		);
	}

	/** The single generic refusal (no existence oracle). */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'specflux-site-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The comment status in this ability's vocabulary, or null when it is not
	 * one we handle (e.g. `post-trashed`, or a custom status).
	 *
	 * @param \WP_Comment $comment A comment.
	 */
	private static function statusOf( object $comment ): ?string {
		$approved = (string) $comment->comment_approved;

		return match ( $approved ) {
			'1'     => 'approved',
			'0'     => 'hold',
			'spam'  => 'spam',
			'trash' => 'trash',
			default => null,
		};
	}

	/**
	 * Base cap AND per-object cap for moderate/approve.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function mayModerate( array $input ): bool {
		$id = (int) ( $input['id'] ?? 0 );

		return $id > 0
			&& current_user_can( 'moderate_comments' )
			&& current_user_can( 'edit_comment', $id );
	}

	/**
	 * Base cap AND per-object caps for a reply: edit on the parent comment and
	 * on its post.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function mayReply( array $input ): bool {
		$comment_id = (int) ( $input['comment_id'] ?? 0 );
		if ( $comment_id <= 0 || ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return false;
		}

		$comment = get_comment( $comment_id );
		if ( ! is_object( $comment ) ) {
			// Nothing to authorise against; execute reports not_found.
			return true;
		}

		return current_user_can( 'edit_post', (int) $comment->comment_post_ID );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeList( array $input ): array|WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return self::forbidden();
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return self::forbidden();
		}

		$status = (string) ( $input['status'] ?? 'hold' );
		$map    = array(
			'approved' => 'approve',
			'hold'     => 'hold',
			'spam'     => 'spam',
			'trash'    => 'trash',
			'all'      => 'all',
		);
		if ( ! isset( $map[ $status ] ) ) {
			return new WP_Error(
				'bad_request',
				__( 'status must be approved, hold, spam, trash or all.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $input['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $input['page'] ?? 1 ) );

		$args = array(
			'status'  => $map[ $status ],
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);
		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}
		if ( isset( $input['parent'] ) ) {
			$args['parent'] = (int) $input['parent'];
		}
		$search = trim( (string) ( $input['search'] ?? '' ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$count = get_comments( array_merge( $args, array( 'count' => true ) ) );
		$total = is_numeric( $count ) ? (int) $count : 0;
		$rows  = get_comments(
			array_merge(
				$args,
				array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				)
			)
		);

		$may_see_email = current_user_can( 'moderate_comments' );
		$comments      = array();
		$dropped       = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_object( $row ) ) {
				continue;
			}
			// The query cannot apply per-comment caps: re-check every hit.
			if ( ! current_user_can( 'edit_comment', (int) $row->comment_ID ) ) {
				++$dropped;
				continue;
			}
			$comments[] = self::shape( $row, $may_see_email );
		}

		$total = max( 0, $total - $dropped );

		return array(
			'comments'    => $comments,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Allow-listed comment shape: no IP, no user agent, no user id.
	 *
	 * @param \WP_Comment $row           A comment.
	 * @param bool   $include_email Whether to include author_email.
	 * @return array<string,mixed>
	 */
	private static function shape( object $row, bool $include_email ): array {
		$gmt  = (string) $row->comment_date_gmt;
		$time = '' === $gmt ? false : strtotime( $gmt . ' UTC' );
		$out  = array(
			'id'          => (int) $row->comment_ID,
			'post_id'     => (int) $row->comment_post_ID,
			'parent'      => (int) $row->comment_parent,
			'status'      => self::statusOf( $row ) ?? 'hold',
			'author_name' => (string) $row->comment_author,
			'author_url'  => (string) $row->comment_author_url,
			'date'        => false === $time ? '' : gmdate( 'c', $time ),
			'content'     => (string) $row->comment_content,
			'type'        => (string) $row->comment_type,
		);
		if ( $include_email ) {
			$out['author_email'] = (string) $row->comment_author_email;
		}

		return $out;
	}

	/**
	 * Load a comment the caller may moderate, or the refusal (a WP_Error).
	 *
	 * @param int $id Comment id.
	 * @return \WP_Comment|WP_Error
	 */
	private static function moderatable( int $id ): object {
		if ( $id <= 0 || ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_comment', $id ) ) {
			return self::forbidden();
		}

		$comment = get_comment( $id );
		if ( ! is_object( $comment ) ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}

		return $comment;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeModerate( array $input ): array|WP_Error {
		$id      = (int) ( $input['id'] ?? 0 );
		$action  = (string) ( $input['action'] ?? '' );
		$comment = self::moderatable( $id );
		if ( $comment instanceof WP_Error ) {
			return $comment;
		}

		if ( ! in_array( $action, array( 'unapprove', 'spam', 'trash', 'restore' ), true ) ) {
			return new WP_Error(
				'bad_request',
				__( 'action must be unapprove, spam, trash or restore.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		$previous = self::statusOf( $comment );
		if ( null === $previous ) {
			return self::badTransition( __( 'This comment is in a status that cannot be changed here.', 'specflux-site-agent' ) );
		}

		// Already in the target state: succeed without touching anything.
		$target = array(
			'unapprove' => 'hold',
			'spam'      => 'spam',
			'trash'     => 'trash',
		);
		if ( isset( $target[ $action ] ) && $target[ $action ] === $previous ) {
			return self::result( $id, $previous, $previous );
		}

		$ok = false;
		switch ( $action ) {
			case 'unapprove':
				if ( 'approved' !== $previous ) {
					return self::badTransition( __( 'Only an approved comment can be unapproved. Restore a spam or trashed comment first.', 'specflux-site-agent' ) );
				}
				$ok = wp_set_comment_status( $id, 'hold', true );
				break;

			case 'spam':
				// wp_spam_comment() records the prior status so restore works.
				$ok = wp_spam_comment( $id );
				break;

			case 'trash':
				// With EMPTY_TRASH_DAYS = 0 core's wp_trash_comment() deletes
				// permanently; this ability never does.
				if ( ! defined( 'EMPTY_TRASH_DAYS' ) || ! (int) constant( 'EMPTY_TRASH_DAYS' ) ) {
					return self::badTransition( __( 'The comment trash is disabled on this site, so trashing would delete permanently. Refused.', 'specflux-site-agent' ) );
				}
				$ok = wp_trash_comment( $id );
				break;

			default: // restore.
				if ( 'trash' === $previous ) {
					$ok = wp_untrash_comment( $id );
				} elseif ( 'spam' === $previous ) {
					$ok = wp_unspam_comment( $id );
				} else {
					return self::badTransition( __( 'Only a spam or trashed comment can be restored.', 'specflux-site-agent' ) );
				}
		}

		return self::finish( $id, $previous, $ok );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeApprove( array $input ): array|WP_Error {
		$id      = (int) ( $input['id'] ?? 0 );
		$comment = self::moderatable( $id );
		if ( $comment instanceof WP_Error ) {
			return $comment;
		}

		$previous = self::statusOf( $comment );
		if ( 'approved' === $previous ) {
			return new WP_Error( 'already_approved', __( 'This comment is already approved.', 'specflux-site-agent' ), array( 'status' => 409 ) );
		}
		if ( 'hold' !== $previous ) {
			return self::badTransition( __( 'Only a held comment can be approved. Restore a spam or trashed comment first.', 'specflux-site-agent' ) );
		}

		return self::finish( $id, 'hold', wp_set_comment_status( $id, 'approve', true ) );
	}

	/**
	 * Turn a core mutation result into the ability result.
	 *
	 * @param int                $id       Comment id.
	 * @param string             $previous Status before the change.
	 * @param bool|WP_Error|null $ok       Core's return value.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function finish( int $id, string $previous, mixed $ok ): array|WP_Error {
		if ( $ok instanceof WP_Error ) {
			return $ok;
		}
		if ( ! $ok ) {
			return new WP_Error( 'update_failed', __( 'The comment could not be updated.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		$fresh = get_comment( $id );
		$now   = is_object( $fresh ) ? ( self::statusOf( $fresh ) ?? 'hold' ) : $previous;

		return self::result( $id, $now, $previous );
	}

	/**
	 * @param int    $id       Comment id.
	 * @param string $status   Current status.
	 * @param string $previous Previous status.
	 * @return array<string,mixed>
	 */
	private static function result( int $id, string $status, string $previous ): array {
		return array(
			'id'              => $id,
			'status'          => $status,
			'previous_status' => $previous,
		);
	}

	/**
	 * @param string $message Refusal message.
	 */
	private static function badTransition( string $message ): WP_Error {
		return new WP_Error( 'bad_transition', $message, array( 'status' => 409 ) );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeReply( array $input ): array|WP_Error {
		$parent_id = (int) ( $input['comment_id'] ?? 0 );
		if ( $parent_id <= 0 || ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_comment', $parent_id ) ) {
			return self::forbidden();
		}

		$parent = get_comment( $parent_id );
		if ( ! is_object( $parent ) ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}

		$post_id = (int) $parent->comment_post_ID;
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return self::forbidden();
		}

		if ( 'approved' !== self::statusOf( $parent ) ) {
			return new WP_Error( 'parent_not_approved', __( 'You can only reply to an approved comment.', 'specflux-site-agent' ), array( 'status' => 409 ) );
		}

		$post = get_post( $post_id );
		if ( ! is_object( $post ) ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}
		$post_status = (string) $post->post_status;
		$readable    = 'publish' === $post_status
			|| ( 'private' === $post_status && current_user_can( 'read_post', $post_id ) );
		if ( ! $readable ) {
			return new WP_Error( 'post_not_published', __( 'Replies can only be posted on published posts.', 'specflux-site-agent' ), array( 'status' => 409 ) );
		}
		if ( 'open' !== (string) $post->comment_status ) {
			return new WP_Error( 'comments_closed', __( 'Comments are closed on this post.', 'specflux-site-agent' ), array( 'status' => 409 ) );
		}

		$content = trim( (string) ( $input['content'] ?? '' ) );
		if ( '' === $content ) {
			return new WP_Error( 'empty_content', __( 'The reply is empty.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( $content ) > self::MAX_REPLY_LENGTH ) {
			return new WP_Error( 'content_too_long', __( 'The reply is longer than 2000 characters.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		$user = wp_get_current_user();

		// wp_new_comment() expects SLASHED data and runs core's comment filters
		// (kses for users without unfiltered_html, duplicate and flood checks).
		$new_id = wp_new_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => $parent_id,
				'comment_content'      => wp_slash( $content ),
				'comment_type'         => 'comment',
				'user_id'              => (int) $user->ID,
				'comment_author'       => wp_slash( (string) $user->display_name ),
				'comment_author_email' => wp_slash( (string) $user->user_email ),
				'comment_author_url'   => wp_slash( (string) $user->user_url ),
			),
			true
		);
		if ( $new_id instanceof WP_Error ) {
			return $new_id;
		}

		if ( ! is_int( $new_id ) || $new_id <= 0 ) {
			return new WP_Error( 'reply_failed', __( 'The reply could not be saved.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		$created = get_comment( $new_id );

		return array(
			'id'      => $new_id,
			'post_id' => $post_id,
			'parent'  => $parent_id,
			'status'  => is_object( $created ) ? ( self::statusOf( $created ) ?? 'hold' ) : 'hold',
		);
	}
}
