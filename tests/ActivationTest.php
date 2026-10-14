<?php
/**
 * Activation creates the tables itself: dbDelta() is normally NOT loaded when
 * WordPress runs an activation hook, so the activation callback must not wait
 * for it to exist (Schema::install() requires upgrade.php on its own).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- test-only filesystem stand-in for wp-admin/includes/upgrade.php.
final class ActivationTest extends TestCase {

	private string $upgradeFile = '';

	protected function setUp(): void {
		$this->upgradeFile = ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	protected function tearDown(): void {
		if ( is_file( $this->upgradeFile ) ) {
			unlink( $this->upgradeFile );
		}
	}

	public function test_activation_installs_the_schema_even_though_dbdelta_is_not_loaded(): void {
		// Stand-in for wp-admin/includes/upgrade.php, the only thing that defines dbDelta().
		if ( ! is_dir( dirname( $this->upgradeFile ) ) ) {
			mkdir( dirname( $this->upgradeFile ), 0777, true );
		}
		file_put_contents(
			$this->upgradeFile,
			'<?php function dbDelta( $queries = "", bool $execute = true ): array { foreach ( (array) $queries as $q ) { $GLOBALS["specflux_site_agent_test_dbdelta_queries"][] = (string) $q; } return array(); }'
		);

		$output = shell_exec( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- test-only child process; dbDelta cannot be undefined in-process.
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/activation-probe.php' ) . ' 2>&1'
		);
		$result = json_decode( (string) $output, true );

		$this->assertIsArray( $result, 'Probe output was not JSON: ' . $output );
		$this->assertFalse( $result['dbdelta_defined_at_start'] );
		$this->assertCount( 3, $result['queries'] ); // Runs, steps and (0.4) connector activity.
		$this->assertStringContainsString( 'CREATE TABLE', implode( "\n", $result['queries'] ) );
		$this->assertSame( \Specflux\SiteAgent\Schema::DB_VERSION, $result['db_version'] );
	}
}
