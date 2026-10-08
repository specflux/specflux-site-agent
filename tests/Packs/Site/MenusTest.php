<?php
/**
 * Site\Menus tests: classic menu read / add / remove / reorder.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Menus;
use WP_Error;

final class MenusTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/site-tools.php';

		$GLOBALS['senroflux_test_abilities']            = array();
		$GLOBALS['senroflux_test_ability_categories']   = array();
		$GLOBALS['senroflux_test_posts']                = array();
		$GLOBALS['senroflux_test_next_post_id']         = 100;
		$GLOBALS['senroflux_test_nav_menus']            = array();
		$GLOBALS['senroflux_test_nav_menu_items']       = array();
		$GLOBALS['senroflux_test_nav_update_calls']     = array();
		$GLOBALS['senroflux_test_next_menu_item_id']    = 500;
		$GLOBALS['senroflux_test_terms']                = array();
		$GLOBALS['senroflux_test_taxonomies']           = array();
		$GLOBALS['senroflux_test_registered_nav_menus'] = array(
			'primary' => 'Primary',
			'footer'  => 'Footer',
		);
		$GLOBALS['senroflux_test_nav_menu_locations']   = array( 'primary' => 7 );
		$GLOBALS['senroflux_test_user_caps']            = array(
			'edit_theme_options' => true,
			'read_post'          => true,
		);

		senroflux_test_add_menu( 7, 'Main', 'main' );
		senroflux_test_add_menu( 8, 'Other', 'other' );

		Menus::reset();
		Menus::register();
	}

	private function ability( string $name ): object {
		return $GLOBALS['senroflux_test_abilities'][ 'senroflux/' . $name ];
	}

	private function post( string $status = 'publish', string $type = 'page' ): int {
		return wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => $status,
				'post_title'  => 'Linked',
			)
		);
	}

	public function test_every_ability_refuses_without_edit_theme_options(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'read_post' => true );
		$inputs                              = array(
			'read-menus'         => array(),
			'add-menu-item'      => array(
				'menu_id' => 7,
				'type'    => 'custom',
				'title'   => 'x',
				'url'     => '/x',
			),
			'remove-menu-item'   => array(
				'menu_id' => 7,
				'item_id' => 1,
			),
			'reorder-menu-items' => array(
				'menu_id' => 7,
				'order'   => array( 1 ),
			),
		);

		foreach ( $inputs as $name => $input ) {
			$this->assertFalse( $this->ability( $name )->check_permissions( $input ), $name );
			$this->assertInstanceOf( WP_Error::class, $this->ability( $name )->execute( $input ), $name );
		}
		$this->assertSame( array(), $GLOBALS['senroflux_test_nav_update_calls'] );
	}

	public function test_read_menus_lists_menus_with_locations(): void {
		senroflux_test_add_menu_item( 7, 1 );
		senroflux_test_add_menu_item( 7, 2 );

		$result = $this->ability( 'read-menus' )->execute( array() );

		$this->assertCount( 2, $result['menus'] );
		$this->assertSame(
			array(
				'id'         => 7,
				'name'       => 'Main',
				'slug'       => 'main',
				'item_count' => 2,
				'locations'  => array( 'primary' ),
			),
			$result['menus'][0]
		);
		$this->assertSame( array(), $result['menus'][1]['locations'] );
	}

	public function test_read_menus_with_id_lists_items_and_drops_unreadable_posts(): void {
		$readable   = $this->post();
		$unreadable = $this->post();
		$GLOBALS['senroflux_test_user_caps'][ 'read_post:' . $unreadable ] = false;

		senroflux_test_add_menu_item( 7, 1, array( 'title' => 'Custom' ) );
		senroflux_test_add_menu_item(
			7,
			2,
			array(
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => $readable,
			)
		);
		senroflux_test_add_menu_item(
			7,
			3,
			array(
				'type'      => 'post_type',
				'object'    => 'page',
				'object_id' => $unreadable,
			)
		);

		$result = $this->ability( 'read-menus' )->execute( array( 'menu_id' => 7 ) );

		$this->assertSame(
			array(
				'id'   => 7,
				'name' => 'Main',
				'slug' => 'main',
			),
			$result['menu']
		);
		$this->assertSame( array( 1, 2 ), array_column( $result['items'], 'id' ) );
		$this->assertSame(
			array( 'id', 'title', 'url', 'type', 'object', 'object_id', 'parent', 'position' ),
			array_keys( $result['items'][0] )
		);
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'read-menus' )->execute( array( 'menu_id' => 999 ) ) );
	}

	public function test_add_custom_item_happy_path(): void {
		$result = $this->ability( 'add-menu-item' )->execute(
			array(
				'menu_id' => 7,
				'type'    => 'custom',
				'title'   => 'Docs',
				'url'     => 'https://example.test/docs',
			)
		);

		$this->assertSame( 7, $result['menu_id'] );
		$this->assertSame( 500, $result['item_id'] );
		$this->assertSame( 'Docs', $GLOBALS['senroflux_test_nav_menu_items'][7][500]->title );
	}

	public function test_add_post_item_uses_the_posts_type_and_requires_it_published(): void {
		$page = $this->post();

		$result = $this->ability( 'add-menu-item' )->execute(
			array(
				'menu_id'   => 7,
				'type'      => 'post_type',
				'object_id' => $page,
			)
		);
		$this->assertSame( 'page', $GLOBALS['senroflux_test_nav_menu_items'][7][ $result['item_id'] ]->object );

		$draft = $this->post( 'draft' );
		$this->assertInstanceOf(
			WP_Error::class,
			$this->ability( 'add-menu-item' )->execute(
				array(
					'menu_id'   => 7,
					'type'      => 'post_type',
					'object_id' => $draft,
				)
			)
		);
	}

	public function test_add_item_for_an_unreadable_post_is_refused(): void {
		$page = $this->post();
		$GLOBALS['senroflux_test_user_caps'][ 'read_post:' . $page ] = false;
		$input = array(
			'menu_id'   => 7,
			'type'      => 'post_type',
			'object_id' => $page,
		);

		$this->assertFalse( $this->ability( 'add-menu-item' )->check_permissions( $input ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'add-menu-item' )->execute( $input ) );
		$this->assertSame( array(), $GLOBALS['senroflux_test_nav_update_calls'] );
	}

	/** @return array<string,array{0:string}> */
	public static function badUrls(): array {
		return array(
			'javascript' => array( 'javascript:alert(1)' ),
			'mixed case' => array( 'JaVaScRiPt:alert(1)' ),
			'whitespace' => array( "java\nscript:alert(1)" ),
			'data'       => array( 'data:text/html;base64,AAAA' ),
			'vbscript'   => array( 'vbscript:x' ),
			'ftp'        => array( 'ftp://example.test/x' ),
			'empty'      => array( '' ),
		);
	}

	/** @dataProvider badUrls */
	public function test_add_custom_item_rejects_unsafe_urls( string $url ): void {
		$result = $this->ability( 'add-menu-item' )->execute(
			array(
				'menu_id' => 7,
				'type'    => 'custom',
				'title'   => 'Bad',
				'url'     => $url,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $GLOBALS['senroflux_test_nav_update_calls'] );
	}

	public function test_add_item_validation_edges(): void {
		senroflux_test_add_menu_item( 8, 40 );
		$add = $this->ability( 'add-menu-item' );

		$cases = array(
			'parent in another menu' => array(
				'type'   => 'custom',
				'title'  => 'x',
				'url'    => '/x',
				'parent' => 40,
			),
			'custom without title'   => array(
				'type' => 'custom',
				'url'  => '/x',
			),
			'title too long'         => array(
				'type'  => 'custom',
				'title' => str_repeat( 'a', 201 ),
				'url'   => '/x',
			),
			'post without object_id' => array( 'type' => 'post_type' ),
			'unknown post'           => array(
				'type'      => 'post_type',
				'object_id' => 9999,
			),
			'unknown term'           => array(
				'type'      => 'taxonomy',
				'object_id' => 9999,
			),
		);
		foreach ( $cases as $label => $extra ) {
			$result = $add->execute( array_merge( array( 'menu_id' => 7 ), $extra ) );
			$this->assertInstanceOf( WP_Error::class, $result, $label );
		}
		$this->assertInstanceOf( WP_Error::class, $add->execute( array_merge( array( 'menu_id' => 999 ), array( 'type' => 'custom' ) ) ) );
		$this->assertSame( array(), $GLOBALS['senroflux_test_nav_update_calls'] );
	}

	public function test_add_item_accepts_parent_in_the_same_menu_and_a_term(): void {
		senroflux_test_add_menu_item( 7, 40 );
		$GLOBALS['senroflux_test_terms']['category:news'] = 5;
		$GLOBALS['senroflux_test_taxonomies']['category'] = (object) array(
			'public'            => true,
			'show_in_nav_menus' => true,
		);

		$result = $this->ability( 'add-menu-item' )->execute(
			array(
				'menu_id'   => 7,
				'type'      => 'taxonomy',
				'object_id' => 5,
				'parent'    => 40,
				'position'  => 3,
			)
		);

		$this->assertSame( 'category', $GLOBALS['senroflux_test_nav_menu_items'][7][ $result['item_id'] ]->object );
		$this->assertSame( '40', $GLOBALS['senroflux_test_nav_menu_items'][7][ $result['item_id'] ]->menu_item_parent );
	}

	public function test_remove_item_happy_path_and_wrong_menu(): void {
		senroflux_test_add_menu_item( 7, 1 );
		senroflux_test_add_menu_item( 8, 2 );
		$remove = $this->ability( 'remove-menu-item' );

		$wrong = $remove->execute(
			array(
				'menu_id' => 7,
				'item_id' => 2,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $wrong );
		$this->assertArrayHasKey( 2, $GLOBALS['senroflux_test_nav_menu_items'][8] );

		$result = $remove->execute(
			array(
				'menu_id' => 7,
				'item_id' => 1,
			)
		);
		$this->assertSame(
			array(
				'menu_id' => 7,
				'item_id' => 1,
				'removed' => true,
			),
			$result
		);
		$this->assertArrayNotHasKey( 1, $GLOBALS['senroflux_test_nav_menu_items'][7] );
		$this->assertTrue( $remove->get_meta()['annotations']['destructive'] );
	}

	public function test_remove_refuses_a_non_menu_item_post(): void {
		$page = $this->post();

		$result = $this->ability( 'remove-menu-item' )->execute(
			array(
				'menu_id' => 7,
				'item_id' => $page,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotNull( get_post( $page ) );
	}

	public function test_reorder_happy_path_sets_positions(): void {
		senroflux_test_add_menu_item( 7, 1 );
		senroflux_test_add_menu_item( 7, 2 );
		senroflux_test_add_menu_item( 7, 3 );

		$result = $this->ability( 'reorder-menu-items' )->execute(
			array(
				'menu_id' => 7,
				'order'   => array( 3, 1, 2 ),
			)
		);

		$this->assertSame(
			array(
				'menu_id' => 7,
				'order'   => array( 3, 1, 2 ),
			),
			$result
		);
		$items = $GLOBALS['senroflux_test_nav_menu_items'][7];
		$this->assertSame( 1, $items[3]->menu_order );
		$this->assertSame( 2, $items[1]->menu_order );
		$this->assertSame( 3, $items[2]->menu_order );
	}

	/** @return array<string,array{0:list<int>}> */
	public static function badOrders(): array {
		return array(
			'missing id'   => array( array( 1, 2 ) ),
			'extra id'     => array( array( 1, 2, 3, 4 ) ),
			'duplicate id' => array( array( 1, 1, 2 ) ),
			'foreign id'   => array( array( 1, 2, 99 ) ),
			'empty'        => array( array() ),
		);
	}

	/**
	 * @param list<int> $order Order to try.
	 * @dataProvider badOrders
	 */
	public function test_reorder_refuses_a_mismatched_id_set( array $order ): void {
		senroflux_test_add_menu_item( 7, 1 );
		senroflux_test_add_menu_item( 7, 2 );
		senroflux_test_add_menu_item( 7, 3 );

		$result = $this->ability( 'reorder-menu-items' )->execute(
			array(
				'menu_id' => 7,
				'order'   => $order,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( array(), $GLOBALS['senroflux_test_nav_update_calls'] );
	}

	public function test_reorder_preserves_every_item_field(): void {
		if ( ! senroflux_test_nav_update_recording() ) {
			$this->markTestSkipped( 'navigation.php stub loaded first; update arguments are not recorded.' );
		}

		senroflux_test_add_menu_item( 7, 1 );
		senroflux_test_add_menu_item(
			7,
			2,
			array(
				'title'            => 'Shown title',
				'post_title'       => 'Raw title',
				'url'              => 'https://example.test/a',
				'menu_item_parent' => '1',
				'attr_title'       => 'Tooltip',
				'post_excerpt'     => 'Tooltip',
				'target'           => '_blank',
				'classes'          => array( 'cta', 'wide' ),
				'xfn'              => 'nofollow',
				'description'      => 'trimmed',
				'post_content'     => 'full description',
				'post_status'      => 'draft',
			)
		);

		$this->ability( 'reorder-menu-items' )->execute(
			array(
				'menu_id' => 7,
				'order'   => array( 2, 1 ),
			)
		);

		$call = $GLOBALS['senroflux_test_nav_update_calls'][0];
		$this->assertSame( 7, $call[0] );
		$this->assertSame( 2, $call[1] );
		$this->assertSame(
			array(
				'menu-item-title'       => 'Raw title',
				'menu-item-url'         => 'https://example.test/a',
				'menu-item-object-id'   => 2,
				'menu-item-object'      => 'custom',
				'menu-item-type'        => 'custom',
				'menu-item-parent-id'   => 1,
				'menu-item-position'    => 1,
				'menu-item-attr-title'  => 'Tooltip',
				'menu-item-target'      => '_blank',
				'menu-item-classes'     => 'cta wide',
				'menu-item-xfn'         => 'nofollow',
				'menu-item-description' => 'full description',
				'menu-item-status'      => 'draft',
			),
			$call[2]
		);
	}

	public function test_reorder_leaves_title_url_and_parent_untouched_in_the_store(): void {
		senroflux_test_add_menu_item(
			7,
			1,
			array(
				'title'      => 'One',
				'post_title' => 'One',
			)
		);
		senroflux_test_add_menu_item(
			7,
			2,
			array(
				'title'            => 'Two',
				'post_title'       => 'Two',
				'menu_item_parent' => '1',
			)
		);

		$this->ability( 'reorder-menu-items' )->execute(
			array(
				'menu_id' => 7,
				'order'   => array( 2, 1 ),
			)
		);

		$items = $GLOBALS['senroflux_test_nav_menu_items'][7];
		$this->assertSame( 'Two', $items[2]->title );
		$this->assertSame( 'https://example.test/2', $items[2]->url );
		$this->assertSame( '1', (string) $items[2]->menu_item_parent );
		$this->assertSame( 'One', $items[1]->title );
	}

	public function test_schemas_have_no_max_length_and_reorder_bounds(): void {
		foreach ( array( 'read-menus', 'add-menu-item', 'remove-menu-item', 'reorder-menu-items' ) as $name ) {
			$ability = $this->ability( $name );
			$json    = (string) wp_json_encode( array( $ability->get_input_schema(), $ability->get_output_schema() ) );
			$this->assertStringNotContainsString( 'maxLength', $json, $name );
			$this->assertStringNotContainsString( 'minLength', $json, $name );
		}

		$order = $this->ability( 'reorder-menu-items' )->get_input_schema()['properties']['order'];
		$this->assertSame( 1, $order['minItems'] );
		$this->assertSame( 200, $order['maxItems'] );
	}
}
