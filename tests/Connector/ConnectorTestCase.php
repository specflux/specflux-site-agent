<?php
/**
 * Shared fixtures for the connector suites: a fake adapter, fixture packs and
 * abilities, and helpers to authenticate "as" an application password.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Connector;

use PHPUnit\Framework\TestCase;
use Specflux\SenroFlux\Connector\Connection;
use Specflux\SenroFlux\Connector\Connections;
use Specflux\SenroFlux\Connector\Connector;
use Specflux\SenroFlux\Connector\ExposureSet;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\Clock;
use Specflux\SenroFlux\Tools\HarnessTools;
use SenroFlux_Test_Fake_Ability;
use SenroFlux_Test_Fake_Mcp_Server;
use SenroFlux_Test_Fake_Mcp_Tool;
use WP_Error;

abstract class ConnectorTestCase extends TestCase {

	protected const USER = 7;
	protected const UUID = '11111111-2222-3333-4444-555555555555';

	protected function setUp(): void {
		$GLOBALS['senroflux_test_filters']               = array();
		$GLOBALS['senroflux_test_options']               = array();
		$GLOBALS['senroflux_test_user_meta']             = array();
		$GLOBALS['senroflux_test_abilities']             = array();
		$GLOBALS['senroflux_test_user_caps']             = array( 'manage_options' => true );
		$GLOBALS['senroflux_test_user_caps_by_id']       = array();
		$GLOBALS['senroflux_test_current_user_id']       = self::USER;
		$GLOBALS['senroflux_test_app_password']          = null;
		$GLOBALS['senroflux_test_multisite']             = false;
		$GLOBALS['senroflux_test_bad_nonce']             = false;
		$GLOBALS['senroflux_test_redirect']              = '';
		$GLOBALS['senroflux_test_app_passwords']         = array();
		$GLOBALS['senroflux_test_users']                 = array( self::USER => (object) array( 'user_login' => 'editor' ) );
		$GLOBALS['senroflux_test_remote_post_responses'] = array();
		$GLOBALS['senroflux_test_remote_post_calls']     = array();
		$_POST = array();
		Plugin::reset();
		Plugin::set_dependency_probe( false ); // Built-in mode unless a test says otherwise.
		Connector::set_adapter_version_probe( null );
		ExposureSet::reset();
		Clock::reset();
	}

	protected function tearDown(): void {
		Plugin::reset();
		Connector::set_adapter_version_probe( null );
		ExposureSet::reset();
		Clock::reset();
		$GLOBALS['senroflux_test_app_password'] = null;
		$GLOBALS['senroflux_test_multisite']    = false;
		$GLOBALS['senroflux_test_bad_nonce']    = false;
		$GLOBALS['senroflux_test_filters']      = array();
		$_POST                                  = array();
	}

	/** Switch on, adapter 0.7.0 present, single site. */
	protected function enableConnector(): void {
		update_option( Connector::OPTION, '1' );
		Connector::set_adapter_version_probe( '0.7.0' );
	}

	protected function senroGateMode(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		ExposureSet::reset();
	}

	/** Register an ability (and return it). */
	protected function ability( string $name, array $meta = array() ): SenroFlux_Test_Fake_Ability {
		$ability                                      = new SenroFlux_Test_Fake_Ability( $name, true, array( 'ok' => true ), '', null, $meta );
		$GLOBALS['senroflux_test_abilities'][ $name ] = $ability;

		return $ability;
	}

	/**
	 * Register a fixture pack that owns the given abilities.
	 *
	 * @param array<string,int> $tiers          ability => tier.
	 * @param bool              $needs_senrogate Commerce-style: refuses the built-in gate.
	 * @param callable|null     $verb_for        Argument-aware verb (ability, input) => verb.
	 */
	protected function pack( string $name, array $tiers, bool $needs_senrogate = false, ?callable $verb_for = null ): void {
		$pack = new class( $name, $tiers, $needs_senrogate, $verb_for ) extends Pack {
			public function __construct( private readonly string $packName, private readonly array $tiers, private readonly bool $needs, private readonly mixed $verbForFn ) {
				parent::__construct( array() );
			}

			public function name(): string {
				return $this->packName;
			}

			public function allowList(): array {
				return array_keys( $this->tiers );
			}

			public function verbMap(): array {
				return $this->tiers;
			}

			public function requiresAgentSafety(): bool {
				return $this->needs;
			}

			public function verbFor( string $ability, array $input ): string {
				return null !== $this->verbForFn ? ( $this->verbForFn )( $ability, $input ) : $ability;
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}
		};

		add_filter( 'senroflux_packs', static fn ( array $packs ): array => $packs + array( $name => $pack ), 10, 1 );
		ExposureSet::reset();
	}

	/** The standard fixture: reads, writes, a commerce write, plus the exclusions. */
	protected function standardAbilities(): void {
		foreach ( array( 'senroflux/read-thing', 'senroflux/write-thing', 'shop/refund', 'senroflux/hidden-thing' ) as $name ) {
			$this->ability( $name, 'senroflux/hidden-thing' === $name ? array( 'senroflux' => array( 'hidden' => true ) ) : array() );
		}
		$this->ability( HarnessTools::TOOL_NAME );
		$this->pack(
			'fixture',
			array(
				'senroflux/read-thing'   => 0,
				'senroflux/write-thing'  => 1,
				'senroflux/hidden-thing' => 0,
				HarnessTools::TOOL_NAME  => 0,
			)
		);
		$this->pack( 'shop', array( 'shop/refund' => 2 ), true );
	}

	/** Authenticate as a registered application password with a scope. */
	protected function connectAs( string $scope = Connection::SCOPE_READ, int $user = self::USER, string $uuid = self::UUID ): Connection {
		Connections::add( $user, $uuid, $scope );
		$GLOBALS['senroflux_test_current_user_id'] = $user;
		$GLOBALS['senroflux_test_app_password']    = $uuid;

		return new Connection( $user, $uuid, $scope );
	}

	/** A server fake with a tool per ability (tool name = ability name with '/' -> '-'). */
	protected function server( array $abilities, string $id = Connector::SERVER_ID ): SenroFlux_Test_Fake_Mcp_Server {
		$server = new SenroFlux_Test_Fake_Mcp_Server( $id );
		foreach ( $abilities as $name ) {
			$server->add( $this->tool( $name ) );
		}

		return $server;
	}

	protected function tool( string $ability ): SenroFlux_Test_Fake_Mcp_Tool {
		return new SenroFlux_Test_Fake_Mcp_Tool( str_replace( '/', '-', $ability ), $ability );
	}
}
