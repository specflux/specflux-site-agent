<?php
/**
 * The tool-breadth abilities' pack wiring (0.5 K5).
 *
 * One table that says, for each breadth ability, which pack role carries it,
 * the pack verb suffix, its tier, the capability a user needs for the role to
 * be offered at all, and the input keys a client sends (the S9 shape-compat
 * seam). The pages, posts and site packs each pull the groups that fit them,
 * so a verb, its tier and its Agent Safety classification can never drift
 * between packs — the {@see ContentBundle} union then needs no change.
 *
 * Tiers follow the existing precedent: reads are 0; reversible writes are 1
 * (restoring a post, which WordPress brings back as a draft; a post-meta write
 * is undone by Belayline Agent Governance's post-meta capture); anything with a public effect
 * on the site, or that grants access, is 2 (`site/set-front-page`,
 * `site/update-navigation`, publishing, approving a held comment, replying as
 * the site, a role change). Trashing a post is 2 for every post type: it takes
 * a live page or post off the site at once, so it needs approval even though
 * it is reversible (kept simple and fail-closed rather than status-dependent). There is no hard-delete verb here at all.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Static wiring table for the K5 abilities.
 */
final class ToolBreadth {

	/** Posts, pages and public custom post types: list, trash, restore, revisions, meta. */
	public const CONTENT = 'content';

	/** Comment moderation. */
	public const COMMENTS = 'comments';

	/** Users, site settings, classic menus, plugin and theme inventory. */
	public const SITE = 'site';

	/**
	 * Key for a verb whose written object is NOT a post. The run tracker
	 * records a written id under the verb's `objectIdKey`, and the report
	 * resolves a bare id as a post, so a comment or option written under
	 * `id` would be shown as an unrelated post. A key that no output carries
	 * tracks nothing instead.
	 */
	public const UNTRACKED = 'untracked';

	/**
	 * role => [ability template, verb suffix, tier, capability, input keys, object-id key].
	 *
	 * @return array<string,array<string,array{0:string,1:string,2:int,3:string,4:list<string>,5:string}>>
	 */
	private static function table(): array {
		return array(
			self::CONTENT  => array(
				'list-posts'       => array( 'list-posts', 'list-posts', 0, '', array( 'post_type', 'search', 'status', 'author', 'date_after', 'date_before', 'orderby', 'order', 'page', 'per_page', 'count_only' ), 'id' ),
				'trash'            => array( 'trash-post', 'trash-post', 2, '', array( 'id' ), 'id' ),
				'restore'          => array( 'restore-post', 'restore-post', 1, '', array( 'id' ), 'id' ),
				'revisions'        => array( 'list-revisions', 'list-revisions', 0, '', array( 'post_id', 'limit' ), 'post_id' ),
				// Restoring overwrites the live content of what is usually a
				// published post, so it is Tier 2 like an edit to a public post.
				'restore-revision' => array( 'restore-revision', 'restore-revision', 2, '', array( 'revision_id' ), 'post_id' ),
				'read-meta'        => array( 'list-post-meta', 'list-post-meta', 0, '', array( 'post_id' ), 'post_id' ),
				'set-meta'         => array( 'set-post-meta', 'set-post-meta', 1, '', array( 'post_id', 'key', 'value' ), 'post_id' ),
			),
			self::COMMENTS => array(
				'list-comments'    => array( 'list-comments', 'list-comments', 0, 'moderate_comments', array( 'post_id', 'status', 'search', 'parent', 'page', 'per_page' ), self::UNTRACKED ),
				'moderate-comment' => array( 'moderate-comment', 'moderate-comment', 1, 'moderate_comments', array( 'id', 'action' ), self::UNTRACKED ),
				'approve-comment'  => array( 'approve-comment', 'approve-comment', 2, 'moderate_comments', array( 'id' ), self::UNTRACKED ),
				'reply-comment'    => array( 'reply-to-comment', 'reply-to-comment', 2, 'moderate_comments', array( 'comment_id', 'content' ), self::UNTRACKED ),
			),
			self::SITE     => array(
				'list-users'         => array( 'list-users', 'list-users', 0, 'list_users', array( 'search', 'role', 'page', 'per_page' ), self::UNTRACKED ),
				'read-user'          => array( 'read-user', 'read-user', 0, '', array( 'id' ), self::UNTRACKED ),
				'set-user-role'      => array( 'set-user-role', 'set-user-role', 2, 'promote_users', array( 'id', 'role' ), self::UNTRACKED ),
				'read-settings'      => array( 'read-settings', 'read-settings', 0, 'manage_options', array( 'group' ), self::UNTRACKED ),
				'update-settings'    => array( 'update-settings', 'update-settings', 2, 'manage_options', array( 'settings' ), self::UNTRACKED ),
				'read-menus'         => array( 'read-menus', 'read-menus', 0, 'edit_theme_options', array( 'menu_id' ), self::UNTRACKED ),
				'add-menu-item'      => array( 'add-menu-item', 'add-menu-item', 2, 'edit_theme_options', array( 'menu_id', 'type', 'object_id', 'title', 'url', 'parent', 'position' ), self::UNTRACKED ),
				'remove-menu-item'   => array( 'remove-menu-item', 'remove-menu-item', 2, 'edit_theme_options', array( 'menu_id', 'item_id' ), self::UNTRACKED ),
				'reorder-menu-items' => array( 'reorder-menu-items', 'reorder-menu-items', 2, 'edit_theme_options', array( 'menu_id', 'order' ), self::UNTRACKED ),
				'list-plugins'       => array( 'list-plugins', 'list-plugins', 0, 'activate_plugins', array( 'status' ), self::UNTRACKED ),
				'list-themes'        => array( 'list-themes', 'list-themes', 0, 'switch_themes', array(), self::UNTRACKED ),
			),
		);
	}

	/**
	 * role => ability template for the given groups.
	 *
	 * @param list<string> $groups Group names.
	 * @return array<string,string>
	 */
	public static function roles( array $groups ): array {
		$roles = array();
		foreach ( self::rows( $groups ) as $role => $row ) {
			$roles[ $role ] = $row[0];
		}

		return $roles;
	}

	/**
	 * Pack verb => tier for the given groups.
	 *
	 * @param string       $prefix Pack verb prefix ('pages', 'posts', 'site').
	 * @param list<string> $groups Group names.
	 * @return array<string,int>
	 */
	public static function verbMap( string $prefix, array $groups ): array {
		$map = array();
		foreach ( self::rows( $groups ) as $row ) {
			$map[ $prefix . '/' . $row[1] ] = $row[2];
		}

		return $map;
	}

	/**
	 * role => list of pack verbs (one each) for the given groups.
	 *
	 * @param string       $prefix Pack verb prefix.
	 * @param list<string> $groups Group names.
	 * @return array<string,list<string>>
	 */
	public static function roleVerbs( string $prefix, array $groups ): array {
		$verbs = array();
		foreach ( self::rows( $groups ) as $role => $row ) {
			$verbs[ $role ] = array( $prefix . '/' . $row[1] );
		}

		return $verbs;
	}

	/**
	 * role => capability needed for the role to be offered, for the given groups.
	 *
	 * @param list<string> $groups Group names.
	 * @return array<string,string>
	 */
	public static function roleCapabilities( array $groups ): array {
		$caps = array();
		foreach ( self::rows( $groups ) as $role => $row ) {
			if ( '' !== $row[3] ) {
				$caps[ $role ] = $row[3];
			}
		}

		return $caps;
	}

	/**
	 * The pack verb for one ability's base name, or null when it is not a
	 * breadth ability of the given groups.
	 *
	 * @param string       $prefix    Pack verb prefix.
	 * @param string       $base_name Ability name without the namespace.
	 * @param list<string> $groups    Group names.
	 */
	public static function verbFor( string $prefix, string $base_name, array $groups ): ?string {
		foreach ( self::rows( $groups ) as $row ) {
			if ( $row[0] === $base_name ) {
				return $prefix . '/' . $row[1];
			}
		}

		return null;
	}

	/**
	 * The input keys a client sends for one ability template ([] when unknown).
	 *
	 * @param string       $template Ability template.
	 * @param list<string> $groups   Group names.
	 * @return list<string>
	 */
	public static function inputProperties( string $template, array $groups ): array {
		foreach ( self::rows( $groups ) as $row ) {
			if ( $row[0] === $template ) {
				return $row[4];
			}
		}

		return array();
	}

	/**
	 * The object-id key for one pack verb, or null when it is not a breadth verb.
	 *
	 * @param string       $verb   Pack verb.
	 * @param list<string> $groups Group names.
	 */
	public static function objectIdKey( string $verb, array $groups ): ?string {
		foreach ( self::rows( $groups ) as $row ) {
			if ( str_ends_with( $verb, '/' . $row[1] ) ) {
				return $row[5];
			}
		}

		return null;
	}

	/**
	 * Every ability template across all groups, for tests and tooling.
	 *
	 * @return list<string>
	 */
	public static function allTemplates(): array {
		return array_values( self::roles( array( self::CONTENT, self::COMMENTS, self::SITE ) ) );
	}

	/**
	 * Is this ability id one of the breadth abilities, in a namespace a pack
	 * resolves them through (`specflux/` polyfill or `core/`)? Used to keep
	 * them out of in-admin runs; the connector does not consult it.
	 *
	 * @param string $ability Ability id.
	 */
	public static function isBreadthAbility( string $ability ): bool {
		$parts = explode( '/', $ability, 2 );

		return 2 === count( $parts )
			&& in_array( $parts[0], array( 'specflux', 'core' ), true )
			&& in_array( $parts[1], self::allTemplates(), true );
	}

	/**
	 * @param list<string> $groups Group names.
	 * @return array<string,array{0:string,1:string,2:int,3:string,4:list<string>,5:string}>
	 */
	private static function rows( array $groups ): array {
		$table = self::table();
		$rows  = array();
		foreach ( $groups as $group ) {
			$rows += $table[ $group ] ?? array();
		}

		return $rows;
	}
}
