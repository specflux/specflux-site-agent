<?php
/**
 * Turning the connector on must not widen mcp-adapter's default server:
 * SenroFlux abilities never opt in with `meta.mcp.public`.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Packs\Commerce\Abilities;
use SenroFlux_Test_Fake_Mcp_Adapter;

final class DefaultServerUnchangedTest extends ConnectorTestCase {

	public function test_no_registered_senroflux_ability_sets_mcp_public(): void {
		require_once dirname( __DIR__ ) . '/stubs/blocks.php';
		require_once dirname( __DIR__ ) . '/stubs/media.php';
		require_once dirname( __DIR__ ) . '/stubs/commerce.php';

		$GLOBALS['senroflux_test_ability_categories'] = array();
		Abilities::reset();
		Abilities::registerCategory();
		Abilities::register();
		$this->enableConnector();
		Connector::createServer( new SenroFlux_Test_Fake_Mcp_Adapter() );

		$registered = array_filter(
			$GLOBALS['senroflux_test_abilities'],
			static fn ( string $name ): bool => str_starts_with( $name, 'senroflux/' ),
			ARRAY_FILTER_USE_KEY
		);
		$this->assertNotEmpty( $registered, 'the registration under test registered nothing' );
		foreach ( $registered as $name => $ability ) {
			$meta = $ability->get_meta();
			$this->assertNotTrue( $meta['mcp']['public'] ?? false, $name . ' opts into the default server' );
		}
	}

	public function test_no_source_outside_the_connector_writes_an_mcp_meta_key(): void {
		$root  = dirname( __DIR__, 2 ) . '/src';
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || str_contains( $file->getPathname(), '/src/Connector/' ) ) {
				continue;
			}
			$source = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local source file.
			if ( 1 === preg_match( '/[\'"]mcp[\'"]\s*=>/', $source ) ) {
				$found[] = $file->getPathname();
			}
		}

		$this->assertSame( array(), $found );
	}
}
