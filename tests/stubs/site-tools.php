<?php
/**
 * Test-only stand-ins for the settings / classic-menu / inventory abilities
 * (Settings, Menus, Inventory in src/Packs/Site).
 *
 * Everything is `function_exists`-guarded so a real WordPress load order
 * (or an earlier stub file) wins. `wp_update_nav_menu_item()` is defined here
 * BEFORE `navigation.php` is pulled in so that, when this file is the first
 * to load, calls are recorded with their full argument list; when
 * `navigation.php` loaded first, `senroflux_test_nav_update_recording()`
 * reports false.
 *
 * Knobs: `senroflux_test_nav_menus` (id => term object),
 * `senroflux_test_nav_menu_items` (menu id => id => item object, shared with
 * navigation.php), `senroflux_test_plugins`, `senroflux_test_active_plugins`,
 * `senroflux_test_network_plugins`, `senroflux_test_themes`,
 * `senroflux_test_site_transients`, `senroflux_test_post_types`,
 * `senroflux_test_terms`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

require_once __DIR__ . '/blocks.php';

$GLOBALS['senroflux_test_nav_menus']        = $GLOBALS['senroflux_test_nav_menus'] ?? array();
$GLOBALS['senroflux_test_nav_update_calls'] = $GLOBALS['senroflux_test_nav_update_calls'] ?? array();
$GLOBALS['senroflux_test_plugins']          = $GLOBALS['senroflux_test_plugins'] ?? array();
$GLOBALS['senroflux_test_active_plugins']   = $GLOBALS['senroflux_test_active_plugins'] ?? array();
$GLOBALS['senroflux_test_network_plugins']  = $GLOBALS['senroflux_test_network_plugins'] ?? array();
$GLOBALS['senroflux_test_themes']           = $GLOBALS['senroflux_test_themes'] ?? array();
$GLOBALS['senroflux_test_site_transients']  = $GLOBALS['senroflux_test_site_transients'] ?? array();
$GLOBALS['senroflux_test_terms']            = $GLOBALS['senroflux_test_terms'] ?? array();

if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
	/** Recording stand-in: stores EVERY argument on the item. */
	function wp_update_nav_menu_item( $menu_id = 0, $menu_item_db_id = 0, $menu_item_data = array(), $fire_after_hooks = true ) {
		unset( $fire_after_hooks );
		$menu_id                                      = (int) $menu_id;
		$menu_item_db_id                              = (int) $menu_item_db_id;
		$GLOBALS['senroflux_test_nav_update_calls'][] = array( $menu_id, $menu_item_db_id, $menu_item_data );

		if ( isset( $GLOBALS['senroflux_test_nav_item_failure'] ) && ( $menu_item_data['menu-item-title'] ?? '' ) === $GLOBALS['senroflux_test_nav_item_failure'] ) {
			return new WP_Error( 'update_nav_menu_item_failed', 'Stubbed failure.' );
		}

		$id = 0 !== $menu_item_db_id ? $menu_item_db_id : (int) ( $GLOBALS['senroflux_test_next_menu_item_id'] ?? 500 );
		$GLOBALS['senroflux_test_next_menu_item_id'] = max( $id + 1, (int) ( $GLOBALS['senroflux_test_next_menu_item_id'] ?? 500 ) );

		$item             = new stdClass();
		$item->ID         = $id;
		$item->title      = (string) ( $menu_item_data['menu-item-title'] ?? '' );
		$item->post_title = $item->title;
		$item->url        = (string) ( $menu_item_data['menu-item-url'] ?? '' );
		$item->type       = (string) ( $menu_item_data['menu-item-type'] ?? 'custom' );
		$item->object     = (string) ( $menu_item_data['menu-item-object'] ?? '' );
		$item->object_id  = (int) ( $menu_item_data['menu-item-object-id'] ?? 0 );
		$position         = (int) ( $menu_item_data['menu-item-position'] ?? 0 );
		$item->menu_order = 0 !== $position ? $position : count( $GLOBALS['senroflux_test_nav_menu_items'][ $menu_id ] ?? array() ) + 1;

		$item->menu_item_parent = (string) (int) ( $menu_item_data['menu-item-parent-id'] ?? 0 );
		$item->attr_title       = (string) ( $menu_item_data['menu-item-attr-title'] ?? '' );
		$item->post_excerpt     = $item->attr_title;
		$item->target           = (string) ( $menu_item_data['menu-item-target'] ?? '' );
		$item->classes          = array_values( array_filter( explode( ' ', (string) ( $menu_item_data['menu-item-classes'] ?? '' ) ) ) );
		$item->xfn              = (string) ( $menu_item_data['menu-item-xfn'] ?? '' );
		$item->description      = (string) ( $menu_item_data['menu-item-description'] ?? '' );
		$item->post_content     = $item->description;
		$item->post_status      = (string) ( $menu_item_data['menu-item-status'] ?? 'publish' );

		$GLOBALS['senroflux_test_nav_menu_items'][ $menu_id ][ $id ] = $item;

		return $id;
	}
}

// Reuses the shared nav stubs (items store, wp_delete_post, locations, ...).
require_once __DIR__ . '/navigation.php';

if ( ! function_exists( 'senroflux_test_nav_update_recording' ) ) {
	/** Whether wp_update_nav_menu_item() is this file's recording version. */
	function senroflux_test_nav_update_recording(): bool {
		return str_ends_with( (string) ( new ReflectionFunction( 'wp_update_nav_menu_item' ) )->getFileName(), 'site-tools.php' );
	}
}

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	function wp_get_nav_menu_object( $menu ) {
		return $GLOBALS['senroflux_test_nav_menus'][ (int) $menu ] ?? false;
	}
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	function wp_get_nav_menus( $args = array() ) {
		unset( $args );

		return array_values( (array) $GLOBALS['senroflux_test_nav_menus'] );
	}
}

if ( ! function_exists( 'senroflux_test_add_menu' ) ) {
	function senroflux_test_add_menu( int $id, string $name = 'Main', string $slug = 'main' ): object {
		$term          = new stdClass();
		$term->term_id = $id;
		$term->name    = $name;
		$term->slug    = $slug;
		$term->count   = 0;

		$GLOBALS['senroflux_test_nav_menus'][ $id ] = $term;

		return $term;
	}
}

if ( ! function_exists( 'senroflux_test_add_menu_item' ) ) {
	/** Insert a full-fidelity item straight into the shared store. */
	function senroflux_test_add_menu_item( int $menu_id, int $id, array $fields = array() ): object {
		$item = (object) array_merge(
			array(
				'ID'               => $id,
				'title'            => 'Item ' . $id,
				'post_title'       => 'Item ' . $id,
				'url'              => 'https://example.test/' . $id,
				'type'             => 'custom',
				'object'           => 'custom',
				'object_id'        => $id,
				'menu_order'       => $id,
				'menu_item_parent' => '0',
				'attr_title'       => '',
				'post_excerpt'     => '',
				'target'           => '',
				'classes'          => array(),
				'xfn'              => '',
				'description'      => '',
				'post_content'     => '',
				'post_status'      => 'publish',
			),
			$fields
		);

		$GLOBALS['senroflux_test_nav_menu_items'][ $menu_id ][ $id ] = $item;
		if ( isset( $GLOBALS['senroflux_test_nav_menus'][ $menu_id ] ) ) {
			++$GLOBALS['senroflux_test_nav_menus'][ $menu_id ]->count;
		}

		return $item;
	}
}

if ( ! function_exists( 'is_post_type_viewable' ) ) {
	/** Only post and page are public; anything else (e.g. 'secret') is not. */
	function is_post_type_viewable( $post_type ): bool {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/** Same fixture format as media.php: `<taxonomy>:<lowercased name>` => id. */
	function get_term( int $term_id, string $taxonomy = '' ): ?object {
		unset( $taxonomy );

		foreach ( $GLOBALS['senroflux_test_terms'] ?? array() as $key => $id ) {
			if ( (int) $id === $term_id ) {
				list( $term_taxonomy, $name ) = explode( ':', (string) $key, 2 );

				return (object) array(
					'term_id'  => $term_id,
					'name'     => $name,
					'taxonomy' => $term_taxonomy,
				);
			}
		}

		return null;
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	/** Same knob as media.php: `senroflux_test_taxonomies[$name]` or false. */
	function get_taxonomy( string $taxonomy ): object|false {
		return $GLOBALS['senroflux_test_taxonomies'][ $taxonomy ] ?? false;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins( $plugin_folder = '' ) {
		unset( $plugin_folder );

		return (array) $GLOBALS['senroflux_test_plugins'];
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return in_array( $plugin, (array) $GLOBALS['senroflux_test_active_plugins'], true );
	}
}

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $plugin ) {
		return in_array( $plugin, (array) $GLOBALS['senroflux_test_network_plugins'], true );
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $transient ) {
		return $GLOBALS['senroflux_test_site_transients'][ $transient ] ?? false;
	}
}

if ( ! class_exists( 'SenroFlux_Test_Fake_Theme' ) ) {
	/** Duck-typed WP_Theme: the accessors Inventory calls. */
	class SenroFlux_Test_Fake_Theme {

		public function __construct(
			private string $stylesheet,
			private string $name,
			private string $version,
			private string $template,
			private bool $block
		) {
		}

		public function get_template(): string {
			return $this->template;
		}

		public function get( string $header ) {
			return 'Name' === $header ? $this->name : ( 'Version' === $header ? $this->version : false );
		}

		public function is_block_theme(): bool {
			return $this->block;
		}

		public function get_stylesheet(): string {
			return $this->stylesheet;
		}
	}
}

if ( ! function_exists( 'wp_get_themes' ) ) {
	function wp_get_themes( $args = array() ) {
		unset( $args );

		return (array) $GLOBALS['senroflux_test_themes'];
	}
}
