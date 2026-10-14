<?php
/**
 * 0.4 S1: ResultPresenter and the built-in presenters, fed the return shapes
 * of the real abilities (Content, Media, Commerce, Site packs).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Present;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Present\Links;
use Specflux\SiteAgent\Present\Presenter;
use Specflux\SiteAgent\Present\ResultPresenter;

final class ResultPresenterTest extends TestCase {

	protected function tearDown(): void {
		remove_all_filters( 'specflux_site_agent_result_presenters' );
	}

	private function present( string $tool, array $response, string $status = 'ok' ): array {
		return ( new ResultPresenter( null, new FakeLinks() ) )->present( $tool, $response, $status );
	}

	private function labels( array $links ): array {
		return array_column( $links, 'url', 'label' );
	}

	public function test_created_draft_post_has_edit_and_preview_links(): void {
		$card = $this->present(
			'wpab__specflux__create-post',
			array(
				'id'     => 42,
				'status' => 'draft',
			)
		);

		$this->assertSame( 'post', $card['type'] );
		$this->assertSame( 'Post #42', $card['title'] );
		$this->assertSame( 'draft', $card['status'] );
		$this->assertSame(
			array(
				'Edit'    => 'https://example.test/edit/42',
				'Preview' => 'https://example.test/?p=42&preview=true',
			),
			$this->labels( $card['links'] )
		);
	}

	public function test_published_page_read_uses_title_and_permalink(): void {
		$card = $this->present(
			'specflux/read-content',
			array(
				'id'        => 7,
				'post_type' => 'page',
				'status'    => 'publish',
				'slug'      => 'about',
				'parent'    => 0,
				'title_raw' => 'About <b>us</b>',
				'link'      => 'https://example.test/about/',
			)
		);

		$this->assertSame( 'About us', $card['title'] );
		$this->assertSame( 'https://example.test/about/', $this->labels( $card['links'] )['View'] );
		$this->assertArrayNotHasKey( 'Preview', $this->labels( $card['links'] ) );
	}

	public function test_preview_url_becomes_a_link_card(): void {
		$card = $this->present( 'wpab__specflux__get-preview-url', array( 'preview_url' => 'https://example.test/?p=9&preview=true' ) );

		$this->assertSame( 'link', $card['type'] );
		$this->assertSame( 'https://example.test/?p=9&preview=true', $card['links'][0]['url'] );
		$this->assertSame( 'preview', $card['links'][0]['kind'] );
	}

	public function test_non_http_preview_url_is_dropped(): void {
		$card = $this->present( 'specflux/get-preview-url', array( 'preview_url' => 'javascript:alert(1)' ) );

		$this->assertSame( 'table', $card['type'] );
		$this->assertArrayNotHasKey( 'links', $card );
	}

	public function test_media_read_shows_thumbnail_and_alt(): void {
		$card = $this->present(
			'specflux/read-media',
			array(
				'attachment_id' => 15,
				'alt'           => 'A red bicycle',
				'title'         => 'bike',
				'mime_type'     => 'image/jpeg',
				'url'           => 'https://example.test/wp-content/uploads/bike.jpg',
				'width'         => 800,
			)
		);

		$this->assertSame( 'media', $card['type'] );
		$this->assertSame( 'https://example.test/wp-content/uploads/bike.jpg', $card['thumbnail_url'] );
		$this->assertSame( 'A red bicycle', $card['alt'] );
		$this->assertSame( 'https://example.test/edit/15', $card['links'][0]['url'] );
	}

	public function test_generated_image_without_alt_still_gets_a_card(): void {
		$card = $this->present(
			'specflux/generate-image',
			array(
				'attachment_id' => 3,
				'url'           => 'https://example.test/u/a.png',
			)
		);

		$this->assertSame( 'media', $card['type'] );
		$this->assertSame( 'Image #3', $card['title'] );
		$this->assertSame( '', $card['alt'] );
	}

	public function test_published_product_has_edit_and_view_links(): void {
		$card = $this->present(
			'woocommerce/products-get',
			array(
				'id'            => 88,
				'name'          => 'Trail Mug',
				'status'        => 'publish',
				'regular_price' => '18.00',
			)
		);

		$this->assertSame(
			array(
				'Edit' => 'https://example.test/edit/88',
				'View' => 'https://example.test/?p=88',
			),
			$this->labels( $card['links'] )
		);
		$this->assertSame( 'view', $card['links'][1]['kind'] );
	}

	public function test_draft_product_has_edit_and_preview_links_the_pane_can_use(): void {
		$card = $this->present(
			'woocommerce/products-get',
			array(
				'id'            => 89,
				'name'          => 'Draft Mug',
				'status'        => 'draft',
				'regular_price' => '18.00',
			)
		);

		$this->assertSame(
			array(
				'Edit'    => 'https://example.test/edit/89',
				'Preview' => 'https://example.test/?p=89&preview=true',
			),
			$this->labels( $card['links'] )
		);
		$this->assertSame( 'preview', $card['links'][1]['kind'] );
	}

	public function test_product_view_link_is_omitted_when_the_helper_has_nothing(): void {
		$links          = new FakeLinks();
		$links->missing = array( 90 );
		$card           = ( new ResultPresenter( null, $links ) )->present(
			'woocommerce/products-get',
			array(
				'id'            => 90,
				'name'          => 'Gone',
				'status'        => 'draft',
				'regular_price' => '1',
			),
			'ok'
		);

		$this->assertSame( array(), $card['links'] );
	}

	public function test_product_shows_name_price_stock_status(): void {
		$card = $this->present(
			'woocommerce/products-get',
			array(
				'id'             => 88,
				'name'           => 'Trail Mug',
				'status'         => 'publish',
				'regular_price'  => '18.00',
				'sale_price'     => '14.00',
				'stock_status'   => 'instock',
				'stock_quantity' => 12,
			)
		);

		$this->assertSame( 'product', $card['type'] );
		$this->assertSame( 'Trail Mug', $card['title'] );
		$this->assertSame( 'publish', $card['status'] );
		$this->assertSame(
			array(
				array(
					'label' => 'Price',
					'value' => '14.00',
				),
				array(
					'label' => 'Stock',
					'value' => 'instock (12)',
				),
			),
			$card['fields']
		);
	}

	public function test_coupon_create_result_is_a_disabled_coupon_card(): void {
		$card = $this->present( 'specflux/coupon-create', array( 'coupon_id' => 61 ) );

		$this->assertSame( 'coupon', $card['type'] );
		$this->assertSame( 'Coupon #61', $card['title'] );
		$this->assertSame(
			array(
				'label' => 'Enabled',
				'value' => 'No',
			),
			$card['fields'][0]
		);
	}

	public function test_coupon_enable_and_full_coupon_shapes(): void {
		$enabled = $this->present( 'specflux/coupon-enable', array( 'coupon_id' => 61 ) );
		$this->assertSame( 'Yes', $enabled['fields'][0]['value'] );

		$full = $this->present(
			'woocommerce/coupons-get',
			array(
				'id'     => 5,
				'code'   => 'SAVE10',
				'amount' => '10',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'SAVE10', $full['title'] );
		$this->assertSame(
			array(
				'label' => 'Amount',
				'value' => '10',
			),
			$full['fields'][0]
		);
		$this->assertSame(
			array(
				'label' => 'Enabled',
				'value' => 'Yes',
			),
			$full['fields'][1]
		);
	}

	public function test_navigation_lists_items(): void {
		$card = $this->present(
			'specflux/read-navigation',
			array(
				'kind'                        => 'items',
				'items'                       => array(
					array(
						'label'   => 'Home',
						'url'     => 'https://example.test/',
						'page_id' => 2,
						'order'   => 0,
						'key'     => 'a',
						'parent'  => null,
					),
					array(
						'label'   => 'Shop',
						'url'     => 'https://example.test/shop/',
						'page_id' => 3,
						'order'   => 1,
						'key'     => 'b',
						'parent'  => null,
					),
				),
				'multiple_locations_assigned' => false,
				'stock_sample_page'           => null,
			)
		);

		$this->assertSame( 'list', $card['type'] );
		$this->assertSame( 2, $card['count'] );
		$this->assertSame(
			array(
				'label'  => 'Home',
				'detail' => 'https://example.test/',
			),
			$card['items'][0]
		);
	}

	public function test_store_report_is_a_table(): void {
		$card = $this->present(
			'specflux/store-report',
			array(
				'from'               => '2026-09-01',
				'to'                 => '2026-09-30',
				'order_count'        => 31,
				'gross_sales'        => 1250.5,
				'net_sales'          => 1200.5,
				'refunds_total'      => 50.0,
				'currency'           => 'USD',
				'low_stock_products' => array( 4, 9 ),
			)
		);

		$this->assertSame( 'table', $card['type'] );
		$this->assertSame( array( 'Orders', '31' ), $card['rows'][0] );
		$this->assertSame( array( 'Gross sales', '1250.5 USD' ), $card['rows'][1] );
		$this->assertSame( array( 'Low-stock products', '2' ), $card['rows'][4] );
	}

	public function test_list_read_shows_count_and_first_five(): void {
		$posts = array();
		for ( $i = 1; $i <= 8; ++$i ) {
			$posts[] = array(
				'id'        => $i,
				'post_type' => 'post',
				'status'    => 'draft',
				'slug'      => "p$i",
				'parent'    => 0,
				'title_raw' => "Post $i",
			);
		}
		$card = $this->present(
			'specflux/read-content',
			array(
				'posts'       => $posts,
				'total'       => 20,
				'total_pages' => 2,
			)
		);

		$this->assertSame( 'list', $card['type'] );
		$this->assertSame( 20, $card['count'] );
		$this->assertCount( 5, $card['items'] );
		$this->assertSame(
			array(
				'label'  => 'Post 1',
				'detail' => 'draft',
			),
			$card['items'][0]
		);
		$this->assertSame( 15, $card['more'] );
	}

	public function test_media_search_list_carries_thumbnails_and_empty_list_reads_zero(): void {
		$card = $this->present(
			'specflux/media-search',
			array(
				'results' => array(
					array(
						'id'           => 1,
						'url'          => 'https://example.test/a.jpg',
						'title'        => 'A',
						'alt'          => 'alt a',
						'already_used' => false,
					),
				),
			)
		);
		$this->assertSame( 'https://example.test/a.jpg', $card['items'][0]['thumbnail_url'] );

		$empty = $this->present( 'specflux/list-terms', array( 'terms' => array() ) );
		$this->assertSame( 'list', $empty['type'] );
		$this->assertSame( 0, $empty['count'] );
	}

	public function test_unknown_deeply_nested_shape_becomes_a_two_level_table(): void {
		$card = $this->present(
			'acme/odd-ability',
			array(
				'name'   => 'Widget',
				'ok'     => true,
				'tags'   => array( 'a', 'b' ),
				'level1' => array(
					'level2' => array( 'level3' => array( 'level4' => 'deep' ) ),
					'plain'  => 'x',
				),
				'rows'   => array( array( 'a' => 1 ), array( 'a' => 2 ) ),
			)
		);

		$this->assertSame( 'table', $card['type'] );
		$this->assertSame( array( 'Field', 'Value' ), $card['columns'] );
		$map = array_column( $card['rows'], 1, 0 );
		$this->assertSame( 'Widget', $map['name'] );
		$this->assertSame( 'Yes', $map['ok'] );
		$this->assertSame( 'a, b', $map['tags'] );
		$this->assertSame( 'x', $map['level1.plain'] );
		$this->assertSame( '1 item', $map['level1.level2'] );
		$this->assertSame( '2 items', $map['rows'] );
		$this->assertCount( 6, $card['rows'] );
		foreach ( $card['rows'] as $row ) {
			$this->assertLessThanOrEqual( 1, substr_count( $row[0], '.' ), 'at most two levels' );
			$this->assertIsString( $row[1] );
			$this->assertDoesNotMatchRegularExpression( '/^[\[{]/', $row[1], 'never a JSON string' );
		}
		$this->assertStringNotContainsString( 'level4', (string) wp_json_encode( $card ) );
	}

	public function test_errors_present_as_readable_text(): void {
		$card = $this->present( 'wpab__specflux__update-post', array( 'error' => 'Post not found.' ), 'error' );
		$this->assertSame( 'error', $card['type'] );
		$this->assertSame( 'Post not found.', $card['message'] );

		$blank = $this->present( 'specflux/update-post', array(), 'error' );
		$this->assertSame( 'error', $blank['type'] );
		$this->assertNotSame( '', $blank['message'] );
	}

	public function test_filter_adds_a_presenter_and_can_override_builtins(): void {
		add_filter(
			'specflux_site_agent_result_presenters',
			static function ( array $presenters ): array {
				$presenters[] = new class() implements Presenter {
					public function supports( string $ability, array $response ): bool {
						return 'acme/ticket' === $ability;
					}

					public function present( string $ability, array $response, Links $links ): array {
						return array(
							'type'  => 'ticket',
							'title' => 'Ticket ' . $response['ref'],
						);
					}
				};
				$presenters[] = 'not a presenter';

				return $presenters;
			}
		);

		$card = ( new ResultPresenter( null, new FakeLinks() ) )->present( 'wpab__acme__ticket', array( 'ref' => 'T-9' ) );
		$this->assertSame(
			array(
				'type'  => 'ticket',
				'title' => 'Ticket T-9',
			),
			$card
		);
		$this->assertSame(
			'post',
			$this->present(
				'specflux/create-post',
				array(
					'id'     => 1,
					'status' => 'draft',
				)
			)['type']
		);
	}

	public function test_a_throwing_presenter_falls_through_to_the_fallback(): void {
		$bad = new class() implements Presenter {
			public function supports( string $ability, array $response ): bool {
				return true;
			}

			public function present( string $ability, array $response, Links $links ): array {
				throw new \RuntimeException( 'boom' );
			}
		};

		$card = ( new ResultPresenter( array( $bad ), new FakeLinks() ) )->present( 'acme/x', array( 'a' => 1 ) );
		$this->assertSame( 'table', $card['type'] );
		$this->assertSame( array( 'a', '1' ), $card['rows'][0] );
	}

	public function test_page_id_gets_a_page_preview_link_from_the_helper(): void {
		$links = new FakeLinks();
		$card  = ( new ResultPresenter( null, $links ) )->present(
			'specflux/update-post',
			array(
				'id'        => 11,
				'status'    => 'draft',
				'post_type' => 'page',
			)
		);

		$this->assertSame( 'Page #11', $card['title'] );
		$this->assertSame( 'https://example.test/?p=11&preview=true', $this->labels( $card['links'] )['Preview'] );
	}

	public function test_a_helper_returning_null_omits_the_link(): void {
		$links          = new FakeLinks();
		$links->missing = array( 42 );
		$card           = ( new ResultPresenter( null, $links ) )->present(
			'specflux/create-post',
			array(
				'id'     => 42,
				'status' => 'draft',
			)
		);

		$this->assertSame( array(), $card['links'] );
	}

	public function test_title_and_coupon_code_resolve_from_the_id(): void {
		$links          = new FakeLinks();
		$links->titles  = array(
			42 => 'Spring sale',
			3  => 'hero.jpg',
		);
		$links->coupons = array( 61 => 'SPRING10' );
		$presenter      = new ResultPresenter( null, $links );

		$this->assertSame(
			'Spring sale',
			$presenter->present(
				'specflux/create-post',
				array(
					'id'     => 42,
					'status' => 'draft',
				)
			)['title']
		);
		$this->assertSame(
			'hero.jpg',
			$presenter->present(
				'specflux/generate-image',
				array(
					'attachment_id' => 3,
					'url'           => 'https://example.test/u/a.png',
				)
			)['title']
		);
		$this->assertSame( 'SPRING10', $presenter->present( 'specflux/coupon-create', array( 'coupon_id' => 61 ) )['title'] );
		$this->assertSame(
			'Post #9',
			$presenter->present(
				'specflux/create-post',
				array(
					'id'     => 9,
					'status' => 'draft',
				)
			)['title']
		);
	}
}

final class FakeLinks implements Links {

	/** @var array<int,string> */
	public array $titles = array();

	/** @var array<int,string> */
	public array $coupons = array();

	/** @var list<int> Ids whose helpers return null. */
	public array $missing = array();

	public function edit( int $id ): ?string {
		return in_array( $id, $this->missing, true ) ? null : 'https://example.test/edit/' . $id;
	}

	public function view( int $id, bool $published ): ?string {
		if ( in_array( $id, $this->missing, true ) ) {
			return null;
		}

		return 'https://example.test/?p=' . $id . ( $published ? '' : '&preview=true' );
	}

	public function title( int $id ): string {
		return $this->titles[ $id ] ?? '';
	}

	public function couponCode( int $id ): string {
		return $this->coupons[ $id ] ?? '';
	}
}
