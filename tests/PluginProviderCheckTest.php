<?php
/**
 * Plugin-level tests for 0.3 S11 follow-up: `start()` refuses on the
 * harness's own blocking checks (the provider check) through the SAME
 * evaluator ({@see \Specflux\SiteAgent\Setup\Checks}) that backs the setup
 * panel — "one evaluator" (S11), never a rule re-derived at start().
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Model\ModelGatewayInterface;
use Specflux\SiteAgent\Model\ModelTurn;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Setup\Checks;
use Specflux\SiteAgent\Tools\ToolExecutor;
use Specflux\SiteAgent\Tools\ToolRegistry;
use Specflux\SiteAgent\Approval\ApprovalBridge;
use WP_Error;
use wpdb;

final class PluginProviderCheckTest extends TestCase {

	protected function setUp(): void {
		Plugin::reset();
		$GLOBALS['specflux_site_agent_test_current_user_id'] = 1;
		$GLOBALS['specflux_site_agent_test_transients']      = array();
	}

	protected function tearDown(): void {
		Checks::setProviderProbe( true ); // Restore the suite-wide default (tests/bootstrap.php).
		remove_all_filters( 'specflux_site_agent_packs' );
		Plugin::reset();
		unset( $GLOBALS['wpdb'] );
	}

	/** Wires a real Runner (no model calls expected) into the singleton. */
	private function seedRunnerGraph(): Runner {
		$db              = new wpdb();
		$db->queryReturn = 1;
		$GLOBALS['wpdb'] = $db;

		$runner = new Runner(
			new WpdbRunStore( $db ),
			new ToolExecutor(),
			new class() implements ModelGatewayInterface {
				public function generateTurn( array $history, string $system_instruction, ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error {
					unset( $history, $system_instruction, $tools );

					return new WP_Error( 'unused', 'no model calls in this test' );
				}
			},
			new ApprovalBridge()
		);

		$prop = new \ReflectionProperty( Plugin::class, 'runner' );
		$prop->setAccessible( true );
		$prop->setValue( Plugin::instance(), $runner );

		return $runner;
	}

	/** A pack whose own preflight always passes — only the harness check is under test. */
	private function registerAlwaysBoundPack(): void {
		add_filter(
			'specflux_site_agent_packs',
			static function ( array $packs ): array {
				$packs['fixture'] = new class() extends Pack {
					public function name(): string {
						return 'fixture';
					}

					public function verbMap(): array {
						return array();
					}

					protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
						unset( $user_id );

						return null; // Always bound — not what this test is about.
					}
				};

				return $packs;
			}
		);
	}

	public function test_start_refuses_on_the_direct_allow_path_when_the_provider_check_fails(): void {
		Plugin::set_dependency_probe( true ); // AS mode — irrelevant to the harness check.
		Checks::setProviderProbe( false );
		$this->seedRunnerGraph();

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array( 'specflux/read-content' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_provider_unconfigured', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function test_start_refuses_with_a_pack_when_the_provider_check_fails_even_though_the_pack_itself_passes(): void {
		Plugin::set_dependency_probe( true );
		Checks::setProviderProbe( false );
		$this->seedRunnerGraph();
		$this->registerAlwaysBoundPack();

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array(), array(), 'fixture' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'specflux_site_agent_provider_unconfigured', $result->get_error_code() );
	}

	public function test_start_succeeds_once_the_provider_is_configured(): void {
		Plugin::set_dependency_probe( true );
		Checks::setProviderProbe( true );
		$this->seedRunnerGraph();

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array( 'specflux/read-content' ) );

		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * "One evaluator" (S11): the panel's `Checks::harnessChecks()` verdict for
	 * a given viewer/provider state and `start()`'s refusal must never
	 * disagree about WHICH check failed.
	 */
	public function test_the_panels_evaluation_agrees_with_starts_refusal(): void {
		Plugin::set_dependency_probe( true );
		Checks::setProviderProbe( false );
		$this->seedRunnerGraph();

		$panel_failure = Checks::firstBlockingFailure( Checks::harnessChecks() );
		$this->assertNotNull( $panel_failure );

		$result = Plugin::instance()->start( 'specflux-mac', 'Write a post', array( 'specflux/read-content' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $panel_failure->errorCode(), $result->get_error_code() );
	}
}
