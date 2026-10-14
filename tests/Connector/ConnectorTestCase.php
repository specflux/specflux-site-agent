<?php
/**
 * Shared fixtures for the connector suites: a fake adapter, fixture packs and
 * abilities, and helpers to authenticate "as" an application password.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Connector;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Connector\Connection;
use Specflux\SiteAgent\Connector\Connections;
use Specflux\SiteAgent\Connector\Connector;
use Specflux\SiteAgent\Connector\ExposureSet;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Tools\HarnessTools;
use SpecfluxSiteAgent_Test_Fake_Ability;
use SpecfluxSiteAgent_Test_Fake_Mcp_Server;
use SpecfluxSiteAgent_Test_Fake_Mcp_Tool;
use WP_Error;

abstract class ConnectorTestCase extends TestCase {

	protected const USER = 7;
	protected const UUID = '11111111-2222-3333-4444-555555555555';

	protected function setUp(): void {
		$GLOBALS['specflux_site_agent_test_filters']               = array();
		$GLOBALS['specflux_site_agent_test_options']               = array();
		$GLOBALS['specflux_site_agent_test_user_meta']             = array();
		$GLOBALS['specflux_site_agent_test_abilities']             = array();
		$GLOBALS['specflux_site_agent_test_user_caps']             = array( 'manage_options' => true );
		$GLOBALS['specflux_site_agent_test_user_caps_by_id']       = array();
		$GLOBALS['specflux_site_agent_test_current_user_id']       = self::USER;
		$GLOBALS['specflux_site_agent_test_app_password']          = null;
		$GLOBALS['specflux_site_agent_test_multisite']             = false;
		$GLOBALS['specflux_site_agent_test_bad_nonce']             = false;
		$GLOBALS['specflux_site_agent_test_redirect']              = '';
		$GLOBALS['specflux_site_agent_test_app_passwords']         = array();
		$GLOBALS['specflux_site_agent_test_users']                 = array( self::USER => (object) array( 'user_login' => 'editor' ) );
		$GLOBALS['specflux_site_agent_test_remote_post_responses'] = array();
		$GLOBALS['specflux_site_agent_test_remote_post_calls']     = array();
		$_POST                                    = array();
		$GLOBALS['wpdb']                          = new \wpdb();
		$GLOBALS['specflux_site_agent_test_cron'] = array();
		Plugin::reset();
		Plugin::set_dependency_probe( false ); // Built-in mode unless a test says otherwise.
		Connector::set_adapter_version_probe( null );
		Connector::set_serving( null );
		ExposureSet::reset();
		Clock::reset();
	}

	protected function tearDown(): void {
		Plugin::reset();
		Connector::set_adapter_version_probe( null );
		Connector::set_serving( null );
		ExposureSet::reset();
		Clock::reset();
		$GLOBALS['specflux_site_agent_test_app_password'] = null;
		$GLOBALS['specflux_site_agent_test_multisite']    = false;
		$GLOBALS['specflux_site_agent_test_bad_nonce']    = false;
		$GLOBALS['specflux_site_agent_test_filters']      = array();
		$_POST = array();
	}

	/** Switch on, adapter 0.7.0 present, single site. */
	protected function enableConnector(): void {
		update_option( Connector::OPTION, '1' );
		Connector::set_adapter_version_probe( '0.7.0' );
	}

	protected function governanceMode(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
		ExposureSet::reset();
	}

	/** Register an ability (and return it). */
	protected function ability( string $name, array $meta = array() ): SpecfluxSiteAgent_Test_Fake_Ability {
		$ability = new SpecfluxSiteAgent_Test_Fake_Ability( $name, true, array( 'ok' => true ), '', null, $meta );
		$GLOBALS['specflux_site_agent_test_abilities'][ $name ] = $ability;

		return $ability;
	}

	/**
	 * Register a fixture pack that owns the given abilities.
	 *
	 * @param array<string,int> $tiers          ability => tier.
	 * @param bool              $needs_governance Commerce-style: refuses the built-in gate.
	 * @param callable|null     $verb_for        Argument-aware verb (ability, input) => verb.
	 */
	protected function pack( string $name, array $tiers, bool $needs_governance = false, ?callable $verb_for = null ): void {
		$pack = new class( $name, $tiers, $needs_governance, $verb_for ) extends Pack {
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

		add_filter( 'specflux_site_agent_packs', static fn ( array $packs ): array => $packs + array( $name => $pack ), 10, 1 );
		ExposureSet::reset();
	}

	/** The standard fixture: reads, writes, a commerce write, plus the exclusions. */
	protected function standardAbilities(): void {
		foreach ( array( 'specflux/read-thing', 'specflux/write-thing', 'shop/refund', 'specflux/hidden-thing' ) as $name ) {
			$this->ability( $name, 'specflux/hidden-thing' === $name ? array( 'specflux' => array( 'hidden' => true ) ) : array() );
		}
		$this->ability( HarnessTools::TOOL_NAME );
		$this->pack(
			'fixture',
			array(
				'specflux/read-thing'   => 0,
				'specflux/write-thing'  => 1,
				'specflux/hidden-thing' => 0,
				HarnessTools::TOOL_NAME => 0,
			)
		);
		$this->pack( 'shop', array( 'shop/refund' => 2 ), true );
	}

	/** Authenticate as a registered application password with a scope. */
	protected function connectAs( string $scope = Connection::SCOPE_READ, int $user = self::USER, string $uuid = self::UUID ): Connection {
		Connections::add( $user, $uuid, $scope );
		$GLOBALS['specflux_site_agent_test_current_user_id'] = $user;
		$GLOBALS['specflux_site_agent_test_app_password']    = $uuid;

		return new Connection( $user, $uuid, $scope );
	}

	/** A server fake with a tool per ability (tool name = ability name with '/' -> '-'). */
	protected function server( array $abilities, string $id = Connector::SERVER_ID ): SpecfluxSiteAgent_Test_Fake_Mcp_Server {
		$server = new SpecfluxSiteAgent_Test_Fake_Mcp_Server( $id );
		foreach ( $abilities as $name ) {
			$server->add( $this->tool( $name ) );
		}

		return $server;
	}

	protected function tool( string $ability ): SpecfluxSiteAgent_Test_Fake_Mcp_Tool {
		return new SpecfluxSiteAgent_Test_Fake_Mcp_Tool( str_replace( '/', '-', $ability ), $ability );
	}
}
