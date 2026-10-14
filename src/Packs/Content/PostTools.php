<?php
/**
 * Post-management abilities: list, trash/restore, revisions, post meta.
 *
 * TARGET REPO PATH: src/Packs/Content/PostTools.php
 *
 * Seven abilities, all in the shared content category:
 *
 *  - `specflux/list-posts`      (read-only, never returns content)
 *  - `specflux/trash-post`      (reversible: wp_trash_post only, never a force delete)
 *  - `specflux/restore-post`    (wp_untrash_post)
 *  - `specflux/list-revisions`  (read-only, no content)
 *  - `specflux/restore-revision`
 *  - `specflux/list-post-meta`  (read-only, REGISTERED REST-visible scalar keys only)
 *  - `specflux/set-post-meta`
 *
 * Every permission_callback checks a REAL capability (the post type's own
 * `edit_posts` / `delete_posts`) AND the per-object meta capability
 * (`edit_post`, `delete_post`, `read_post`, `edit_post_meta`) for the object
 * named in the input. Every execute path re-checks both and re-validates the
 * post-type allow-list itself: the schema enums are enforced by the Abilities
 * API, not by this class. A refusal is always one generic `forbidden` error,
 * so a missing object and an unreadable one look the same.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Content;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the post-management abilities.
 */
final class PostTools {

	/** Types that are never manageable, even with show_in_rest. */
	private const EXCLUDED_TYPES = array(
		'attachment',
		'nav_menu_item',
		'revision',
		'wp_navigation',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_block',
		'customize_changeset',
		'oembed_cache',
		'user_request',
	);

	/** Statuses list-posts can filter on. */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

	/** Default list-posts statuses: every one but trash. */
	private const DEFAULT_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/** Registered-meta scalar types this class will read or write. */
	private const META_TYPES = array( 'string', 'number', 'integer', 'boolean' );

	/** Longest string meta value set-post-meta writes. */
	private const MAX_META_STRING = 10000;

	/** Whether {@see register()} has run for this request. */
	private static bool $registered = false;

	/**
	 * Wire the ability registration hook (call once, from the composition root).
	 */
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

	/**
	 * Register the seven abilities. Idempotent per request.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::registerListPosts();
		self::registerTrashPost();
		self::registerRestorePost();
		self::registerListRevisions();
		self::registerRestoreRevision();
		self::registerListPostMeta();
		self::registerSetPostMeta();
	}

	/**
	 * Days WordPress keeps trashed posts (EMPTY_TRASH_DAYS). Zero or less
	 * means wp_trash_post() would delete permanently. A seam: tests override
	 * it through a `specflux_site_agent_test_*` global because a constant can only be
	 * defined once per PHP process.
	 */
	public static function trashDays(): int {
		if ( isset( $GLOBALS['specflux_site_agent_test_empty_trash_days'] ) ) {
			return (int) $GLOBALS['specflux_site_agent_test_empty_trash_days'];
		}

		return defined( 'EMPTY_TRASH_DAYS' ) ? (int) constant( 'EMPTY_TRASH_DAYS' ) : 0;
	}

	// --- Registration -------------------------------------------------------

	/** Register specflux/list-posts. */
	private static function registerListPosts(): void {
		wp_register_ability(
			'specflux/list-posts',
			array(
				'label'               => __( 'List posts', 'specflux-site-agent' ),
				'description'         => __( 'List posts or pages (or another REST-visible post type) with filters: search text (up to 200 characters), status, author, date range, ordering and paging. Returns metadata only, never content. Set count_only to get just the totals.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'post_type'   => array(
							'type'    => 'string',
							'default' => 'post',
						),
						'search'      => array( 'type' => 'string' ),
						'status'      => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
								'enum' => self::STATUSES,
							),
						),
						'author'      => array( 'type' => 'integer' ),
						'date_after'  => array( 'type' => 'string' ),
						'date_before' => array( 'type' => 'string' ),
						'orderby'     => array(
							'type'    => 'string',
							'enum'    => array( 'date', 'modified', 'title' ),
							'default' => 'date',
						),
						'order'       => array(
							'type'    => 'string',
							'enum'    => array( 'asc', 'desc' ),
							'default' => 'desc',
						),
						'page'        => array(
							'type'    => 'integer',
							'default' => 1,
						),
						'per_page'    => array(
							'type'    => 'integer',
							'maximum' => 100,
							'default' => 20,
						),
						'count_only'  => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'total', 'total_pages' ),
					'additionalProperties' => false,
					'properties'           => array(
						'posts'       => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'id'        => array( 'type' => 'integer' ),
									'post_type' => array( 'type' => 'string' ),
									'status'    => array( 'type' => 'string' ),
									'title'     => array( 'type' => 'string' ),
									'slug'      => array( 'type' => 'string' ),
									'author'    => array( 'type' => 'integer' ),
									'date'      => array( 'type' => 'string' ),
									'modified'  => array( 'type' => 'string' ),
									'link'      => array( 'type' => 'string' ),
								),
							),
						),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeListPosts( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = self::arr( $input );

					return self::mayListType( (string) ( $input['post_type'] ?? 'post' ) );
				},
				'meta'                => self::meta( true, false, true ),
			)
		);
	}

	/** Register specflux/trash-post. */
	private static function registerTrashPost(): void {
		wp_register_ability(
			'specflux/trash-post',
			array(
				'label'               => __( 'Trash a post', 'specflux-site-agent' ),
				'description'         => __( 'Move a post or page to the trash. Reversible with restore-post; refused when the site does not keep a trash. Never deletes permanently.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => self::idSchema( 'id' ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status', 'previous_status' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'              => array( 'type' => 'integer' ),
						'status'          => array(
							'type' => 'string',
							'enum' => array( 'trash' ),
						),
						'previous_status' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeTrashPost( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayDelete( self::inputInt( self::arr( $input ), 'id' ) );
				},
				// NOT destructive: the post lands in the trash and restore-post
				// brings it back. Belayline Agent Governance parks destructive=true as irreversible.
				'meta'                => self::meta( false, false, false ),
			)
		);
	}

	/** Register specflux/restore-post. */
	private static function registerRestorePost(): void {
		wp_register_ability(
			'specflux/restore-post',
			array(
				'label'               => __( 'Restore a trashed post', 'specflux-site-agent' ),
				'description'         => __( 'Restore a trashed post or page. WordPress puts it back as a draft by default.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => self::idSchema( 'id' ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'status' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'     => array( 'type' => 'integer' ),
						'status' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeRestorePost( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayDelete( self::inputInt( self::arr( $input ), 'id' ) );
				},
				'meta'                => self::meta( false, false, false ),
			)
		);
	}

	/** Register specflux/list-revisions. */
	private static function registerListRevisions(): void {
		wp_register_ability(
			'specflux/list-revisions',
			array(
				'label'               => __( 'List revisions', 'specflux-site-agent' ),
				'description'         => __( 'List a post\'s revisions, newest first: id, date, author, title and whether it is an autosave. Content is not returned.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
						'limit'   => array(
							'type'    => 'integer',
							'maximum' => 50,
							'default' => 20,
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'revisions' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id'   => array( 'type' => 'integer' ),
						'revisions' => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'revision_id' => array( 'type' => 'integer' ),
									'date'        => array( 'type' => 'string' ),
									'author'      => array( 'type' => 'integer' ),
									'title'       => array( 'type' => 'string' ),
									'is_autosave' => array( 'type' => 'boolean' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeListRevisions( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayEdit( self::inputInt( self::arr( $input ), 'post_id' ) );
				},
				'meta'                => self::meta( true, false, true ),
			)
		);
	}

	/** Register specflux/restore-revision. */
	private static function registerRestoreRevision(): void {
		wp_register_ability(
			'specflux/restore-revision',
			array(
				'label'               => __( 'Restore a revision', 'specflux-site-agent' ),
				'description'         => __( 'Restore a post to one of its revisions. WordPress records the current state as a new revision first, so this can be undone.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => self::idSchema( 'revision_id' ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'revision_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id'     => array( 'type' => 'integer' ),
						'revision_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeRestoreRevision( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayRestoreRevision( self::inputInt( self::arr( $input ), 'revision_id' ) );
				},
				// Not destructive: the pre-restore state becomes a new revision.
				'meta'                => self::meta( false, false, false ),
			)
		);
	}

	/** Register specflux/list-post-meta. */
	private static function registerListPostMeta(): void {
		wp_register_ability(
			'specflux/list-post-meta',
			array(
				'label'               => __( 'List post meta', 'specflux-site-agent' ),
				'description'         => __( 'List the custom fields a post exposes: only keys registered for its post type as REST-visible single scalar values. Other keys are never returned.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => self::idSchema( 'post_id' ),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'meta' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
						'meta'    => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'additionalProperties' => false,
								'properties'           => array(
									'key'   => array( 'type' => 'string' ),
									'value' => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'null' ) ),
									'type'  => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeListPostMeta( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					return self::mayEdit( self::inputInt( self::arr( $input ), 'post_id' ) );
				},
				'meta'                => self::meta( true, false, true ),
			)
		);
	}

	/** Register specflux/set-post-meta. */
	private static function registerSetPostMeta(): void {
		wp_register_ability(
			'specflux/set-post-meta',
			array(
				'label'               => __( 'Set post meta', 'specflux-site-agent' ),
				'description'         => __( 'Set one custom field on a post. Only keys registered for the post type as REST-visible single scalar values can be set, and the value must match the registered type. String values are limited to 10000 characters.', 'specflux-site-agent' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'key', 'value' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
						'value'   => array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'post_id', 'key', 'value' ),
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array( 'type' => 'integer' ),
						'key'     => array( 'type' => 'string' ),
						'value'   => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'null' ) ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeSetPostMeta( self::arr( $input ) );
				},
				'permission_callback' => static function ( $input = array() ) {
					$input = self::arr( $input );

					return self::mayEditMeta( self::inputInt( $input, 'post_id' ), (string) ( $input['key'] ?? '' ) );
				},
				'meta'                => self::meta( false, false, true ),
			)
		);
	}

	// --- Execute ------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeListPosts( array $input ): array|WP_Error {
		$post_type = (string) ( $input['post_type'] ?? 'post' );
		if ( ! self::mayListType( $post_type ) ) {
			return self::forbidden();
		}

		if ( ! class_exists( '\WP_Query' ) ) {
			return new WP_Error( 'query_unavailable', __( 'Query unavailable.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		$statuses = self::DEFAULT_STATUSES;
		if ( isset( $input['status'] ) && is_array( $input['status'] ) ) {
			$statuses = array_values( array_intersect( array_map( 'strval', $input['status'] ), self::STATUSES ) );
			if ( array() === $statuses ) {
				$statuses = self::DEFAULT_STATUSES;
			}
		}

		$per_page   = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );
		$count_only = ! empty( $input['count_only'] );
		$orderby    = (string) ( $input['orderby'] ?? 'date' );
		if ( ! in_array( $orderby, array( 'date', 'modified', 'title' ), true ) ) {
			$orderby = 'date';
		}

		$args = array(
			'post_type'           => $post_type,
			'post_status'         => $statuses,
			'orderby'             => $orderby,
			'order'               => 'asc' === strtolower( (string) ( $input['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC',
			'posts_per_page'      => $count_only ? -1 : $per_page,
			'paged'               => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
		);

		if ( $count_only ) {
			$args['fields'] = 'ids';
			unset( $args['paged'] );
		}

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = substr( trim( (string) $input['search'] ), 0, 200 );
		}

		if ( isset( $input['author'] ) && (int) $input['author'] > 0 ) {
			$args['author'] = (int) $input['author'];
		}

		$date_query = array();
		foreach ( array(
			'date_after'  => 'after',
			'date_before' => 'before',
		) as $key => $bound ) {
			if ( ! isset( $input[ $key ] ) || '' === trim( (string) $input[ $key ] ) ) {
				continue;
			}
			$when = trim( (string) $input[ $key ] );
			if ( false === strtotime( $when ) ) {
				return new WP_Error(
					'invalid_date',
					/* translators: %s: input property name. */
					sprintf( __( '%s must be an ISO 8601 date or Y-m-d.', 'specflux-site-agent' ), $key ),
					array( 'status' => 400 )
				);
			}
			$date_query[ $bound ] = $when;
		}
		if ( array() !== $date_query ) {
			$date_query['inclusive'] = true;
			$date_query['column']    = 'post_date';
			$args['date_query']      = array( $date_query );
		}

		$query = new \WP_Query( $args );
		$posts = is_array( $query->posts ) ? $query->posts : array();

		// The ability permission only checks the type's edit capability, so
		// each hit still needs its own edit check. `total` comes from the
		// query and cannot see this filter: it is reduced by what this page
		// dropped (other pages are not re-checked).
		$editable = array_values(
			array_filter(
				$posts,
				static fn ( $p ) => ( is_object( $p ) || is_int( $p ) ) && current_user_can( 'edit_post', is_object( $p ) ? (int) $p->ID : $p )
			)
		);

		if ( $count_only ) {
			// Ids are cheap, so the exact editable total is counted, not estimated.
			$total = count( $editable );

			return array(
				'total'       => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			);
		}

		$dropped = count( $posts ) - count( $editable );

		$shaped = array();
		foreach ( $editable as $p ) {
			if ( is_object( $p ) ) {
				$shaped[] = self::shapePost( $p );
			}
		}

		return array(
			'posts'       => $shaped,
			'total'       => max( 0, (int) $query->found_posts - $dropped ),
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeTrashPost( array $input ): array|WP_Error {
		$id = self::inputInt( $input, 'id' );
		if ( ! self::mayDelete( $id ) ) {
			return self::forbidden();
		}

		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return self::forbidden();
		}

		if ( 'trash' === $post->post_status ) {
			return new WP_Error( 'already_trashed', __( 'That post is already in the trash.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		// wp_trash_post() force-deletes when the trash is disabled; refuse
		// rather than let a "reversible" call destroy the post.
		if ( self::trashDays() <= 0 ) {
			return new WP_Error(
				'trash_disabled',
				__( 'This site has the trash disabled (EMPTY_TRASH_DAYS is 0), so trashing would delete the post permanently. Nothing was changed.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		$previous = (string) $post->post_status;
		if ( ! wp_trash_post( $id ) ) {
			return new WP_Error( 'trash_failed', __( 'The post could not be moved to the trash.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		return array(
			'id'              => $id,
			'status'          => 'trash',
			'previous_status' => $previous,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeRestorePost( array $input ): array|WP_Error {
		$id = self::inputInt( $input, 'id' );
		if ( ! self::mayDelete( $id ) ) {
			return self::forbidden();
		}

		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return self::forbidden();
		}

		if ( 'trash' !== $post->post_status ) {
			return new WP_Error( 'not_trashed', __( 'That post is not in the trash.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		if ( ! wp_untrash_post( $id ) ) {
			return new WP_Error( 'restore_failed', __( 'The post could not be restored.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		return array(
			'id'     => $id,
			'status' => (string) get_post_status( $id ),
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeListRevisions( array $input ): array|WP_Error {
		$id = self::inputInt( $input, 'post_id' );
		if ( ! self::mayEdit( $id ) ) {
			return self::forbidden();
		}

		$limit = max( 1, min( 50, (int) ( $input['limit'] ?? 20 ) ) );

		$revisions = array();
		$found     = wp_get_post_revisions(
			$id,
			array(
				'numberposts' => $limit,
				'order'       => 'DESC',
			)
		);
		foreach ( array_slice( array_values( $found ), 0, $limit ) as $revision ) {
			if ( ! is_object( $revision ) ) {
				continue;
			}
			$revisions[] = array(
				'revision_id' => (int) $revision->ID,
				'date'        => (string) $revision->post_date,
				'author'      => (int) $revision->post_author,
				'title'       => (string) $revision->post_title,
				'is_autosave' => false !== wp_is_post_autosave( $revision ),
			);
		}

		return array(
			'post_id'   => $id,
			'revisions' => $revisions,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeRestoreRevision( array $input ): array|WP_Error {
		$revision_id = self::inputInt( $input, 'revision_id' );
		if ( ! self::mayRestoreRevision( $revision_id ) ) {
			return self::forbidden();
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! is_object( $revision ) ) {
			return self::forbidden();
		}

		$restored = wp_restore_post_revision( $revision_id );
		if ( ! $restored ) {
			return new WP_Error( 'restore_failed', __( 'The revision could not be restored.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		return array(
			'post_id'     => (int) $revision->post_parent,
			'revision_id' => $revision_id,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeListPostMeta( array $input ): array|WP_Error {
		$id = self::inputInt( $input, 'post_id' );
		if ( ! self::mayEdit( $id ) ) {
			return self::forbidden();
		}

		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return self::forbidden();
		}

		$meta = array();
		foreach ( self::exposedMetaKeys( (string) $post->post_type ) as $key => $type ) {
			if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) {
				continue;
			}
			$meta[] = array(
				'key'   => $key,
				'value' => self::readMetaValue( $id, $key, $type ),
				'type'  => $type,
			);
		}

		return array(
			'post_id' => $id,
			'meta'    => $meta,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeSetPostMeta( array $input ): array|WP_Error {
		$id  = self::inputInt( $input, 'post_id' );
		$key = (string) ( $input['key'] ?? '' );
		if ( ! self::mayEditMeta( $id, $key ) ) {
			return self::forbidden();
		}

		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return self::forbidden();
		}

		// One generic answer for "unregistered", "not REST-visible", "not
		// scalar" and "not single": never reveal which keys exist.
		$exposed = self::exposedMetaKeys( (string) $post->post_type );
		if ( ! isset( $exposed[ $key ] ) ) {
			return new WP_Error( 'meta_not_registered', __( 'That meta key cannot be set here.', 'specflux-site-agent' ), array( 'status' => 400 ) );
		}

		$type    = $exposed[ $key ];
		$checked = self::coerceMetaValue( $input['value'] ?? null, $type );
		if ( null === $checked ) {
			return new WP_Error(
				'meta_invalid_value',
				/* translators: %s: registered meta type. */
				sprintf( __( 'The value does not match the registered type (%s).', 'specflux-site-agent' ), $type ),
				array( 'status' => 400 )
			);
		}

		$value = $checked['value'];

		// update_post_meta() expects slashed data and unslashes it.
		$stored = is_string( $value ) ? wp_slash( $value ) : $value;

		// update_post_meta() also returns false when the value is unchanged,
		// so a false is only a failure if the stored value is not the new one.
		if ( false === update_post_meta( $id, $key, $stored ) && self::readMetaValue( $id, $key, $type ) !== $value ) {
			return new WP_Error( 'meta_update_failed', __( 'The meta value could not be saved.', 'specflux-site-agent' ), array( 'status' => 500 ) );
		}

		return array(
			'post_id' => $id,
			'key'     => $key,
			'value'   => self::readMetaValue( $id, $key, $type ),
		);
	}

	// --- Permissions --------------------------------------------------------

	/**
	 * Whether the current user may list this type: it is allowed and the
	 * type's own `edit_posts` capability is held.
	 *
	 * @param string $post_type Post type slug.
	 */
	private static function mayListType( string $post_type ): bool {
		$cap = self::typeCap( $post_type, 'edit_posts' );

		return null !== $cap && current_user_can( $cap );
	}

	/**
	 * Real type capability AND per-object `edit_post` for an existing post of
	 * an allowed type.
	 *
	 * @param int $id Post id.
	 */
	private static function mayEdit( int $id ): bool {
		return self::mayOnPost( $id, 'edit_posts', 'edit_post' );
	}

	/**
	 * Real type capability AND per-object `delete_post`.
	 *
	 * @param int $id Post id.
	 */
	private static function mayDelete( int $id ): bool {
		return self::mayOnPost( $id, 'delete_posts', 'delete_post' );
	}

	/**
	 * `edit_post` on the post plus `edit_post_meta` for the key.
	 *
	 * @param int    $id  Post id.
	 * @param string $key Meta key.
	 */
	private static function mayEditMeta( int $id, string $key ): bool {
		return '' !== $key && self::mayEdit( $id ) && current_user_can( 'edit_post_meta', $id, $key );
	}

	/**
	 * A revision is restorable when it is a real revision, its parent is an
	 * allowed type, and the user can edit the PARENT and read the revision.
	 *
	 * @param int $revision_id Revision id.
	 */
	private static function mayRestoreRevision( int $revision_id ): bool {
		if ( $revision_id <= 0 ) {
			return false;
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! is_object( $revision ) || 'revision' !== $revision->post_type ) {
			return false;
		}

		return self::mayEdit( (int) $revision->post_parent )
			&& current_user_can( 'read_post', $revision_id );
	}

	/**
	 * @param int    $id        Post id.
	 * @param string $type_cap  Property of the post type's `cap` object.
	 * @param string $meta_cap  Per-object capability.
	 */
	private static function mayOnPost( int $id, string $type_cap, string $meta_cap ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return false;
		}

		$cap = self::typeCap( (string) $post->post_type, $type_cap );

		return null !== $cap && current_user_can( $cap ) && current_user_can( $meta_cap, $id );
	}

	// --- Helpers ------------------------------------------------------------

	/**
	 * The named capability of an allowed post type, or null when the type is
	 * not allowed. Post and page are always allowed; any other type must be
	 * REST-visible and not on the excluded list.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $cap_name  Property of the type's `cap` object.
	 */
	private static function typeCap( string $post_type, string $cap_name ): ?string {
		if ( '' === $post_type || in_array( $post_type, self::EXCLUDED_TYPES, true ) || ! function_exists( 'get_post_type_object' ) ) {
			return null;
		}

		$object = get_post_type_object( $post_type );
		if ( null === $object ) {
			return null;
		}

		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) && empty( $object->show_in_rest ) ) {
			return null;
		}

		$cap = $object->cap->{$cap_name} ?? null;

		return is_string( $cap ) && '' !== $cap ? $cap : null;
	}

	/**
	 * Meta keys this class exposes for a post type, as key => registered type.
	 * Registered, REST-visible, single, scalar. Nothing else.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string,string>
	 */
	private static function exposedMetaKeys( string $post_type ): array {
		if ( ! function_exists( 'get_registered_meta_keys' ) ) {
			return array();
		}

		$keys = array();
		foreach ( get_registered_meta_keys( 'post', $post_type ) as $key => $args ) {
			$type = $args['type'] ?? '';
			if ( empty( $args['show_in_rest'] ) || empty( $args['single'] ) || ! is_string( $type ) || ! in_array( $type, self::META_TYPES, true ) ) {
				continue;
			}
			$keys[ (string) $key ] = $type;
		}

		return $keys;
	}

	/**
	 * Read one meta value as its registered type.
	 *
	 * @param int    $id   Post id.
	 * @param string $key  Meta key.
	 * @param string $type Registered type.
	 * @return string|int|float|bool|null
	 */
	private static function readMetaValue( int $id, string $key, string $type ): string|int|float|bool|null {
		$raw = get_post_meta( $id, $key, true );

		if ( 'boolean' === $type ) {
			return filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
		}
		if ( ! is_scalar( $raw ) ) {
			return null;
		}
		if ( 'string' === $type ) {
			return (string) $raw;
		}
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		return 'integer' === $type ? (int) $raw : $raw + 0;
	}

	/**
	 * Validate a value against a registered scalar type. Null means refused.
	 *
	 * @param mixed  $value Input value.
	 * @param string $type  Registered type.
	 * @return array{value:string|int|float|bool}|null
	 */
	private static function coerceMetaValue( mixed $value, string $type ): ?array {
		switch ( $type ) {
			case 'string':
				if ( is_string( $value ) && strlen( $value ) <= self::MAX_META_STRING ) {
					return array( 'value' => $value );
				}
				return null;

			case 'integer':
				if ( is_int( $value ) ) {
					return array( 'value' => $value );
				}
				if ( is_float( $value ) && floor( $value ) === $value ) {
					return array( 'value' => (int) $value );
				}
				if ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ) {
					return array( 'value' => (int) $value );
				}
				return null;

			case 'number':
				if ( is_int( $value ) || is_float( $value ) ) {
					return array( 'value' => $value );
				}
				if ( is_string( $value ) && is_numeric( $value ) ) {
					return array( 'value' => $value + 0 );
				}
				return null;

			case 'boolean':
				if ( is_bool( $value ) ) {
					return array( 'value' => $value );
				}
				if ( 'true' === $value || 'false' === $value ) {
					return array( 'value' => 'true' === $value );
				}
				return null;
		}

		return null;
	}

	/**
	 * @param \WP_Post $post A post.
	 * @return array<string,mixed>
	 */
	private static function shapePost( object $post ): array {
		$id = (int) $post->ID;

		return array(
			'id'        => $id,
			'post_type' => (string) $post->post_type,
			'status'    => (string) $post->post_status,
			'title'     => (string) $post->post_title,
			'slug'      => (string) $post->post_name,
			'author'    => (int) $post->post_author,
			'date'      => (string) $post->post_date,
			'modified'  => (string) $post->post_modified,
			'link'      => function_exists( 'get_permalink' ) ? (string) get_permalink( $id ) : '',
		);
	}

	/**
	 * A one-integer input schema.
	 *
	 * @param string $property The property name.
	 * @return array<string,mixed>
	 */
	private static function idSchema( string $property ): array {
		return array(
			'type'                 => 'object',
			'required'             => array( $property ),
			'additionalProperties' => false,
			'properties'           => array(
				$property => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input    Input.
	 * @param string              $property Property name.
	 */
	private static function inputInt( array $input, string $property ): int {
		return isset( $input[ $property ] ) && is_numeric( $input[ $property ] ) ? (int) $input[ $property ] : 0;
	}

	/**
	 * @param mixed $input Raw callback input.
	 * @return array<string,mixed>
	 */
	private static function arr( mixed $input ): array {
		return is_array( $input ) ? $input : array();
	}

	/** The one generic refusal. */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'specflux-site-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Ability meta with the shared annotation shape.
	 *
	 * @param bool $read_only   Read-only.
	 * @param bool $destructive Irreversible (Belayline Agent Governance parks these).
	 * @param bool $idempotent  Idempotent.
	 * @return array<string,mixed>
	 */
	private static function meta( bool $read_only, bool $destructive, bool $idempotent ): array {
		return array(
			'annotations' => array(
				'readonly'    => $read_only,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'specflux'    => array( 'hidden' => false ),
		);
	}
}
