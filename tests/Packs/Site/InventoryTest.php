<?php
/**
 * Site\Inventory tests: list-plugins / list-themes.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Site;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Packs\Site\Inventory;
use WP_Error;

final class InventoryTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 2 ) . '/stubs/site-tools.php';

		$GLOBALS['senroflux_test_abilities']          = array();
		$GLOBALS['senroflux_test_ability_categories'] = array();
		$GLOBALS['senroflux_test_plugins']            = array(
			'akismet/akismet.php' => array(
				'Name'        => 'Akismet',
				'Version'     => '5.3',
				'AuthorURI'   => 'https://example.test/author',
				'Author'      => 'Someone <someone@example.test>',
				'Description' => 'Spam.',
			),
			'hello.php'           => array(
				'Name'    => 'Hello Dolly',
				'Version' => '1.7',
			),
			'netwide/netwide.php' => array(
				'Name'    => 'Netwide',
				'Version' => '2.0',
			),
		);
		$GLOBALS['senroflux_test_active_plugins']     = array( 'akismet/akismet.php' );
		$GLOBALS['senroflux_test_network_plugins']    = array( 'netwide/netwide.php' );
		$GLOBALS['senroflux_test_site_transients']    = array(
			'update_plugins' => (object) array( 'response' => array( 'hello.php' => (object) array( 'new_version' => '1.8' ) ) ),
			'update_themes'  => (object) array( 'response' => array( 'child' => array( 'new_version' => '2' ) ) ),
		);
		$GLOBALS['senroflux_test_stylesheet']         = 'child';
		$GLOBALS['senroflux_test_themes']             = array(
			'parent' => new \SenroFlux_Test_Fake_Theme( 'parent', 'Parent', '1.0', 'parent', false ),
			'child'  => new \SenroFlux_Test_Fake_Theme( 'child', 'Child', '2.0', 'parent', true ),
		);
		$GLOBALS['senroflux_test_user_caps']          = array(
			'activate_plugins' => true,
			'switch_themes'    => true,
		);

		Inventory::reset();
		Inventory::register();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['senroflux_test_stylesheet'] );
	}

	private function ability( string $name ): object {
		return $GLOBALS['senroflux_test_abilities'][ 'senroflux/' . $name ];
	}

	public function test_both_refuse_without_their_capability(): void {
		$GLOBALS['senroflux_test_user_caps'] = array( 'switch_themes' => true );
		$this->assertFalse( $this->ability( 'list-plugins' )->check_permissions( array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'list-plugins' )->execute( array() ) );

		$GLOBALS['senroflux_test_user_caps'] = array( 'activate_plugins' => true );
		$this->assertFalse( $this->ability( 'list-themes' )->check_permissions( array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->ability( 'list-themes' )->execute( array() ) );
	}

	public function test_list_plugins_shape_status_and_updates(): void {
		$result = $this->ability( 'list-plugins' )->execute( array() );

		$this->assertCount( 3, $result['plugins'] );
		$by_file = array_column( $result['plugins'], null, 'file' );
		$this->assertSame( 'active', $by_file['akismet/akismet.php']['status'] );
		$this->assertSame( 'inactive', $by_file['hello.php']['status'] );
		$this->assertSame( 'network_active', $by_file['netwide/netwide.php']['status'] );
		$this->assertTrue( $by_file['hello.php']['update_available'] );
		$this->assertFalse( $by_file['akismet/akismet.php']['update_available'] );
		$this->assertSame( '5.3', $by_file['akismet/akismet.php']['version'] );

		foreach ( $result['plugins'] as $plugin ) {
			$this->assertSame( array( 'file', 'name', 'version', 'status', 'update_available' ), array_keys( $plugin ) );
		}
		$this->assertStringNotContainsString( 'example.test', (string) wp_json_encode( $result ) );
	}

	public function test_list_plugins_status_filter(): void {
		$active = $this->ability( 'list-plugins' )->execute( array( 'status' => 'active' ) );
		$this->assertSame( array( 'akismet/akismet.php', 'netwide/netwide.php' ), array_column( $active['plugins'], 'file' ) );

		$inactive = $this->ability( 'list-plugins' )->execute( array( 'status' => 'inactive' ) );
		$this->assertSame( array( 'hello.php' ), array_column( $inactive['plugins'], 'file' ) );

		$this->assertInstanceOf( WP_Error::class, $this->ability( 'list-plugins' )->execute( array( 'status' => 'bogus' ) ) );
	}

	public function test_list_themes_shape(): void {
		$result = $this->ability( 'list-themes' )->execute( array() );

		$by_slug = array_column( $result['themes'], null, 'stylesheet' );
		$this->assertSame(
			array(
				'stylesheet'       => 'child',
				'name'             => 'Child',
				'version'          => '2.0',
				'active'           => true,
				'parent'           => 'parent',
				'block_theme'      => true,
				'update_available' => true,
			),
			$by_slug['child']
		);
		$this->assertSame( '', $by_slug['parent']['parent'] );
		$this->assertFalse( $by_slug['parent']['active'] );
		$this->assertFalse( $by_slug['parent']['update_available'] );
		foreach ( $result['themes'] as $theme ) {
			$this->assertSame( array( 'stylesheet', 'name', 'version', 'active', 'parent', 'block_theme', 'update_available' ), array_keys( $theme ) );
		}
	}

	public function test_schemas_have_no_max_length_and_are_read_only(): void {
		foreach ( array( 'list-plugins', 'list-themes' ) as $name ) {
			$ability = $this->ability( $name );
			$json    = (string) wp_json_encode( array( $ability->get_input_schema(), $ability->get_output_schema() ) );
			$this->assertStringNotContainsString( 'maxLength', $json );
			$this->assertTrue( $ability->get_meta()['annotations']['readonly'] );
			$this->assertFalse( $ability->get_meta()['annotations']['destructive'] );
		}
	}
}
