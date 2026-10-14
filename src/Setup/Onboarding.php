<?php
/**
 * The guided-setup steps behind the Runs screen's "Set up Specflux Site Agent" panel.
 *
 * Specflux Site Agent NEVER writes Belayline Agent Governance's settings. Step 4 only builds a link into
 * Belayline Agent Governance's own Capability Packs page with the change pre-filled; a human
 * with Belayline Agent Governance's capability clicks Save there.
 *
 * Pure computation: every step is plain data (status, why, one action) so the
 * React panel renders it and the Start button's tooltip can name the step that
 * blocks it.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Setup;

use Specflux\SiteAgent\Packs\ContentBundle;
use Specflux\SiteAgent\Packs\PackRegistry;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\SiteBrief;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Computes the six setup steps for one viewer.
 */
final class Onboarding {

	/** Site option: 1 once an administrator skipped the site-brief step. */
	public const BRIEF_SKIPPED_OPTION = 'specflux_site_agent_setup_brief_skipped';

	/** Belayline Agent Governance's plugin file. */
	public const AGENT_GOVERNANCE_FILE = 'belayline-agent-governance/belayline-agent-governance.php';

	/** The old Agent Safety plugin file Belayline Agent Governance replaces. */
	public const AGENT_SAFETY_FILE = 'agent-safety/agent-safety.php';

	/** Belayline Agent Governance's Capability Packs page (under Tools). */
	public const PACKS_PAGE = 'belayline-packs';

	/**
	 * Test seam: which plugins are installed and active. Null restores reality.
	 *
	 * @var array{installed:list<string>,active:list<string>}|null
	 */
	private static ?array $plugin_probe = null;

	/**
	 * Test seam: force which plugins are installed and active.
	 *
	 * @param array{installed:list<string>,active:list<string>}|null $probe Plugin files, or null for reality.
	 */
	public static function setPluginProbe( ?array $probe ): void {
		self::$plugin_probe = $probe;
	}

	/**
	 * The steps that apply to this viewer, in order, numbered 1..n after any
	 * hidden step is dropped.
	 *
	 * @param int $user_id The viewer.
	 * @return list<array<string,mixed>>
	 */
	public static function steps( int $user_id ): array {
		$mode     = Plugin::currentGateMode();
		$names    = self::runnablePackNames( $user_id );
		$runnable = array_values( array_filter( $names, static fn ( string $name ): bool => ContentBundle::covers( $name ) ) );

		$steps   = array();
		$steps[] = self::providerStep( $user_id );
		$steps[] = self::approvalStep( $mode );

		$old_plugin = self::oldPluginStep( $user_id );
		if ( null !== $old_plugin ) {
			$steps[] = $old_plugin;
		}

		if ( GateMode::AgentSafety === $mode ) {
			$steps[] = self::roleBindingStep( $user_id, array() !== $runnable );
		}

		$steps[] = self::siteBriefStep( $user_id );
		$steps[] = self::firstRunStep( $user_id );

		foreach ( $steps as $index => $step ) {
			$steps[ $index ]['number'] = $index + 1;
		}

		// The first-run goals are for the packs the viewer can run right now.
		foreach ( $steps as $index => $step ) {
			if ( 'first-run' === $step['id'] ) {
				$steps[ $index ]['goals'] = self::starterGoals( $names );
			}
		}

		return $steps;
	}

	/** Is the old Agent Safety plugin active beside an installed Belayline Agent Governance? */
	public static function oldPluginBlocks( int $user_id ): bool {
		$step = self::oldPluginStep( $user_id );

		return null !== $step && 'fail' === $step['status'];
	}

	/**
	 * The first blocking step that fails, or null.
	 *
	 * @param list<array<string,mixed>> $steps Output of {@see steps()}.
	 * @return array<string,mixed>|null
	 */
	public static function firstBlocking( array $steps ): ?array {
		foreach ( $steps as $step ) {
			if ( true === $step['blocking'] && 'fail' === $step['status'] ) {
				return $step;
			}
		}

		return null;
	}

	// ------------------------------------------------------------------
	// Steps
	// ------------------------------------------------------------------

	/**
	 * Step "Connect a model": the existing `specflux/provider` check.
	 *
	 * @return array<string,mixed>
	 */
	private static function providerStep( int $user_id ): array {
		$check  = Checks::providerCheck();
		$passed = $check->passed();
		$action = null;
		$ask    = null;

		if ( ! $passed ) {
			if ( $check->fixVisibleFor( $user_id ) && null !== $check->fixUrl() ) {
				$action = array(
					'label' => 'update_core' === $check->fixCapability() || 'specflux_site_agent_wordpress_outdated' === $check->errorCode()
						? __( 'Update WordPress', 'specflux-site-agent' )
						: __( 'Open Connectors', 'specflux-site-agent' ),
					'url'   => $check->fixUrl(),
				);
			} else {
				$ask = $check->blockedMessage();
			}
		}

		return array(
			'id'       => 'provider',
			'title'    => __( 'Connect a model', 'specflux-site-agent' ),
			'why'      => __( 'Specflux Site Agent needs an AI model to plan and write for you.', 'specflux-site-agent' ),
			'status'   => $passed ? 'pass' : 'fail',
			'blocking' => true,
			'detail'   => $passed ? null : $check->messageFor( $user_id ),
			'action'   => $action,
			'ask'      => $ask,
		);
	}

	/**
	 * Step "Choose how changes are approved": always passes, it is a choice.
	 *
	 * @return array<string,mixed>
	 */
	private static function approvalStep( GateMode $mode ): array {
		$state  = 'missing';
		$action = null;

		if ( GateMode::AgentSafety === $mode ) {
			$state = 'active';
		} elseif ( self::isInstalled( self::AGENT_GOVERNANCE_FILE ) ) {
			$state = 'installed';
		}

		if ( 'installed' === $state && function_exists( 'wp_nonce_url' ) && function_exists( 'admin_url' ) ) {
			$action = array(
				'label' => __( 'Activate Belayline Agent Governance', 'specflux-site-agent' ),
				'url'   => wp_nonce_url(
					admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( self::AGENT_GOVERNANCE_FILE ) ),
					'activate-plugin_' . self::AGENT_GOVERNANCE_FILE
				),
			);
		} elseif ( 'missing' === $state && function_exists( 'admin_url' ) ) {
			$action = array(
				'label' => __( 'Install Belayline Agent Governance', 'specflux-site-agent' ),
				'url'   => admin_url( 'plugin-install.php?tab=plugin-information&plugin=belayline-agent-governance' ),
			);
		}

		return array(
			'id'       => 'approval',
			'title'    => __( 'Choose how changes are approved', 'specflux-site-agent' ),
			'why'      => __( 'Decide who signs off before Specflux Site Agent changes your site.', 'specflux-site-agent' ),
			'status'   => 'pass',
			'blocking' => false,
			'mode'     => GateMode::AgentSafety === $mode ? 'belayline-agent-governance' : 'built_in',
			'options'  => array(
				array(
					'id'          => 'built_in',
					'title'       => __( 'Approve every change here', 'specflux-site-agent' ),
					'description' => __( 'Specflux Site Agent asks you on this screen before each change.', 'specflux-site-agent' ),
				),
				array(
					'id'          => 'belayline-agent-governance',
					'title'       => __( 'Use Belayline Agent Governance rules', 'specflux-site-agent' ),
					'description' => __( 'Belayline Agent Governance decides what needs approval by role and keeps an audit log.', 'specflux-site-agent' ),
					'state'       => $state,
					'action'      => $action,
				),
			),
			'action'   => null,
		);
	}

	/**
	 * Step "Clear the old Agent Safety plugin": hidden unless both it and
	 * Belayline Agent Governance are installed.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function oldPluginStep( int $user_id ): ?array {
		if ( ! self::isInstalled( self::AGENT_SAFETY_FILE ) || ! self::isInstalled( self::AGENT_GOVERNANCE_FILE ) ) {
			return null;
		}

		$passed = ! self::isActive( self::AGENT_SAFETY_FILE );
		$action = null;
		$ask    = null;

		if ( ! $passed ) {
			if ( function_exists( 'user_can' ) && user_can( $user_id, 'activate_plugins' ) && function_exists( 'wp_nonce_url' ) && function_exists( 'admin_url' ) ) {
				$action = array(
					'label' => __( 'Deactivate Agent Safety', 'specflux-site-agent' ),
					'url'   => wp_nonce_url(
						admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( self::AGENT_SAFETY_FILE ) ),
						'deactivate-plugin_' . self::AGENT_SAFETY_FILE
					),
				);
			} else {
				$ask = __( 'Ask an administrator to deactivate the Agent Safety plugin.', 'specflux-site-agent' );
			}
		}

		return array(
			'id'       => 'old-plugin',
			'title'    => __( 'Clear the old Agent Safety plugin', 'specflux-site-agent' ),
			'why'      => __( 'Belayline Agent Governance replaces Agent Safety and keeps its data, but the two cannot be active together.', 'specflux-site-agent' ),
			'status'   => $passed ? 'pass' : 'fail',
			'blocking' => true,
			'action'   => $action,
			'ask'      => $ask,
		);
	}

	/**
	 * Step "Allow Specflux Site Agent for your role" (Belayline Agent Governance mode only): passes when
	 * at least one content pack is runnable for the viewer.
	 *
	 * @return array<string,mixed>
	 */
	private static function roleBindingStep( int $user_id, bool $passed ): array {
		$role = self::roleLabel( $user_id );
		$step = array(
			'id'       => 'role-binding',
			'title'    => __( 'Allow Specflux Site Agent for your role', 'specflux-site-agent' ),
			'why'      => __( 'Belayline Agent Governance only lets a role use Specflux Site Agent once someone binds that role to a pack.', 'specflux-site-agent' ),
			'status'   => $passed ? 'pass' : 'fail',
			'blocking' => true,
			'role'     => $role,
			'action'   => null,
			'ask'      => null,
		);

		if ( $passed ) {
			return $step;
		}

		if ( function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' ) ) {
			$step['action'] = array(
				'label' => __( 'Turn on in Belayline Agent Governance', 'specflux-site-agent' ),
				'url'   => self::bindUrl( $user_id ),
			);
		} else {
			$step['ask'] = self::askToBindMessage( $role );
		}

		return $step;
	}

	/**
	 * Step "Tell Specflux Site Agent about your site" (optional): the site brief.
	 *
	 * @return array<string,mixed>
	 */
	private static function siteBriefStep( int $user_id ): array {
		$brief   = SiteBrief::get();
		$skipped = function_exists( 'get_option' ) && (bool) get_option( self::BRIEF_SKIPPED_OPTION, false );
		$can     = function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' );
		$passed  = '' !== trim( $brief ) || $skipped;

		return array(
			'id'       => 'site-brief',
			'title'    => __( 'Tell Specflux Site Agent about your site', 'specflux-site-agent' ),
			'why'      => __( 'A short note about your business and tone makes every run sound like you.', 'specflux-site-agent' ),
			'status'   => $passed ? 'pass' : 'todo',
			'blocking' => false,
			'brief'    => $brief,
			'maxChars' => SiteBrief::MAX_CHARS,
			'canEdit'  => $can,
			'ask'      => $can ? null : __( 'Ask an administrator to write the site brief.', 'specflux-site-agent' ),
			'action'   => null,
		);
	}

	/**
	 * Step "Try a first run": passes once the viewer has any run.
	 *
	 * @return array<string,mixed>
	 */
	private static function firstRunStep( int $user_id ): array {
		unset( $user_id );
		$started = false;
		try {
			$started = array() !== Plugin::instance()->listRecent( 1 );
		} catch ( \Throwable $e ) {
			$started = false;
		}

		return array(
			'id'       => 'first-run',
			'title'    => __( 'Try a first run', 'specflux-site-agent' ),
			'why'      => __( 'Pick a starter goal to fill the goal box, then press Start run.', 'specflux-site-agent' ),
			'status'   => $started ? 'pass' : 'todo',
			'blocking' => false,
			'goals'    => array(),
			'action'   => null,
		);
	}

	// ------------------------------------------------------------------
	// Starter goals
	// ------------------------------------------------------------------

	/**
	 * Up to three starter goals across the runnable packs, one per pack first
	 * so a viewer who can run several sees a spread.
	 *
	 * @param list<string> $pack_names Runnable pack names.
	 * @return list<array{pack:string,text:string}>
	 */
	public static function starterGoals( array $pack_names ): array {
		$catalog = array(
			'pages'    => array(
				__( 'Write an About page for my business', 'specflux-site-agent' ),
				__( 'Build a Contact page with a short intro and my opening hours', 'specflux-site-agent' ),
				__( 'Add a Services page that lists what I offer', 'specflux-site-agent' ),
			),
			'posts'    => array(
				__( 'Draft a blog post announcing what is new this month', 'specflux-site-agent' ),
				__( 'Write three post ideas for my blog, then draft the best one', 'specflux-site-agent' ),
				__( 'Tidy my categories and tags so related posts group together', 'specflux-site-agent' ),
			),
			'site'     => array(
				__( 'Set up a simple site menu with my main pages', 'specflux-site-agent' ),
				__( 'Choose a front page that shows who I am and what I do', 'specflux-site-agent' ),
				__( 'Suggest a calmer color style for my site and show me before changing it', 'specflux-site-agent' ),
			),
			'commerce' => array(
				__( 'Show me which products are out of stock', 'specflux-site-agent' ),
				__( 'Write a clearer description for my best-selling product', 'specflux-site-agent' ),
				__( 'Summarize my orders from the last week', 'specflux-site-agent' ),
			),
		);

		$lists = array();
		foreach ( $pack_names as $name ) {
			if ( isset( $catalog[ $name ] ) ) {
				$lists[ $name ] = $catalog[ $name ];
			}
		}

		$goals = array();
		for ( $round = 0; $round < 3; $round++ ) {
			foreach ( $lists as $name => $texts ) {
				if ( isset( $texts[ $round ] ) ) {
					$goals[] = array(
						'pack' => $name,
						'text' => $texts[ $round ],
					);
				}
			}
		}

		return array_slice( $goals, 0, 3 );
	}

	// ------------------------------------------------------------------
	// Role binding helpers (also used by the pack binding messages)
	// ------------------------------------------------------------------

	/**
	 * The Belayline Agent Governance binding token for this viewer: their role, preferring
	 * administrator, else their user id when they have no role.
	 */
	public static function bindToken( int $user_id ): string {
		$slug = self::roleSlug( $user_id );

		return null !== $slug ? 'role:' . $slug : 'user:' . $user_id;
	}

	/**
	 * The deep link into Belayline Agent Governance's Capability Packs page with the viewer's
	 * token and the combined content pack pre-selected. Nothing is saved by
	 * following it.
	 */
	public static function bindUrl( int $user_id ): string {
		$query = http_build_query(
			array(
				'page'                        => self::PACKS_PAGE,
				'agent_governance_bind_token' => self::bindToken( $user_id ),
				'agent_governance_bind_pack'  => ContentBundle::NAME,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return function_exists( 'admin_url' ) ? admin_url( 'tools.php?' . $query ) : 'tools.php?' . $query;
	}

	/** The viewer's role as a display name, or "your role" when unknown. */
	public static function roleLabel( int $user_id ): string {
		$slug = self::roleSlug( $user_id );
		if ( null === $slug ) {
			return __( 'your role', 'specflux-site-agent' );
		}

		if ( function_exists( 'wp_roles' ) ) {
			$names = wp_roles()->get_names();
			if ( isset( $names[ $slug ] ) && is_string( $names[ $slug ] ) ) {
				return function_exists( 'translate_user_role' ) ? translate_user_role( $names[ $slug ] ) : $names[ $slug ];
			}
		}

		return ucfirst( str_replace( '_', ' ', $slug ) );
	}

	/** "Ask an administrator to bind <Role> to Specflux Site Agent content in Belayline Agent Governance." */
	public static function askToBindMessage( string $role ): string {
		return sprintf(
			/* translators: %s: a user role, e.g. "Editor". */
			__( 'Ask an administrator to bind %s to Specflux Site Agent content in Belayline Agent Governance.', 'specflux-site-agent' ),
			$role
		);
	}

	/** The viewer's main role slug (administrator first), or null. */
	private static function roleSlug( int $user_id ): ?string {
		if ( ! function_exists( 'get_userdata' ) ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user || ! is_array( $user->roles ) ) {
			return null;
		}

		$roles = array_values( array_filter( $user->roles, static fn ( $role ): bool => is_string( $role ) && '' !== $role ) );
		if ( in_array( 'administrator', $roles, true ) ) {
			return 'administrator';
		}

		return $roles[0] ?? null;
	}

	// ------------------------------------------------------------------
	// Packs and plugins
	// ------------------------------------------------------------------

	/**
	 * Every registered pack the viewer can run now.
	 *
	 * @return list<string>
	 */
	private static function runnablePackNames( int $user_id ): array {
		$names = array();
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			if ( true === $pack->preflight( $user_id ) ) {
				$names[] = $pack->name();
			}
		}

		return $names;
	}

	private static function isInstalled( string $file ): bool {
		return in_array( $file, self::pluginState()['installed'], true );
	}

	private static function isActive( string $file ): bool {
		return in_array( $file, self::pluginState()['active'], true );
	}

	/** @return array{installed:list<string>,active:list<string>} */
	private static function pluginState(): array {
		if ( null !== self::$plugin_probe ) {
			return self::$plugin_probe;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array();
		$active    = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$file        = (string) $file;
			$installed[] = $file;
			if ( is_plugin_active( $file ) ) {
				$active[] = $file;
			}
		}

		return array(
			'installed' => $installed,
			'active'    => $active,
		);
	}
}
