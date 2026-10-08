<?php
/**
 * Site\Settings tests: read-settings / update-settings.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Settings;
use WP_Error;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/site-tools.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_posts']              = array();
		$GLOBALS['senroflux_test_next_post_id']       = 100;
		$GLOBALS['senroflux_test_options']            = array(
			'blogname'       => 'Old name',
			'posts_per_page' => '10',
			'siteurl'        => 'https://example.test',
			'home'           => 'https://example.test',
			'admin_email'    => 'admin@example.test',
			'default_role'   => 'subscriber',
			'blog_public'    => '1',
		);
		$GLOBALS['senroflux_test_user_caps']          = array(
			'manage_options' => true,
			'edit_post'      => true,
		);

		Settings::reset();
		Settings::register();
	}

	private function read(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/read-settings'];
	}

	private function update(): object {
		return $GLOBALS['senroflux_test_abilities']['senroflux/update-settings'];
	}

	private function page( string $status = 'publish', string $type = 'page' ): int {
		return wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => $status,
				'post_title'  => 'P',
			)
		);
	}

	public function test_both_abilities_refuse_without_manage_options(): void {
		$GLOBALS['senroflux_test_user_caps'] = array();

		$this->assertFalse( $this->read()->check_permissions( array() ) );
		$this->assertFalse( $this->update()->check_permissions( array( 'settings' => array( 'blogname' => 'x' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->read()->execute( array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->update()->execute( array( 'settings' => array( 'blogname' => 'x' ) ) ) );
		$this->assertSame( 'Old name', get_option( 'blogname' ) );
	}

	public function test_read_returns_typed_settings_and_read_only_block(): void {
		$result = $this->read()->execute( array() );

		$this->assertSame( 'Old name', $result['settings']['blogname'] );
		$this->assertSame( 10, $result['settings']['posts_per_page'] );
		$this->assertSame( 'https://example.test', $result['read_only']['siteurl'] );
		$this->assertSame( 'subscriber', $result['read_only']['default_role'] );
		$this->assertSame(
			array( 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role' ),
			array_keys( $result['read_only'] )
		);
		$this->assertArrayNotHasKey( 'siteurl', $result['settings'] );
		$this->assertArrayNotHasKey( 'blog_public', $result['settings'] );
	}

	public function test_read_filters_by_group(): void {
		$result = $this->read()->execute( array( 'group' => 'reading' ) );

		$this->assertArrayHasKey( 'posts_per_page', $result['settings'] );
		$this->assertArrayNotHasKey( 'blogname', $result['settings'] );
		$this->assertArrayNotHasKey( 'comment_order', $result['settings'] );
		$this->assertInstanceOf( WP_Error::class, $this->read()->execute( array( 'group' => 'bad_group' ) ) );
	}

	public function test_update_reports_before_and_after_and_writes(): void {
		$result = $this->update()->execute(
			array(
				'settings' => array(
					'blogname'           => 'New name',
					'posts_per_page'     => 12,
					'comment_moderation' => true,
					'timezone_string'    => 'Europe/Paris',
				),
			)
		);

		$this->assertSame(
			array(
				'before' => 'Old name',
				'after'  => 'New name',
			),
			$result['updated']['blogname']
		);
		$this->assertSame(
			array(
				'before' => 10,
				'after'  => 12,
			),
			$result['updated']['posts_per_page']
		);
		$this->assertSame( 1, $result['updated']['comment_moderation']['after'] );
		$this->assertSame( 'Europe/Paris', get_option( 'timezone_string' ) );
		$this->assertSame( '12', get_option( 'posts_per_page' ) );
	}

	/** @return array<string,array{0:string}> */
	public static function forbiddenKeys(): array {
		return array(
			'siteurl'            => array( 'siteurl' ),
			'home'               => array( 'home' ),
			'admin_email'        => array( 'admin_email' ),
			'new_admin_email'    => array( 'new_admin_email' ),
			'users_can_register' => array( 'users_can_register' ),
			'default_role'       => array( 'default_role' ),
			'blog_public'        => array( 'blog_public' ),
			'unknown'            => array( 'some_random_option' ),
		);
	}

	/** @dataProvider forbiddenKeys */
	public function test_forbidden_keys_are_refused_and_nothing_is_written( string $key ): void {
		$before = $GLOBALS['senroflux_test_options'];

		// The valid entry comes first: atomicity means it must not land.
		$result = $this->update()->execute(
			array(
				'settings' => array(
					'blogname' => 'Should not land',
					$key       => 'evil',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'setting_not_allowed', $result->get_error_code() );
		$this->assertStringContainsString( $key, $result->get_error_message() );
		$this->assertSame( $before, $GLOBALS['senroflux_test_options'] );
	}

	/** @return array<string,array{0:string,1:mixed}> */
	public static function invalidValues(): array {
		return array(
			'int too high'       => array( 'posts_per_page', 101 ),
			'int too low'        => array( 'posts_per_page', 0 ),
			'int not numeric'    => array( 'posts_per_page', 'ten' ),
			'int float'          => array( 'posts_per_page', 1.5 ),
			'week day'           => array( 'start_of_week', 7 ),
			'bad enum'           => array( 'show_on_front', 'elsewhere' ),
			'bad rating'         => array( 'avatar_rating', 'NC17' ),
			'bad flag'           => array( 'comment_moderation', 2 ),
			'bad timezone'       => array( 'timezone_string', 'Mars/Olympus' ),
			'empty blogname'     => array( 'blogname', '' ),
			'array text'         => array( 'blogname', array( 'x' ) ),
			'long blogname'      => array( 'blogname', str_repeat( 'a', 201 ) ),
			'depth below 2'      => array( 'thread_comments_depth', 1 ),
			'days above 3650'    => array( 'close_comments_days_old', 3651 ),
			'max links negative' => array( 'comment_max_links', -1 ),
		);
	}

	/** @dataProvider invalidValues */
	public function test_invalid_values_are_refused_and_nothing_is_written( string $key, mixed $value ): void {
		$before = $GLOBALS['senroflux_test_options'];

		$result = $this->update()->execute(
			array(
				'settings' => array(
					'blogname' => 'Should not land',
					$key       => $value,
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_setting_value', $result->get_error_code() );
		$this->assertStringContainsString( $key, $result->get_error_message() );
		$this->assertSame( $before, $GLOBALS['senroflux_test_options'] );
	}

	public function test_empty_settings_is_an_error(): void {
		$this->assertInstanceOf( WP_Error::class, $this->update()->execute( array( 'settings' => array() ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->update()->execute( array() ) );
	}

	public function test_empty_timezone_is_allowed(): void {
		$result = $this->update()->execute( array( 'settings' => array( 'timezone_string' => '' ) ) );

		$this->assertSame( '', $result['updated']['timezone_string']['after'] );
	}

	public function test_page_on_front_must_be_a_published_page(): void {
		$draft = $this->page( 'draft' );
		$post  = $this->page( 'publish', 'post' );
		$good  = $this->page();

		foreach ( array( $draft, $post, 99999 ) as $bad ) {
			$result = $this->update()->execute( array( 'settings' => array( 'page_on_front' => $bad ) ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_setting_value', $result->get_error_code() );
		}
		$this->assertNull( $GLOBALS['senroflux_test_options']['page_on_front'] ?? null );

		$result = $this->update()->execute(
			array(
				'settings' => array(
					'show_on_front' => 'page',
					'page_on_front' => $good,
				),
			)
		);
		$this->assertSame( $good, $result['updated']['page_on_front']['after'] );
		$this->assertSame( 0, $result['updated']['page_on_front']['before'] );
	}

	public function test_page_the_caller_cannot_edit_is_refused_by_permission_and_execute(): void {
		$good = $this->page();
		$GLOBALS['senroflux_test_user_caps'][ 'edit_post:' . $good ] = false;

		$input = array( 'settings' => array( 'page_for_posts' => $good ) );

		$this->assertFalse( $this->update()->check_permissions( $input ) );
		$result = $this->update()->execute( $input );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNull( $GLOBALS['senroflux_test_options']['page_for_posts'] ?? null );
	}

	public function test_front_and_posts_page_must_differ(): void {
		$good = $this->page();

		$result = $this->update()->execute(
			array(
				'settings' => array(
					'page_on_front'  => $good,
					'page_for_posts' => $good,
				),
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNull( $GLOBALS['senroflux_test_options']['page_on_front'] ?? null );

		// Against the stored value too.
		$this->update()->execute( array( 'settings' => array( 'page_on_front' => $good ) ) );
		$again = $this->update()->execute( array( 'settings' => array( 'page_for_posts' => $good ) ) );
		$this->assertInstanceOf( WP_Error::class, $again );
	}

	public function test_schemas_have_no_max_length(): void {
		foreach ( array( 'senroflux/read-settings', 'senroflux/update-settings' ) as $name ) {
			$ability = $GLOBALS['senroflux_test_abilities'][ $name ];
			$json    = (string) wp_json_encode( array( $ability->get_input_schema(), $ability->get_output_schema() ) );
			$this->assertStringNotContainsString( 'maxLength', $json );
			$this->assertStringNotContainsString( 'minLength', $json );
		}
	}

	public function test_annotations(): void {
		$this->assertTrue( $this->read()->get_meta()['annotations']['readonly'] );
		$meta = $this->update()->get_meta()['annotations'];
		$this->assertFalse( $meta['destructive'] );
		$this->assertTrue( $meta['idempotent'] );
	}
}
