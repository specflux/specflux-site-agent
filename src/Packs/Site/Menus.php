<?php
/**
 * The classic-menu registrar: `senroflux/read-menus`, `senroflux/add-menu-item`,
 * `senroflux/remove-menu-item` and `senroflux/reorder-menu-items`.
 *
 * CLASSIC menus only (nav_menu terms and nav_menu_item posts). Block themes
 * keep their menus in wp_navigation posts, handled by the Navigation
 * abilities. Every ability needs `edit_theme_options`, re-checked at execute.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Site;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Registers the four classic-menu abilities.
 */
final class Menus {

	/** The capability that gates every ability. */
	private const CAPABILITY = 'edit_theme_options';

	/** Longest accepted menu item title. */
	private const MAX_TITLE = 200;

	/** Longest accepted custom URL. */
	private const MAX_URL = 2000;

	/** Most items one reorder call may carry. */
	private const MAX_ITEMS = 200;

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

		self::registerRead();
		self::registerAdd();
		self::registerRemove();
		self::registerReorder();
	}

	/** senroflux/read-menus. */
	private static function registerRead(): void {
		wp_register_ability(
			'senroflux/read-menus',
			array(
				'label'               => __( 'Read classic menus', 'senroflux' ),
				'description'         => __( 'List the classic (non block-theme) navigation menus, or with menu_id the items of one menu. Block themes use the navigation abilities instead. Items pointing at content you cannot read are left out.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'default'              => array(),
					'properties'           => array(
						'menu_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'menus' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'menu'  => array( 'type' => 'object' ),
						'items' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeRead( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( self::CAPABILITY );
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

	/** senroflux/add-menu-item. */
	private static function registerAdd(): void {
		wp_register_ability(
			'senroflux/add-menu-item',
			array(
				'label'               => __( 'Add a classic menu item', 'senroflux' ),
				'description'         => __( 'Add one item to a classic (non block-theme) menu. type post_type links a published post or page (object_id), taxonomy links a term (object_id), custom needs a title and an http, https or relative url. The title is up to 200 characters. parent is a menu item id in the same menu (default 0). Block themes use the navigation abilities instead.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id', 'type' ),
					'additionalProperties' => false,
					'properties'           => array(
						'menu_id'   => array( 'type' => 'integer' ),
						'type'      => array(
							'type' => 'string',
							'enum' => array( 'post_type', 'custom', 'taxonomy' ),
						),
						'object_id' => array( 'type' => 'integer' ),
						'title'     => array( 'type' => 'string' ),
						'url'       => array( 'type' => 'string' ),
						'parent'    => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'position'  => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id', 'item_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'menu_id' => array( 'type' => 'integer' ),
						'item_id' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeAdd( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function ( $input = null ) {
					return self::canAdd( is_array( $input ) ? $input : array() );
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

	/** senroflux/remove-menu-item. */
	private static function registerRemove(): void {
		wp_register_ability(
			'senroflux/remove-menu-item',
			array(
				'label'               => __( 'Remove a classic menu item', 'senroflux' ),
				'description'         => __( 'Permanently delete one item from a classic (non block-theme) menu; menu items have no trash. Child items move up to the removed item\'s parent. The item must belong to menu_id.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id', 'item_id' ),
					'additionalProperties' => false,
					'properties'           => array(
						'menu_id' => array( 'type' => 'integer' ),
						'item_id' => array( 'type' => 'integer' ),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id', 'item_id', 'removed' ),
					'additionalProperties' => false,
					'properties'           => array(
						'menu_id' => array( 'type' => 'integer' ),
						'item_id' => array( 'type' => 'integer' ),
						'removed' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeRemove( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( self::CAPABILITY );
				},
				'meta'                => self::meta(
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					)
				),
			)
		);
	}

	/** senroflux/reorder-menu-items. */
	private static function registerReorder(): void {
		wp_register_ability(
			'senroflux/reorder-menu-items',
			array(
				'label'               => __( 'Reorder classic menu items', 'senroflux' ),
				'description'         => __( 'Set the order of the items in a classic (non block-theme) menu. order must list EVERY current item id of the menu exactly once. Titles, links and parents are left as they are; only positions change.', 'senroflux' ),
				'category'            => Navigation::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id', 'order' ),
					'additionalProperties' => false,
					'properties'           => array(
						'menu_id' => array( 'type' => 'integer' ),
						'order'   => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => self::MAX_ITEMS,
							'items'    => array( 'type' => 'integer' ),
						),
					),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'required'             => array( 'menu_id', 'order' ),
					'additionalProperties' => false,
					'properties'           => array(
						'menu_id' => array( 'type' => 'integer' ),
						'order'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
				),
				'execute_callback'    => static function ( $input = array() ) {
					return self::executeReorder( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return current_user_can( self::CAPABILITY );
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

	/**
	 * Base capability plus read_post on the post a post_type item would link.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private static function canAdd( array $input ): bool {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return false;
		}

		if ( 'post_type' === ( $input['type'] ?? '' ) && isset( $input['object_id'] ) && is_numeric( $input['object_id'] ) ) {
			$object_id = (int) $input['object_id'];
			if ( $object_id > 0 && ! current_user_can( 'read_post', $object_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeRead( array $input ): array|WP_Error {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::forbidden();
		}

		if ( isset( $input['menu_id'] ) ) {
			$menu = self::menu( $input['menu_id'] );
			if ( $menu instanceof WP_Error ) {
				return $menu;
			}

			$items = array();
			foreach ( self::menuItems( (int) $menu->term_id, false ) as $item ) {
				$type      = (string) ( $item->type ?? '' );
				$object_id = (int) ( $item->object_id ?? 0 );
				if ( 'post_type' === $type && $object_id > 0 && ! current_user_can( 'read_post', $object_id ) ) {
					continue;
				}
				$items[] = array(
					'id'        => (int) $item->ID,
					'title'     => (string) ( $item->title ?? '' ),
					'url'       => (string) ( $item->url ?? '' ),
					'type'      => $type,
					'object'    => (string) ( $item->object ?? '' ),
					'object_id' => $object_id,
					'parent'    => (int) ( $item->menu_item_parent ?? 0 ),
					'position'  => (int) $item->menu_order,
				);
			}

			return array(
				'menu'  => array(
					'id'   => (int) $menu->term_id,
					'name' => (string) $menu->name,
					'slug' => (string) $menu->slug,
				),
				'items' => $items,
			);
		}

		$registered = function_exists( 'get_registered_nav_menus' ) ? array_keys( get_registered_nav_menus() ) : array();
		$locations  = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
		$menus      = array();
		$all        = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus() : array();
		foreach ( $all as $menu ) {
			$assigned = array();
			foreach ( $locations as $slug => $assigned_id ) {
				if ( (int) $assigned_id === (int) $menu->term_id && in_array( $slug, $registered, true ) ) {
					$assigned[] = (string) $slug;
				}
			}
			$menus[] = array(
				'id'         => (int) $menu->term_id,
				'name'       => (string) $menu->name,
				'slug'       => (string) $menu->slug,
				'item_count' => (int) $menu->count,
				'locations'  => $assigned,
			);
		}

		return array( 'menus' => $menus );
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeAdd( array $input ): array|WP_Error {
		if ( ! self::canAdd( $input ) ) {
			return self::forbidden();
		}

		$menu = self::menu( $input['menu_id'] ?? null );
		if ( $menu instanceof WP_Error ) {
			return $menu;
		}
		$menu_id = (int) $menu->term_id;

		$type = (string) ( $input['type'] ?? '' );
		if ( ! in_array( $type, array( 'post_type', 'custom', 'taxonomy' ), true ) ) {
			return self::bad( 'invalid_type', __( 'type must be post_type, custom or taxonomy.', 'senroflux' ) );
		}

		$title = '';
		if ( isset( $input['title'] ) ) {
			if ( ! is_string( $input['title'] ) ) {
				return self::bad( 'invalid_title', __( 'title must be a string.', 'senroflux' ) );
			}
			$title = sanitize_text_field( $input['title'] );
			if ( mb_strlen( $title ) > self::MAX_TITLE ) {
				return self::bad( 'invalid_title', __( 'title is too long (200 characters at most).', 'senroflux' ) );
			}
		}

		$args = array(
			'menu-item-type'   => $type,
			'menu-item-status' => 'publish',
			'menu-item-title'  => $title,
		);

		if ( 'custom' === $type ) {
			if ( '' === $title ) {
				return self::bad( 'invalid_title', __( 'A custom item needs a title.', 'senroflux' ) );
			}
			$url = self::safeUrl( $input['url'] ?? null );
			if ( null === $url ) {
				return self::bad( 'invalid_url', __( 'url must be an http, https or relative URL.', 'senroflux' ) );
			}
			$args['menu-item-url']    = $url;
			$args['menu-item-object'] = 'custom';
		} else {
			$object_id = isset( $input['object_id'] ) && is_numeric( $input['object_id'] ) ? (int) $input['object_id'] : 0;
			if ( $object_id <= 0 ) {
				return self::bad( 'invalid_object', __( 'object_id is required for this type.', 'senroflux' ) );
			}
			$object = 'post_type' === $type ? self::postObject( $object_id ) : self::termObject( $object_id );
			if ( $object instanceof WP_Error ) {
				return $object;
			}
			$args['menu-item-object']    = $object;
			$args['menu-item-object-id'] = $object_id;
		}

		$parent = isset( $input['parent'] ) && is_numeric( $input['parent'] ) ? (int) $input['parent'] : 0;
		if ( $parent < 0 ) {
			return self::bad( 'invalid_parent', __( 'parent must be 0 or a menu item id.', 'senroflux' ) );
		}
		if ( $parent > 0 && ! isset( self::itemsById( $menu_id )[ $parent ] ) ) {
			return self::bad( 'invalid_parent', __( 'parent must be an item of the same menu.', 'senroflux' ) );
		}
		$args['menu-item-parent-id'] = $parent;

		if ( isset( $input['position'] ) ) {
			if ( ! is_numeric( $input['position'] ) || (int) $input['position'] < 1 ) {
				return self::bad( 'invalid_position', __( 'position must be a positive integer.', 'senroflux' ) );
			}
			$args['menu-item-position'] = (int) $input['position'];
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( $item_id instanceof WP_Error ) {
			return $item_id;
		}

		return array(
			'menu_id' => $menu_id,
			'item_id' => (int) $item_id,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeRemove( array $input ): array|WP_Error {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::forbidden();
		}

		$menu = self::menu( $input['menu_id'] ?? null );
		if ( $menu instanceof WP_Error ) {
			return $menu;
		}
		$menu_id = (int) $menu->term_id;

		$item_id = isset( $input['item_id'] ) && is_numeric( $input['item_id'] ) ? (int) $input['item_id'] : 0;
		if ( $item_id <= 0 || ! isset( self::itemsById( $menu_id )[ $item_id ] ) ) {
			return new WP_Error(
				'item_not_in_menu',
				__( 'That item does not belong to this menu.', 'senroflux' ),
				array( 'status' => 404 )
			);
		}

		// Menu items have no trash: this is a hard delete. Core re-parents the
		// removed item's children to its own parent.
		if ( ! wp_delete_post( $item_id, true ) ) {
			return new WP_Error(
				'delete_failed',
				__( 'The menu item could not be removed.', 'senroflux' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'menu_id' => $menu_id,
			'item_id' => $item_id,
			'removed' => true,
		);
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function executeReorder( array $input ): array|WP_Error {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::forbidden();
		}

		$menu = self::menu( $input['menu_id'] ?? null );
		if ( $menu instanceof WP_Error ) {
			return $menu;
		}
		$menu_id = (int) $menu->term_id;

		$raw = $input['order'] ?? null;
		if ( ! is_array( $raw ) || array() === $raw || count( $raw ) > self::MAX_ITEMS ) {
			return self::bad( 'invalid_order', __( 'order must list between 1 and 200 menu item ids.', 'senroflux' ) );
		}

		$order = array();
		foreach ( $raw as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) {
				return self::bad( 'invalid_order', __( 'order must contain only integer menu item ids.', 'senroflux' ) );
			}
			$order[] = (int) $id;
		}

		$items = self::itemsById( $menu_id );
		if ( count( $order ) !== count( array_unique( $order ) ) ) {
			return self::bad( 'invalid_order', __( 'order contains a duplicate id.', 'senroflux' ) );
		}
		$current = array_keys( $items );
		sort( $current );
		$wanted = $order;
		sort( $wanted );
		if ( $current !== $wanted ) {
			return self::bad( 'invalid_order', __( 'order must contain exactly the current item ids of the menu, no more and no fewer.', 'senroflux' ) );
		}

		foreach ( $order as $index => $id ) {
			$result = wp_update_nav_menu_item( $menu_id, $id, self::preservedArgs( $items[ $id ], $index + 1 ) );
			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		return array(
			'menu_id' => $menu_id,
			'order'   => $order,
		);
	}

	/**
	 * Every field `wp_update_nav_menu_item()` would otherwise reset, taken from
	 * the existing item, with only the position changed. Raw post columns are
	 * preferred over the decorated properties (which are filtered or trimmed).
	 *
	 * @param object $item     A `wp_get_nav_menu_items()` entry.
	 * @param int    $position New 1-based position.
	 * @return array<string,mixed>
	 */
	private static function preservedArgs( object $item, int $position ): array {
		$type    = (string) ( $item->type ?? '' );
		$classes = $item->classes ?? array();

		return array(
			'menu-item-title'       => (string) ( $item->post_title ?? $item->title ?? '' ),
			'menu-item-url'         => ( 'post_type' === $type || 'taxonomy' === $type ) ? '' : (string) ( $item->url ?? '' ),
			'menu-item-object-id'   => (int) ( $item->object_id ?? 0 ),
			'menu-item-object'      => (string) ( $item->object ?? '' ),
			'menu-item-type'        => '' === $type ? 'custom' : $type,
			'menu-item-parent-id'   => (int) ( $item->menu_item_parent ?? 0 ),
			'menu-item-position'    => $position,
			'menu-item-attr-title'  => (string) ( $item->post_excerpt ?? $item->attr_title ?? '' ),
			'menu-item-target'      => (string) ( $item->target ?? '' ),
			'menu-item-classes'     => implode( ' ', array_map( 'strval', is_array( $classes ) ? $classes : array() ) ),
			'menu-item-xfn'         => (string) ( $item->xfn ?? '' ),
			'menu-item-description' => (string) ( $item->post_content ?? $item->description ?? '' ),
			'menu-item-status'      => (string) ( $item->post_status ?? 'publish' ),
		);
	}

	/**
	 * Resolve a menu id to its nav_menu term.
	 *
	 * @param mixed $menu_id Raw menu id.
	 * @return \WP_Term|WP_Error
	 */
	private static function menu( mixed $menu_id ): object {
		$id   = is_numeric( $menu_id ) ? (int) $menu_id : 0;
		$menu = $id > 0 && function_exists( 'wp_get_nav_menu_object' ) ? wp_get_nav_menu_object( $id ) : false;
		if ( ! is_object( $menu ) ) {
			return new WP_Error(
				'menu_not_found',
				__( 'Menu not found.', 'senroflux' ),
				array( 'status' => 404 )
			);
		}

		return $menu;
	}

	/**
	 * The items of one menu.
	 *
	 * @param int  $menu_id Menu term id.
	 * @param bool $any     Include items of every post status (not only published).
	 * @return list<\WP_Post>
	 */
	private static function menuItems( int $menu_id, bool $any ): array {
		if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$items = $any ? wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) : wp_get_nav_menu_items( $menu_id );

		return is_array( $items ) ? array_values( $items ) : array();
	}

	/**
	 * A menu's items of any status keyed by menu-item id.
	 *
	 * @param int $menu_id Menu term id.
	 * @return array<int,\WP_Post>
	 */
	private static function itemsById( int $menu_id ): array {
		$by_id = array();
		foreach ( self::menuItems( $menu_id, true ) as $item ) {
			$by_id[ (int) $item->ID ] = $item;
		}

		return $by_id;
	}

	/**
	 * The post type of a linkable post, or a refusal.
	 *
	 * @param int $post_id Post id.
	 * @return string|WP_Error
	 */
	private static function postObject( int $post_id ): string|WP_Error {
		$post = get_post( $post_id );
		if ( ! is_object( $post ) || ! current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error(
				'object_not_found',
				__( 'That content was not found.', 'senroflux' ),
				array( 'status' => 404 )
			);
		}

		$post_type = (string) $post->post_type;
		if ( 'nav_menu_item' === $post_type || ! is_post_type_viewable( $post_type ) ) {
			return self::bad( 'invalid_object', __( 'That content type cannot be linked from a menu.', 'senroflux' ) );
		}

		// Published, or private and readable (read_post was checked above).
		$status = (string) $post->post_status;
		if ( 'publish' !== $status && 'private' !== $status ) {
			return self::bad( 'invalid_object', __( 'Only published content can be added to a menu.', 'senroflux' ) );
		}

		return $post_type;
	}

	/**
	 * The taxonomy of a linkable term, or a refusal.
	 *
	 * @param int $term_id Term id.
	 * @return string|WP_Error
	 */
	private static function termObject( int $term_id ): string|WP_Error {
		$term = get_term( $term_id );
		if ( ! is_object( $term ) || $term instanceof WP_Error || empty( $term->taxonomy ) ) {
			return new WP_Error(
				'object_not_found',
				__( 'That term was not found.', 'senroflux' ),
				array( 'status' => 404 )
			);
		}

		$taxonomy = get_taxonomy( (string) $term->taxonomy );
		if ( ! is_object( $taxonomy ) || empty( $taxonomy->public ) || empty( $taxonomy->show_in_nav_menus ) ) {
			return self::bad( 'invalid_object', __( 'That taxonomy cannot be linked from a menu.', 'senroflux' ) );
		}

		return (string) $term->taxonomy;
	}

	/**
	 * A custom URL restricted to http, https and relative forms, or null.
	 *
	 * @param mixed $raw Raw url input.
	 */
	private static function safeUrl( mixed $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		// Control characters and whitespace are how scheme filters get bypassed.
		$url = trim( (string) preg_replace( '/[\x00-\x20\x7f]+/', '', $raw ) );
		if ( '' === $url || strlen( $url ) > self::MAX_URL ) {
			return null;
		}

		if ( 1 === preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $url, $match ) && ! in_array( strtolower( $match[1] ), array( 'http', 'https' ), true ) ) {
			return null;
		}

		$clean = esc_url_raw( $url, array( 'http', 'https' ) );

		return '' === $clean ? null : $clean;
	}

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private static function bad( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}

	private static function forbidden(): WP_Error {
		return new WP_Error(
			'forbidden',
			__( 'You are not allowed to do that.', 'senroflux' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * @param array<string,mixed> $annotations Ability meta annotations.
	 * @return array<string,mixed>
	 */
	private static function meta( array $annotations ): array {
		return array(
			'annotations' => $annotations,
			'senroflux'   => array( 'hidden' => false ),
		);
	}
}
