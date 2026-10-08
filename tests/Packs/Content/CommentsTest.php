<?php
/**
 * Content\Comments tests: list / moderate / approve / reply.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Content;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Content\Comments;
use stdClass;
use WP_Error;

final class CommentsTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/comments-users.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_comments']           = array();
		$GLOBALS['senroflux_test_comment_prev']       = array();
		$GLOBALS['senroflux_test_users']              = array();
		$GLOBALS['senroflux_test_current_user_id']    = 7;
		unset( $GLOBALS['senroflux_test_new_comment_approved'], $GLOBALS['senroflux_test_new_comment_data'] );
		$GLOBALS['senroflux_test_user_caps'] = array(
			'edit_posts'        => true,
			'moderate_comments' => true,
		);

		$post                                = new stdClass();
		$post->ID                            = 10;
		$post->post_status                   = 'publish';
		$post->comment_status                = 'open';
		$GLOBALS['senroflux_test_posts'][10] = $post;

		$GLOBALS['senroflux_test_users'][7] = new \SenroFlux_Test_User( 7, 'mod', 'Mod "Ann"', 'mod@example.test', array( 'editor' ), 'https://mod.test' );

		Comments::reset();
		Comments::register();
	}

	private function ability( string $name ): object {
		return $GLOBALS['senroflux_test_abilities'][ 'senroflux/' . $name ];
	}

	private function addComment( int $id, string $approved, int $post = 10, int $parent_id = 0 ): void {
		$c                       = new stdClass();
		$c->comment_ID           = $id;
		$c->comment_post_ID      = $post;
		$c->comment_parent       = $parent_id;
		$c->comment_approved     = $approved;
		$c->comment_author       = 'Guest ' . $id;
		$c->comment_author_email = 'guest' . $id . '@example.test';
		$c->comment_author_url   = '';
		$c->comment_author_IP    = '203.0.113.9';
		$c->comment_agent        = 'Mozilla';
		$c->user_id              = 99;
		$c->comment_date_gmt     = '2026-03-04 05:06:07';
		$c->comment_content      = 'Hello ' . $id;
		$c->comment_type         = 'comment';

		$GLOBALS['senroflux_test_comments'][ $id ] = $c;
	}

	private function caps( array $caps ): void {
		$GLOBALS['senroflux_test_user_caps'] = $caps;
	}

	// --- list-comments ---------------------------------------------------

	public function test_list_refused_without_base_cap(): void {
		$this->caps( array() );
		$this->assertFalse( $this->ability( 'list-comments' )->check_permissions( array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'list-comments' )->execute( array() ) );
	}

	public function test_list_refused_for_post_without_object_cap(): void {
		$this->caps(
			array(
				'edit_posts'   => true,
				'edit_post:10' => false,
			)
		);
		$this->assertFalse( $this->ability( 'list-comments' )->check_permissions( array( 'post_id' => 10 ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'list-comments' )->execute( array( 'post_id' => 10 ) ) );
	}

	public function test_list_happy_path_defaults_to_hold_and_hides_secrets(): void {
		$this->addComment( 1, '0' );
		$this->addComment( 2, '1' );
		$this->caps(
			array(
				'edit_posts'        => true,
				'moderate_comments' => true,
				'edit_comment'      => true,
			)
		);

		$out = $this->ability( 'list-comments' )->execute( array() );

		$this->assertSame( 1, $out['total'] );
		$this->assertSame( 1, $out['total_pages'] );
		$this->assertSame( 'hold', $out['comments'][0]['status'] );
		$this->assertSame( 'guest1@example.test', $out['comments'][0]['author_email'] );
		$this->assertSame( '2026-03-04T05:06:07+00:00', $out['comments'][0]['date'] );
		$json = (string) wp_json_encode( $out );
		foreach ( array( '203.0.113.9', 'Mozilla', 'comment_author_IP' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json );
		}
		$this->assertArrayNotHasKey( 'user_id', $out['comments'][0] );
	}

	public function test_list_author_email_only_with_moderate_comments(): void {
		$this->addComment( 1, '0' );
		$this->caps(
			array(
				'edit_posts'   => true,
				'edit_comment' => true,
			)
		);

		$out = $this->ability( 'list-comments' )->execute( array() );

		$this->assertCount( 1, $out['comments'] );
		$this->assertArrayNotHasKey( 'author_email', $out['comments'][0] );
	}

	public function test_list_drops_unreadable_comments_and_adjusts_total(): void {
		$this->addComment( 1, '0' );
		$this->addComment( 2, '0' );
		$this->caps(
			array(
				'edit_posts'     => true,
				'edit_comment'   => true,
				'edit_comment:2' => false,
			)
		);

		$out = $this->ability( 'list-comments' )->execute( array() );

		$this->assertSame( array( 1 ), array_column( $out['comments'], 'id' ) );
		$this->assertSame( 1, $out['total'] );
	}

	// --- moderate-comment ------------------------------------------------

	public function test_moderate_refused_without_base_cap(): void {
		$this->addComment( 1, '1' );
		$this->caps( array( 'edit_comment' => true ) );
		$in = array(
			'id'     => 1,
			'action' => 'spam',
		);

		$this->assertFalse( $this->ability( 'moderate-comment' )->check_permissions( $in ) );
		$this->assertSame( 'forbidden', $this->ability( 'moderate-comment' )->execute( $in )->get_error_code() );
	}

	public function test_moderate_refused_without_object_cap(): void {
		$this->addComment( 1, '1' );
		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment'      => true,
				'edit_comment:1'    => false,
			)
		);
		$in = array(
			'id'     => 1,
			'action' => 'spam',
		);

		$this->assertFalse( $this->ability( 'moderate-comment' )->check_permissions( $in ) );
		$this->assertSame( 'forbidden', $this->ability( 'moderate-comment' )->execute( $in )->get_error_code() );
		$this->assertSame( '1', $GLOBALS['senroflux_test_comments'][1]->comment_approved );
	}

	public function test_moderate_spam_then_restore_returns_previous_status(): void {
		$this->addComment( 1, '1' );
		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment'      => true,
			)
		);
		$ab = $this->ability( 'moderate-comment' );

		$out = $ab->execute(
			array(
				'id'     => 1,
				'action' => 'spam',
			)
		);
		$this->assertSame(
			array(
				'id'              => 1,
				'status'          => 'spam',
				'previous_status' => 'approved',
			),
			$out
		);

		$out = $ab->execute(
			array(
				'id'     => 1,
				'action' => 'restore',
			)
		);
		$this->assertSame(
			array(
				'id'              => 1,
				'status'          => 'approved',
				'previous_status' => 'spam',
			),
			$out
		);
	}

	public function test_moderate_trash_unapprove_and_restore_refusals(): void {
		$this->addComment( 1, '1' );
		$this->addComment( 2, '0' );
		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment'      => true,
			)
		);
		$ab = $this->ability( 'moderate-comment' );

		$this->assertSame(
			'trash',
			$ab->execute(
				array(
					'id'     => 1,
					'action' => 'trash',
				)
			)['status']
		);
		$this->assertSame(
			'approved',
			$ab->execute(
				array(
					'id'     => 1,
					'action' => 'restore',
				)
			)['status']
		);
		$this->assertSame(
			'hold',
			$ab->execute(
				array(
					'id'     => 1,
					'action' => 'unapprove',
				)
			)['status']
		);
		// Not trashed or spam: restore refused.
		$this->assertSame(
			'bad_transition',
			$ab->execute(
				array(
					'id'     => 2,
					'action' => 'restore',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'not_found',
			$ab->execute(
				array(
					'id'     => 404,
					'action' => 'spam',
				)
			)->get_error_code()
		);
	}

	public function test_moderate_never_approves_or_deletes(): void {
		$schema = $this->ability( 'moderate-comment' )->get_input_schema();
		$this->assertSame( array( 'unapprove', 'spam', 'trash', 'restore' ), $schema['properties']['action']['enum'] );
		$meta = $this->ability( 'moderate-comment' )->get_meta()['annotations'];
		$this->assertFalse( $meta['destructive'] );
		$this->assertTrue( $meta['idempotent'] );
	}

	// --- approve-comment -------------------------------------------------

	public function test_approve_refused_without_base_or_object_cap(): void {
		$this->addComment( 1, '0' );
		$this->caps( array( 'edit_comment' => true ) );
		$this->assertFalse( $this->ability( 'approve-comment' )->check_permissions( array( 'id' => 1 ) ) );

		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment:1'    => false,
			)
		);
		$this->assertFalse( $this->ability( 'approve-comment' )->check_permissions( array( 'id' => 1 ) ) );
		$this->assertSame( 'forbidden', $this->ability( 'approve-comment' )->execute( array( 'id' => 1 ) )->get_error_code() );
	}

	public function test_approve_happy_path_and_refusals(): void {
		$this->addComment( 1, '0' );
		$this->addComment( 2, 'spam' );
		$this->addComment( 3, 'trash' );
		$this->addComment( 4, '1' );
		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment'      => true,
			)
		);
		$ab = $this->ability( 'approve-comment' );

		$this->assertSame(
			array(
				'id'              => 1,
				'status'          => 'approved',
				'previous_status' => 'hold',
			),
			$ab->execute( array( 'id' => 1 ) )
		);
		$this->assertSame( 'bad_transition', $ab->execute( array( 'id' => 2 ) )->get_error_code() );
		$this->assertStringContainsString( 'Restore', $ab->execute( array( 'id' => 3 ) )->get_error_message() );
		$this->assertSame( 'already_approved', $ab->execute( array( 'id' => 4 ) )->get_error_code() );
		$this->assertSame( 'spam', $GLOBALS['senroflux_test_comments'][2]->comment_approved );
	}

	// --- reply-to-comment ------------------------------------------------

	private function replyCaps(): void {
		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment'      => true,
				'edit_post'         => true,
			)
		);
	}

	public function test_reply_refused_without_each_capability(): void {
		$this->addComment( 1, '1' );
		$in = array(
			'comment_id' => 1,
			'content'    => 'Thanks',
		);
		$ab = $this->ability( 'reply-to-comment' );

		$this->caps(
			array(
				'edit_comment' => true,
				'edit_post'    => true,
			)
		);
		$this->assertFalse( $ab->check_permissions( $in ) );

		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment:1'    => false,
				'edit_post'         => true,
			)
		);
		$this->assertFalse( $ab->check_permissions( $in ) );

		$this->caps(
			array(
				'moderate_comments' => true,
				'edit_comment'      => true,
				'edit_post:10'      => false,
			)
		);
		$this->assertFalse( $ab->check_permissions( $in ) );
		$this->assertSame( 'forbidden', $ab->execute( $in )->get_error_code() );
		$this->assertArrayNotHasKey( 'senroflux_test_new_comment_data', $GLOBALS );
	}

	public function test_reply_happy_path_slashes_and_uses_current_user(): void {
		$this->addComment( 1, '1' );
		$this->replyCaps();

		$out = $this->ability( 'reply-to-comment' )->execute(
			array(
				'comment_id' => 1,
				'content'    => '  Thanks "friend"  ',
			)
		);

		$this->assertSame( 'approved', $out['status'] );
		$this->assertSame( 10, $out['post_id'] );
		$this->assertSame( 1, $out['parent'] );
		$data = $GLOBALS['senroflux_test_new_comment_data'];
		$this->assertSame( 7, $data['user_id'] );
		$this->assertSame( 'Thanks \"friend\"', $data['comment_content'] );
		$this->assertSame( 1, $data['comment_parent'] );
	}

	public function test_reply_content_and_parent_refusals(): void {
		$this->addComment( 1, '1' );
		$this->addComment( 2, '0' );
		$this->replyCaps();
		$ab = $this->ability( 'reply-to-comment' );

		$this->assertSame(
			'empty_content',
			$ab->execute(
				array(
					'comment_id' => 1,
					'content'    => "  \n ",
				)
			)->get_error_code()
		);
		$this->assertSame(
			'content_too_long',
			$ab->execute(
				array(
					'comment_id' => 1,
					'content'    => str_repeat( 'a', 2001 ),
				)
			)->get_error_code()
		);
		$this->assertIsArray(
			$ab->execute(
				array(
					'comment_id' => 1,
					'content'    => str_repeat( 'a', 2000 ),
				)
			)
		);
		$this->assertSame(
			'parent_not_approved',
			$ab->execute(
				array(
					'comment_id' => 2,
					'content'    => 'Hi',
				)
			)->get_error_code()
		);
	}

	public function test_reply_refused_on_unpublished_post(): void {
		$this->addComment( 1, '1' );
		$GLOBALS['senroflux_test_posts'][10]->post_status = 'draft';
		$this->replyCaps();

		$this->assertSame(
			'post_not_published',
			$this->ability( 'reply-to-comment' )->execute(
				array(
					'comment_id' => 1,
					'content'    => 'Hi',
				)
			)->get_error_code()
		);
	}

	// --- schemas ---------------------------------------------------------

	public function test_no_schema_contains_max_length(): void {
		foreach ( array( 'list-comments', 'moderate-comment', 'approve-comment', 'reply-to-comment' ) as $name ) {
			$ab = $this->ability( $name );
			$this->assertStringNotContainsString( 'maxLength', wp_json_encode( $ab->get_input_schema() ), $name );
			$this->assertStringNotContainsString( 'minLength', wp_json_encode( $ab->get_output_schema() ), $name );
			$this->assertStringNotContainsString( 'maxLength', wp_json_encode( $ab->get_output_schema() ), $name );
		}
		$this->assertStringContainsString( '2000', $this->ability( 'reply-to-comment' )->get_description() );
	}
}
