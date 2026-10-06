<?php
/**
 * The external-AI-apps opt-in: `meta.mcp.public` for senroflux/* abilities (0.4 S8).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Passthrough;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Admin\SettingsScreen;
use Specflux\SenroFlux\Passthrough\ExternalExposure;
use Specflux\SenroFlux\Plugin;

final class ExternalExposureTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['senroflux_test_options']   = array();
		$GLOBALS['senroflux_test_user_caps'] = array( 'manage_options' => true );
		$_POST                               = array();
		Plugin::reset();
		remove_all_filters( 'wp_register_ability_args' );
		ExternalExposure::register();
	}

	protected function tearDown(): void {
		Plugin::reset();
		remove_all_filters( 'wp_register_ability_args' );
		unset( $GLOBALS['senroflux_test_options'], $GLOBALS['senroflux_test_user_caps'] );
		$_POST = array();
	}

	private function setUpState( bool $on, bool $senrogate ): void {
		$GLOBALS['senroflux_test_options'][ ExternalExposure::OPTION ] = $on ? '1' : '0';
		Plugin::set_dependency_probe( $senrogate );
	}

	/** One call site for the core hook name. */
	private static function filtered( array $args, string $name ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core Abilities API hook.
		return apply_filters( 'wp_register_ability_args', $args, $name );
	}

	/** Run registration args through the real hook, as WP does. */
	private function registered( string $name, array $meta = array() ): array {
		return self::filtered( array( 'meta' => $meta ), $name );
	}

	public function test_off_by_default_sets_mcp_public_false(): void {
		Plugin::set_dependency_probe( true );

		$this->assertFalse( $this->registered( 'senroflux/list-things' )['meta']['mcp']['public'] );
		$this->assertFalse( ExternalExposure::effective() );
	}

	public function test_on_with_senrogate_present_is_public(): void {
		$this->setUpState( true, true );

		$this->assertTrue( $this->registered( 'senroflux/list-things' )['meta']['mcp']['public'] );
	}

	public function test_on_with_senrogate_absent_is_not_public(): void {
		$this->setUpState( true, false );

		$this->assertFalse( ExternalExposure::effective() );
		$this->assertFalse( $this->registered( 'senroflux/list-things' )['meta']['mcp']['public'] );
	}

	/** @return array<string,array{0:string}> */
	public static function harnessTools(): array {
		return array(
			'ask-user'               => array( 'senroflux/ask-user' ),
			'propose-plan'           => array( 'senroflux/propose-plan' ),
			'suggest-brief-addition' => array( 'senroflux/suggest-brief-addition' ),
		);
	}

	/** @dataProvider harnessTools */
	public function test_harness_tools_are_never_public( string $name ): void {
		$this->setUpState( true, true );

		$this->assertFalse(
			$this->registered(
				$name,
				array(
					'mcp'    => array( 'public' => true ),
					'public' => true,
				)
			)['meta']['mcp']['public']
		);
	}

	public function test_hidden_abilities_are_never_public(): void {
		$this->setUpState( true, true );

		$args = $this->registered(
			'senroflux/secret',
			array(
				'senroflux' => array( 'hidden' => true ),
				'mcp'       => array( 'public' => true ),
			)
		);

		$this->assertFalse( $args['meta']['mcp']['public'] );
	}

	public function test_other_namespaces_are_untouched(): void {
		$this->setUpState( true, true );

		$this->assertSame( array( 'meta' => array() ), $this->registered( 'acme/do-thing' ) );
	}

	public function test_off_overrides_an_inherited_meta_public(): void {
		$this->setUpState( false, true );

		$meta = $this->registered( 'senroflux/list-things', array( 'public' => true ) )['meta'];

		$this->assertFalse( $meta['mcp']['public'] );
		$this->assertTrue( $meta['public'], 'other meta is preserved' );
		$this->assertFalse( self::mcpExposed( $meta ), 'mirrors the adapter: explicit mcp.public beats meta.public' );
	}

	/**
	 * Mirrors McpAbilityExposure's precedence: `meta.mcp.public` wins when it
	 * is set, else `meta.public`; anything malformed fails closed.
	 */
	private static function mcpExposed( array $meta ): bool {
		if ( array_key_exists( 'mcp', $meta ) && is_array( $meta['mcp'] ) && array_key_exists( 'public', $meta['mcp'] ) ) {
			return true === $meta['mcp']['public'];
		}

		return true === ( $meta['public'] ?? false );
	}

	public function test_precedence_helper_explicit_false_beats_true_and_true_beats_false(): void {
		$this->assertFalse(
			self::mcpExposed(
				array(
					'public' => true,
					'mcp'    => array( 'public' => false ),
				)
			)
		);
		$this->assertTrue(
			self::mcpExposed(
				array(
					'public' => false,
					'mcp'    => array( 'public' => true ),
				)
			)
		);
		$this->assertFalse( self::mcpExposed( array( 'mcp' => 'nope' ) ) );
	}

	public function test_malformed_meta_fails_closed(): void {
		$this->setUpState( true, true );

		$args = self::filtered( array( 'meta' => 'bad' ), 'senroflux/senroflux-thing' );
		$this->assertTrue( $args['meta']['mcp']['public'], 'garbage meta is replaced, not trusted' );

		$args = self::filtered(
			array(
				'meta' => array(
					'senroflux' => 'x',
					'mcp'       => 'y',
				),
			),
			'senroflux/ask-user'
		);
		$this->assertFalse( $args['meta']['mcp']['public'] );
	}

	public function test_sanitize(): void {
		$this->assertTrue( ExternalExposure::sanitize( '1' ) );
		$this->assertFalse( ExternalExposure::sanitize( '0' ) );
		$this->assertFalse( ExternalExposure::sanitize( 'yes please' ) );
		$this->assertFalse( ExternalExposure::sanitize( array( '1' ) ) );
	}

	private function screen(): SettingsScreen {
		return new class() extends SettingsScreen {
			protected function redirectPassthrough( ?string $error_code ): void {
			}
		};
	}

	public function test_save_stores_the_switch_and_needs_manage_options(): void {
		$_POST[ ExternalExposure::OPTION ] = '1';
		$this->screen()->handleSaveExternal();
		$this->assertSame( '1', $GLOBALS['senroflux_test_options'][ ExternalExposure::OPTION ] );

		unset( $_POST[ ExternalExposure::OPTION ] );
		$this->screen()->handleSaveExternal();
		$this->assertSame( '0', $GLOBALS['senroflux_test_options'][ ExternalExposure::OPTION ] );
	}

	public function test_control_is_disabled_with_an_explanation_when_senrogate_is_absent(): void {
		$this->setUpState( true, false );

		ob_start();
		$this->screen()->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="senroflux_external_apps"[^>]*disabled/', $html );
		$this->assertStringNotContainsString( "checked='checked' disabled", $html );
		$this->assertStringContainsString( 'This needs SenroGate', $html );
		$this->assertStringContainsString( 'not SenroFlux&#039;s plan, budgets or report', str_replace( '&#39;', '&#039;', $html ) );
	}
}
