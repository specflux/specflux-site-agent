<?php
/**
 * 0.4 S4: the "What changes" diff builder.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Approval;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Approval\Diff\ApprovalDiff;
use Specflux\SiteAgent\Approval\Diff\ObjectReader;

final class ApprovalDiffTest extends TestCase {

	private function build( string $ability, array $args, ?FakeReader $reader = null ): ?array {
		return ( new ApprovalDiff( $reader ?? new FakeReader() ) )->build( $ability, $args );
	}

	public function test_price_change_shows_old_and_new(): void {
		$diff = $this->build(
			'woocommerce/product-update',
			array(
				'id'            => 7,
				'regular_price' => '12.50',
			)
		);

		$this->assertSame(
			array(
				array(
					'field'    => 'regular_price',
					'current'  => '10.00',
					'proposed' => '12.50',
				),
			),
			$diff['fields']
		);
		$this->assertArrayNotHasKey( 'content', $diff );
	}

	public function test_equal_numbers_are_not_a_change(): void {
		$this->assertNull(
			$this->build(
				'woocommerce/product-update',
				array(
					'id'            => 7,
					'regular_price' => '10',
				)
			)
		);
	}

	public function test_title_and_status_on_a_post(): void {
		$diff = $this->build(
			'specflux/update-post',
			array(
				'id'     => 5,
				'title'  => 'New title',
				'status' => 'draft',
			)
		);

		$this->assertSame(
			array(
				array(
					'field'    => 'title',
					'current'  => 'Old title',
					'proposed' => 'New title',
				),
			),
			$diff['fields']
		);
	}

	public function test_content_gets_a_word_diff_of_stripped_text(): void {
		$diff = $this->build(
			'specflux/update-post',
			array(
				'id'      => 5,
				'content' => '<!-- wp:paragraph --><p>Hello <strong>brave new</strong> world</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertSame(
			array(
				array(
					't'    => 'eq',
					'text' => 'Hello',
				),
				array(
					't'    => 'ins',
					'text' => 'brave new',
				),
				array(
					't'    => 'eq',
					'text' => 'world',
				),
			),
			$diff['content']['ops']
		);
		$this->assertFalse( $diff['content']['truncated'] );
	}

	public function test_replaced_words_show_as_delete_then_insert(): void {
		$diff = $this->build(
			'specflux/update-post',
			array(
				'id'      => 5,
				'content' => '<p>Hello there</p>',
			)
		);

		$this->assertSame(
			array(
				array(
					't'    => 'eq',
					'text' => 'Hello',
				),
				array(
					't'    => 'del',
					'text' => 'world',
				),
				array(
					't'    => 'ins',
					'text' => 'there',
				),
			),
			$diff['content']['ops']
		);
	}

	public function test_menu_labels_are_compared(): void {
		$diff = $this->build(
			'specflux/update-navigation',
			array(
				'items' => array(
					array(
						'label' => 'Home',
						'order' => 1,
					),
					array(
						'label' => 'Shop',
						'order' => 2,
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					't'    => 'eq',
					'text' => 'Home',
				),
				array(
					't'    => 'del',
					'text' => 'About',
				),
				array(
					't'    => 'ins',
					'text' => 'Shop',
				),
			),
			$diff['menu']['ops']
		);
	}

	public function test_coupon_amount(): void {
		$diff = $this->build(
			'specflux/coupon-update',
			array(
				'coupon_id' => 3,
				'amount'    => '15',
			)
		);

		$this->assertSame(
			array(
				array(
					'field'    => 'amount',
					'current'  => '10',
					'proposed' => '15',
				),
			),
			$diff['fields']
		);
	}

	public function test_unknown_ability_has_no_diff(): void {
		$this->assertNull(
			$this->build(
				'acme/do-thing',
				array(
					'id'    => 5,
					'title' => 'x',
				)
			)
		);
	}

	public function test_unreadable_object_has_no_diff(): void {
		$this->assertNull(
			$this->build(
				'specflux/update-post',
				array(
					'id'    => 999,
					'title' => 'x',
				)
			)
		);
	}

	public function test_huge_content_falls_back_to_removed_then_added(): void {
		$reader                      = new FakeReader();
		$old                         = implode( ' ', array_map( static fn ( $i ) => "a$i", range( 1, 2000 ) ) );
		$new                         = implode( ' ', array_map( static fn ( $i ) => "b$i", range( 1, 2000 ) ) );
		$reader->posts[5]['content'] = $old;

		$diff = $this->build(
			'specflux/update-post',
			array(
				'id'      => 5,
				'content' => $new,
			),
			$reader
		);

		$this->assertTrue( $diff['content']['truncated'] );
		$this->assertSame( array( 'del', 'ins' ), array_column( $diff['content']['ops'], 't' ) );
	}
}

final class FakeReader implements ObjectReader {

	public array $posts = array(
		5 => array(
			'title'   => 'Old title',
			'status'  => 'draft',
			'slug'    => 's',
			'excerpt' => '',
			'content' => '<p>Hello world</p>',
		),
	);

	public function post( int $id ): ?array {
		return $this->posts[ $id ] ?? null;
	}

	public function product( int $id ): ?array {
		return 7 === $id ? array(
			'title'         => 'Mug',
			'status'        => 'publish',
			'slug'          => 'mug',
			'excerpt'       => '',
			'content'       => '',
			'regular_price' => '10.00',
			'sale_price'    => '',
		) : null;
	}

	public function coupon( int $id ): ?array {
		return 3 === $id ? array( 'amount' => '10' ) : null;
	}

	public function menuLabels(): array {
		return array( 'Home', 'About' );
	}
}
