<?php
/**
 * PostTools tests: list, trash/restore, revisions, post meta.
 *
 * TARGET REPO PATH: tests/Packs/Content/PostToolsTest.php
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Packs\Content;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Content\PostTools;
use WP_Error;

final class PostToolsTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/blocks.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/media.php';
		require_once dirname( __DIR__, 2 ) . '/stubs/post-tools.php';

		$GLOBALS['specflux_site_agent_test_abilities']          = array();
		$GLOBALS['specflux_site_agent_test_ability_categories'] = array();
		$GLOBALS['specflux_site_agent_test_posts']              = array();
		$GLOBALS['specflux_site_agent_test_next_post_id']       = 100;
		$GLOBALS['specflux_site_agent_test_postmeta']           = array();
		$GLOBALS['specflux_site_agent_test_user_caps']          = array();
		$GLOBALS['specflux_site_agent_test_trash_calls']        = array();
		$GLOBALS['specflux_site_agent_test_restored_revisions'] = array();
		$GLOBALS['specflux_site_agent_test_empty_trash_days']   = 30;
		$GLOBALS['specflux_site_agent_test_post_types']         = array(
			'post'       => true,
			'page'       => true,
			'product'    => true,
			'private_t'  => false,
			'attachment' => true,
		);
		$GLOBALS['specflux_site_agent_test_registered_meta']    = array();
		$GLOBALS['specflux_site_agent_test_revisions']          = array();

		PostTools::reset();
		PostTools::register();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['specflux_site_agent_test_empty_trash_days'] );
	}

	private function ability( string $name ): object {
		return $GLOBALS['specflux_site_agent_test_abilities'][ 'specflux/' . $name ];
	}

	/**
	 * @param array<string,mixed> $extra Extra post fields.
	 */
	private function makePost( string $type = 'post', string $status = 'publish', array $extra = array() ): int {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_type'   => $type,
					'post_status' => $status,
					'post_title'  => 'Title ' . $type,
				),
				$extra
			)
		);

		// The post stub omits columns real WP_Post always carries.
		get_post( $id )->post_author = $extra['post_author'] ?? 1;

		return $id;
	}

	/**
	 * @param array<string,bool> $caps Capability knob entries.
	 */
	private function grant( array $caps ): void {
		$GLOBALS['specflux_site_agent_test_user_caps'] = $caps;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function denied( string $name, array $input ): bool {
		return false === $this->ability( $name )->check_permissions( $input );
	}

	/**
	 * Execute and unwrap an expected success.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>
	 */
	private function call( string $name, array $input ): array {
		$result = $this->ability( $name )->execute( $input );
		$this->assertNotInstanceOf( WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertIsArray( $result );

		return $result;
	}

	/**
	 * @param array<string,mixed> $input Input.
	 */
	private function runError( string $name, array $input ): WP_Error {
		$result = $this->ability( $name )->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $result );

		return $result;
	}

	private function registerMeta( string $type, string $key, string $meta_type, bool $single = true, bool $rest = true ): void {
		$GLOBALS['specflux_site_agent_test_registered_meta'][ $type ][ $key ] = array(
			'type'         => $meta_type,
			'single'       => $single,
			'show_in_rest' => $rest,
		);
	}

	// --- Registration -------------------------------------------------------

	public function test_registers_seven_abilities_with_no_maxlength(): void {
		$names = array( 'list-posts', 'trash-post', 'restore-post', 'list-revisions', 'restore-revision', 'list-post-meta', 'set-post-meta' );
		foreach ( $names as $name ) {
			$ability = $this->ability( $name );
			$this->assertStringNotContainsString( 'maxLength', (string) wp_json_encode( $ability->get_input_schema() ), $name );
			$this->assertStringNotContainsString( 'minLength', (string) wp_json_encode( $ability->get_input_schema() ), $name );
			$this->assertStringNotContainsString( 'maxLength', (string) wp_json_encode( $ability->get_output_schema() ), $name );
			$this->assertStringNotContainsString( 'minLength', (string) wp_json_encode( $ability->get_output_schema() ), $name );
		}
		$this->assertCount( 7, $names );
	}

	public function test_trash_and_restore_are_not_destructive(): void {
		foreach ( array( 'trash-post', 'restore-post', 'restore-revision', 'set-post-meta' ) as $name ) {
			$this->assertFalse( $this->ability( $name )->get_meta()['annotations']['destructive'], $name );
		}
		foreach ( array( 'list-posts', 'list-revisions', 'list-post-meta' ) as $name ) {
			$this->assertTrue( $this->ability( $name )->get_meta()['annotations']['readonly'], $name );
		}
	}

	public function test_register_is_idempotent(): void {
		PostTools::register();
		$this->assertCount( 7, $GLOBALS['specflux_site_agent_test_abilities'] );
	}

	// --- list-posts -----------------------------------------------------------

	public function test_list_posts_refused_without_base_cap(): void {
		$this->assertTrue( $this->denied( 'list-posts', array( 'post_type' => 'post' ) ) );
	}

	public function test_list_posts_refuses_disallowed_types_even_with_cap(): void {
		$this->grant(
			array(
				'edit_attachments' => true,
				'edit_private_ts'  => true,
				'edit_revisions'   => true,
			)
		);
		foreach ( array( 'attachment', 'private_t', 'revision', 'nonexistent' ) as $type ) {
			$this->assertTrue( $this->denied( 'list-posts', array( 'post_type' => $type ) ), $type );
			$this->assertSame( 'forbidden', $this->runError( 'list-posts', array( 'post_type' => $type ) )->get_error_code(), $type );
		}
	}

	public function test_list_posts_uses_type_own_capability(): void {
		$this->grant( array( 'edit_posts' => true ) );
		$this->assertTrue( $this->denied( 'list-posts', array( 'post_type' => 'product' ) ) );
		$this->grant( array( 'edit_products' => true ) );
		$this->assertTrue( true === $this->ability( 'list-posts' )->check_permissions( array( 'post_type' => 'product' ) ) );
	}

	public function test_list_posts_happy_path_shape_and_no_content(): void {
		$this->grant( array( 'edit_posts' => true ) );
		$id = $this->makePost( 'post', 'draft', array( 'post_content' => 'SECRET BODY' ) );
		$this->grant(
			array(
				'edit_posts'       => true,
				'edit_post:' . $id => true,
			)
		);

		$out = $this->call( 'list-posts', array() );

		$this->assertSame( 1, $out['total'] );
		$this->assertCount( 1, $out['posts'] );
		$this->assertSame( $id, $out['posts'][0]['id'] );
		$this->assertSame( 'draft', $out['posts'][0]['status'] );
		$this->assertStringNotContainsString( 'SECRET BODY', (string) wp_json_encode( $out ) );
		$this->assertArrayNotHasKey( 'content', $out['posts'][0] );
	}

	public function test_list_posts_drops_unreadable_hits_and_adjusts_total(): void {
		$a = $this->makePost();
		$b = $this->makePost();
		$this->grant(
			array(
				'edit_posts'      => true,
				'edit_post:' . $a => true,
				'edit_post:' . $b => false,
			)
		);

		$out = $this->call( 'list-posts', array() );

		$this->assertCount( 1, $out['posts'] );
		$this->assertSame( $a, $out['posts'][0]['id'] );
		$this->assertSame( 1, $out['total'] );
	}

	public function test_list_posts_excludes_trash_by_default_and_allows_it_on_request(): void {
		$live    = $this->makePost( 'post', 'publish' );
		$trashed = $this->makePost( 'post', 'trash' );
		$this->grant(
			array(
				'edit_posts'            => true,
				'edit_post:' . $live    => true,
				'edit_post:' . $trashed => true,
			)
		);

		$default = $this->call( 'list-posts', array() );
		$this->assertSame( array( $live ), array_column( $default['posts'], 'id' ) );

		$only_trash = $this->call( 'list-posts', array( 'status' => array( 'trash' ) ) );
		$this->assertSame( array( $trashed ), array_column( $only_trash['posts'], 'id' ) );
	}

	public function test_list_posts_count_only_returns_no_posts(): void {
		$a = $this->makePost();
		$b = $this->makePost();
		$this->grant(
			array(
				'edit_posts'      => true,
				'edit_post:' . $a => true,
				'edit_post:' . $b => false,
			)
		);

		$out = $this->call( 'list-posts', array( 'count_only' => true ) );

		$this->assertArrayNotHasKey( 'posts', $out );
		$this->assertSame( 1, $out['total'] );
		$this->assertSame( 1, $out['total_pages'] );
	}

	public function test_list_posts_rejects_unparseable_date(): void {
		$this->grant( array( 'edit_posts' => true ) );
		$this->assertSame( 'invalid_date', $this->runError( 'list-posts', array( 'date_after' => 'not a date' ) )->get_error_code() );
	}

	// --- trash-post -----------------------------------------------------------

	public function test_trash_post_refused_without_base_cap(): void {
		$id = $this->makePost();
		$this->grant( array( 'delete_post:' . $id => true ) );
		$this->assertTrue( $this->denied( 'trash-post', array( 'id' => $id ) ) );
		$this->assertSame( 'forbidden', $this->runError( 'trash-post', array( 'id' => $id ) )->get_error_code() );
	}

	public function test_trash_post_refused_without_object_cap(): void {
		$id = $this->makePost();
		$this->grant( array( 'delete_posts' => true ) );
		$this->assertTrue( $this->denied( 'trash-post', array( 'id' => $id ) ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_trash_calls'] );
	}

	public function test_trash_post_refuses_disallowed_type(): void {
		$id = $this->makePost( 'attachment' );
		$this->grant(
			array(
				'delete_attachments' => true,
				'delete_post:' . $id => true,
			)
		);
		$this->assertTrue( $this->denied( 'trash-post', array( 'id' => $id ) ) );
	}

	public function test_trash_post_happy_path(): void {
		$id = $this->makePost( 'post', 'publish' );
		$this->grant(
			array(
				'delete_posts'       => true,
				'delete_post:' . $id => true,
			)
		);

		$this->assertTrue( true === $this->ability( 'trash-post' )->check_permissions( array( 'id' => $id ) ) );
		$out = $this->call( 'trash-post', array( 'id' => $id ) );

		$this->assertSame(
			array(
				'id'              => $id,
				'status'          => 'trash',
				'previous_status' => 'publish',
			),
			$out
		);
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_trash_post_refuses_when_trash_disabled(): void {
		$id = $this->makePost();
		$this->grant(
			array(
				'delete_posts'       => true,
				'delete_post:' . $id => true,
			)
		);
		$GLOBALS['specflux_site_agent_test_empty_trash_days'] = 0;

		$err = $this->runError( 'trash-post', array( 'id' => $id ) );

		$this->assertSame( 'trash_disabled', $err->get_error_code() );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_trash_calls'] );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	public function test_trash_days_seam_reads_constant_in_production(): void {
		unset( $GLOBALS['specflux_site_agent_test_empty_trash_days'] );
		$expected = defined( 'EMPTY_TRASH_DAYS' ) ? (int) constant( 'EMPTY_TRASH_DAYS' ) : 0;
		$this->assertSame( $expected, PostTools::trashDays() );
	}

	public function test_trash_post_refuses_already_trashed(): void {
		$id = $this->makePost( 'post', 'trash' );
		$this->grant(
			array(
				'delete_posts'       => true,
				'delete_post:' . $id => true,
			)
		);

		$err = $this->runError( 'trash-post', array( 'id' => $id ) );

		$this->assertSame( 'already_trashed', $err->get_error_code() );
		$this->assertSame( array( 'status' => 400 ), $err->get_error_data() );
	}

	// --- restore-post ---------------------------------------------------------

	public function test_restore_post_refused_without_base_cap(): void {
		$id = $this->makePost( 'post', 'trash' );
		$this->grant( array( 'delete_post:' . $id => true ) );
		$this->assertTrue( $this->denied( 'restore-post', array( 'id' => $id ) ) );
		$this->assertSame( 'forbidden', $this->runError( 'restore-post', array( 'id' => $id ) )->get_error_code() );
	}

	public function test_restore_post_refused_without_object_cap(): void {
		$id = $this->makePost( 'post', 'trash' );
		$this->grant( array( 'delete_posts' => true ) );
		$this->assertTrue( $this->denied( 'restore-post', array( 'id' => $id ) ) );
		$this->assertSame( 'trash', get_post_status( $id ) );
	}

	public function test_restore_post_happy_path(): void {
		$id = $this->makePost( 'post', 'trash' );
		$this->grant(
			array(
				'delete_posts'       => true,
				'delete_post:' . $id => true,
			)
		);

		$out = $this->call( 'restore-post', array( 'id' => $id ) );

		$this->assertSame(
			array(
				'id'     => $id,
				'status' => 'draft',
			),
			$out
		);
	}

	public function test_restore_post_refuses_non_trashed(): void {
		$id = $this->makePost( 'post', 'publish' );
		$this->grant(
			array(
				'delete_posts'       => true,
				'delete_post:' . $id => true,
			)
		);

		$this->assertSame( 'not_trashed', $this->runError( 'restore-post', array( 'id' => $id ) )->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	// --- list-revisions -------------------------------------------------------

	public function test_list_revisions_refused_without_base_cap(): void {
		$id = $this->makePost();
		$this->grant( array( 'edit_post:' . $id => true ) );
		$this->assertTrue( $this->denied( 'list-revisions', array( 'post_id' => $id ) ) );
		$this->assertSame( 'forbidden', $this->runError( 'list-revisions', array( 'post_id' => $id ) )->get_error_code() );
	}

	public function test_list_revisions_refused_without_object_cap(): void {
		$id = $this->makePost();
		$this->grant( array( 'edit_posts' => true ) );
		$this->assertTrue( $this->denied( 'list-revisions', array( 'post_id' => $id ) ) );
	}

	public function test_list_revisions_happy_path_without_content(): void {
		$id                           = $this->makePost();
		$rev                          = $this->makePost(
			'revision',
			'inherit',
			array(
				'post_parent'  => $id,
				'post_content' => 'OLD BODY',
			)
		);
		$aut                          = $this->makePost(
			'revision',
			'inherit',
			array(
				'post_parent' => $id,
				'post_name'   => $id . '-autosave-v1',
			)
		);
		get_post( $rev )->post_author = 7;
		get_post( $aut )->post_author = 7;
		$GLOBALS['specflux_site_agent_test_revisions'][ $id ] = array( get_post( $rev ), get_post( $aut ) );
		$this->grant(
			array(
				'edit_posts'       => true,
				'edit_post:' . $id => true,
			)
		);

		$out = $this->call( 'list-revisions', array( 'post_id' => $id ) );

		$this->assertSame( $id, $out['post_id'] );
		$this->assertSame( array( $rev, $aut ), array_column( $out['revisions'], 'revision_id' ) );
		$this->assertSame( array( false, true ), array_column( $out['revisions'], 'is_autosave' ) );
		$this->assertStringNotContainsString( 'OLD BODY', (string) wp_json_encode( $out ) );
	}

	public function test_list_revisions_empty_for_post_without_revisions(): void {
		$id = $this->makePost( 'page' );
		$this->grant(
			array(
				'edit_pages'       => true,
				'edit_post:' . $id => true,
			)
		);

		$out = $this->call( 'list-revisions', array( 'post_id' => $id ) );

		$this->assertSame( array(), $out['revisions'] );
	}

	public function test_list_revisions_refuses_disallowed_type(): void {
		$id = $this->makePost( 'attachment' );
		$this->grant(
			array(
				'edit_attachments' => true,
				'edit_post:' . $id => true,
			)
		);
		$this->assertTrue( $this->denied( 'list-revisions', array( 'post_id' => $id ) ) );
	}

	// --- restore-revision -----------------------------------------------------

	public function test_restore_revision_refused_without_base_cap(): void {
		$id  = $this->makePost();
		$rev = $this->makePost( 'revision', 'inherit', array( 'post_parent' => $id ) );
		$this->grant(
			array(
				'edit_post:' . $id  => true,
				'read_post:' . $rev => true,
			)
		);
		$this->assertTrue( $this->denied( 'restore-revision', array( 'revision_id' => $rev ) ) );
		$this->assertSame( 'forbidden', $this->runError( 'restore-revision', array( 'revision_id' => $rev ) )->get_error_code() );
	}

	public function test_restore_revision_refused_without_parent_edit_or_revision_read(): void {
		$id  = $this->makePost();
		$rev = $this->makePost( 'revision', 'inherit', array( 'post_parent' => $id ) );

		$this->grant(
			array(
				'edit_posts'        => true,
				'read_post:' . $rev => true,
			)
		);
		$this->assertTrue( $this->denied( 'restore-revision', array( 'revision_id' => $rev ) ) );

		$this->grant(
			array(
				'edit_posts'       => true,
				'edit_post:' . $id => true,
			)
		);
		$this->assertTrue( $this->denied( 'restore-revision', array( 'revision_id' => $rev ) ) );
		$this->assertSame( array(), $GLOBALS['specflux_site_agent_test_restored_revisions'] );
	}

	public function test_restore_revision_refuses_non_revision_id(): void {
		$id = $this->makePost();
		$this->grant(
			array(
				'edit_posts'       => true,
				'edit_post:' . $id => true,
				'read_post:' . $id => true,
			)
		);
		$this->assertTrue( $this->denied( 'restore-revision', array( 'revision_id' => $id ) ) );
	}

	public function test_restore_revision_refuses_disallowed_parent_type(): void {
		$att = $this->makePost( 'attachment' );
		$rev = $this->makePost( 'revision', 'inherit', array( 'post_parent' => $att ) );
		$this->grant(
			array(
				'edit_attachments'  => true,
				'edit_post:' . $att => true,
				'read_post:' . $rev => true,
			)
		);
		$this->assertTrue( $this->denied( 'restore-revision', array( 'revision_id' => $rev ) ) );
	}

	public function test_restore_revision_happy_path(): void {
		$id  = $this->makePost();
		$rev = $this->makePost( 'revision', 'inherit', array( 'post_parent' => $id ) );
		$this->grant(
			array(
				'edit_posts'        => true,
				'edit_post:' . $id  => true,
				'read_post:' . $rev => true,
			)
		);

		$out = $this->call( 'restore-revision', array( 'revision_id' => $rev ) );

		$this->assertSame(
			array(
				'post_id'     => $id,
				'revision_id' => $rev,
			),
			$out
		);
		$this->assertSame( array( $rev ), $GLOBALS['specflux_site_agent_test_restored_revisions'] );
	}

	// --- list-post-meta -------------------------------------------------------

	public function test_list_post_meta_refused_without_base_cap(): void {
		$id = $this->makePost();
		$this->grant( array( 'edit_post:' . $id => true ) );
		$this->assertTrue( $this->denied( 'list-post-meta', array( 'post_id' => $id ) ) );
		$this->assertSame( 'forbidden', $this->runError( 'list-post-meta', array( 'post_id' => $id ) )->get_error_code() );
	}

	public function test_list_post_meta_refused_without_object_cap(): void {
		$id = $this->makePost();
		$this->grant( array( 'edit_posts' => true ) );
		$this->assertTrue( $this->denied( 'list-post-meta', array( 'post_id' => $id ) ) );
	}

	public function test_list_post_meta_returns_only_exposed_scalar_keys_and_drops_uneditable(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$this->registerMeta( 'post', 'rating', 'integer' );
		$this->registerMeta( 'post', 'featured', 'boolean' );
		$this->registerMeta( 'post', 'locked', 'string' );
		$this->registerMeta( 'post', 'tags_blob', 'array' );
		$this->registerMeta( 'post', 'hidden_rest', 'string', true, false );
		$this->registerMeta( 'post', 'multi', 'string', false );
		$this->registerMeta( 'page', 'page_only', 'string' );
		update_post_meta( $id, 'subtitle', 'Hello' );
		update_post_meta( $id, 'rating', '4' );
		update_post_meta( $id, 'featured', '1' );
		update_post_meta( $id, 'unregistered', 'nope' );
		update_post_meta( $id, 'locked', 'x' );
		$this->grant(
			array(
				'edit_posts'            => true,
				'edit_post:' . $id      => true,
				'edit_post_meta'        => true,
				'edit_post_meta:' . $id => true,
			)
		);
		$out = $this->call( 'list-post-meta', array( 'post_id' => $id ) );
		$map = array_column( $out['meta'], null, 'key' );

		$this->assertSame( array( 'subtitle', 'rating', 'featured', 'locked' ), array_keys( $map ) );
		$this->assertSame( 'Hello', $map['subtitle']['value'] );
		$this->assertSame( 4, $map['rating']['value'] );
		$this->assertSame( 'integer', $map['rating']['type'] );
		$this->assertTrue( $map['featured']['value'] );
		$this->assertStringNotContainsString( 'unregistered', (string) wp_json_encode( $out ) );
		$this->assertStringNotContainsString( 'nope', (string) wp_json_encode( $out ) );
		$this->assertStringNotContainsString( 'hidden_rest', (string) wp_json_encode( $out ) );
		$this->assertStringNotContainsString( 'page_only', (string) wp_json_encode( $out ) );
	}

	public function test_list_post_meta_drops_keys_user_cannot_edit(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$this->grant(
			array(
				'edit_posts'            => true,
				'edit_post:' . $id      => true,
				'edit_post_meta'        => false,
				'edit_post_meta:' . $id => false,
			)
		);

		$out = $this->call( 'list-post-meta', array( 'post_id' => $id ) );

		$this->assertSame( array(), $out['meta'] );
	}

	// --- set-post-meta --------------------------------------------------------

	/**
	 * @return array<string,bool>
	 */
	private function metaCaps( int $id ): array {
		return array(
			'edit_posts'            => true,
			'edit_post:' . $id      => true,
			'edit_post_meta'        => true,
			'edit_post_meta:' . $id => true,
		);
	}

	public function test_set_post_meta_refused_without_base_cap(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$caps = $this->metaCaps( $id );
		unset( $caps['edit_posts'] );
		$this->grant( $caps );

		$input = array(
			'post_id' => $id,
			'key'     => 'subtitle',
			'value'   => 'x',
		);
		$this->assertTrue( $this->denied( 'set-post-meta', $input ) );
		$this->assertSame( 'forbidden', $this->runError( 'set-post-meta', $input )->get_error_code() );
	}

	public function test_set_post_meta_refused_without_edit_post_on_object(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$caps                       = $this->metaCaps( $id );
		$caps[ 'edit_post:' . $id ] = false;
		$this->grant( $caps );

		$this->assertTrue(
			$this->denied(
				'set-post-meta',
				array(
					'post_id' => $id,
					'key'     => 'subtitle',
					'value'   => 'x',
				)
			)
		);
	}

	public function test_set_post_meta_refused_without_edit_post_meta(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$caps                            = $this->metaCaps( $id );
		$caps[ 'edit_post_meta:' . $id ] = false;
		$this->grant( $caps );

		$input = array(
			'post_id' => $id,
			'key'     => 'subtitle',
			'value'   => 'x',
		);
		$this->assertTrue( $this->denied( 'set-post-meta', $input ) );
		$this->assertSame( 'forbidden', $this->runError( 'set-post-meta', $input )->get_error_code() );
		$this->assertSame( '', get_post_meta( $id, 'subtitle', true ) );
	}

	public function test_set_post_meta_happy_path_per_type(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$this->registerMeta( 'post', 'rating', 'integer' );
		$this->registerMeta( 'post', 'score', 'number' );
		$this->registerMeta( 'post', 'featured', 'boolean' );
		$this->grant( $this->metaCaps( $id ) );

		$cases = array(
			array( 'subtitle', 'Hello', 'Hello' ),
			array( 'rating', 5, 5 ),
			array( 'rating', '7', 7 ),
			array( 'score', 1.5, 1.5 ),
			array( 'featured', true, true ),
			array( 'featured', 'false', false ),
		);
		foreach ( $cases as list( $key, $value, $expected ) ) {
			$out = $this->call(
				'set-post-meta',
				array(
					'post_id' => $id,
					'key'     => $key,
					'value'   => $value,
				)
			);
			$this->assertSame( $expected, $out['value'], $key );
			$this->assertSame( $key, $out['key'] );
			$this->assertSame( $id, $out['post_id'] );
		}
	}

	public function test_set_post_meta_refuses_unregistered_key_without_hinting(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'real_but_hidden', 'string', true, false );
		update_post_meta( $id, 'exists_unregistered', 'v' );
		$this->grant( $this->metaCaps( $id ) );

		$a = $this->runError(
			'set-post-meta',
			array(
				'post_id' => $id,
				'key'     => 'exists_unregistered',
				'value'   => 'x',
			)
		);
		$b = $this->runError(
			'set-post-meta',
			array(
				'post_id' => $id,
				'key'     => 'never_existed',
				'value'   => 'x',
			)
		);
		$c = $this->runError(
			'set-post-meta',
			array(
				'post_id' => $id,
				'key'     => 'real_but_hidden',
				'value'   => 'x',
			)
		);

		$this->assertSame( 'meta_not_registered', $a->get_error_code() );
		$this->assertSame( $a->get_error_message(), $b->get_error_message() );
		$this->assertSame( $a->get_error_message(), $c->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $a->get_error_data() );
		$this->assertSame( 'v', get_post_meta( $id, 'exists_unregistered', true ) );
	}

	public function test_set_post_meta_refuses_non_scalar_and_non_single_registrations(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'blob', 'array' );
		$this->registerMeta( 'post', 'obj', 'object' );
		$this->registerMeta( 'post', 'multi', 'string', false );
		$this->grant( $this->metaCaps( $id ) );

		foreach ( array( 'blob', 'obj', 'multi' ) as $key ) {
			$err = $this->runError(
				'set-post-meta',
				array(
					'post_id' => $id,
					'key'     => $key,
					'value'   => 'x',
				)
			);
			$this->assertSame( 'meta_not_registered', $err->get_error_code(), $key );
		}
	}

	public function test_set_post_meta_refuses_value_type_mismatch(): void {
		$id = $this->makePost();
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$this->registerMeta( 'post', 'rating', 'integer' );
		$this->registerMeta( 'post', 'featured', 'boolean' );
		$this->grant( $this->metaCaps( $id ) );

		$bad = array(
			array( 'subtitle', 12 ),
			array( 'subtitle', str_repeat( 'a', 10001 ) ),
			array( 'rating', 'abc' ),
			array( 'rating', 1.5 ),
			array( 'featured', 'maybe' ),
		);
		foreach ( $bad as list( $key, $value ) ) {
			$err = $this->runError(
				'set-post-meta',
				array(
					'post_id' => $id,
					'key'     => $key,
					'value'   => $value,
				)
			);
			$this->assertSame( 'meta_invalid_value', $err->get_error_code(), $key );
		}
		$this->assertSame( '', get_post_meta( $id, 'rating', true ) );
	}

	public function test_set_post_meta_uses_post_types_own_registration(): void {
		$id = $this->makePost( 'page' );
		$this->registerMeta( 'post', 'subtitle', 'string' );
		$GLOBALS['specflux_site_agent_test_user_caps'] = $this->metaCaps( $id ) + array( 'edit_pages' => true );

		$err = $this->runError(
			'set-post-meta',
			array(
				'post_id' => $id,
				'key'     => 'subtitle',
				'value'   => 'x',
			)
		);

		$this->assertSame( 'meta_not_registered', $err->get_error_code() );
	}

	public function test_set_post_meta_refuses_disallowed_post_type(): void {
		$id = $this->makePost( 'attachment' );
		$this->registerMeta( 'attachment', 'subtitle', 'string' );
		$GLOBALS['specflux_site_agent_test_user_caps'] = $this->metaCaps( $id ) + array( 'edit_attachments' => true );

		$this->assertTrue(
			$this->denied(
				'set-post-meta',
				array(
					'post_id' => $id,
					'key'     => 'subtitle',
					'value'   => 'x',
				)
			)
		);
	}
}
