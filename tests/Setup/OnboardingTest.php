<?php
/**
 * Guided setup (onboarding O4): the six step computations.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Setup;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\SiteBrief;
use Specflux\SiteAgent\Setup\Checks;
use Specflux\SiteAgent\Setup\Onboarding;
use WP_Error;

final class OnboardingTest extends TestCase {

	private const ADMIN  = 1;
	private const EDITOR = 2;

	protected function setUp(): void {
		require_once dirname( __DIR__ ) . '/stubs/blocks.php';
		Plugin::reset();
		Plugin::set_dependency_probe( false );
		Checks::setProviderProbe( true );
		Onboarding::setPluginProbe(
			array(
				'installed' => array(),
				'active'    => array(),
			)
		);
		remove_all_filters( 'specflux_site_agent_packs' );
		$GLOBALS['specflux_site_agent_test_options']         = array();
		$GLOBALS['specflux_site_agent_test_users']           = array(
			self::ADMIN  => (object) array( 'roles' => array( 'administrator' ) ),
			self::EDITOR => (object) array( 'roles' => array( 'editor' ) ),
		);
		$GLOBALS['specflux_site_agent_test_user_caps_by_id'] = array(
			self::ADMIN  => array(
				'manage_options'   => true,
				'activate_plugins' => true,
			),
			self::EDITOR => array(
				'manage_options'   => false,
				'activate_plugins' => false,
			),
		);
		$GLOBALS['specflux_site_agent_test_user_caps']       = array();
	}

	protected function tearDown(): void {
		Plugin::reset();
		Checks::setProviderProbe( true );
		Onboarding::setPluginProbe( null );
		remove_all_filters( 'specflux_site_agent_packs' );
		unset( $GLOBALS['specflux_site_agent_test_users'], $GLOBALS['specflux_site_agent_test_user_caps_by_id'] );
	}

	/** @return array<string,mixed> */
	private function step( int $user_id, string $id ): ?array {
		foreach ( Onboarding::steps( $user_id ) as $step ) {
			if ( $id === $step['id'] ) {
				return $step;
			}
		}

		return null;
	}

	private function ids( int $user_id ): array {
		return array_column( Onboarding::steps( $user_id ), 'id' );
	}

	private function registerPack( string $name, bool $runnable ): void {
		$pack = new class() extends Pack {
			public bool $runnable   = true;
			public string $packName = 'pages';

			public function name(): string {
				return $this->packName;
			}

			/** @return list<string> */
			public function allowList(): array {
				return array( 'specflux/read-content' );
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array( 'read' => 0 );
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return null;
			}

			/** @param list<string>|null $skills_disable Ignored by the double. */
			public function preflight( int $user_id, string $consumer = '', string $goal = '', ?array $skills_disable = null ): bool|WP_Error {
				unset( $user_id, $consumer, $goal, $skills_disable );

				return $this->runnable ? true : new WP_Error( 'pack_unbound', 'unbound' );
			}
		};

		$pack->runnable = $runnable;
		$pack->packName = $name;
		add_filter( 'specflux_site_agent_packs', static fn ( array $packs ): array => $packs + array( $name => $pack ) );
	}

	private function governanceMode(): void {
		Plugin::reset();
		Plugin::set_dependency_probe( true );
	}

	// ---- Step 1 --------------------------------------------------------

	public function test_connect_a_model_passes_when_a_provider_is_configured(): void {
		$step = $this->step( self::ADMIN, 'provider' );

		$this->assertSame( 'pass', $step['status'] );
		$this->assertTrue( $step['blocking'] );
		$this->assertNull( $step['action'] );
	}

	public function test_connect_a_model_fails_with_a_connectors_link_for_an_admin(): void {
		Checks::setProviderProbe( false );
		$step = $this->step( self::ADMIN, 'provider' );

		$this->assertSame( 'fail', $step['status'] );
		$this->assertSame( 'Open Connectors', $step['action']['label'] );
		$this->assertStringContainsString( 'options-connectors.php', $step['action']['url'] );
		$this->assertNull( $step['ask'] );
	}

	public function test_connect_a_model_asks_a_non_admin_to_find_an_administrator(): void {
		Checks::setProviderProbe( false );
		$step = $this->step( self::EDITOR, 'provider' );

		$this->assertSame( 'fail', $step['status'] );
		$this->assertNull( $step['action'] );
		$this->assertStringContainsString( 'Ask an administrator', $step['ask'] );
	}

	// ---- Step 2 --------------------------------------------------------

	public function test_approval_always_passes_and_is_never_blocking(): void {
		foreach ( array( false, true ) as $present ) {
			Plugin::reset();
			Plugin::set_dependency_probe( $present );
			$step = $this->step( self::ADMIN, 'approval' );
			$this->assertSame( 'pass', $step['status'] );
			$this->assertFalse( $step['blocking'] );
		}
	}

	public function test_approval_offers_an_install_link_when_agent_governance_is_missing(): void {
		$step = $this->step( self::ADMIN, 'approval' );

		$this->assertSame( 'built_in', $step['mode'] );
		$this->assertSame( 'missing', $step['options'][1]['state'] );
		$this->assertStringContainsString( 'plugin-install.php', $step['options'][1]['action']['url'] );
		$this->assertStringContainsString( 'plugin=belayline-agent-governance', $step['options'][1]['action']['url'] );
	}

	public function test_approval_offers_activation_when_agent_governance_is_installed_but_off(): void {
		Onboarding::setPluginProbe(
			array(
				'installed' => array( 'belayline-agent-governance/belayline-agent-governance.php' ),
				'active'    => array(),
			)
		);
		$step = $this->step( self::ADMIN, 'approval' );

		$this->assertSame( 'installed', $step['options'][1]['state'] );
		$this->assertStringContainsString( 'action=activate', $step['options'][1]['action']['url'] );
		$this->assertStringContainsString( 'belayline-agent-governance%2Fbelayline-agent-governance.php', $step['options'][1]['action']['url'] );
	}

	public function test_approval_shows_agent_governance_active_without_an_action(): void {
		$this->governanceMode();
		$step = $this->step( self::ADMIN, 'approval' );

		$this->assertSame( 'belayline-agent-governance', $step['mode'] );
		$this->assertSame( 'active', $step['options'][1]['state'] );
		$this->assertNull( $step['options'][1]['action'] );
	}

	// ---- Step 3 --------------------------------------------------------

	public function test_the_old_plugin_step_is_hidden_unless_both_plugins_are_installed(): void {
		$cases = array(
			'neither'                         => array(),
			'only old'                        => array( 'agent-safety/agent-safety.php' ),
			'only belayline-agent-governance' => array( 'belayline-agent-governance/belayline-agent-governance.php' ),
		);
		foreach ( $cases as $label => $installed ) {
			Onboarding::setPluginProbe(
				array(
					'installed' => $installed,
					'active'    => $installed,
				)
			);
			$this->assertNotContains( 'old-plugin', $this->ids( self::ADMIN ), $label );
		}

		$both = array( 'agent-safety/agent-safety.php', 'belayline-agent-governance/belayline-agent-governance.php' );
		Onboarding::setPluginProbe(
			array(
				'installed' => $both,
				'active'    => array(),
			)
		);
		$this->assertContains( 'old-plugin', $this->ids( self::ADMIN ) );
	}

	public function test_the_old_plugin_step_passes_when_it_is_installed_but_not_active(): void {
		$both = array( 'agent-safety/agent-safety.php', 'belayline-agent-governance/belayline-agent-governance.php' );
		Onboarding::setPluginProbe(
			array(
				'installed' => $both,
				'active'    => array( 'belayline-agent-governance/belayline-agent-governance.php' ),
			)
		);

		$step = $this->step( self::ADMIN, 'old-plugin' );

		$this->assertSame( 'pass', $step['status'] );
		$this->assertFalse( Onboarding::oldPluginBlocks( self::ADMIN ) );
	}

	public function test_the_old_plugin_step_fails_with_a_deactivate_link_when_both_are_active(): void {
		$both = array( 'agent-safety/agent-safety.php', 'belayline-agent-governance/belayline-agent-governance.php' );
		Onboarding::setPluginProbe(
			array(
				'installed' => $both,
				'active'    => $both,
			)
		);

		$admin = $this->step( self::ADMIN, 'old-plugin' );
		$this->assertSame( 'fail', $admin['status'] );
		$this->assertTrue( $admin['blocking'] );
		$this->assertStringContainsString( 'action=deactivate', $admin['action']['url'] );
		$this->assertStringContainsString( 'agent-safety%2Fagent-safety.php', $admin['action']['url'] );
		$this->assertTrue( Onboarding::oldPluginBlocks( self::ADMIN ) );

		$editor = $this->step( self::EDITOR, 'old-plugin' );
		$this->assertNull( $editor['action'] );
		$this->assertStringContainsString( 'Ask an administrator', $editor['ask'] );
	}

	// ---- Step 4 --------------------------------------------------------

	public function test_the_role_step_is_hidden_in_built_in_mode(): void {
		$this->assertNotContains( 'role-binding', $this->ids( self::ADMIN ) );
	}

	public function test_the_role_step_passes_when_a_content_pack_is_runnable(): void {
		$this->governanceMode();
		$this->registerPack( 'posts', true );

		$step = $this->step( self::ADMIN, 'role-binding' );

		$this->assertSame( 'pass', $step['status'] );
		$this->assertNull( $step['action'] );
	}

	public function test_a_runnable_commerce_pack_alone_does_not_pass_the_role_step(): void {
		$this->governanceMode();
		$this->registerPack( 'commerce', true );
		$this->registerPack( 'pages', false );

		$this->assertSame( 'fail', $this->step( self::ADMIN, 'role-binding' )['status'] );
	}

	public function test_the_role_step_gives_an_admin_the_deep_link_for_their_role(): void {
		$this->governanceMode();
		$this->registerPack( 'pages', false );

		$step = $this->step( self::ADMIN, 'role-binding' );

		$this->assertSame( 'fail', $step['status'] );
		$this->assertTrue( $step['blocking'] );
		$this->assertSame( 'Turn on in Belayline Agent Governance', $step['action']['label'] );
		$this->assertSame(
			'https://example.test/wp-admin/tools.php?page=belayline-packs&belayline_bind_token=role%3Aadministrator&belayline_bind_pack=specflux-content',
			$step['action']['url']
		);
		$this->assertNull( $step['ask'] );
	}

	public function test_the_role_step_names_the_role_and_pack_for_a_non_admin(): void {
		$this->governanceMode();
		$this->registerPack( 'pages', false );

		$step = $this->step( self::EDITOR, 'role-binding' );

		$this->assertSame( 'fail', $step['status'] );
		$this->assertNull( $step['action'] );
		$this->assertSame( 'Ask an administrator to bind Editor to Specflux Site Agent content in Belayline Agent Governance.', $step['ask'] );
	}

	public function test_the_bind_link_uses_the_viewers_own_role_token(): void {
		$this->assertSame( 'role:editor', Onboarding::bindToken( self::EDITOR ) );
		$this->assertStringContainsString( 'belayline_bind_token=role%3Aeditor&', Onboarding::bindUrl( self::EDITOR ) );

		$GLOBALS['specflux_site_agent_test_users'][9] = (object) array( 'roles' => array( 'shop_manager', 'administrator' ) );
		$this->assertSame( 'role:administrator', Onboarding::bindToken( 9 ), 'administrator wins among several roles' );

		$GLOBALS['specflux_site_agent_test_users'][8] = (object) array( 'roles' => array() );
		$this->assertSame( 'user:8', Onboarding::bindToken( 8 ), 'a user with no role binds by user id' );
	}

	// ---- Step 5 --------------------------------------------------------

	public function test_the_site_brief_step_is_todo_until_saved_or_skipped(): void {
		$step = $this->step( self::ADMIN, 'site-brief' );
		$this->assertSame( 'todo', $step['status'] );
		$this->assertFalse( $step['blocking'] );
		$this->assertTrue( $step['canEdit'] );

		SiteBrief::set( 'We sell bicycles in Leeds.' );
		$saved = $this->step( self::ADMIN, 'site-brief' );
		$this->assertSame( 'pass', $saved['status'] );
		$this->assertSame( 'We sell bicycles in Leeds.', $saved['brief'] );

		SiteBrief::set( '' );
		update_option( Onboarding::BRIEF_SKIPPED_OPTION, 1 );
		$this->assertSame( 'pass', $this->step( self::ADMIN, 'site-brief' )['status'] );
	}

	public function test_a_non_admin_cannot_edit_the_site_brief(): void {
		$step = $this->step( self::EDITOR, 'site-brief' );

		$this->assertFalse( $step['canEdit'] );
		$this->assertNotNull( $step['ask'] );
	}

	// ---- Step 6 --------------------------------------------------------

	public function test_first_run_offers_three_goals_spread_across_the_runnable_packs(): void {
		$this->registerPack( 'pages', true );
		$this->registerPack( 'posts', true );
		$this->registerPack( 'site', false );

		$step = $this->step( self::ADMIN, 'first-run' );

		$this->assertSame( 'todo', $step['status'] );
		$this->assertCount( 3, $step['goals'] );
		$this->assertSame( array( 'pages', 'posts', 'pages' ), array_column( $step['goals'], 'pack' ) );
		$this->assertCount( 3, array_unique( array_column( $step['goals'], 'text' ) ) );
	}

	public function test_first_run_offers_no_goals_when_no_pack_is_runnable(): void {
		$this->registerPack( 'pages', false );

		$this->assertSame( array(), $this->step( self::ADMIN, 'first-run' )['goals'] );
	}

	public function test_starter_goals_ignore_unknown_packs_and_cap_at_three(): void {
		$this->assertSame( array(), Onboarding::starterGoals( array( 'mystery' ) ) );
		$this->assertCount( 3, Onboarding::starterGoals( array( 'pages', 'posts', 'site', 'commerce' ) ) );
	}

	// ---- Whole list ----------------------------------------------------

	public function test_steps_are_numbered_without_gaps_when_steps_are_hidden(): void {
		$numbers = array_column( Onboarding::steps( self::ADMIN ), 'number' );

		$this->assertSame( array( 1, 2, 3, 4 ), $numbers );
		$this->assertSame( array( 'provider', 'approval', 'site-brief', 'first-run' ), $this->ids( self::ADMIN ) );
	}

	public function test_the_full_list_has_six_steps_in_the_spec_order(): void {
		$both = array( 'agent-safety/agent-safety.php', 'belayline-agent-governance/belayline-agent-governance.php' );
		Onboarding::setPluginProbe(
			array(
				'installed' => $both,
				'active'    => array(),
			)
		);
		$this->governanceMode();

		$this->assertSame(
			array( 'provider', 'approval', 'old-plugin', 'role-binding', 'site-brief', 'first-run' ),
			$this->ids( self::ADMIN )
		);
	}

	public function test_first_blocking_returns_the_first_failing_blocking_step_only(): void {
		Checks::setProviderProbe( false );
		$this->governanceMode();
		$this->registerPack( 'pages', false );

		$this->assertSame( 'provider', Onboarding::firstBlocking( Onboarding::steps( self::ADMIN ) )['id'] );

		Checks::setProviderProbe( true );
		$this->assertSame( 'role-binding', Onboarding::firstBlocking( Onboarding::steps( self::ADMIN ) )['id'] );

		$this->registerPack( 'posts', true );
		$this->assertNull( Onboarding::firstBlocking( Onboarding::steps( self::ADMIN ) ), 'todo steps never block' );
	}

	// ---- Pack-unavailable messages name the fix ---------------------------

	private function bindingCheckFor( int $user_id ): \Specflux\SiteAgent\Setup\SetupCheck {
		foreach ( ( new \Specflux\SiteAgent\Packs\Pages\PagesPack() )->setupChecks( $user_id ) as $candidate ) {
			if ( 'pages/binding' === $candidate->id() ) {
				return $candidate;
			}
		}

		$this->fail( 'no pages/binding check' );
	}

	public function test_an_unbound_content_pack_tells_an_admin_what_to_bind_and_links_the_prefilled_page(): void {
		$this->governanceMode(); // Belayline Agent Governance locator absent in the suite => unbound.
		$check = $this->bindingCheckFor( self::ADMIN );

		$this->assertStringContainsString( 'Bind Administrator to Specflux Site Agent content in Belayline Agent Governance', $check->messageFor( self::ADMIN ) );
		$this->assertSame( Onboarding::bindUrl( self::ADMIN ), $check->fixUrl() );
	}

	public function test_an_unbound_content_pack_tells_an_editor_to_ask_an_administrator_by_role(): void {
		$this->governanceMode();
		$check = $this->bindingCheckFor( self::EDITOR );

		$this->assertSame( 'Ask an administrator to bind Editor to Specflux Site Agent content in Belayline Agent Governance.', $check->messageFor( self::EDITOR ) );
	}

	public function test_a_non_content_pack_is_not_told_to_bind_specflux_site_agent_content(): void {
		$this->governanceMode();
		$pack = new class() extends Pack {
			public function name(): string {
				return 'widgets';
			}

			/** @return array<string,int> */
			public function verbMap(): array {
				return array();
			}

			protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
				unset( $user_id );

				return new WP_Error( 'pack_unbound', 'x' );
			}
		};

		$message = $pack->preflight( self::ADMIN );

		$this->assertInstanceOf( WP_Error::class, $message );
		$this->assertStringNotContainsString( 'Specflux Site Agent content', $message->get_error_message() );
		$this->assertStringContainsString( 'widgets pack for Administrator', $message->get_error_message() );
	}
}
