<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads local plugin files.
/**
 * The vendored admin UI kit matches its manifest, and the zip build ships it.
 *
 * `admin-ui-kit/bin/sync` copies the kit into assets/vendor/admin-ui-kit/ and writes
 * MANIFEST.sha256. If a vendored file is edited by hand, or half-synced, this fails.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Admin\Ui;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Admin\Ui\Kit;

final class KitVendoredTest extends TestCase {

	private function dir(): string {
		return dirname( __DIR__, 3 ) . '/assets/vendor/admin-ui-kit/';
	}

	/** @return array<string,string> file => sha256 */
	private function manifest(): array {
		$lines = file( $this->dir() . 'MANIFEST.sha256', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertIsArray( $lines, 'the manifest exists' );

		$out = array();
		foreach ( $lines as $line ) {
			$this->assertSame( 1, preg_match( '/^([a-f0-9]{64})\s+\*?(\S+)$/', $line, $match ), 'manifest line: ' . $line );
			$out[ $match[2] ] = $match[1];
		}

		return $out;
	}

	public function test_every_vendored_file_matches_the_manifest(): void {
		foreach ( $this->manifest() as $file => $hash ) {
			$this->assertFileExists( $this->dir() . $file );
			$this->assertSame( $hash, hash_file( 'sha256', $this->dir() . $file ), $file . ' differs from the manifest: re-run admin-ui-kit/bin/sync' );
		}
	}

	public function test_no_vendored_file_is_missing_from_the_manifest(): void {
		$listed = array_keys( $this->manifest() );
		$found  = array_values( array_diff( (array) scandir( $this->dir() ), array( '.', '..', 'MANIFEST.sha256' ) ) );
		sort( $listed );
		sort( $found );

		$this->assertSame( $listed, $found );
	}

	public function test_the_files_the_screens_load_are_vendored(): void {
		foreach ( array( 'tokens.css', 'components.css', 'aui.js', 'icons.svg', 'VERSION' ) as $file ) {
			$this->assertArrayHasKey( $file, $this->manifest() );
		}
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', trim( (string) file_get_contents( $this->dir() . 'VERSION' ) ) );
		$this->assertStringContainsString( '--aui-accent', Kit::inlineCss() );
		$this->assertStringContainsString( 'id="aui-i-gear"', Kit::sprite() );
	}

	public function test_the_kit_makes_no_network_requests(): void {
		foreach ( array( 'tokens.css', 'components.css', 'aui.js' ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', (string) file_get_contents( $this->dir() . $file ), $file . ' references a remote host (wp.org guideline 8)' );
		}
	}

	public function test_the_release_build_ships_the_kit(): void {
		$root = dirname( __DIR__, 3 );
		$ign  = array_map( 'trim', (array) file( $root . '/.distignore', FILE_IGNORE_NEW_LINES ) );

		// rsync excludes an unanchored `assets` or `vendor` at ANY depth, which would drop assets/vendor/admin-ui-kit.
		$this->assertNotContains( 'assets', $ign );
		$this->assertNotContains( 'vendor', $ign );
		$this->assertContains( '/vendor', $ign, 'the Composer vendor/ stays excluded, anchored to the top level' );
		$this->assertContains( 'assets/src', $ign, 'the React sources stay excluded' );

		$build = (string) file_get_contents( $root . '/bin/build-zip.sh' );
		$this->assertStringNotContainsString( '(^|/)vendor/[^w]', $build, 'the zip check must not deny assets/vendor/' );
	}
}
